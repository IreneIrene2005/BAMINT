<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header('HTTP/1.1 401 Unauthorized', true, 401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
require_once "db/database.php";
header('Content-Type: application/json');
try {
    // Total tenants
    $total_tenants = (int)$conn->query("SELECT COUNT(*) FROM tenants")->fetchColumn();

    // Total rooms
    $total_rooms = (int)$conn->query("SELECT COUNT(*) FROM rooms")->fetchColumn();

    // Occupied rooms - use room status when available
    $occupied_rooms = (int)$conn->query("SELECT COUNT(*) FROM rooms WHERE status IN ('occupied','booked')")->fetchColumn();

    // Vacant rooms
    $vacant_rooms = max(0, $total_rooms - $occupied_rooms);

    // Occupancy rate
    $occupancy_rate = $total_rooms > 0 ? round(($occupied_rooms / $total_rooms) * 100, 1) : 0;

    // Total income this month (payment_transactions)
    $current_month = date('Y-m');
    $stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM payment_transactions WHERE DATE_FORMAT(payment_date, '%Y-%m') = :month AND payment_status IN ('verified','approved')");
    $stmt->execute(['month' => $current_month]);
    $total_income = (float)$stmt->fetchColumn();

    // Pending maintenance
    $pending_maintenance = (int)$conn->query("SELECT COUNT(*) FROM maintenance_requests WHERE status = 'pending'")->fetchColumn();

    // Revenue trend (last 6 months)
    $revenue_labels = [];
    $revenue_data = [];
    for ($i = 5; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-{$i} months"));
        $stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM payment_transactions WHERE DATE_FORMAT(payment_date, '%Y-%m') = :month AND payment_status IN ('verified','approved')");
        $stmt->execute(['month' => $month]);
        $amount = (float)$stmt->fetchColumn();
        $revenue_labels[] = date('M Y', strtotime($month));
        $revenue_data[] = $amount;
    }

    // Occupancy chart data
    $occupancy_labels = ['Occupied', 'Vacant'];
    $occupancy_values = [$occupied_rooms, $vacant_rooms];

    // Room types distribution
    $room_types_labels = [];
    $room_types_data = [];
    $rt_stmt = $conn->query("SELECT room_type, COUNT(*) as cnt FROM rooms GROUP BY room_type ORDER BY cnt DESC");
    while ($r = $rt_stmt->fetch(PDO::FETCH_ASSOC)) {
        $room_types_labels[] = $r['room_type'];
        $room_types_data[] = (int)$r['cnt'];
    }

    echo json_encode([
        'success' => true,
        'total_tenants' => $total_tenants,
        'total_rooms' => $total_rooms,
        'occupied_rooms' => $occupied_rooms,
        'vacant_rooms' => $vacant_rooms,
        'occupancy_rate' => $occupancy_rate,
        'total_income' => $total_income,
        'pending_maintenance' => $pending_maintenance,
        'revenue_labels' => $revenue_labels,
        'revenue_data' => $revenue_data,
        'occupancy_labels' => $occupancy_labels,
        'occupancy_data' => $occupancy_values,
        'room_types_labels' => $room_types_labels,
        'room_types_data' => $room_types_data,
        'timestamp' => date('c')
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
