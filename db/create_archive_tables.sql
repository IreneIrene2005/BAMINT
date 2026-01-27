-- Archive tables for storing old records
-- These tables store archived payment transactions and maintenance requests

CREATE TABLE IF NOT EXISTS payment_transactions_archive (
    id INT PRIMARY KEY,
    bill_id INT,
    tenant_id INT,
    payment_amount DECIMAL(10, 2),
    payment_method VARCHAR(50),
    payment_type VARCHAR(20),
    payment_status VARCHAR(50),
    verified_by INT,
    verification_date DATETIME,
    notes TEXT,
    created_at DATETIME,
    archived_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (bill_id) REFERENCES bills(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_requests_archive (
    id INT PRIMARY KEY,
    tenant_id INT,
    room_id INT,
    category VARCHAR(100),
    description TEXT,
    priority VARCHAR(20),
    status VARCHAR(50),
    assigned_to INT,
    completion_date DATETIME,
    notes TEXT,
    created_at DATETIME,
    archived_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index for better query performance
CREATE INDEX idx_archived_at_payment ON payment_transactions_archive(archived_at);
CREATE INDEX idx_archived_at_maintenance ON maintenance_requests_archive(archived_at);
CREATE INDEX idx_tenant_payment_archive ON payment_transactions_archive(tenant_id);
CREATE INDEX idx_tenant_maintenance_archive ON maintenance_requests_archive(tenant_id);
