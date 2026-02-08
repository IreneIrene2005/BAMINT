<?php
/**
 * Migration: Add checkin_time and checkout_time to room_requests table
 */
require_once "database.php";

try {
    echo "Adding checkin_time and checkout_time columns to room_requests table...\n";
    
    // Check if checkin_time column exists
    $col_check = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'room_requests' AND TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'checkin_time'");
    $col_check->execute();
    
    if ($col_check->rowCount() === 0) {
        $conn->exec("ALTER TABLE room_requests ADD COLUMN checkin_time TIME DEFAULT '14:00' COMMENT 'Check-in time (24-hour format)'");
        echo "✓ checkin_time column added\n";
    } else {
        echo "ℹ checkin_time column already exists\n";
    }
    
    // Check if checkout_time column exists
    $col_check = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'room_requests' AND TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'checkout_time'");
    $col_check->execute();
    
    if ($col_check->rowCount() === 0) {
        $conn->exec("ALTER TABLE room_requests ADD COLUMN checkout_time TIME DEFAULT '11:00' COMMENT 'Check-out time (24-hour format)'");
        echo "✓ checkout_time column added\n";
    } else {
        echo "ℹ checkout_time column already exists\n";
    }
    
    echo "\n✓ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
