<?php
/**
 * Migration: Add address column to tenant_accounts table
 * Purpose: Store customer addresses in the tenant_accounts table
 * Date: February 10, 2026
 */

require_once __DIR__ . '/../database.php';

try {
    // Check if address column already exists
    $checkColumn = $conn->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'tenant_accounts' 
        AND COLUMN_NAME = 'address'
        AND TABLE_SCHEMA = 'bamint'
    ");
    
    if ($checkColumn->rowCount() === 0) {
        // Add address column if it doesn't exist
        $conn->exec("
            ALTER TABLE `tenant_accounts` 
            ADD COLUMN `address` text DEFAULT NULL
        ");
        echo "✓ Successfully added 'address' column to tenant_accounts table\n";
    } else {
        echo "✓ Column 'address' already exists in tenant_accounts table\n";
    }
    
    // Verify the column was added
    $verifyColumn = $conn->query("
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'tenant_accounts' 
        AND COLUMN_NAME = 'address'
        AND TABLE_SCHEMA = 'bamint'
    ");
    
    $result = $verifyColumn->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        echo "✓ Column Details:\n";
        echo "  - Name: " . $result['COLUMN_NAME'] . "\n";
        echo "  - Type: " . $result['COLUMN_TYPE'] . "\n";
        echo "  - Nullable: " . ($result['IS_NULLABLE'] === 'YES' ? 'Yes' : 'No') . "\n";
    }
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
