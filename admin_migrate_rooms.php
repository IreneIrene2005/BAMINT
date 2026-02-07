<?php
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

require_once 'db_connect.php';

$result = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'migrate') {
    try {
        // Update room types to capitalized versions
        $updates = [
            "UPDATE rooms SET room_type = 'Single' WHERE LOWER(room_type) = 'single'",
            "UPDATE rooms SET room_type = 'Double' WHERE LOWER(room_type) = 'double'",
            "UPDATE rooms SET room_type = 'Family' WHERE LOWER(room_type) = 'suite'"
        ];
        
        foreach ($updates as $query) {
            $conn->query($query);
        }
        
        $result = "✅ Successfully updated all room types! Single, Double, and Suite (now Family) have been capitalized.";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Room Type Migration - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <h2><i class="bi bi-tools"></i> Room Type Migration</h2>
                <p class="text-muted">Update existing room types to capitalized format.</p>
                
                <?php if ($result): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $result; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Changes:</h5>
                        <ul>
                            <li><code>single</code> → <code>Single</code></li>
                            <li><code>double</code> → <code>Double</code></li>
                            <li><code>suite</code> → <code>Family</code></li>
                        </ul>
                        <form method="POST">
                            <input type="hidden" name="action" value="migrate">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-arrow-repeat"></i> Run Migration
                            </button>
                            <a href="admin_rooms.php" class="btn btn-secondary">Cancel</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
