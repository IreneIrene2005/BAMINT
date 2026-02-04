<?php
// Utility script to create an advance payment bill for a room request if it does not exist
require_once 'db_pdo.php';

if (!isset($argv[1])) {
    echo "Usage: php create_advance_bill.php <room_request_id>\n";
    exit(1);
}
$room_request_id = intval($argv[1]);

// Get the room request
$stmt = $pdo->prepare("SELECT * FROM room_requests WHERE id = :id");
$stmt->execute(['id' => $room_request_id]);
$rr = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rr) {
    echo "Room request not found.\n";
    exit(1);
}

// Check if an advance payment bill already exists
$stmt = $pdo->prepare("SELECT * FROM bills WHERE tenant_id = :tenant_id AND room_id = :room_id AND notes LIKE '%ADVANCE PAYMENT%' AND status IN ('pending','partial')");
$stmt->execute(['tenant_id' => $rr['tenant_id'], 'room_id' => $rr['room_id']]);
$bill = $stmt->fetch(PDO::FETCH_ASSOC);
if ($bill) {
    echo "Advance payment bill already exists.\n";
    exit(0);
}

// Calculate total cost
$rate_stmt = $pdo->prepare("SELECT rate FROM rooms WHERE id = :room_id");
$rate_stmt->execute(['room_id' => $rr['room_id']]);
$rate = $rate_stmt->fetchColumn();
$checkin = $rr['checkin_date'];
$checkout = $rr['checkout_date'];
$nights = 0;
if ($checkin && $checkout) {
    $checkin_dt = new DateTime($checkin);
    $checkout_dt = new DateTime($checkout);
    $interval = $checkin_dt->diff($checkout_dt);
    $nights = (int)$interval->days;
}
$total_cost = $rate * $nights;
$notes = "ADVANCE PAYMENT - Move-in fee ($nights night" . ($nights > 1 ? "s" : "") . ", ₱" . number_format($rate, 2) . "/night)";
$billing_month = (new DateTime($checkin))->format('Y-m');
$due_date = (new DateTime($checkin))->format('Y-m-d');

// Insert the bill
$stmt = $pdo->prepare("INSERT INTO bills (tenant_id, room_id, billing_month, amount_due, due_date, status, notes, created_at, updated_at) VALUES (:tenant_id, :room_id, :billing_month, :amount_due, :due_date, 'pending', :notes, NOW(), NOW())");
$stmt->execute([
    'tenant_id' => $rr['tenant_id'],
    'room_id' => $rr['room_id'],
    'billing_month' => $billing_month,
    'amount_due' => $total_cost,
    'due_date' => $due_date,
    'notes' => $notes
]);
echo "Advance payment bill created for room_request_id=$room_request_id.\n";
