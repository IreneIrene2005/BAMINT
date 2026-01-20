<?php
/**
 * Add verification_notes column to tenants table
 * Run this script once to update database schema
 */

require_once 'db/database.php';

try {
    // Check if column exists
    $stmt = $conn->prepare("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'tenants' AND COLUMN_NAME = 'verification_notes'
    ");
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        // Column doesn't exist, add it
        $conn->exec("ALTER TABLE tenants ADD COLUMN verification_notes TEXT NULL AFTER status");
        echo "✓ Successfully added 'verification_notes' column to tenants table<br>";
    } else {
        echo "✓ 'verification_notes' column already exists<br>";
    }
    
    // Check if verification_date column exists
    $stmt = $conn->prepare("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'tenants' AND COLUMN_NAME = 'verification_date'
    ");
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        // Column doesn't exist, add it
        $conn->exec("ALTER TABLE tenants ADD COLUMN verification_date TIMESTAMP NULL AFTER verification_notes");
        echo "✓ Successfully added 'verification_date' column to tenants table<br>";
    } else {
        echo "✓ 'verification_date' column already exists<br>";
    }
    
    // Check if verified_by column exists
    $stmt = $conn->prepare("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'tenants' AND COLUMN_NAME = 'verified_by'
    ");
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        // Column doesn't exist, add it
        $conn->exec("ALTER TABLE tenants ADD COLUMN verified_by VARCHAR(255) NULL AFTER verification_date");
        echo "✓ Successfully added 'verified_by' column to tenants table<br>";
    } else {
        echo "✓ 'verified_by' column already exists<br>";
    }
    
    echo "<br><strong>Database migration completed successfully!</strong>";
    
} catch (PDOException $e) {
    echo "Error during migration: " . $e->getMessage();
    exit(1);
}
?>
