<?php
/**
 * Database Migration Script
 * Adds missing columns to existing tables
 */

require_once "database.php";

try {
    // Add room_type column to rooms table if it doesn't exist
    $sql = "ALTER TABLE rooms ADD COLUMN room_type VARCHAR(100) AFTER room_number";
    try {
        $conn->exec($sql);
        echo "✓ Added room_type column to rooms table<br>";
    } catch (PDOException $e) {
        // Column might already exist
        if (strpos($e->getMessage(), "Duplicate column") === false) {
            echo "ℹ room_type column already exists or error: " . $e->getMessage() . "<br>";
        }
    }

    // Add id_number column to tenants table if it doesn't exist
    $sql = "ALTER TABLE tenants ADD COLUMN id_number VARCHAR(255) AFTER phone";
    try {
        $conn->exec($sql);
        echo "✓ Added id_number column to tenants table<br>";
    } catch (PDOException $e) {
        // Column might already exist
        if (strpos($e->getMessage(), "Duplicate column") === false) {
            echo "ℹ id_number column already exists or error: " . $e->getMessage() . "<br>";
        }
    }

    // Add status column to tenants table if it doesn't exist
    $sql = "ALTER TABLE tenants ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active' AFTER end_date";
    try {
        $conn->exec($sql);
        echo "✓ Added status column to tenants table<br>";
    } catch (PDOException $e) {
        // Column might already exist
        if (strpos($e->getMessage(), "Duplicate column") === false) {
            echo "ℹ status column already exists or error: " . $e->getMessage() . "<br>";
        }
    }

    // Create bills table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS `bills` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `tenant_id` int(11) NOT NULL,
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
      KEY `tenant_id` (`tenant_id`),
      KEY `room_id` (`room_id`),
      KEY `billing_month` (`billing_month`),
      KEY `status` (`status`),
      CONSTRAINT `bills_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
      CONSTRAINT `bills_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try {
        $conn->exec($sql);
        echo "✓ Created bills table<br>";
    } catch (PDOException $e) {
        echo "ℹ Bills table already exists or error: " . $e->getMessage() . "<br>";
    }

    // Create payment_transactions table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS `payment_transactions` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `bill_id` int(11) NOT NULL,
      `tenant_id` int(11) NOT NULL,
      `payment_amount` decimal(10,2) NOT NULL,
      `payment_method` varchar(100),
      `payment_date` date NOT NULL,
      `notes` text,
      `recorded_by` int(11),
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `bill_id` (`bill_id`),
      KEY `tenant_id` (`tenant_id`),
      KEY `payment_date` (`payment_date`),
      CONSTRAINT `payment_transactions_ibfk_1` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`id`) ON DELETE CASCADE,
      CONSTRAINT `payment_transactions_ibfk_2` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try {
        $conn->exec($sql);
        echo "✓ Created payment_transactions table<br>";
    } catch (PDOException $e) {
        echo "ℹ Payment transactions table already exists or error: " . $e->getMessage() . "<br>";
    }

    // Create maintenance_requests table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS `maintenance_requests` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `tenant_id` int(11) NOT NULL,
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
      KEY `tenant_id` (`tenant_id`),
      KEY `room_id` (`room_id`),
      KEY `assigned_to` (`assigned_to`),
      KEY `status` (`status`),
      KEY `priority` (`priority`),
      CONSTRAINT `maintenance_requests_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
      CONSTRAINT `maintenance_requests_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
      CONSTRAINT `maintenance_requests_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `admins` (`id`) ON SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try {
        $conn->exec($sql);
        echo "✓ Created maintenance_requests table<br>";
    } catch (PDOException $e) {
        echo "ℹ Maintenance requests table already exists or error: " . $e->getMessage() . "<br>";
    }

    echo "<br><strong>Migration completed successfully!</strong>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
