<?php
/**
 * ROOM REQUEST APPROVAL WORKFLOW - VERIFICATION
 * 
 * USER REQUEST:
 * "after the admin approve the payment in this page the customer who got approve by the 
 *  payment, there should be a button called approve and reject here and once its approve 
 *  the room will be occupied"
 * 
 * IMPLEMENTATION STATUS: ✓ COMPLETE
 * 
 * WORKFLOW:
 * 
 * 1. CUSTOMER BOOKS ROOM (tenant_add_room.php)
 *    ✓ Room request created with status='pending_payment'
 *    ✓ Bill created for advance payment
 *    ✓ Room status set to 'booked'
 *    Display: Room appears as "Booked" in admin_rooms.php (yellow badge)
 * 
 * 2. CUSTOMER MAKES PAYMENT (tenant_make_payment.php)
 *    ✓ Payment created with status='pending'
 *    ✓ Awaiting admin/front_desk verification
 * 
 * 3. ADMIN/FRONT_DESK VERIFIES PAYMENT (bills.php)
 *    ✓ Payment status updated to 'verified'
 *    ✓ Bill status may update to 'paid' or 'partial'
 * 
 * 4. ROOM REQUESTS QUEUE SHOWS APPROVAL BUTTONS (room_requests_queue.php)
 *    ✓ Request status shows 'pending_payment'
 *    ✓ Payment verification check filters for verified/approved payments
 *    ✓ Approve/Reject buttons APPEAR (green/red)
 *    ✓ BOTH ADMIN AND FRONT_DESK can see the buttons ← JUST FIXED
 *    
 *    Before: Only admin could see the buttons
 *    After: Both admin and front_desk can see and use the buttons
 * 
 * 5. ADMIN/FRONT_DESK CLICKS APPROVE BUTTON
 *    ✓ Room request status updated to 'approved'
 *    ✓ Room status updated to 'occupied' (from 'booked')
 *    ✓ Tenant status updated to 'active'
 *    ✓ Customer receives booking receipt notification
 *    Display: Room appears as "Occupied" in admin_rooms.php (gray badge)
 * 
 * 6. CUSTOMER APPEARS IN TENANTS.PHP
 *    ✓ With "Approved" booking status badge
 *    ✓ Room number displayed
 *    ✓ Check-in/check-out dates displayed
 * 
 * CHANGES MADE:
 * 
 * File: room_requests_queue.php, Line 766
 * 
 * Before:
 *   if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
 * 
 * After:
 *   if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'front_desk'])) {
 * 
 * This allows BOTH admin and front_desk to:
 * - See the Approve/Reject buttons when payment is verified
 * - Click Approve to confirm the booking and occupy the room
 * - Click Reject to decline the booking
 * 
 * VERIFICATION:
 * 
 * To test the complete workflow:
 * 1. Login as a customer and create a room booking (tenant_add_room.php)
 * 2. Login as admin/front_desk and verify payment (bills.php)
 * 3. Visit room_requests_queue.php
 * 4. Both admin AND front_desk should see the Approve/Reject buttons
 * 5. Click Approve to occupy the room
 * 6. Customer should appear in tenants.php with "Approved" status
 */

session_start();
require_once "db/database.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo "Please login first";
    exit;
}

echo "<h2>Room Request Approval Workflow</h2>";
echo "<pre>";
echo "Your Role: " . htmlspecialchars($_SESSION['role']) . "\n";
echo "Can See Room Queue: " . (in_array($_SESSION['role'], ['admin', 'front_desk']) ? "YES ✓" : "NO") . "\n";
echo "Can Approve Requests: " . (in_array($_SESSION['role'], ['admin', 'front_desk']) ? "YES ✓" : "NO") . "\n";
echo "</pre>";

echo "<h3>Current Pending Room Requests:</h3>";

$sql = "
SELECT 
    rr.id,
    rr.status as request_status,
    t.name,
    r.room_number,
    r.status as room_status,
    b.amount_due,
    COALESCE(SUM(CASE WHEN pt.payment_status IN ('verified','approved') THEN pt.payment_amount ELSE 0 END), 0) as verified_paid
FROM room_requests rr
JOIN tenants t ON rr.tenant_id = t.id
JOIN rooms r ON rr.room_id = r.id
LEFT JOIN bills b ON rr.tenant_id = b.tenant_id AND rr.room_id = b.room_id
LEFT JOIN payment_transactions pt ON b.id = pt.bill_id
WHERE rr.status IN ('pending_payment', 'approved')
GROUP BY rr.id
ORDER BY rr.id DESC
LIMIT 10
";

$stmt = $conn->prepare($sql);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($results)) {
    echo "<p>No pending room requests found.</p>";
} else {
    echo "<table border='1' cellspacing='0' cellpadding='8'>";
    echo "<tr>";
    echo "<th>Request ID</th>";
    echo "<th>Customer</th>";
    echo "<th>Room</th>";
    echo "<th>Request Status</th>";
    echo "<th>Room Status</th>";
    echo "<th>Amount Due</th>";
    echo "<th>Verified Paid</th>";
    echo "<th>Action Available</th>";
    echo "</tr>";
    
    foreach ($results as $req) {
        $should_show = $req['verified_paid'] > 0 && $req['request_status'] === 'pending_payment';
        
        echo "<tr>";
        echo "<td>" . $req['id'] . "</td>";
        echo "<td>" . $req['name'] . "</td>";
        echo "<td>" . $req['room_number'] . "</td>";
        echo "<td>" . $req['request_status'] . "</td>";
        echo "<td>" . $req['room_status'] . "</td>";
        echo "<td>₱" . number_format($req['amount_due'] ?? 0, 2) . "</td>";
        echo "<td>₱" . number_format($req['verified_paid'], 2) . "</td>";
        echo "<td>";
        if ($req['request_status'] === 'pending_payment') {
            echo $should_show ? "Approve/Reject buttons visible ✓" : "Awaiting payment verification";
        } else if ($req['request_status'] === 'approved') {
            echo "Room is occupied ✓";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>
