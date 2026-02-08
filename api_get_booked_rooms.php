<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once "db/database.php";

try {
    // Fetch booked rooms with their associated tenant information
    // Only include bookings that are in booked/approved state and exclude rooms already occupied
    $sql = "
        SELECT 
            rr.id as booking_id,
            r.id as room_id,
            r.room_number,
            r.rate as room_rate,
            t.id as tenant_id,
            t.name as tenant_name,
            t.phone,
            t.email,
            rr.checkin_date,
            rr.checkout_date,
            rr.status
        FROM room_requests rr
        JOIN rooms r ON rr.room_id = r.id
        JOIN tenants t ON rr.tenant_id = t.id
        WHERE rr.status IN ('approved', 'pending_payment', 'booked')
          AND (r.status IS NULL OR r.status IN ('available', 'booked'))
        ORDER BY rr.checkin_date DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $formatted_bookings = [];
    foreach ($bookings as $booking) {
        // Extract date-only parts from datetime fields
        $checkin_date_only = $booking['checkin_date'] ? substr($booking['checkin_date'], 0, 10) : null;
        $checkout_date_only = $booking['checkout_date'] ? substr($booking['checkout_date'], 0, 10) : null;
        
        // Provide both raw ISO dates (for JS calculations) and human-friendly dates (for display)
        $formatted_bookings[] = [
            'booking_id' => $booking['booking_id'],
            'room_id' => $booking['room_id'],
            'room_number' => $booking['room_number'],
            'room_rate' => floatval($booking['room_rate']),
            'tenant_id' => $booking['tenant_id'],
            'tenant_name' => $booking['tenant_name'],
            'tenant_phone' => $booking['phone'],
            'tenant_email' => $booking['email'],
            'raw_checkin' => $checkin_date_only,
            'raw_checkout' => $checkout_date_only,
            'checkin_date' => $checkin_date_only ? date('M d, Y', strtotime($checkin_date_only)) : null,
            'checkout_date' => $checkout_date_only ? date('M d, Y', strtotime($checkout_date_only)) : null,
            'status' => $booking['status']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'bookings' => $formatted_bookings,
        'total' => count($formatted_bookings)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching bookings: ' . $e->getMessage()
    ]);
}
?>
