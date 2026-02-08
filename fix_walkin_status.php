<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

// Only admin can run this
if ($_SESSION['role'] !== 'admin') {
    die('Unauthorized');
}

require_once "db/database.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_walkin'])) {
    try {
        // Activate all inactive walk-in customers (those without room_id)
        $stmt = $conn->prepare("UPDATE tenants SET status = 'active' WHERE status = 'inactive' AND room_id IS NULL");
        $stmt->execute();
        $affected = $stmt->rowCount();
        
        echo "<div class='alert alert-success'>Fixed $affected walk-in customer records (status changed from inactive to active).</div>";
        echo "<a href='tenants.php?view=active' class='btn btn-primary'>Back to Customers</a>";
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    }
    exit;
}

// Activate specific customer by ID
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_customer_id'])) {
    $id = intval($_POST['activate_customer_id']);
    try {
        $stmt = $conn->prepare("UPDATE tenants SET status = 'active' WHERE id = :id");
        $stmt->execute(['id' => $id]);
        header("location: tenants.php?view=active");
        exit;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}
?>
<form method="POST" style="padding: 20px;">
    <button type="submit" name="fix_walkin" value="1" class="btn btn-warning">Fix All Walk-in Customers (set to Active)</button>
</form>
