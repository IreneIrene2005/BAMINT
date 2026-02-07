<?php
/**
 * Add Check-in/Check-out columns to tenants table
 */
require_once "db/database.php";

try {
    // Check if checkin_time column exists
    $result = $conn->query("SHOW COLUMNS FROM tenants LIKE 'checkin_time'")->fetch();
    
    if (!$result) {
        echo "Adding checkin_time column...\n";
        $conn->exec("ALTER TABLE tenants ADD COLUMN checkin_time DATETIME NULL DEFAULT NULL AFTER status");
        echo "✓ checkin_time added\n";
    } else {
        echo "✓ checkin_time already exists\n";
    }
    
    // Check if checkout_time column exists
    $result = $conn->query("SHOW COLUMNS FROM tenants LIKE 'checkout_time'")->fetch();
    
    if (!$result) {
        echo "Adding checkout_time column...\n";
        $conn->exec("ALTER TABLE tenants ADD COLUMN checkout_time DATETIME NULL DEFAULT NULL AFTER checkin_time");
        echo "✓ checkout_time added\n";
    } else {
        echo "✓ checkout_time already exists\n";
    }
    
    echo "\n✓ Migration complete!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
