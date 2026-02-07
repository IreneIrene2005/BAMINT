<?php
/**
 * Migration: Convert room_requests checkin_date and checkout_date to DATETIME
 */
require_once "db/database.php";

try {
    echo "Converting room_requests dates to DATETIME...\n";
    
    // Convert checkin_date to DATETIME
    $conn->exec("ALTER TABLE room_requests MODIFY checkin_date DATETIME DEFAULT NULL");
    echo "✓ checkin_date converted to DATETIME\n";
    
    // Convert checkout_date to DATETIME
    $conn->exec("ALTER TABLE room_requests MODIFY checkout_date DATETIME DEFAULT NULL");
    echo "✓ checkout_date converted to DATETIME\n";
    
    echo "\n✓ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
