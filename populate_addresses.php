<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'front_desk'])) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

// Check if address column exists
try {
    $check_stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tenants' AND COLUMN_NAME = 'address'");
    $check_stmt->execute();
    $column_exists = $check_stmt->rowCount() > 0;
    
    if (!$column_exists) {
        echo "Address column does not exist in tenants table. Adding it...";
        $conn->exec("ALTER TABLE tenants ADD COLUMN address VARCHAR(255) NULL DEFAULT NULL");
        echo " Done!<br>";
    }
    
    // Get all customers without addresses
    $stmt = $conn->prepare("SELECT id, name FROM tenants WHERE address IS NULL OR address = '' LIMIT 50");
    $stmt->execute();
    $customers_without_address = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($customers_without_address) > 0) {
        echo "<h3>Customers without addresses: " . count($customers_without_address) . "</h3>";
        echo "<form method='POST'>";
        echo "<table class='table'>";
        echo "<tr><th>ID</th><th>Name</th><th>Address</th></tr>";
        
        foreach ($customers_without_address as $customer) {
            echo "<tr>";
            echo "<td>" . $customer['id'] . "</td>";
            echo "<td>" . htmlspecialchars($customer['name']) . "</td>";
            echo "<td><input type='text' name='address_" . $customer['id'] . "' class='form-control' placeholder='Enter address'></td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<button type='submit' class='btn btn-primary'>Save Addresses</button>";
        echo "</form>";
    } else {
        echo "All customers have addresses!";
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST)) {
        $updated = 0;
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'address_') === 0) {
                $customer_id = intval(str_replace('address_', '', $key));
                $address = trim($value);
                
                if ($address) {
                    $update_stmt = $conn->prepare("UPDATE tenants SET address = :address WHERE id = :id");
                    $update_stmt->execute(['address' => $address, 'id' => $customer_id]);
                    $updated++;
                }
            }
        }
        echo "Updated $updated customers with addresses. <a href='tenants.php'>Back to Customers</a>";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
