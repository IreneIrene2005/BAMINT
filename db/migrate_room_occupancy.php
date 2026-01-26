<?php
/**
 * Migration: Add occupancy and tenant info fields to room_requests
 * - Add tenant_count: number of tenants occupying the room
 * - Add tenant_name, email, phone, address: validation info
 */

require_once "database.php";

try {
    // Check if columns already exist
    $result = $conn->query("SHOW COLUMNS FROM room_requests LIKE 'tenant_count'");
    $column_exists = $result->rowCount() > 0;

    if (!$column_exists) {
        // Add tenant_count column
        $conn->exec("ALTER TABLE room_requests ADD COLUMN tenant_count INT DEFAULT 1 AFTER room_id");
        echo "✓ Added tenant_count column<br>";
    } else {
        echo "✓ tenant_count column already exists<br>";
    }

    // Check if tenant info columns exist
    $result = $conn->query("SHOW COLUMNS FROM room_requests LIKE 'tenant_info_name'");
    $column_exists = $result->rowCount() > 0;

    if (!$column_exists) {
        // Add tenant information columns
        $conn->exec("ALTER TABLE room_requests ADD COLUMN tenant_info_name VARCHAR(255) AFTER tenant_count");
        $conn->exec("ALTER TABLE room_requests ADD COLUMN tenant_info_email VARCHAR(255) AFTER tenant_info_name");
        $conn->exec("ALTER TABLE room_requests ADD COLUMN tenant_info_phone VARCHAR(20) AFTER tenant_info_email");
        $conn->exec("ALTER TABLE room_requests ADD COLUMN tenant_info_address TEXT AFTER tenant_info_phone");
        echo "✓ Added tenant info columns (name, email, phone, address)<br>";
    } else {
        echo "✓ Tenant info columns already exist<br>";
    }

    // Check if approved_date column exists
    $result = $conn->query("SHOW COLUMNS FROM room_requests LIKE 'approved_date'");
    $column_exists = $result->rowCount() > 0;

    if (!$column_exists) {
        // Add approved_date column
        $conn->exec("ALTER TABLE room_requests ADD COLUMN approved_date DATETIME DEFAULT NULL AFTER updated_at");
        echo "✓ Added approved_date column<br>";
    } else {
        echo "✓ approved_date column already exists<br>";
    }

    echo "<br><strong>Migration completed successfully!</strong>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
