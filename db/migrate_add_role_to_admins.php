<?php
// Migration script to add role column to admins table
require_once dirname(__DIR__) . '/db/database.php';

try {
    // Check if role column exists
    $check_stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'admins' AND COLUMN_NAME = 'role'");
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() === 0) {
        // Add role column
        $conn->exec("ALTER TABLE `admins` ADD COLUMN `role` VARCHAR(50) DEFAULT 'admin' AFTER `password`");
        echo "✅ Added 'role' column to admins table<br>";
    } else {
        echo "ℹ️ 'role' column already exists<br>";
    }
    
    // Check if created_at column exists
    $check_stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'admins' AND COLUMN_NAME = 'created_at'");
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() === 0) {
        // Add created_at column
        $conn->exec("ALTER TABLE `admins` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "✅ Added 'created_at' column to admins table<br>";
    } else {
        echo "ℹ️ 'created_at' column already exists<br>";
    }
    
    echo "<br>✅ Migration completed successfully!<br>";
    echo "<br><a href='admin_user_management.php' class='btn btn-primary'>Go to User Management</a>";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 2rem; background: #f8f9fa; }
        .container { max-width: 600px; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<div class="container">
    <h2 class="mb-4">Database Migration</h2>
    <div class="alert alert-info">
        <strong>Setting up User Management...</strong>
    </div>
    <?php ob_flush(); ?>
</div>
</body>
</html>
