<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'front_desk'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

require_once "db_pdo.php";
require_once "db/notifications.php";

// Alias $pdo as $conn for compatibility
$conn = $pdo;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$booking_id = $input['booking_id'] ?? 0;
$bill_id = $input['bill_id'] ?? 0;

if ($action !== 'approve_booking' || !$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid action or booking ID']);
    exit;
}

try {
    // Start transaction
    $conn->beginTransaction();

    // Get booking details
    $booking_stmt = $conn->prepare("
        SELECT rr.*, t.name as tenant_name, t.email as tenant_email
        FROM room_requests rr
        JOIN tenants t ON rr.tenant_id = t.id
        WHERE rr.id = :booking_id
    ");
    $booking_stmt->execute(['booking_id' => $booking_id]);
    $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    // Update room request status to approved
    $update_rr = $conn->prepare("UPDATE room_requests SET status = 'approved' WHERE id = :booking_id");
    $update_rr->execute(['booking_id' => $booking_id]);

    // If bill_id is provided, update payment status to verified
    if ($bill_id) {
        $update_payment = $conn->prepare("
            UPDATE payment_transactions
            SET payment_status = 'verified', verification_date = NOW()
            WHERE bill_id = :bill_id AND payment_status = 'pending'
        ");
        $update_payment->execute(['bill_id' => $bill_id]);

        // Update bill status if all payments are verified
        $check_payments = $conn->prepare("
            SELECT COUNT(*) as total, COUNT(CASE WHEN payment_status IN ('verified', 'approved') THEN 1 END) as verified
            FROM payment_transactions
            WHERE bill_id = :bill_id
        ");
        $check_payments->execute(['bill_id' => $bill_id]);
        $payment_status = $check_payments->fetch(PDO::FETCH_ASSOC);

        if ($payment_status['total'] > 0 && $payment_status['verified'] == $payment_status['total']) {
            $update_bill = $conn->prepare("UPDATE bills SET status = 'paid' WHERE id = :bill_id");
            $update_bill->execute(['bill_id' => $bill_id]);
        }
    }

    // Send notification to tenant
    notifyTenantRoomRequestStatus($conn, $booking['tenant_id'], $booking_id, 'approved');

    // Commit transaction
    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Booking approved successfully']);

} catch (Exception $e) {
    // Rollback on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error approving booking: ' . $e->getMessage()]);
}
?>