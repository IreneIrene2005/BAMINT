<?php
/**
 * API endpoint to fetch amenities list
 * Used for real-time updates in front desk dashboard
 */
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once "db/database.php";

header('Content-Type: application/json');

try {
    // Fetch all amenities
    $stmt = $conn->prepare("SELECT id, name, description, price, is_active FROM extra_amenities ORDER BY name ASC");
    $stmt->execute();
    $amenities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $amenities,
        'timestamp' => time()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
