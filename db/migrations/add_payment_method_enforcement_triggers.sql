-- Trigger to enforce checkout_payment_method is never NULL for checkouts
DELIMITER $$
DROP TRIGGER IF EXISTS trg_payment_transactions_enforce_checkout_method$$
CREATE TRIGGER trg_payment_transactions_enforce_checkout_method
BEFORE INSERT ON payment_transactions
FOR EACH ROW
BEGIN
  -- If this is a checkout (is_checkout_payment = 1), require checkout_payment_method
  IF NEW.is_checkout_payment = 1 THEN
    IF NEW.checkout_payment_method IS NULL OR TRIM(NEW.checkout_payment_method) = '' THEN
      -- If checkout_payment_method is empty, reject the insert
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Checkout payment method is required for checkout transactions';
    END IF;
  END IF;
  
  -- If this is a check-in (is_checkout_payment = 0), populate checkin_payment_method from payment_method if missing
  IF NEW.is_checkout_payment = 0 THEN
    IF NEW.checkin_payment_method IS NULL OR TRIM(NEW.checkin_payment_method) = '' THEN
      IF NEW.payment_method IS NOT NULL AND TRIM(NEW.payment_method) <> '' THEN
        SET NEW.checkin_payment_method = TRIM(NEW.payment_method);
      END IF;
    END IF;
  END IF;
END$$
DELIMITER ;
