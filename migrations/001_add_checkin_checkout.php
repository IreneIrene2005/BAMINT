<?php
/**
 * Migration: Add Check-in/Check-out Tracking to Tenants
 * Adds checkin_time and checkout_time columns to the tenants table
 */

require_once "db/database.php";

try {
    // Check if columns exist before adding them
    $check = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='tenants' AND COLUMN_NAME='checkin_time'");
    $exists = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$exists) {
        // Add checkin_time column
        $conn->exec("ALTER TABLE tenants ADD COLUMN checkin_time DATETIME NULL DEFAULT NULL AFTER status");
        echo "✓ Added checkin_time column\n";
    } else {
        echo "✓ checkin_time column already exists\n";
    }
    
    // Check for checkout_time column
    $check2 = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='tenants' AND COLUMN_NAME='checkout_time'");
    $exists2 = $check2->fetch(PDO::FETCH_ASSOC);
    
    if (!$exists2) {
        // Add checkout_time column
        $conn->exec("ALTER TABLE tenants ADD COLUMN checkout_time DATETIME NULL DEFAULT NULL AFTER checkin_time");
        echo "✓ Added checkout_time column\n";
    } else {
        echo "✓ checkout_time column already exists\n";
    }
    
    echo "\n✓ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Migration error: " . $e->getMessage() . "\n";
}
?>
