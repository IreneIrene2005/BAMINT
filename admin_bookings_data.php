<?php
// admin_bookings_data.php
// Returns booking (room request) data for admin_bookings.php as JSON

require_once 'db/database.php';

header('Content-Type: application/json');

$sql = "
    SELECT rr.id, rr.request_date AS date, rr.status, rr.notes,
           c.name AS customer_name, r.room_number, r.room_type
    FROM room_requests rr
    JOIN customers c ON rr.customer_id = c.id
    JOIN rooms r ON rr.room_id = r.id
    ORDER BY rr.request_date DESC
";
$stmt = $conn->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'bookings' => $rows]);
