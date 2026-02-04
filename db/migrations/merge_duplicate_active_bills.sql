-- Migration: Preview + Stored Procedure to merge duplicate active bills for ALL tenants
-- WARNING: Backup your database before running this migration and/or the merge procedure.
-- XAMPP Windows backup example:
-- C:\xampp\mysql\bin\mysqldump -u root bamint > C:\temp\bamint_backup.sql

-- 1) Create a merge log for auditing
CREATE TABLE IF NOT EXISTS `merge_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `target_bill_id` int(11) NOT NULL,
  `merged_count` int(11) NOT NULL,
  `merged_bill_ids` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Preview query: lists tenants with >1 active bill and shows target & source ids
-- Run this first to review the candidates (do NOT run the procedure yet until you check results)

-- Example preview SELECT (run this manually to inspect):
-- SELECT tenant_id, name, cnt, target_bill_id, source_bill_ids, total_due FROM (
--   SELECT t.id AS tenant_id, t.name,
--       COUNT(b.id) AS cnt,
--       (SELECT id FROM bills b2 WHERE b2.tenant_id = t.id AND (b2.status != 'paid' OR DATE_ADD(b2.updated_at, INTERVAL 7 DAY) >= NOW()) ORDER BY b2.billing_month DESC LIMIT 1) AS target_bill_id,
--       (SELECT GROUP_CONCAT(id) FROM bills b3 WHERE b3.tenant_id = t.id AND (b3.status != 'paid' OR DATE_ADD(b3.updated_at, INTERVAL 7 DAY) >= NOW()) AND id <> (SELECT id FROM bills b4 WHERE b4.tenant_id = t.id AND (b4.status != 'paid' OR DATE_ADD(b4.updated_at, INTERVAL 7 DAY) >= NOW()) ORDER BY b4.billing_month DESC LIMIT 1)) AS source_bill_ids,
--       (SELECT IFNULL(SUM(amount_due),0) FROM bills b5 WHERE b5.tenant_id = t.id AND (b5.status != 'paid' OR DATE_ADD(b5.updated_at, INTERVAL 7 DAY) >= NOW())) AS total_due
--   FROM tenants t
--   JOIN bills b ON b.tenant_id = t.id
--   WHERE (b.status != 'paid' OR DATE_ADD(b.updated_at, INTERVAL 7 DAY) >= NOW())
--   GROUP BY t.id HAVING COUNT(b.id) > 1
-- ) x ORDER BY name;

-- 3) Create stored procedure to perform merges for ALL tenants with duplicate active bills
DROP PROCEDURE IF EXISTS `sp_merge_duplicate_active_bills`;
DELIMITER $$
CREATE PROCEDURE `sp_merge_duplicate_active_bills`()
BEGIN
  DECLARE done INT DEFAULT FALSE;
  DECLARE v_tenant INT;
  DECLARE v_target INT;
  DECLARE v_added DECIMAL(18,2) DEFAULT 0;
  DECLARE v_currentDue DECIMAL(18,2) DEFAULT 0;
  DECLARE v_newPaid DECIMAL(18,2) DEFAULT 0;
  DECLARE v_newStatus VARCHAR(20);
  DECLARE v_mergedIds TEXT DEFAULT NULL;

  DECLARE cur CURSOR FOR 
    SELECT t.id FROM tenants t
    JOIN bills b ON b.tenant_id = t.id
    WHERE (b.status != 'paid' OR DATE_ADD(b.updated_at, INTERVAL 7 DAY) >= NOW())
    GROUP BY t.id HAVING COUNT(b.id) > 1;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

  -- Temporary table to hold source bill IDs per tenant
  CREATE TEMPORARY TABLE IF NOT EXISTS temp_sources (id INT PRIMARY KEY) ENGINE=MEMORY;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_tenant;
    IF done THEN
      LEAVE read_loop;
    END IF;

    -- Determine target bill (most recent billing_month among active bills)
    SELECT id INTO v_target FROM bills WHERE tenant_id = v_tenant AND (status != 'paid' OR DATE_ADD(updated_at, INTERVAL 7 DAY) >= NOW()) ORDER BY billing_month DESC LIMIT 1;

    IF v_target IS NULL THEN
      ITERATE read_loop;
    END IF;

    -- Fill temp_sources with the other bills to merge
    TRUNCATE TABLE temp_sources;
    INSERT IGNORE INTO temp_sources (id)
      SELECT id FROM bills WHERE tenant_id = v_tenant AND (status != 'paid' OR DATE_ADD(updated_at, INTERVAL 7 DAY) >= NOW()) AND id <> v_target;

    IF (SELECT COUNT(*) FROM temp_sources) = 0 THEN
      -- Nothing to merge for this tenant
      ITERATE read_loop;
    END IF;

    -- Start transaction for this tenant
    START TRANSACTION;
    -- Move itemized lines, maintenance request references, and payment transactions into target
    UPDATE bill_items SET bill_id = v_target WHERE bill_id IN (SELECT id FROM temp_sources);
    UPDATE maintenance_requests SET billed_bill_id = v_target WHERE billed_bill_id IN (SELECT id FROM temp_sources);
    UPDATE payment_transactions SET bill_id = v_target WHERE bill_id IN (SELECT id FROM temp_sources);

    -- Append notes (avoid simple duplicates using GROUP_CONCAT distinct)
    UPDATE bills SET notes = TRIM(BOTH ' |' FROM CONCAT(IFNULL(notes,''), ' | ', (SELECT GROUP_CONCAT(DISTINCT notes SEPARATOR ' | ') FROM bills WHERE id IN (SELECT id FROM temp_sources) AND IFNULL(notes,'') <> ''))) WHERE id = v_target;

    -- Recompute totals
    SELECT amount_due INTO v_currentDue FROM bills WHERE id = v_target;
    SELECT IFNULL(SUM(amount_due),0) INTO v_added FROM bills WHERE id IN (SELECT id FROM temp_sources);
    SET @newDue = v_currentDue + v_added;

    SELECT IFNULL(SUM(payment_amount),0) INTO v_newPaid FROM payment_transactions WHERE bill_id = v_target AND payment_status IN ('verified','approved');
    SET v_newStatus = IF(v_newPaid >= @newDue AND @newDue > 0, 'paid', IF(v_newPaid > 0, 'partial', 'pending'));

    -- Capture merged IDs for logging
    SELECT GROUP_CONCAT(id) INTO v_mergedIds FROM temp_sources;

    -- Delete source bills
    DELETE FROM bills WHERE id IN (SELECT id FROM temp_sources);

    -- Update target bill values
    UPDATE bills SET amount_due = @newDue, amount_paid = v_newPaid, status = v_newStatus, updated_at = NOW() WHERE id = v_target;

    -- Insert audit log entry
    INSERT INTO merge_log (tenant_id, target_bill_id, merged_count, merged_bill_ids, created_at)
      VALUES (v_tenant, v_target, (SELECT COUNT(*) FROM temp_sources), v_mergedIds, NOW());

    COMMIT;

    -- Clear temp table
    TRUNCATE TABLE temp_sources;

  END LOOP;
  CLOSE cur;

  DROP TEMPORARY TABLE IF EXISTS temp_sources;
END$$
DELIMITER ;

-- Usage:
-- 1) Backup DB
-- 2) Run the preview SELECT (see top of file) and review the tenant/bill lists.
-- 3) When ready, run: CALL sp_merge_duplicate_active_bills();
-- 4) Verify results and review merge_log:
--    SELECT * FROM merge_log ORDER BY created_at DESC LIMIT 50;

-- End of migration
