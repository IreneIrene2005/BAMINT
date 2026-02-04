<?php
// Utility script to fix advance payment bill for a room request
require_once 'db_pdo.php';

if (!isset($argv[1])) {
    echo "Usage: php fix_advance_bill.php <room_request_id>\n";
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

// Find the advance payment bill for this request
$stmt = $pdo->prepare("SELECT * FROM bills WHERE tenant_id = :tenant_id AND room_id = :room_id AND notes LIKE '%ADVANCE PAYMENT%' AND status IN ('pending','partial')");
$stmt->execute(['tenant_id' => $rr['tenant_id'], 'room_id' => $rr['room_id']]);
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($bills) === 0) {
    echo "No advance payment bill found.\n";
    // Optionally, create one here
    exit(1);
}
if (count($bills) > 1) {
    echo "Multiple advance payment bills found. Keeping only the first, setting others to 'archived'.\n";
    for ($i = 1; $i < count($bills); $i++) {
        $pdo->prepare("UPDATE bills SET status = 'archived' WHERE id = :id")->execute(['id' => $bills[$i]['id']]);
    }
}
// Ensure the first bill has correct notes and status
$bill = $bills[0];
$pdo->prepare("UPDATE bills SET notes = CONCAT('ADVANCE PAYMENT - Move-in fee', ''), status = 'pending' WHERE id = :id")->execute(['id' => $bill['id']]);
echo "Advance payment bill fixed for room_request_id=$room_request_id.\n";
