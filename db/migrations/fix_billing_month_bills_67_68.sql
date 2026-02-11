-- Fix billing_month for bills 67 and 68 that have 0000-00-00
UPDATE bills 
SET billing_month = '2026-02-01'
WHERE id IN (67, 68);

-- Verify the update
SELECT id, billing_month, created_at FROM bills WHERE id IN (67, 68);
