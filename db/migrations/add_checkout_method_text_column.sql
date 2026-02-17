-- Add a text column to store checkout payment method and migrate existing data
ALTER TABLE payment_transactions
ADD COLUMN IF NOT EXISTS checkout_payment_method VARCHAR(100) NULL AFTER checkout_payment_method_id;

-- Populate the new column using the lookup table when available, otherwise fallback to existing payment_method
UPDATE payment_transactions pt
LEFT JOIN payment_methods_checkout pmco ON pt.checkout_payment_method_id = pmco.id
SET pt.checkout_payment_method =
    CASE
        WHEN pmco.method_name IS NOT NULL AND pmco.method_name <> '' THEN pmco.method_name
        WHEN pt.payment_method IS NOT NULL AND TRIM(pt.payment_method) <> '' THEN TRIM(pt.payment_method)
        ELSE NULL
    END
WHERE pt.is_checkout_payment = 1;

-- Add index to speed up lookups by method
ALTER TABLE payment_transactions
ADD INDEX IF NOT EXISTS idx_checkout_method_text (checkout_payment_method);

-- Optional verification query (not executed by migration file runner):
-- SELECT id, bill_id, payment_method, checkout_payment_method, is_checkout_payment FROM payment_transactions WHERE is_checkout_payment = 1 LIMIT 50;
