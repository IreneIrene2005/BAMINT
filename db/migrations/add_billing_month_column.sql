-- Add billing_month column to bills table
ALTER TABLE bills ADD COLUMN billing_month date AFTER room_id;

-- Create index on billing_month for faster queries
ALTER TABLE bills ADD INDEX idx_billing_month (billing_month);

-- Populate billing_month with checkin_date for existing bills
UPDATE bills SET billing_month = checkin_date WHERE billing_month IS NULL AND checkin_date IS NOT NULL;

-- Verify the update
SELECT id, billing_month, checkin_date FROM bills LIMIT 10;
