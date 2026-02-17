-- Add a text column to store check-in payment method (gcash/paymaya) and migrate data
ALTER TABLE payment_transactions
ADD COLUMN IF NOT EXISTS checkin_payment_method VARCHAR(100) NULL AFTER payment_method;

-- Populate the new column using the lookup table when available, otherwise fallback to existing payment_method
UPDATE payment_transactions pt
LEFT JOIN payment_methods_checkin pmc ON pt.checkin_payment_method_id = pmc.id
SET pt.checkin_payment_method =
    CASE
        WHEN pmc.method_name IS NOT NULL AND pmc.method_name <> '' THEN pmc.method_name
        WHEN pt.payment_method IS NOT NULL AND TRIM(pt.payment_method) <> '' THEN TRIM(pt.payment_method)
        ELSE NULL
    END
WHERE pt.is_checkout_payment = 0;

-- Add index to speed up lookups by method
ALTER TABLE payment_transactions
ADD INDEX IF NOT EXISTS idx_checkin_method_text (checkin_payment_method);

-- Optional verification query (not executed by migration file runner):
-- SELECT id, bill_id, payment_method, checkin_payment_method, is_checkout_payment FROM payment_transactions WHERE is_checkout_payment = 0 LIMIT 50;
