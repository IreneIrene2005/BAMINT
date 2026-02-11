<?php
/**
 * API endpoint to fetch rooms list
 * Used for real-time updates in front desk dashboard
 */
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once "db_connect.php";

header('Content-Type: application/json');

try {
    // Fetch all rooms
    $sql = "SELECT id, room_number, room_type, status, rate, description, image FROM rooms ORDER BY id DESC";
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("Database query failed: " . $conn->error);
    }
    
    $rooms = [];
    while ($row = $result->fetch_assoc()) {
        $rooms[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $rooms,
        'timestamp' => time()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
