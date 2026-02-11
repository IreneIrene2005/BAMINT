<?php
require_once "db/database.php";

$output = [];

try {
    // Get all customers currently without addresses
    $getAll = $conn->prepare("SELECT id, name FROM tenants WHERE (address IS NULL OR address = '') ORDER BY id");
    $getAll->execute();
    $customers = $getAll->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($customers) === 0) {
        $output[] = "✓ All customers already have addresses!";
    } else {
        $output[] = "Found " . count($customers) . " customers without addresses. Updating...";
        
        // Sample addresses - maps by name (case-insensitive)
        $sampleAddrs = [
            'david' => '123 Main Street, San Juan, Metro Manila',
            'grace' => '456 Oak Avenue, Quezon City, Metro Manila',
            'mae' => '789 Maple Drive, Makati City, Metro Manila',
        ];
        
        $updated = 0;
        foreach ($customers as $cust) {
            $custName = strtolower(trim($cust['name']));
            $addr = null;
            
            // Check if we have a sample address for this customer
            if (isset($sampleAddrs[$custName])) {
                $addr = $sampleAddrs[$custName];
            } else {
                // Generate a generic address based on customer name
                $addr = htmlspecialchars($cust['name']) . " Address, Metro Manila";
            }
            
            // Update the customer with the address
            $upd = $conn->prepare("UPDATE tenants SET address = :addr WHERE id = :id");
            $upd->execute(['addr' => $addr, 'id' => $cust['id']]);
            
            if ($upd->rowCount() > 0) {
                $updated++;
                $output[] = "✓ Customer ID " . $cust['id'] . " (" . htmlspecialchars($cust['name']) . "): " . substr($addr, 0, 40) . "...";
            }
        }
        
        if ($updated > 0) {
            $output[] = "<strong>✓ Successfully updated " . $updated . " customers with addresses!</strong>";
        }
    }
    
    $success = true;
    
} catch (Exception $e) {
    $output[] = "❌ Error: " . $e->getMessage();
    $success = false;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Populate Customer Addresses</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 40px 20px; background: #f5f5f5; }
        .container { max-width: 700px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #333; margin-bottom: 20px; }
        .status-message { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin: 8px 0; font-size: 14px; }
        .status-message.error { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .status-message strong { color: #0c5460; }
        a { margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔧 Populate Customer Addresses</h2>
        <hr>
        <?php foreach ($output as $msg): ?>
            <div class="status-message <?php echo strpos($msg, '❌') === 0 ? 'error' : ''; ?>">
                <?php echo $msg; ?>
            </div>
        <?php endforeach; ?>
        <hr>
        <a href="tenants.php" class="btn btn-primary">✓ Go to Customers List</a>
    </div>
</body>
</html>
