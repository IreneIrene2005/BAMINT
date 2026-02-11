<?php
session_start();
require_once "db/database.php";

// DEBUG: Check for pending room requests with payments
$sql = "
SELECT 
    rr.id,
    rr.tenant_id,
    rr.room_id,
    rr.status as rr_status,
    t.name,
    r.room_number,
    b.id as bill_id,
    b.amount_due,
    COUNT(pt.id) as payment_count,
    SUM(CASE WHEN pt.payment_status IN ('verified', 'approved') THEN pt.payment_amount ELSE 0 END) as verified_paid,
    SUM(CASE WHEN pt.payment_status = 'pending' THEN pt.payment_amount ELSE 0 END) as pending_paid,
    SUM(pt.payment_amount) as total_paid
FROM room_requests rr
LEFT JOIN tenants t ON rr.tenant_id = t.id
LEFT JOIN rooms r ON rr.room_id = r.id
LEFT JOIN bills b ON rr.tenant_id = b.tenant_id AND rr.room_id = b.room_id
LEFT JOIN payment_transactions pt ON b.id = pt.bill_id
WHERE rr.status = 'pending_payment'
GROUP BY rr.id
";

$stmt = $conn->prepare($sql);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<pre>";
echo "Pending Payment Requests:\n\n";
foreach ($results as $row) {
    echo "Request ID: " . $row['id'] . "\n";
    echo "  Tenant: " . $row['name'] . " (ID: " . $row['tenant_id'] . ")\n";
    echo "  Room: " . $row['room_number'] . " (ID: " . $row['room_id'] . ")\n";
    echo "  Bill ID: " . $row['bill_id'] . "\n";
    echo "  Amount Due: " . $row['amount_due'] . "\n";
    echo "  Verified Paid: " . $row['verified_paid'] . "\n";
    echo "  Pending Paid: " . $row['pending_paid'] . "\n";
    echo "  Total Paid: " . $row['total_paid'] . "\n";
    echo "  Payment Count: " . $row['payment_count'] . "\n";
    echo "  Should Show Buttons: " . ($row['verified_paid'] > 0 ? "YES" : "NO") . "\n";
    echo "\n";
}
echo "</pre>";
?>
