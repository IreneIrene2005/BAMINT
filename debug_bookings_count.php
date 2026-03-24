<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db_pdo.php";

// Alias $pdo as $conn for compatibility
$conn = $pdo;

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug - Bookings Count</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h1>Debug - Bookings Count</h1>
    
    <div class="card mb-3">
        <div class="card-header">1. All Room Requests</div>
        <div class="card-body">
            <pre><?php
            $query = "SELECT id, tenant_id, room_id, status, checkin_date, created_at FROM room_requests ORDER BY id DESC LIMIT 10";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($results, JSON_PRETTY_PRINT);
            ?></pre>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">2. Recent Bills</div>
        <div class="card-body">
            <pre><?php
            $query = "SELECT id, tenant_id, room_id, status, checkin_date, created_at FROM bills ORDER BY id DESC LIMIT 10";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($results, JSON_PRETTY_PRINT);
            ?></pre>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">3. Recent Payment Transactions</div>
        <div class="card-body">
            <pre><?php
            $query = "SELECT pt.id, pt.bill_id, pt.payment_status, pt.payment_date, pt.payment_amount, b.tenant_id, b.room_id, b.checkin_date FROM payment_transactions pt JOIN bills b ON pt.bill_id = b.id ORDER BY pt.id DESC LIMIT 10";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($results, JSON_PRETTY_PRINT);
            ?></pre>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">4. Sidebar Query Result</div>
        <div class="card-body">
            <pre><?php
            $query = "
                SELECT COUNT(DISTINCT rr.id) as count
                FROM room_requests rr
                WHERE rr.status IN ('pending_payment', 'approved')
                AND EXISTS (
                    SELECT 1 FROM payment_transactions pt
                    JOIN bills b ON pt.bill_id = b.id
                    WHERE pt.payment_status IN ('verified', 'approved')
                      AND b.tenant_id = rr.tenant_id
                      AND b.room_id = rr.room_id
                      AND b.checkin_date = rr.checkin_date
                )
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode($result, JSON_PRETTY_PRINT);
            ?></pre>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">5. Detailed Matching (Pending Bookings with Verified Payments)</div>
        <div class="card-body">
            <pre><?php
            $query = "
                SELECT 
                    rr.id as booking_id,
                    rr.tenant_id,
                    rr.room_id,
                    rr.status as booking_status,
                    rr.checkin_date,
                    pt.payment_status,
                    b.id as bill_id,
                    b.checkin_date as bill_checkin_date
                FROM room_requests rr
                LEFT JOIN bills b ON b.tenant_id = rr.tenant_id AND b.room_id = rr.room_id
                LEFT JOIN payment_transactions pt ON pt.bill_id = b.id AND pt.payment_status IN ('verified', 'approved')
                WHERE rr.status IN ('pending_payment', 'approved')
                ORDER BY rr.id DESC LIMIT 20
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($results, JSON_PRETTY_PRINT);
            ?></pre>
        </div>
    </div>

</div>
</body>
</html>
