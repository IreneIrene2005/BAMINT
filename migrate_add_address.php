<?php
require_once "db/database.php";

$output = [];

try {
    // Check if address column exists
    $checkCol = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tenants' AND COLUMN_NAME = 'address' AND TABLE_SCHEMA = 'bamint'");
    $checkCol->execute();
    $exists = $checkCol->rowCount() > 0;
    
    if (!$exists) {
        // Add address column
        $conn->exec("ALTER TABLE `tenants` ADD COLUMN `address` VARCHAR(255) NULL DEFAULT NULL AFTER `phone`");
        $output[] = "✓ Address column added to tenants table";
    } else {
        $output[] = "✓ Address column already exists";
    }
    
    // Populate sample addresses for existing customers
    $sampleAddr = [
        ['id' => 1, 'addr' => '123 Main Street, San Juan, Metro Manila'],
        ['id' => 2, 'addr' => '456 Oak Avenue, Quezon City, Metro Manila'],
        ['id' => 3, 'addr' => '789 Maple Drive, Makati, Metro Manila'],
    ];
    
    $updated = 0;
    foreach ($sampleAddr as $sa) {
        $upd = $conn->prepare("UPDATE `tenants` SET `address` = :addr WHERE `id` = :id AND (address IS NULL OR address = '')");
        $upd->execute(['addr' => $sa['addr'], 'id' => $sa['id']]);
        if ($upd->rowCount() > 0) {
            $updated++;
            $output[] = "✓ Added address for customer ID " . $sa['id'];
        }
    }
    
    $output[] = "✓ Setup complete! " . $updated . " customers updated";
    $success = true;
    
} catch (Exception $e) {
    $output[] = "Error: " . $e->getMessage();
    $success = false;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Address Column Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 40px 20px; background: #f5f5f5; }
        .container { max-width: 600px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #333; margin-bottom: 20px; }
        .status-message { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin: 8px 0; }
        .status-message.error { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        a { margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔧 Address Column Setup</h2>
        <hr>
        <?php foreach ($output as $msg): ?>
            <div class="status-message <?php echo strpos($msg, 'Error') === 0 ? 'error' : ''; ?>">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endforeach; ?>
        <hr>
        <a href="tenants.php" class="btn btn-primary">Go to Customers List</a>
    </div>
</body>
</html>
