<?php
session_start();

// Check access - only admin and front desk
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once "db/database.php";

$tenant_id = intval($_GET['id'] ?? 0);

if ($tenant_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid tenant ID']);
    exit;
}

try {
    // Get customer information (include tenant-level checkin/checkout when available)
    $stmt = $conn->prepare("SELECT id, name, email, phone, address, status, checkin_time, checkout_time, room_id FROM tenants WHERE id = :id");
    $stmt->execute(['id' => $tenant_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit;
    }
    
    // Get the most recent address from room_requests, fallback to tenants.address
    $addr_stmt = $conn->prepare("SELECT tenant_info_address FROM room_requests WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT 1");
    $addr_stmt->execute(['tenant_id' => $tenant_id]);
    $addr_row = $addr_stmt->fetch(PDO::FETCH_ASSOC);
    if ($addr_row && $addr_row['tenant_info_address']) {
        $customer['address'] = $addr_row['tenant_info_address'];
    }

    $response = [
        'success' => true,
        'customer' => $customer,
        'room_info' => null,
        'stay_info' => null,
        'co_tenants' => []
    ];

    // Get room information and stay dates/times
    $room_stmt = $conn->prepare("
        SELECT rr.checkin_date, rr.checkout_date, 
               r.id as room_id, r.room_number, r.room_type
        FROM room_requests rr
        JOIN rooms r ON rr.room_id = r.id
        WHERE rr.tenant_id = :tenant_id
        ORDER BY rr.id DESC
        LIMIT 1
    ");
    $room_stmt->execute(['tenant_id' => $tenant_id]);
    $room_data = $room_stmt->fetch(PDO::FETCH_ASSOC);

    if ($room_data) {
        $response['room_info'] = [
            'room_number' => $room_data['room_number'],
            'room_type' => $room_data['room_type']
        ];
        
        // Extract date and time from datetime columns
        $checkin_dt = $room_data['checkin_date'] ? new DateTime($room_data['checkin_date']) : null;
        $checkout_dt = $room_data['checkout_date'] ? new DateTime($room_data['checkout_date']) : null;
        
        $response['stay_info'] = [
            'checkin_date' => $room_data['checkin_date'],
            'checkin_time' => $checkin_dt ? $checkin_dt->format('H:i') : null,
            'checkout_date' => $room_data['checkout_date'],
            'checkout_time' => $checkout_dt ? $checkout_dt->format('H:i') : null
        ];
    }
    else {
        // fallback to tenant-level checkin/checkout timestamps
        $t_checkin = $customer['checkin_time'] ?? null;
        $t_checkout = $customer['checkout_time'] ?? null;
        if ($t_checkin || $t_checkout) {
            $checkin_dt = $t_checkin ? new DateTime($t_checkin) : null;
            $checkout_dt = $t_checkout ? new DateTime($t_checkout) : null;
            $response['stay_info'] = [
                'checkin_date' => $t_checkin ? $t_checkin : null,
                'checkin_time' => $checkin_dt ? $checkin_dt->format('H:i') : null,
                'checkout_date' => $t_checkout ? $t_checkout : null,
                'checkout_time' => $checkout_dt ? $checkout_dt->format('H:i') : null
            ];
        }
    }

    // Get co-tenants linked to this primary tenant (avoid duplicates with DISTINCT)
    $co_tenants_stmt = $conn->prepare("
        SELECT DISTINCT id, name, email, phone
        FROM co_tenants
        WHERE primary_tenant_id = :tenant_id
        ORDER BY name ASC
    ");
    $co_tenants_stmt->execute(['tenant_id' => $tenant_id]);
    $response['co_tenants'] = $co_tenants_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
