-- Restore `payment_method` column and populate from checkin_payment_method for check-ins
ALTER TABLE payment_transactions
ADD COLUMN IF NOT EXISTS payment_method VARCHAR(100) NULL AFTER payment_amount;

-- Populate payment_method from checkin_payment_method for check-in rows
UPDATE payment_transactions
SET payment_method = checkin_payment_method
WHERE is_checkout_payment = 0 AND (payment_method IS NULL OR TRIM(payment_method) = '');

-- Optionally index
ALTER TABLE payment_transactions
ADD INDEX IF NOT EXISTS idx_payment_method (payment_method);
