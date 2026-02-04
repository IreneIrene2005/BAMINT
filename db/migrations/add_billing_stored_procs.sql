-- Migration: add stored procedures to manage amenity billing and provide merge template
-- Run this file in your MySQL client (phpMyAdmin or mysql CLI). Requires MySQL 5.7+ (DELIMITER usage).

-- Ensure maintenance_requests has billed columns (safe, uses IF NOT EXISTS on MySQL 8+)
ALTER TABLE `maintenance_requests` ADD COLUMN IF NOT EXISTS `billed` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `maintenance_requests` ADD COLUMN IF NOT EXISTS `billed_bill_id` INT(11) DEFAULT NULL;

-- Ensure bill_items table exists (itemized charges)
CREATE TABLE IF NOT EXISTS `bill_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bill_id` int(11) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) NOT NULL,
  `description` text,
  `amount` decimal(10,2) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `bill_id` (`bill_id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `bill_items_ibfk_1` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Drop procedure if exists (safe to run multiple times)
DROP PROCEDURE IF EXISTS `sp_add_amenity_to_bill`;
DELIMITER $$
CREATE PROCEDURE `sp_add_amenity_to_bill`(
    IN p_customer_id INT,
    IN p_cost DECIMAL(10,2),
    IN p_request_id INT,
    IN p_category VARCHAR(255)
)
BEGIN
    DECLARE v_bill_id INT DEFAULT NULL;
    DECLARE v_note TEXT DEFAULT NULL;
    DECLARE v_room_id INT DEFAULT NULL;
    DECLARE v_target_month DATE DEFAULT (DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01'));

    -- Validation
    IF p_customer_id IS NULL OR p_cost IS NULL OR p_cost <= 0 THEN
        SELECT 0 AS bill_id, 'invalid-input' AS status;
        LEAVE BEGIN;
    END IF;

    -- If request provided and already billed, return existing bill id
    IF p_request_id IS NOT NULL THEN
        SELECT billed, billed_bill_id INTO @billed_flag, @billed_bill_id FROM maintenance_requests WHERE id = p_request_id LIMIT 1;
        IF @billed_flag = 1 AND @billed_bill_id IS NOT NULL THEN
            SELECT @billed_bill_id AS bill_id, 'already_billed' AS status;
            LEAVE BEGIN;
        END IF;
        -- check bills notes for the request reference to avoid duplicate billing
        SELECT id INTO v_bill_id FROM bills WHERE customer_id = p_customer_id AND notes LIKE CONCAT('%Request #', p_request_id, '%') LIMIT 1;
        IF v_bill_id IS NOT NULL THEN
            SELECT v_bill_id AS bill_id, 'already_billed' AS status;
            LEAVE BEGIN;
        END IF;
        SET v_note = CONCAT('Amenity: ', COALESCE(p_category,'amenity'), ' (Request #', p_request_id, ')');
    END IF;

    -- Try to find active bill (not paid OR recently paid within 7-day grace)
    SELECT id INTO v_bill_id
    FROM bills
    WHERE customer_id = p_customer_id
      AND (status != 'paid' OR DATE_ADD(updated_at, INTERVAL 7 DAY) >= NOW())
    ORDER BY billing_month DESC
    LIMIT 1;

    -- If none, try to attach to current-month bill
    IF v_bill_id IS NULL THEN
        SELECT id INTO v_bill_id FROM bills WHERE customer_id = p_customer_id AND billing_month = v_target_month LIMIT 1;
    END IF;

    IF v_bill_id IS NOT NULL THEN
        -- Append to existing bill
        UPDATE bills SET amount_due = amount_due + p_cost, updated_at = NOW() WHERE id = v_bill_id;
        IF v_note IS NOT NULL THEN
            UPDATE bills SET notes = TRIM(BOTH ' |' FROM CONCAT(IFNULL(notes,''), ' | ', v_note)) WHERE id = v_bill_id;
        END IF;

        -- Add itemized entry and mark maintenance request billed (if provided)
        IF p_request_id IS NOT NULL THEN
            INSERT INTO bill_items (bill_id, request_id, tenant_id, description, amount)
            VALUES (v_bill_id, p_request_id, p_customer_id, v_note, p_cost);
            UPDATE maintenance_requests SET billed = 1, billed_bill_id = v_bill_id WHERE id = p_request_id;
        END IF;

        SELECT v_bill_id AS bill_id, 'appended' AS status;
        LEAVE BEGIN;
    ELSE
        -- Create a new bill containing only the amenity cost (avoid adding room rate)
        SELECT room_id INTO v_room_id FROM customers WHERE id = p_customer_id LIMIT 1;
        INSERT INTO bills (customer_id, room_id, billing_month, amount_due, amount_paid, status, notes, created_at, updated_at)
        VALUES (p_customer_id, v_room_id, v_target_month, p_cost, 0, 'pending', v_note, NOW(), NOW());
        SET v_bill_id = LAST_INSERT_ID();

        IF p_request_id IS NOT NULL THEN
            INSERT INTO bill_items (bill_id, request_id, tenant_id, description, amount)
            VALUES (v_bill_id, p_request_id, p_customer_id, v_note, p_cost);
            UPDATE maintenance_requests SET billed = 1, billed_bill_id = v_bill_id WHERE id = p_request_id;
        END IF;

        SELECT v_bill_id AS bill_id, 'created' AS status;
        LEAVE BEGIN;
    END IF;
END$$
DELIMITER ;

-- Merge template: replace :CUSTOMER_ID with the customer id you want to merge active bills for,
-- or run as a manual transaction by supplying the target and source bills.
-- Example manual merge template (replace TARGET_ID and SOURCE_IDS):
-- START TRANSACTION;
-- UPDATE bill_items SET bill_id = TARGET_ID WHERE bill_id IN (SOURCE_IDS);
-- UPDATE maintenance_requests SET billed_bill_id = TARGET_ID WHERE billed_bill_id IN (SOURCE_IDS);
-- UPDATE payment_transactions SET bill_id = TARGET_ID WHERE bill_id IN (SOURCE_IDS);
-- UPDATE bills SET notes = TRIM(BOTH ' |' FROM CONCAT(IFNULL(notes,''), ' | ', (SELECT GROUP_CONCAT(DISTINCT notes SEPARATOR ' | ') FROM bills WHERE id IN (SOURCE_IDS) AND IFNULL(notes,'') <> ''))) WHERE id = TARGET_ID;
-- SET @currentDue = (SELECT IFNULL(amount_due,0) FROM bills WHERE id = TARGET_ID);
-- SET @added = (SELECT IFNULL(SUM(amount_due),0) FROM bills WHERE id IN (SOURCE_IDS));
-- SET @newDue = @currentDue + @added;
-- SET @newPaid = (SELECT IFNULL(SUM(payment_amount),0) FROM payment_transactions WHERE bill_id = TARGET_ID AND payment_status IN ('verified','approved'));
-- SET @newStatus = IF(@newPaid >= @newDue AND @newDue > 0, 'paid', IF(@newPaid > 0, 'partial', 'pending'));
-- DELETE FROM bills WHERE id IN (SOURCE_IDS);
-- UPDATE bills SET amount_due = @newDue, amount_paid = @newPaid, status = @newStatus, updated_at = NOW() WHERE id = TARGET_ID;
-- COMMIT;

-- Optional: you can create a stored procedure for merging too, but the manual template above is explicit and safer because you can preview SELECTs before running the DELETE.

-- End of migration
