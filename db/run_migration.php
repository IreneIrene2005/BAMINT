<?php
session_start();
// Only allow admin users to run migrations from the web UI
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// Only allow POST to actually run the migration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Run migration and capture output
    ob_start();
    // Keep error display minimal, migrate file echoes its status
    require_once __DIR__ . '/migrate_add_bill_items.php';
    $output = ob_get_clean();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8" />
        <title>Migration Output</title>
        <link href="/BAMINT/assets/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="p-4">
        <div class="container">
            <h3>Migration Result</h3>
            <pre style="white-space: pre-wrap; background:#f8f9fa; padding:1rem; border:1px solid #ddd;"><?php echo htmlspecialchars($output); ?></pre>
            <a class="btn btn-primary" href="/BAMINT/admin_additional_charges.php">Back to Additional Charges</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Show confirmation page (GET)
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Run Migration</title>
    <link href="/BAMINT/assets/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <div class="container">
        <h3>Run DB Migration</h3>
        <p class="text-warning">This will execute <code>db/migrate_add_bill_items.php</code> and modify your database schema. Ensure you have a database backup before continuing.</p>
        <form method="POST">
            <button type="submit" class="btn btn-danger">Run Migration Now</button>
            <a class="btn btn-secondary" href="/BAMINT/admin_additional_charges.php">Cancel</a>
        </form>
    </div>
</body>
</html>