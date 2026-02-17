-- Create payment_methods_checkin table (online methods only)
CREATE TABLE IF NOT EXISTS payment_methods_checkin (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    method_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create payment_methods_checkout table (all methods: cash, online)
CREATE TABLE IF NOT EXISTS payment_methods_checkout (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    method_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert check-in payment methods (online only)
INSERT INTO payment_methods_checkin (method_name, description) VALUES
('gcash', 'GCash'),
('paymaya', 'PayMaya')
ON DUPLICATE KEY UPDATE is_active = 1;

-- Insert checkout payment methods (cash + online)
INSERT INTO payment_methods_checkout (method_name, description) VALUES
('cash', 'Cash Payment'),
('gcash', 'GCash'),
('paymaya', 'PayMaya')
ON DUPLICATE KEY UPDATE is_active = 1;

-- Modify payment_transactions table to add separate payment method tracking
ALTER TABLE payment_transactions 
ADD COLUMN checkin_payment_method_id INT(11) AFTER payment_method,
ADD COLUMN checkout_payment_method_id INT(11) AFTER checkin_payment_method_id,
ADD FOREIGN KEY (checkin_payment_method_id) REFERENCES payment_methods_checkin(id),
ADD FOREIGN KEY (checkout_payment_method_id) REFERENCES payment_methods_checkout(id);

-- Add an index for better query performance
ALTER TABLE payment_transactions ADD INDEX idx_checkin_method (checkin_payment_method_id);
ALTER TABLE payment_transactions ADD INDEX idx_checkout_method (checkout_payment_method_id);

-- Migrate existing data: Map old payment_method values to the new structure
UPDATE payment_transactions pt
JOIN payment_methods_checkin pmc ON LOWER(pt.payment_method) = LOWER(pmc.method_name)
SET pt.checkin_payment_method_id = pmc.id
WHERE pt.is_checkout_payment = 0 AND pt.payment_method IS NOT NULL;

UPDATE payment_transactions pt
JOIN payment_methods_checkout pmco ON LOWER(pt.payment_method) = LOWER(pmco.method_name)
SET pt.checkout_payment_method_id = pmco.id
WHERE pt.is_checkout_payment = 1 AND pt.payment_method IS NOT NULL;
