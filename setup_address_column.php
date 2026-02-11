<?php
require_once "db/database.php";

try {
    // Check if address column exists
    $check = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tenants' AND COLUMN_NAME = 'address'");
    $check->execute();
    
    if ($check->rowCount() === 0) {
        // Column doesn't exist, add it
        echo "Adding address column to tenants table...<br>";
        $conn->exec("ALTER TABLE tenants ADD COLUMN address VARCHAR(255) NULL DEFAULT NULL AFTER phone");
        echo "✓ Address column added successfully!<br>";
    } else {
        echo "✓ Address column already exists<br>";
    }
    
    // Populate sample addresses for existing customers without addresses
    echo "<br>Populating sample addresses...<br>";
    
    $sample_addresses = [
        ['name' => 'david', 'address' => '123 Main Street, San Juan, Metro Manila'],
        ['name' => 'grace', 'address' => '456 Oak Avenue, Quezon City, Metro Manila'],
        ['name' => 'mae', 'address' => '789 Maple Drive, Makati, Metro Manila'],
    ];
    
    $updated = 0;
    foreach ($sample_addresses as $data) {
        $stmt = $conn->prepare("UPDATE tenants SET address = :address WHERE LOWER(name) = LOWER(:name) AND (address IS NULL OR address = '')");
        $result = $stmt->execute(['address' => $data['address'], 'name' => $data['name']]);
        if ($stmt->rowCount() > 0) {
            echo "✓ Updated " . htmlspecialchars($data['name']) . " with address: " . htmlspecialchars($data['address']) . "<br>";
            $updated++;
        }
    }
    
    if ($updated > 0) {
        echo "<br><strong>$updated customer(s) updated with addresses!</strong><br>";
    }
    
    echo "<br><a href='tenants.php' style='display:inline-block; padding:8px 16px; background:#007bff; color:white; text-decoration:none; border-radius:4px;'>View Customers List</a>";
    
} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Setup - Address Column</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 20px; font-family: Arial; }
        h2 { margin-bottom: 20px; color: #333; }
    </style>
</head>
<body>
<div class="container" style="max-width: 600px;">
    <h2>Address Column Setup</h2>
    <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 20px;">
