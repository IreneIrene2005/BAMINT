<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'front_desk'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once "db_pdo.php";

// Alias $pdo as $conn for compatibility
$conn = $pdo;

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

    // Determine room number(s) from active bills for current checkout session
    $rooms_stmt = $conn->prepare("
        SELECT DISTINCT r.room_number
        FROM bills b
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.tenant_id = :tenant_id AND b.status IN ('pending', 'partial', 'unpaid', 'overdue', 'paid')
        ORDER BY b.id DESC
    ");
    $rooms_stmt->execute(['tenant_id' => $tenant_id]);
    $room_list = array_filter(array_unique(array_column($rooms_stmt->fetchAll(PDO::FETCH_ASSOC), 'room_number')));
    $room_number = $tenant['room_number'] ?? 'N/A';
    if (!empty($room_list)) {
        $room_number = implode(', ', $room_list);
    }

    // Get total amount due from active bills
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount_due), 0) as total_due
        FROM bills
        WHERE tenant_id = :tenant_id AND status IN ('pending', 'partial', 'unpaid', 'overdue')
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $due_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_due = floatval($due_result['total_due']);

    // Get total paid amount from verified/approved payment transactions
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(payment_amount), 0) as total_paid
        FROM payment_transactions
        WHERE tenant_id = :tenant_id AND payment_status IN ('verified', 'approved')
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $paid_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_paid = floatval($paid_result['total_paid']);

    // Get additional charges (maintenance/amenity requests that have a cost)
    $stmt = $conn->prepare("
        SELECT category, cost, status, submitted_date
        FROM maintenance_requests
        WHERE tenant_id = :tenant_id AND cost > 0 AND status IN ('pending', 'completed')
        ORDER BY submitted_date DESC
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $charges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sum the charges
    $total_charges = 0.0;
    foreach ($charges as $c) {
        $total_charges += floatval($c['cost']);
    }

    // Grand Total Due should be based on what has been paid + additional charges
    // This matches your requirement: amount_paid + total_additional_charges
    $grand_total_due = max(0, $total_paid) + floatval($total_charges);

    // Also expose remaining balance for optionally showing how much room/due is unpaid
    $remaining_balance = max(0, $total_due - $total_paid);

    echo json_encode([
        'success' => true,
        'tenant_id' => $tenant['id'],
        'tenant_name' => $tenant['name'],
        'email' => $tenant['email'],
        'phone' => $tenant['phone'],
        'room_number' => $room_number,
        'amount_paid' => $total_paid,
        'charges_total' => $total_charges,
        'grand_total_due' => $grand_total_due,
        'charges' => $charges
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
