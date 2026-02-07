<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$tenant_id = isset($_POST['tenant_id']) ? intval($_POST['tenant_id']) : 0;
$cancellation_id = isset($_POST['cancellation_id']) ? intval($_POST['cancellation_id']) : 0;

if (!$tenant_id || !$cancellation_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    // Get cancellation details
    $cancel_stmt = $conn->prepare("
        SELECT bc.*, t.name, t.email, r.room_number 
        FROM booking_cancellations bc
        INNER JOIN tenants t ON bc.tenant_id = t.id
        INNER JOIN rooms r ON bc.room_id = r.id
        WHERE bc.id = :cancellation_id
    ");
    $cancel_stmt->execute(['cancellation_id' => $cancellation_id]);
    $cancellation = $cancel_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cancellation) {
        echo json_encode(['success' => false, 'message' => 'Cancellation not found']);
        exit;
    }

    // Start transaction
    $conn->beginTransaction();

    // Archive the tenant account (set status to inactive)
    $archive_stmt = $conn->prepare("UPDATE tenants SET status = 'inactive', updated_at = NOW() WHERE id = :tenant_id");
    $archive_stmt->execute(['tenant_id' => $tenant_id]);

    // Commit transaction
    $conn->commit();

    // Notify the tenant that their cancellation has been approved and account archived
    try {
        createNotification(
            $conn,
            'tenant',
            $tenant_id,
            'cancellation_approved',
            'Cancellation Approved',
            'Your booking cancellation for Room ' . htmlspecialchars($cancellation['room_number']) . ' has been approved by management. Your account has been archived.',
            $cancellation['bill_id'],
            'booking',
            'tenant_dashboard.php'
        );
    } catch (Exception $e) {
        error_log('Tenant notification on cancellation approval failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Cancellation approved and account archived successfully.'
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Archive tenant error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error archiving account: ' . $e->getMessage()
    ]);
}
?>
