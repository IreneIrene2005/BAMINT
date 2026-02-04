<?php
/**
 * Migration: Add bill_items table and maintenance_requests.billed fields
 * Run this once: include or execute from browser/CLI where DB connection is available
 */
require_once 'database.php';
try {
    // Create bill_items table
    $sql = "CREATE TABLE IF NOT EXISTS `bill_items` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $conn->exec($sql);
    echo "Created table bill_items or already exists.<br>";

    // Add billed and billed_bill_id to maintenance_requests
    $cols = $conn->query("SHOW COLUMNS FROM maintenance_requests LIKE 'billed'")->fetchAll();
    if (empty($cols)) {
        $conn->exec("ALTER TABLE maintenance_requests ADD COLUMN billed TINYINT(1) NOT NULL DEFAULT 0 AFTER notes");
        echo "Added column maintenance_requests.billed<br>";
    } else {
        echo "Column maintenance_requests.billed already exists or check failed.<br>";
    }

    $cols = $conn->query("SHOW COLUMNS FROM maintenance_requests LIKE 'billed_bill_id'")->fetchAll();
    if (empty($cols)) {
        $conn->exec("ALTER TABLE maintenance_requests ADD COLUMN billed_bill_id INT(11) DEFAULT NULL AFTER billed");
        echo "Added column maintenance_requests.billed_bill_id<br>";
    } else {
        echo "Column maintenance_requests.billed_bill_id already exists or check failed.<br>";
    }

    echo "Migration completed.\n";
} catch (PDOException $e) {
    echo "Migration error: " . $e->getMessage();
}
?>