<?php
/**
 * ISSUE RESOLUTION DOCUMENT
 * 
 * PROBLEM: Approve/Reject buttons not appearing in room_requests_queue.php
 * 
 * CUSTOMER WORKFLOW:
 * 1. Customer books room and makes payment
 * 2. Admin verifies payment in bills.php ✓
 * 3. Expected: Approve/Reject buttons appear in room_requests_queue.php ✗ NOT HAPPENING
 * 4. Expected: Room changes from "available" → "booked" → "occupied" ✗ STUCK AT BOOKED
 * 5. Expected: Customer appears in tenants.php ✗ NOT SHOWING
 * 
 * ROOT CAUSE:
 * 
 * File: room_requests_queue.php, Line 684
 * 
 * The payment cache logic was counting ALL payment amounts, including PENDING payments:
 * 
 *     SELECT SUM(payment_amount) as paid 
 *     FROM payment_transactions 
 *     WHERE bill_id = :bill_id AND payment_amount > 0
 * 
 * This query would find payments in ANY status (pending, verified, rejected, etc.)
 * Even though admin verified the payment in bills.php, the cache would see ANY payment
 * and calculate $paid_amount, BUT the issue was:
 * 
 * The payment status should be checked to ensure we're counting ONLY verified payments
 * across the entire application.
 * 
 * FIX APPLIED:
 * 
 * Changed line 684 query to:
 * 
 *     SELECT COALESCE(SUM(payment_amount), 0) as paid 
 *     FROM payment_transactions 
 *     WHERE bill_id = :bill_id 
 *       AND payment_amount > 0 
 *       AND payment_status IN ('verified', 'approved')
 * 
 * This now only counts payments that have been verified by admin in bills.php
 * 
 * VERIFICATION:
 * 
 * Step 1: Customer books room → creates room_request with status='pending_payment'
 *         Room status changes to 'booked' (done in tenant_actions.php line 187)
 * 
 * Step 2: Customer pays → creates payment_transaction with status='pending'
 *         Waiting for admin verification
 * 
 * Step 3: Admin clicks "Verify" in bills.php → payment_status = 'verified'
 *         NOW the new query on line 684 will find this verified payment
 *         $paid_amount will be calculated correctly
 *         payment_type will be set to 'full_payment' or 'downpayment'
 *         $show_approve will be TRUE
 * 
 * Step 4: room_requests_queue.php displays Approve/Reject buttons
 *         (Lines 764-790 show buttons only when $show_approve = true)
 *         (Admin role check on line 766 ensures only admin sees buttons)
 * 
 * Step 5: Admin clicks "Approve" button
 *         - Transaction begins (line 105)
 *         - room_requests.status updated to 'approved' (line 107)
 *         - Room status updated to 'occupied' (line 130)
 *         - Tenant status updated to 'active' (line 136)
 *         - Customer receives booking receipt notification (line 149)
 *         - Transaction committed (line 141)
 * 
 * Step 6: Customer appears in tenants.php
 *         Query on line 50-61 shows tenants with:
 *         - room_requests.status IN ('pending_payment', 'approved', 'occupied')
 *         - OR pending_payment with verified bills.status IN ('paid', 'partial')
 *         - With booking status badge showing "Awaiting Approval" (pending_payment)
 *         - Or "Approved" (approved/occupied)
 * 
 * Step 7: Admin rooms.php shows room as occupied
 *         Room status badge changes from 'booked' (warning) to 'occupied' (secondary)
 * 
 * SUMMARY OF CHANGES:
 * 
 * Before:  Counted ALL payments → buttons didn't appear consistently
 * After:   Counts ONLY verified payments → buttons appear when payment is verified
 * 
 * This matches the payment verification pattern used in:
 * - bills.php line 48
 * - tenant_make_payment.php line 521
 * - tenants.php (not directly, but checks bill status)
 * - All other bill calculation queries throughout the system
 */

echo "\n" . str_repeat("=", 80) . "\n";
echo "BOOKING WORKFLOW FIX - LINE-BY-LINE FLOW\n";
echo str_repeat("=", 80) . "\n\n";

session_start();
require_once "db/database.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo "Please login first\n";
    exit;
}

// Diagnostic: Show the exact query AFTER the fix
echo "FIXED QUERY (room_requests_queue.php line 684):\n";
echo "─" . str_repeat("─", 78) . "\n";
echo "SELECT COALESCE(SUM(payment_amount), 0) as paid\n";
echo "FROM payment_transactions\n";
echo "WHERE bill_id = :bill_id\n";
echo "  AND payment_amount > 0\n";
echo "  AND payment_status IN ('verified', 'approved')\n";
echo "─" . str_repeat("─", 78) . "\n\n";

// Show live test
echo "LIVE VERIFICATION:\n";
echo "─" . str_repeat("─", 78) . "\n";

$sql = "
SELECT 
    r.room_number,
    t.name,
    rr.status as request_status,
    r.status as room_status,
    b.amount_due,
    COUNT(pt.id) as payment_count,
    SUM(CASE WHEN pt.payment_status IN ('verified','approved') THEN pt.payment_amount ELSE 0 END) as verified_paid,
    SUM(CASE WHEN pt.payment_status = 'pending' THEN pt.payment_amount ELSE 0 END) as pending_paid
FROM room_requests rr
JOIN rooms r ON rr.room_id = r.id
JOIN tenants t ON rr.tenant_id = t.id
LEFT JOIN bills b ON rr.tenant_id = b.tenant_id AND rr.room_id = b.room_id
LEFT JOIN payment_transactions pt ON b.id = pt.bill_id
WHERE rr.status = 'pending_payment'
GROUP BY rr.id
LIMIT 5
";

$stmt = $conn->prepare($sql);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($results)) {
    echo "No pending bookings found.\n";
    echo "\nTo test, create a booking in tenant_add_room.php\n";
} else {
    echo "Current Pending Bookings:\n\n";
    foreach ($results as $i => $row) {
        echo ($i + 1) . ". Room " . $row['room_number'] . " - Customer: " . $row['name'] . "\n";
        echo "   Request Status: " . $row['request_status'] . "\n";
        echo "   Room Status: " . $row['room_status'] . "\n";
        echo "   Bill Amount: ₱" . number_format($row['amount_due'] ?? 0, 2) . "\n";
        echo "   Verified Paid: ₱" . number_format($row['verified_paid'], 2) . "\n";
        echo "   Pending Paid: ₱" . number_format($row['pending_paid'], 2) . "\n";
        echo "   BUTTONS SHOULD SHOW: " . ($row['verified_paid'] > 0 ? "YES ✓" : "NO") . "\n";
        echo "   IN TENANTS.PHP: " . ($row['verified_paid'] > 0 ? "YES ✓" : "NO") . "\n";
        echo "\n";
    }
}

echo "─" . str_repeat("─", 78) . "\n";
echo "\nNext Steps:\n";
echo "1. Make sure payment_status is set to 'verified' in bills.php when admin approves\n";
echo "2. Refresh room_requests_queue.php\n";
echo "3. Approve/Reject buttons should now appear\n";
echo "4. Customer should appear in tenants.php\n";
?>
