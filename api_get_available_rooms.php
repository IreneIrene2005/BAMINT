<?php
/**
 * API endpoint for real-time available rooms data
 * Returns JSON with current room availability status
 * Used by tenant_add_room.php for real-time updates
 */

session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "tenant") {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once "db/database.php";

try {
    // Fetch all rooms with current availability status
    // Match admin_rooms.php: show all rooms with status = 'available'
    $stmt = $conn->prepare("
        SELECT 
            r.id,
            r.room_number,
            r.room_type,
            r.description,
            r.rate,
            r.status,
            r.image AS image_url,
            (SELECT COUNT(*) FROM tenants WHERE room_id = r.id AND status = 'active') as tenant_count,
            (SELECT COUNT(*) FROM co_tenants ct JOIN tenants t2 ON ct.primary_tenant_id = t2.id WHERE ct.room_id = r.id AND t2.status = 'active') as co_tenant_count
        FROM rooms r
        WHERE r.status = 'available'
        ORDER BY r.room_number ASC
    ");
    
    $stmt->execute();
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response with availability info
    $response = [];
    foreach ($rooms as $room) {
        // Determine availability status
        $status = strtolower(trim($room['status'] ?? ''));
        $available = false;
        $status_label = '';
        
        if ($status === 'available') {
            $available = true;
            $status_label = 'Available';
        } elseif ($status === 'booked') {
            $tenant_count = intval($room['tenant_count']);
            if ($tenant_count === 0) {
                $available = true;
                $status_label = 'Available';
            } else {
                $available = false;
                $status_label = 'Occupied';
            }
        } else {
            $available = false;
            $status_label = ucfirst($status);
        }
        
        $response[] = [
            'id' => intval($room['id']),
            'room_number' => $room['room_number'],
            'room_type' => $room['room_type'],
            'description' => $room['description'],
            'rate' => floatval($room['rate']),
            'image_url' => $room['image_url'],
            'available' => $available,
            'status' => $status,
            'status_label' => $status_label,
            'occupants' => intval($room['tenant_count'] + $room['co_tenant_count'])
        ];
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'rooms' => $response,
        'total' => count($response)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error fetching room availability: ' . $e->getMessage()
    ]);
}
