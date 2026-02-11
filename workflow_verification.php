<?php
session_start();
require_once "db/database.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo "Not logged in";
    exit;
}

echo "<h2>Room Booking Payment Workflow Verification</h2>";

// Check for room requests with verified payments
$sql = "
SELECT 
    rr.id,
    rr.status as request_status,
    t.name,
    r.room_number,
    r.status as room_status,
    b.amount_due,
    COALESCE(SUM(CASE WHEN pt.payment_status IN ('verified','approved') THEN pt.payment_amount ELSE 0 END), 0) as verified_amount,
    COUNT(CASE WHEN pt.payment_status IN ('verified','approved') THEN 1 END) as verified_payment_count
FROM room_requests rr
JOIN tenants t ON rr.tenant_id = t.id
JOIN rooms r ON rr.room_id = r.id
LEFT JOIN bills b ON rr.tenant_id = b.tenant_id AND rr.room_id = b.room_id
LEFT JOIN payment_transactions pt ON b.id = pt.bill_id
WHERE rr.status IN ('pending_payment', 'approved')
GROUP BY rr.id
ORDER BY rr.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Request ID</th><th>Status</th><th>Customer</th><th>Room</th><th>Room Status</th><th>Amount Due</th><th>Verified Paid</th><th>Should Show Buttons</th><th>Customer in List</th></tr>";

foreach ($requests as $req) {
    $should_show = $req['verified_amount'] > 0 && $req['request_status'] === 'pending_payment' ? 'YES' : ($req['request_status'] === 'approved' ? 'APPROVED' : 'NO');
    $in_tenants = ($req['request_status'] === 'pending_payment' && $req['verified_amount'] > 0) || $req['request_status'] === 'approved' ? 'YES' : 'NO';
    
    echo "<tr>";
    echo "<td>" . $req['id'] . "</td>";
    echo "<td>" . $req['request_status'] . "</td>";
    echo "<td>" . $req['name'] . "</td>";
    echo "<td>" . $req['room_number'] . "</td>";
    echo "<td>" . $req['room_status'] . "</td>";
    echo "<td>₱" . number_format($req['amount_due'] ?? 0, 2) . "</td>";
    echo "<td>₱" . number_format($req['verified_amount'], 2) . "</td>";
    echo "<td><strong>" . $should_show . "</strong></td>";
    echo "<td><strong>" . $in_tenants . "</strong></td>";
    echo "</tr>";
}

echo "</table>";

echo "<hr>";
echo "<h3>Expected Workflow:</h3>";
echo "<ol>";
echo "<li>Customer books room → Room status = 'booked' ✓</li>";
echo "<li>Customer submits payment → Payment status = 'pending'</li>";
echo "<li>Admin verifies payment in <code>bills.php</code> → Payment status = 'verified' ✓</li>";
echo "<li><code>room_requests_queue.php</code> shows Approve/Reject buttons ← JUST FIXED</li>";
echo "<li>Admin clicks Approve → Room = 'occupied', Request = 'approved' ✓</li>";
echo "<li>Customer appears in <code>tenants.php</code> with 'Awaiting Approval' status ✓</li>";
echo "<li>After approval, customer shows as 'Approved' ✓</li>";
echo "</ol>";
?>
