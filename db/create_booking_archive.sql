-- Archive table for booking cancellations
CREATE TABLE IF NOT EXISTS booking_cancellations_archive (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_cancellation_id INT,
    tenant_id INT NOT NULL,
    room_id INT NOT NULL,
    booking_id INT,
    checkin_date DATE,
    checkout_date DATE,
    payment_amount DECIMAL(10, 2),
    reason TEXT,
    refund_approved TINYINT(1) DEFAULT 0,
    refund_amount DECIMAL(10, 2),
    refund_notes TEXT,
    refund_date DATETIME,
    admin_notes TEXT,
    archived_by INT,
    archived_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    archived_reason VARCHAR(255),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (archived_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add indexes for better query performance
CREATE INDEX IF NOT EXISTS idx_archived_tenant ON booking_cancellations_archive(tenant_id);
CREATE INDEX IF NOT EXISTS idx_archived_at_booking ON booking_cancellations_archive(archived_at);
CREATE INDEX IF NOT EXISTS idx_original_cancellation ON booking_cancellations_archive(original_cancellation_id);
