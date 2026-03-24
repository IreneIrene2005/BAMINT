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
    <title>Debug - Bookings Verification</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h1>Debug - Bookings Verification Query</h1>
    
    <div class="card mb-3">
        <div class="card-header bg-danger text-white">Current Bookings Page Query Result</div>
        <div class="card-body">
            <h5>Count of Bookings to Display:</h5>
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
                )
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "Count: " . json_encode($result, JSON_PRETTY_PRINT);
            ?></pre>

            <h5>Full Booking Details:</h5>
            <pre><?php
            $query = "
                SELECT
                    rr.id as booking_id,
                    rr.tenant_id,
                    rr.room_id,
                    rr.status as room_request_status,
                    rr.checkin_date,
                    rr.checkout_date,
                    rr.tenant_info_name,
                    rr.created_at as booking_created,
                    t.name as tenant_name,
                    r.room_number,
                    b.id as bill_id,
                    b.status as bill_status,
                    pt.payment_status,
                    pt.payment_amount,
                    pt.payment_date
                FROM room_requests rr
                LEFT JOIN tenants t ON rr.tenant_id = t.id
                LEFT JOIN rooms r ON rr.room_id = r.id
                LEFT JOIN bills b ON b.tenant_id = rr.tenant_id AND b.room_id = rr.room_id
                LEFT JOIN payment_transactions pt ON b.id = pt.bill_id AND pt.payment_status IN ('verified', 'approved')
                WHERE rr.status IN ('pending_payment', 'approved')
                ORDER BY rr.created_at DESC
                LIMIT 20
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($bookings, JSON_PRETTY_PRINT);
            ?></pre>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">All Recent Room Requests (Last 20)</div>
        <div class="card-body">
            <pre><?php
            $query = "SELECT id, tenant_id, room_id, status, checkin_date, tenant_info_name, created_at FROM room_requests ORDER BY id DESC LIMIT 20";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($results, JSON_PRETTY_PRINT);
            ?></pre>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">All Recent Bills (Last 20)</div>
        <div class="card-body">
            <pre><?php
            $query = "SELECT id, tenant_id, room_id, status, checkin_date, created_at FROM bills ORDER BY id DESC LIMIT 20";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($results, JSON_PRETTY_PRINT);
            ?></pre>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">All Recent Payment Transactions (Last 20)</div>
        <div class="card-body">
            <pre><?php
            $query = "SELECT id, bill_id, payment_status, payment_amount, payment_date, created_at FROM payment_transactions ORDER BY id DESC LIMIT 20";
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
