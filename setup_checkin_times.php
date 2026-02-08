<?php
require_once "db/database.php";

try {
    echo "<h2>Database Migration: Adding Check-in/Check-out Times</h2>";
    
    // Check and add checkin_time
    $check = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'room_requests' AND TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'checkin_time'");
    $check->execute();
    if ($check->rowCount() === 0) {
        $conn->exec("ALTER TABLE room_requests ADD COLUMN checkin_time TIME DEFAULT '14:00'");
        echo "<p style='color: green;'>✓ Added checkin_time column</p>";
    } else {
        echo "<p style='color: blue;'>ℹ checkin_time column already exists</p>";
    }
    
    // Check and add checkout_time
    $check = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'room_requests' AND TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'checkout_time'");
    $check->execute();
    if ($check->rowCount() === 0) {
        $conn->exec("ALTER TABLE room_requests ADD COLUMN checkout_time TIME DEFAULT '11:00'");
        echo "<p style='color: green;'>✓ Added checkout_time column</p>";
    } else {
        echo "<p style='color: blue;'>ℹ checkout_time column already exists</p>";
    }
    
    echo "<p style='color: green; font-weight: bold;'>✓ Database updated successfully!</p>";
    echo "<p><a href='tenant_dashboard.php'>Go back to dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
