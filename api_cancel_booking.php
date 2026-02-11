<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ["tenant", "customer"])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

header('Content-Type: application/json');

$customer_id = isset($_SESSION["customer_id"]) ? $_SESSION["customer_id"] : (isset($_SESSION["tenant_id"]) ? $_SESSION["tenant_id"] : null);
if (!$customer_id) {
    echo json_encode(['success' => false, 'message' => 'Customer ID not found']);
    exit;
}

// Get optional cancellation reason from POST
$cancellation_reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
if (empty($cancellation_reason)) {
    $cancellation_reason = 'Customer initiated cancellation';
}

try {
    // Get the approved payment/booking for this customer
    $stmt = $conn->prepare("
        SELECT DISTINCT 
            b.id as bill_id, 
            b.amount_due, 
            pt.payment_amount,
            pt.payment_status,
            pt.verification_date,
            r.room_number,
            r.id as room_id,
            t.name,
            t.email,
            rr.checkin_date,
            rr.checkout_date
        FROM bills b
        INNER JOIN payment_transactions pt ON b.id = pt.bill_id AND pt.payment_status IN ('verified', 'approved')
        INNER JOIN rooms r ON b.room_id = r.id
        INNER JOIN tenants t ON b.tenant_id = t.id AND t.id = :customer_id
        LEFT JOIN room_requests rr ON r.id = rr.room_id AND rr.tenant_id = :customer_id
        ORDER BY pt.verification_date DESC
        LIMIT 1
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'No active booking found']);
        exit;
    }

    // Record the cancellation - ONLY insert, admin must approve before processing
    $conn->beginTransaction();

    // Insert cancellation record as PENDING (refund_approved = 0)
    $cancel_stmt = $conn->prepare("
        INSERT INTO booking_cancellations 
        (bill_id, tenant_id, room_id, payment_amount, checkin_date, checkout_date, cancelled_at, reason, refund_approved)
        VALUES 
        (:bill_id, :tenant_id, :room_id, :payment_amount, :checkin_date, :checkout_date, NOW(), :reason, 0)
    ");
    $cancel_stmt->execute([
        'bill_id' => $booking['bill_id'],
        'tenant_id' => $customer_id,
        'room_id' => $booking['room_id'],
        'payment_amount' => $booking['payment_amount'],
        'checkin_date' => $booking['checkin_date'],
        'checkout_date' => $booking['checkout_date'],
        'reason' => $cancellation_reason
    ]);

    $conn->commit();

    // Notify all admins about the PENDING cancellation request
    try {
        $admins = $conn->query("SELECT id FROM admins")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($admins as $admin) {
            // Build notification message with reason if provided
            $notification_message = 'Customer ' . htmlspecialchars($booking['name']) . ' has requested to cancel their booking for Room ' . htmlspecialchars($booking['room_number']) . '. Payment: ₱' . number_format($booking['payment_amount'], 2) . '. Check-in was scheduled for ' . ($booking['checkin_date'] ? date('M d, Y', strtotime($booking['checkin_date'])) : 'N/A') . '.';
            
            if ($cancellation_reason && $cancellation_reason !== 'Customer initiated cancellation') {
                $notification_message .= ' Reason: ' . htmlspecialchars($cancellation_reason);
            }
            
            createNotification(
                $conn,
                'admin',
                $admin['id'],
                'cancellation_request',
                'Cancellation Request Pending Approval',
                $notification_message,
                $booking['bill_id'],
                'booking',
                'admin_bookings.php?tab=pending_cancellations'
            );
        }
    } catch (Exception $e) {
        error_log('Admin notification on cancellation request failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Your cancellation request has been submitted and is waiting for admin/front desk approval.'
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Booking cancellation error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error processing cancellation: ' . $e->getMessage()
    ]);
}
