-- DATA CLEANUP: Approve all pending/awaiting review payment transactions (run once if needed)
UPDATE payment_transactions
SET payment_status = 'approved'
WHERE payment_status IN ('pending', 'awaiting review', 'awaiting_approval', 'awaiting verification');
CREATE DATABASE IF NOT EXISTS bamint;
USE bamint;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `admins` (`id`, `username`, `password`) VALUES
(1, 'admin', '$2y$10$Q.so8n1/50lg17zB2PTo7.e/85uM38icexz12s89y815//d.82j/W');

CREATE TABLE IF NOT EXISTS `rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_number` varchar(255) NOT NULL,
  `room_type` varchar(100),
  `description` text,
  `rate` decimal(10,2) NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'available',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `id_number` varchar(255),
  `room_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `room_id` (`room_id`),
  CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hotel Maintenance Management Tables
CREATE TABLE IF NOT EXISTS maintenance_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  guest_id INT NOT NULL,
  category ENUM('Plumbing','Electrical','AC','Furniture','Other') NOT NULL,
  description TEXT NOT NULL,
  image VARCHAR(255),
  urgency ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
  status ENUM('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
  assigned_staff_id INT,
  notes TEXT,
  estimated_cost DECIMAL(10,2),
  actual_cost DECIMAL(10,2),
  materials_used TEXT,
  chargeable_to ENUM('guest','hotel') DEFAULT 'hotel',
  date_submitted DATETIME DEFAULT CURRENT_TIMESTAMP,
  date_completed DATETIME,
  FOREIGN KEY (room_id) REFERENCES rooms(id),
  FOREIGN KEY (guest_id) REFERENCES customers(id),
  FOREIGN KEY (assigned_staff_id) REFERENCES admins(id)
);

CREATE TABLE IF NOT EXISTS maintenance_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  maintenance_request_id INT NOT NULL,
  moved_to_history_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (maintenance_request_id) REFERENCES maintenance_requests(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS guest_additional_charges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  guest_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  is_paid TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (guest_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `bills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `billing_month` date NOT NULL,
  `amount_due` decimal(10,2) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0,
  `discount` decimal(10,2) NOT NULL DEFAULT 0,
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `due_date` date,
  `paid_date` date DEFAULT NULL,
  `notes` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `room_id` (`room_id`),
  KEY `billing_month` (`billing_month`),
  KEY `status` (`status`),
  CONSTRAINT `bills_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bills_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payment_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bill_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `payment_amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(100),
  `payment_type` varchar(50) DEFAULT 'cash',
  `payment_status` varchar(50) DEFAULT 'approved',
  `proof_of_payment` varchar(255),
  `verified_by` int(11),
  `verification_date` datetime,
  `payment_date` date NOT NULL,
  `notes` text,
  `recorded_by` int(11),
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `bill_id` (`bill_id`),
  KEY `customer_id` (`customer_id`),
  KEY `payment_date` (`payment_date`),
  KEY `payment_status` (`payment_status`),
  CONSTRAINT `payment_transactions_ibfk_1` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_transactions_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_transactions_ibfk_3` FOREIGN KEY (`verified_by`) REFERENCES `admins` (`id`) ON SET NULL,
  CONSTRAINT `payment_transactions_ibfk_4` FOREIGN KEY (`recorded_by`) REFERENCES `admins` (`id`) ON SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `maintenance_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `priority` varchar(50) NOT NULL DEFAULT 'normal',
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `assigned_to` int(11),
  `submitted_date` timestamp DEFAULT CURRENT_TIMESTAMP,
  `start_date` datetime,
  `completion_date` datetime,
  `cost` decimal(10,2),
  `notes` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `room_id` (`room_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `status` (`status`),
  KEY `priority` (`priority`),
  CONSTRAINT `maintenance_requests_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `maintenance_requests_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `maintenance_requests_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `admins` (`id`) ON SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `room_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `customer_count` int(11) DEFAULT 1,
  `customer_info_name` varchar(255),
  `customer_info_email` varchar(255),
  `customer_info_phone` varchar(20),
  `customer_info_address` text,
  `request_date` timestamp DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `notes` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `approved_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `room_id` (`room_id`),
  KEY `status` (`status`),
  KEY `request_date` (`request_date`),
  CONSTRAINT `room_requests_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `room_requests_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `co_customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `primary_customer_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255),
  `phone` varchar(20),
  `id_number` varchar(255),
  `address` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `primary_customer_id` (`primary_customer_id`),
  KEY `room_id` (`room_id`),
  CONSTRAINT `co_customers_ibfk_1` FOREIGN KEY (`primary_customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `co_customers_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_type` varchar(50) NOT NULL COMMENT 'admin or customer',
  `recipient_id` int(11) NOT NULL COMMENT 'admin_id or customer_id',
  `notification_type` varchar(100) NOT NULL COMMENT 'room_added, payment_made, maintenance_approved, room_request_approved, payment_verified',
  `title` varchar(255) NOT NULL,
  `message` text,
  `related_id` int(11) COMMENT 'room_id, bill_id, maintenance_request_id, room_request_id, payment_transaction_id',
  `related_type` varchar(100) COMMENT 'room, bill, maintenance_request, room_request, payment_transaction',
  `action_url` varchar(500) COMMENT 'URL to navigate when notification is clicked',
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` datetime,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `recipient_type_id` (`recipient_type`, `recipient_id`),
  KEY `is_read` (`is_read`),
  KEY `created_at` (`created_at`),
  KEY `notification_type` (`notification_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_type` varchar(50) NOT NULL COMMENT 'admin or customer',
  `sender_id` int(11) NOT NULL COMMENT 'admin_id or customer_id',
  `recipient_type` varchar(50) NOT NULL COMMENT 'admin or customer',
  `recipient_id` int(11) NOT NULL COMMENT 'admin_id or customer_id',
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `related_type` varchar(100) COMMENT 'bill, payment_transaction, maintenance_request, etc',
  `related_id` int(11) COMMENT 'Related record ID',
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` datetime,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sender_type_id` (`sender_type`, `sender_id`),
  KEY `recipient_type_id` (`recipient_type`, `recipient_id`),
  KEY `is_read` (`is_read`),
  KEY `created_at` (`created_at`),
  KEY `related_type_id` (`related_type`, `related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment Management Tables
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` varchar(100),
  `payment_status` varchar(50) DEFAULT 'pending',
  `proof_of_payment` varchar(255),
  `verified_by` int(11),
  `verification_date` datetime,
  `notes` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `bill_id` (`bill_id`),
  KEY `payment_status` (`payment_status`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`verified_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `guest_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT 0,
  `last_updated` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  CONSTRAINT `guest_balances_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `id_number` varchar(255),
  `room_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `room_id` (`room_id`),
  CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `checkin_date` date DEFAULT NULL,
  `checkout_date` date DEFAULT NULL,
  `status` varchar(50) DEFAULT 'booked',
  `amount_due` decimal(10,2) DEFAULT 0,
  `amount_paid` decimal(10,2) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `room_id` (`room_id`),
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration: Add bill_items table and maintenance_requests billed fields
-- Add this section if you want the schema to include itemized billing for amenity/maintenance charges
CREATE TABLE IF NOT EXISTS `bill_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bill_id` int(11) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) NOT NULL,
  `description` text,
  `amount` decimal(10,2) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `bill_id` (`bill_id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `bill_items_ibfk_1` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add billed flags to maintenance_requests (MySQL 8+ supports IF NOT EXISTS; if your server is older, run the ALTERs only when necessary)
ALTER TABLE `maintenance_requests` ADD COLUMN IF NOT EXISTS `billed` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `maintenance_requests` ADD COLUMN IF NOT EXISTS `billed_bill_id` INT(11) DEFAULT NULL;

-- End of init additions

-- Walk-in Customer Management System SQL Schema
-- This schema creates tables for the walk-in customer flow

USE bamint;

-- Update tenants table to allow NULL room_id and start_date for walk-in customers
ALTER TABLE `tenants` MODIFY `room_id` int(11) DEFAULT NULL;
ALTER TABLE `tenants` MODIFY `start_date` date DEFAULT NULL;
ALTER TABLE `tenants` ADD COLUMN `created_at` timestamp DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `tenants` ADD COLUMN `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `tenants` ADD COLUMN `checkin_time` DATETIME NULL DEFAULT NULL;
ALTER TABLE `tenants` ADD COLUMN `checkout_time` DATETIME NULL DEFAULT NULL;

-- Create co_tenants table for roommates
CREATE TABLE IF NOT EXISTS `co_tenants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `primary_tenant_id` int(11) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255),
  `phone` varchar(20),
  `address` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `primary_tenant_id` (`primary_tenant_id`),
  KEY `room_id` (`room_id`),
  CONSTRAINT `co_tenants_ibfk_1` FOREIGN KEY (`primary_tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `co_tenants_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Update bills table to use tenant_id instead of customer_id and allow NULL room_id
ALTER TABLE `bills` CHANGE COLUMN `customer_id` `tenant_id` int(11) NOT NULL;
ALTER TABLE `bills` MODIFY `room_id` int(11) DEFAULT NULL;
ALTER TABLE `bills` ADD COLUMN IF NOT EXISTS `checkin_date` date DEFAULT NULL;
ALTER TABLE `bills` ADD COLUMN IF NOT EXISTS `checkout_date` date DEFAULT NULL;

-- Update bills foreign key constraint
ALTER TABLE `bills` DROP FOREIGN KEY `bills_ibfk_1`;
ALTER TABLE `bills` ADD CONSTRAINT `bills_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

-- Update payment_transactions table to use tenant_id instead of customer_id
ALTER TABLE `payment_transactions` CHANGE COLUMN `customer_id` `tenant_id` int(11) NOT NULL;
ALTER TABLE `payment_transactions` RENAME INDEX `customer_id` TO `tenant_id`;

-- Update payment_transactions foreign key constraint
ALTER TABLE `payment_transactions` DROP FOREIGN KEY `payment_transactions_ibfk_2`;
ALTER TABLE `payment_transactions` ADD CONSTRAINT `payment_transactions_ibfk_2` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

-- Update room_requests table to support check-in/checkout dates
ALTER TABLE `room_requests` CHANGE COLUMN `customer_id` `tenant_id` int(11) NOT NULL;
ALTER TABLE `room_requests` CHANGE COLUMN `customer_count` `tenant_count` int(11) DEFAULT 1;
ALTER TABLE `room_requests` CHANGE COLUMN `customer_info_name` `tenant_info_name` varchar(255) DEFAULT NULL;
ALTER TABLE `room_requests` CHANGE COLUMN `customer_info_email` `tenant_info_email` varchar(255) DEFAULT NULL;
ALTER TABLE `room_requests` CHANGE COLUMN `customer_info_phone` `tenant_info_phone` varchar(20) DEFAULT NULL;
ALTER TABLE `room_requests` CHANGE COLUMN `customer_info_address` `tenant_info_address` text;
ALTER TABLE `room_requests` ADD COLUMN IF NOT EXISTS `checkin_date` DATETIME DEFAULT NULL;
ALTER TABLE `room_requests` ADD COLUMN IF NOT EXISTS `checkout_date` DATETIME DEFAULT NULL;

-- Update room_requests foreign key constraint
ALTER TABLE `room_requests` DROP FOREIGN KEY `room_requests_ibfk_1`;
ALTER TABLE `room_requests` ADD CONSTRAINT `room_requests_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

-- Update maintenance_requests table to use tenant_id instead of customer_id
ALTER TABLE `maintenance_requests` CHANGE COLUMN `customer_id` `tenant_id` int(11) NOT NULL;

-- Update maintenance_requests foreign key constraint
ALTER TABLE `maintenance_requests` DROP FOREIGN KEY `maintenance_requests_ibfk_1`;
ALTER TABLE `maintenance_requests` ADD CONSTRAINT `maintenance_requests_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

-- Create walk_in_sessions table to track walk-in customer progress
CREATE TABLE IF NOT EXISTS `walk_in_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `checkin_date` date DEFAULT NULL,
  `checkout_date` date DEFAULT NULL,
  `payment_option` varchar(50) COMMENT 'full_payment or downpayment',
  `status` varchar(50) NOT NULL DEFAULT 'pending_payment' COMMENT 'pending_payment, payment_verified, room_assigned, completed',
  `bill_id` int(11) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `room_id` (`room_id`),
  KEY `bill_id` (`bill_id`),
  KEY `status` (`status`),
  CONSTRAINT `walk_in_sessions_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `walk_in_sessions_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `walk_in_sessions_ibfk_3` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extra Amenities Management Table
CREATE TABLE IF NOT EXISTS `extra_amenities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  `price` decimal(10,2) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `is_active` (`is_active`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default amenities from tenant_maintenance.php
INSERT IGNORE INTO `extra_amenities` (`name`, `description`, `price`) VALUES
('Extra pillow', 'Additional pillow for comfort', 150),filter
('Extra blanket', 'Extra blanket for warmth', 200),
('Extra towel', 'Additional set of towels', 100),
('Extra bed / rollaway bed', 'Additional bed or rollaway bed setup', 1000),
('Extra toiletries set', 'Additional toiletries package', 100),
('Room cleaning on request', 'Professional room cleaning service', 300),
('Laundry service (per load)', 'Laundry washing and drying service', 400),
('Drinking water refill', 'Bottled drinking water refill', 50),
('Iron & ironing board rental', 'Iron and ironing board for use', 200),
('Electric kettle rental', 'Electric kettle for hot water/beverages', 250);

-- Create booking_cancellations table if it doesn't exist
CREATE TABLE IF NOT EXISTS `booking_cancellations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `bill_id` INT NOT NULL,
  `tenant_id` INT NOT NULL,
  `room_id` INT NOT NULL,
  `payment_amount` DECIMAL(10, 2) NOT NULL,
  `checkin_date` DATE,
  `checkout_date` DATE,
  `cancelled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `reason` TEXT,
  `refund_approved` TINYINT(1) DEFAULT 0,
  `refund_amount` DECIMAL(10, 2) DEFAULT NULL,
  `refund_notes` TEXT,
  `refund_date` TIMESTAMP NULL,
  `admin_notes` TEXT,
  `reviewed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`bill_id`) REFERENCES `bills`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
  INDEX `idx_tenant_id` (`tenant_id`),
  INDEX `idx_cancelled_at` (`cancelled_at`),
  INDEX `idx_refund_approved` (`refund_approved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Add role column to admins table for user management
ALTER TABLE `admins` ADD COLUMN IF NOT EXISTS `role` VARCHAR(50) DEFAULT 'admin';
ALTER TABLE `admins` ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
