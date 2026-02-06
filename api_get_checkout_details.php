<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once "db/database.php";

$tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : 0;

if ($tenant_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid tenant ID']);
    exit;
}

try {
    // Get tenant and room details
    $stmt = $conn->prepare("
        SELECT t.id, t.name, t.email, t.phone, r.room_number
        FROM tenants t
        LEFT JOIN rooms r ON t.room_id = r.id
        WHERE t.id = :tenant_id
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tenant) {
        echo json_encode(['success' => false, 'message' => 'Tenant not found']);
        exit;
    }

    // Get total amount due for this tenant (sum of all unpaid/partial bills)
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount_due), 0) as total_due
        FROM bills
        WHERE tenant_id = :tenant_id AND status IN ('pending', 'partial', 'unpaid', 'overdue')
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $due_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_due = floatval($due_result['total_due']);

    // Get total amount paid for this tenant
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(payment_amount), 0) as total_paid
        FROM payment_transactions
        WHERE tenant_id = :tenant_id AND payment_status IN ('verified', 'approved')
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $paid_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_paid = floatval($paid_result['total_paid']);

    // Calculate remaining balance
    $remaining = max(0, $total_due - $total_paid);

    // Get additional charges (maintenance/amenity requests that have a cost)
    $stmt = $conn->prepare("
        SELECT category, cost, status, submitted_date
        FROM maintenance_requests
        WHERE tenant_id = :tenant_id AND cost > 0 AND status IN ('pending', 'completed')
        ORDER BY submitted_date DESC
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $charges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'tenant_id' => $tenant['id'],
        'tenant_name' => $tenant['name'],
        'email' => $tenant['email'],
        'phone' => $tenant['phone'],
        'room_number' => $tenant['room_number'],
        'amount_due' => $total_due,
        'amount_paid' => $total_paid,
        'remaining' => $remaining,
        'charges' => $charges
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
