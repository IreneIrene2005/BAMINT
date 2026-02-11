-- Walk-in Customer Management System SQL Schema
-- This schema creates tables for the walk-in customer flow

USE bamint;

-- Update tenants table to allow NULL room_id and start_date for walk-in customers
ALTER TABLE `tenants` MODIFY `room_id` int(11) DEFAULT NULL;
ALTER TABLE `tenants` MODIFY `start_date` date DEFAULT NULL;
ALTER TABLE `tenants` ADD COLUMN `created_at` timestamp DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `tenants` ADD COLUMN `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

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
ALTER TABLE `room_requests` ADD COLUMN IF NOT EXISTS `checkin_date` date DEFAULT NULL;
ALTER TABLE `room_requests` ADD COLUMN IF NOT EXISTS `checkout_date` date DEFAULT NULL;

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

-- Add address column to tenant_accounts table
ALTER TABLE `tenant_accounts` ADD COLUMN IF NOT EXISTS `address` text DEFAULT NULL;

-- Verification query: Check tables after updates
SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'bamint' 
AND TABLE_NAME IN ('tenants', 'bills', 'payment_transactions', 'room_requests', 'co_tenants', 'walk_in_sessions')
ORDER BY TABLE_NAME, ORDINAL_POSITION;
