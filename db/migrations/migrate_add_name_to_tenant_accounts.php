<?php
/**
 * Migration: Add name column to tenant_accounts table
 * Purpose: Store tenant's display name in tenant_accounts for faster access
 * Date: 2026-02-12
 */

require_once __DIR__ . '/../database.php';

try {
    // Check if name column already exists
    $checkColumn = $conn->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tenant_accounts' AND COLUMN_NAME = 'name' AND TABLE_SCHEMA = DATABASE()"
    );
    $checkColumn->execute();

    if ($checkColumn->rowCount() === 0) {
        // Add name column if it doesn't exist
        $conn->exec("ALTER TABLE `tenant_accounts` ADD COLUMN `name` varchar(255) DEFAULT NULL");
        echo "✓ Successfully added 'name' column to tenant_accounts table\n";
    } else {
        echo "✓ Column 'name' already exists in tenant_accounts table\n";
    }

    // Optionally copy existing tenant names into tenant_accounts where tenant_id is present and name is NULL
    $conn->exec("UPDATE tenant_accounts ta JOIN tenants t ON ta.tenant_id = t.id SET ta.name = t.name WHERE ta.name IS NULL OR ta.name = ''");
    echo "✓ Synchronized existing tenant names into tenant_accounts where applicable\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

?>
