<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ["tenant", "customer"])) {
    die("Not logged in");
}

require_once "db/database.php";

$customer_id = isset($_SESSION["customer_id"]) ? $_SESSION["customer_id"] : (isset($_SESSION["tenant_id"]) ? $_SESSION["tenant_id"] : null);

echo "<h3>Debug Information for Customer ID: $customer_id</h3>";

// Check all bills for this customer
echo "<h4>All Bills (regardless of filters):</h4>";
$stmt = $conn->prepare("SELECT id, tenant_id, room_id, amount_due, notes, billing_month, created_at FROM bills WHERE tenant_id = :customer_id ORDER BY id DESC");
$stmt->execute(['customer_id' => $customer_id]);
$all_bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total count: " . count($all_bills) . "<br>";
foreach ($all_bills as $b) {
    echo "Bill ID={$b['id']}, Room={$b['room_id']}, Amount={$b['amount_due']}, Notes=" . substr($b['notes'], 0, 50) . ", Billing=" . $b['billing_month'] . "<br>";
}

// Check payment transactions
echo "<h4>Payment Transactions for this Customer:</h4>";
$stmt = $conn->prepare("SELECT pt.id, pt.bill_id, pt.payment_amount, pt.payment_status FROM payment_transactions pt WHERE pt.tenant_id = :customer_id ORDER BY pt.id DESC");
$stmt->execute(['customer_id' => $customer_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total count: " . count($payments) . "<br>";
foreach ($payments as $p) {
    echo "Payment ID={$p['id']}, Bill={$p['bill_id']}, Amount={$p['payment_amount']}, Status={$p['payment_status']}<br>";
}

// Check the query from tenant_dashboard.php
echo "<h4>Bills from Dashboard Query (with filters):</h4>";
$stmt = $conn->prepare("        
    SELECT b.* FROM bills b
    WHERE b.tenant_id = :customer_id
    AND (
        EXISTS (SELECT 1 FROM payment_transactions pt WHERE pt.bill_id = b.id AND pt.payment_status IN ('verified','approved'))
        OR (b.notes NOT LIKE '%ADVANCE PAYMENT%')
    )
    ORDER BY COALESCE(b.billing_month, b.created_at) DESC, b.id DESC
    LIMIT 10
");
$stmt->execute(['customer_id' => $customer_id]);
$filtered_bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total count: " . count($filtered_bills) . "<br>";
foreach ($filtered_bills as $b) {
    echo "Bill ID={$b['id']}, Room={$b['room_id']}, Amount={$b['amount_due']}, Notes=" . substr($b['notes'], 0, 50) . "<br>";
}

// Check room_requests
echo "<h4>Room Requests for this Customer:</h4>";
$stmt = $conn->prepare("SELECT id, room_id, checkin_date, checkout_date, status FROM room_requests WHERE tenant_id = :customer_id ORDER BY id DESC");
$stmt->execute(['customer_id' => $customer_id]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total count: " . count($requests) . "<br>";
foreach ($requests as $r) {
    echo "Request ID={$r['id']}, Room={$r['room_id']}, Checkin={$r['checkin_date']}, Checkout={$r['checkout_date']}, Status={$r['status']}<br>";
}

?>
