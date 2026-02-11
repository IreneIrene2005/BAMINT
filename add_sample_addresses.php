<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'front_desk'])) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

try {
    // Sample addresses for demo purposes
    $sample_data = [
        ['name' => 'david', 'address' => '123 Main Street, San Juan, Metro Manila'],
        ['name' => 'grace', 'address' => '456 Oak Avenue, Quezon City, Metro Manila'],
        ['name' => 'mae', 'address' => '789 Maple Drive, Makati City, Metro Manila']
    ];
    
    foreach ($sample_data as $data) {
        // Check if customer exists and update their address
        $stmt = $conn->prepare("UPDATE tenants SET address = :address WHERE name = :name AND (address IS NULL OR address = '')");
        $stmt->execute(['address' => $data['address'], 'name' => $data['name']]);
    }
    
    echo "Sample addresses added successfully!<br>";
    echo "<a href='tenants.php'>Back to Customers</a>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
