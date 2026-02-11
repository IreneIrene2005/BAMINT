<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "tenant") {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

$tenant_id = $_SESSION["tenant_id"];
// Alias for compatibility: tenant_id and customer_id are the same
if (!isset($_SESSION["customer_id"])) {
    $_SESSION["customer_id"] = $tenant_id;
}
$customer_id = $_SESSION["customer_id"];
$message = '';
$message_type = '';

// Get tenant status and check if they already have a room
$tenant_has_room = false;
$has_approved_payment = false;
$approved_payment_info = null;

try {
    $status_stmt = $conn->prepare("SELECT room_id FROM tenants WHERE id = :tenant_id");
    $status_stmt->execute(['tenant_id' => $tenant_id]);
    $tenant_status = $status_stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_has_room = ($tenant_status && !empty($tenant_status['room_id']));
} catch (Exception $e) {
    $tenant_has_room = false;
}

// Check if tenant has any approved/verified payment
try {
    $payment_stmt = $conn->prepare("
        SELECT DISTINCT 
            b.id as bill_id,
            b.room_id,
            r.room_number,
            pt.payment_amount,
            pt.verification_date
        FROM bills b
        INNER JOIN payment_transactions pt ON b.id = pt.bill_id AND pt.payment_status IN ('verified', 'approved')
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.tenant_id = :tenant_id
        ORDER BY pt.verification_date DESC
        LIMIT 1
    ");
    $payment_stmt->execute(['tenant_id' => $tenant_id]);
    $approved_payment_info = $payment_stmt->fetch(PDO::FETCH_ASSOC);
    $has_approved_payment = ($approved_payment_info !== false && !empty($approved_payment_info));
} catch (Exception $e) {
    $has_approved_payment = false;
}

// Fetch tenant contact info for pre-filling request modal
$tenant_name = '';
$tenant_email = '';
$tenant_phone = '';
try {
    $tstmt = $conn->prepare("SELECT name, email, phone FROM tenants WHERE id = :tenant_id LIMIT 1");
    $tstmt->execute(['tenant_id' => $tenant_id]);
    $trow = $tstmt->fetch(PDO::FETCH_ASSOC);
    if ($trow) {
        $tenant_name = $trow['name'] ?? '';
        $tenant_email = $trow['email'] ?? '';
        $tenant_phone = $trow['phone'] ?? '';
    }
} catch (Exception $e) {
    // ignore
}

// Handle room request submission
// Handle room request cancellation (AJAX POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_request') {
    header('Content-Type: application/json');
    $resp = ['success' => false, 'message' => ''];
    $request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
    if ($request_id <= 0) {
        $resp['message'] = 'Invalid request id.';
        echo json_encode($resp);
        exit;
    }

    try {
        $check = $conn->prepare("SELECT id, tenant_id, room_id, status FROM room_requests WHERE id = :id LIMIT 1");
        $check->execute(['id' => $request_id]);
        $req = $check->fetch(PDO::FETCH_ASSOC);
        if (!$req || intval($req['tenant_id']) !== intval($tenant_id)) {
            $resp['message'] = 'Request not found or access denied.';
            echo json_encode($resp);
            exit;
        }

        // Only allow cancelling if not already cancelled or approved/moved-in
        if ($req['status'] === 'cancelled' || $req['status'] === 'approved' || $req['status'] === 'moved_in') {
            $resp['message'] = 'This request cannot be cancelled.';
            echo json_encode($resp);
            exit;
        }

        $conn->beginTransaction();
        // Mark request cancelled
        $upd = $conn->prepare("UPDATE room_requests SET status = 'cancelled', updated_at = NOW() WHERE id = :id");
        $upd->execute(['id' => $request_id]);

        // Make room available again (simple approach)
        $roomUp = $conn->prepare("UPDATE rooms SET status = 'available' WHERE id = :room_id");
        $roomUp->execute(['room_id' => $req['room_id']]);

        $conn->commit();

        // Notify all admins about cancellation
        try {
            $admins = $conn->query("SELECT id FROM admins")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($admins as $admin) {
                createNotification(
                    $conn,
                    'admin',
                    $admin['id'],
                    'room_request_cancelled',
                    'Room Request Cancelled',
                    'Tenant ' . ($tenant_id) . ' cancelled room request #' . $request_id . '.',
                    $request_id,
                    'room_request',
                    'room_requests_queue.php'
                );
            }
        } catch (Exception $e) {
            error_log('Admin notification on cancel failed: ' . $e->getMessage());
        }

        // Notify tenant (self) as confirmation
        try {
            createNotification(
                $conn,
                'tenant',
                $tenant_id,
                'room_request_cancelled',
                'Room Request Cancelled',
                'Your room request has been cancelled.',
                $request_id,
                'room_request',
                'tenant_add_room.php'
            );
        } catch (Exception $e) {
            error_log('Tenant notification on cancel failed: ' . $e->getMessage());
        }

        $resp['success'] = true;
        $resp['message'] = 'Room request cancelled successfully.';
        echo json_encode($resp);
        exit;

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('Cancel request failed: ' . $e->getMessage());
        $resp['message'] = 'Failed to cancel request: ' . $e->getMessage();
        echo json_encode($resp);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_room') {
    // Debug: Log received POST data for troubleshooting
    file_put_contents('room_request_debug.log', date('Y-m-d H:i:s') . "\n" . print_r($_POST, true) . "\n\n", FILE_APPEND);
    // Check if tenant already has a room
    if ($tenant_has_room) {
        $message = "⚠️ You already have a room assigned. You cannot request another room while you have an active room.";
        $message_type = "warning";
    }
    else {
        $room_id = isset($_POST['room_id']) ? intval($_POST['room_id']) : 0;
        $tenant_count = isset($_POST['tenant_count']) ? intval($_POST['tenant_count']) : 1;
        $tenant_info_name = isset($_POST['tenant_info_name']) ? trim($_POST['tenant_info_name']) : '';
        $tenant_info_email = isset($_POST['tenant_info_email']) ? trim($_POST['tenant_info_email']) : '';
        $tenant_info_phone = isset($_POST['tenant_info_phone']) ? trim($_POST['tenant_info_phone']) : '';
        $tenant_info_address = isset($_POST['tenant_info_address']) ? trim($_POST['tenant_info_address']) : '';
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

        // Validate required fields
        $errors = [];
        if (empty($tenant_info_name)) $errors[] = "Name is required";
        if (empty($tenant_info_email) || !filter_var($tenant_info_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required";
        if (empty($tenant_info_phone)) $errors[] = "Phone number is required";
        if (empty($tenant_info_address)) $errors[] = "Address is required";
        if ($tenant_count < 1) $errors[] = "Number of occupants must be at least 1";

        // Get room details to validate occupancy limits
        if ($room_id > 0 && empty($errors)) {
            try {
                $room_stmt = $conn->prepare("SELECT room_type FROM rooms WHERE id = :id");
                $room_stmt->execute(['id' => $room_id]);
                $room = $room_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($room) {
                    // Validate occupancy based on room type
                    $room_type = strtolower($room['room_type']);
                    if ($room_type === 'single' && $tenant_count > 1) {
                        $errors[] = "Single rooms can only accommodate 1 person.";
                    } elseif ($room_type === 'shared' && $tenant_count > 2) {
                        $errors[] = "Shared rooms can accommodate maximum 2 people.";
                    } elseif ($room_type === 'bedspace' && $tenant_count > 4) {
                        $errors[] = "Bedspace rooms can accommodate maximum 4 people.";
                    } elseif ($room_type === 'family' && $tenant_count > 5) {
                        $errors[] = "Family rooms can accommodate maximum 5 people.";
                    }
                }
            } catch (Exception $e) {
                $errors[] = "Error validating room: " . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            $message = implode("<br>", $errors);
            $message_type = "danger";
        } elseif ($room_id > 0) {
            try {
                // Check if tenant already has a pending request for this room
                $check_stmt = $conn->prepare("
                    SELECT id FROM room_requests 
                    WHERE tenant_id = :tenant_id AND room_id = :room_id AND status = 'pending'
                ");
                $check_stmt->execute(['tenant_id' => $tenant_id, 'room_id' => $room_id]);
                
                if ($check_stmt->rowCount() > 0) {
                    $message = "You already have a pending request for this room.";
                    $message_type = "warning";
                } else {
                    // Start transaction
                    $conn->beginTransaction();
                    try {
                        // Insert room request with occupancy info
                        $checkin_datetime = isset($_POST['checkin_date']) ? $_POST['checkin_date'] : null;
                        $checkout_datetime = isset($_POST['checkout_date']) ? $_POST['checkout_date'] : null;
                        
                        // Parse the datetime strings to separate date and time
                        // Format from flatpickr: "Y-m-d h:i K" (e.g., "2026-02-09 02:30 PM")
                        $checkin_date = null;
                        $checkin_time = null;
                        if ($checkin_datetime) {
                            $parts = explode(' ', trim($checkin_datetime));
                            $checkin_date = $parts[0]; // Y-m-d
                            if (count($parts) >= 3) {
                                // Combine time and AM/PM, then convert to 24-hour format
                                $time_str = $parts[1] . ' ' . $parts[2]; // "02:30 PM"
                                $checkin_time = date('H:i', strtotime($time_str)); // "14:30"
                            }
                        }
                        
                        $checkout_date = null;
                        $checkout_time = null;
                        if ($checkout_datetime) {
                            $parts = explode(' ', trim($checkout_datetime));
                            $checkout_date = $parts[0]; // Y-m-d
                            if (count($parts) >= 3) {
                                // Combine time and AM/PM, then convert to 24-hour format
                                $time_str = $parts[1] . ' ' . $parts[2]; // "11:00 AM"
                                $checkout_time = date('H:i', strtotime($time_str)); // "11:00"
                            }
                        }
                        
                        $stmt = $conn->prepare("
                            INSERT INTO room_requests (tenant_id, room_id, tenant_count, tenant_info_name, tenant_info_email, tenant_info_phone, tenant_info_address, notes, status, checkin_date, checkout_date, checkin_time, checkout_time)
                            VALUES (:tenant_id, :room_id, :tenant_count, :tenant_info_name, :tenant_info_email, :tenant_info_phone, :tenant_info_address, :notes, 'pending_payment', :checkin_date, :checkout_date, :checkin_time, :checkout_time)
                        ");
                        $stmt->execute([
                            'tenant_id' => $tenant_id,
                            'room_id' => $room_id,
                            'tenant_count' => $tenant_count,
                            'tenant_info_name' => $tenant_info_name,
                            'tenant_info_email' => $tenant_info_email,
                            'tenant_info_phone' => $tenant_info_phone,
                            'tenant_info_address' => $tenant_info_address,
                            'notes' => $notes,
                            'checkin_date' => $checkin_date,
                            'checkout_date' => $checkout_date,
                            'checkin_time' => $checkin_time,
                            'checkout_time' => $checkout_time
                        ]);
                        $roomRequestId = $conn->lastInsertId();

                        // Notify all admins and front desk staff about the new room booking
                        try {
                            notifyAdminsNewBooking($conn, $roomRequestId, $tenant_id, $room_id);
                        } catch (Exception $e) {
                            error_log("Error creating booking notification: " . $e->getMessage());
                        }

                        // Immediately create advance payment bill for this request
                        $rate_stmt = $conn->prepare("SELECT rate FROM rooms WHERE id = :room_id");
                        $rate_stmt->execute(['room_id' => $room_id]);
                        $rate = $rate_stmt->fetchColumn();
                        $nights = 0;
                        if ($checkin_date && $checkout_date) {
                            $checkin_dt = new DateTime($checkin_date);
                            $checkout_dt = new DateTime($checkout_date);
                            $interval = $checkin_dt->diff($checkout_dt);
                            $nights = (int)$interval->days;
                        }
                        $total_cost = $rate * $nights;
                        $bill_notes = "ADVANCE PAYMENT - Move-in fee (" . $nights . " night" . ($nights > 1 ? "s" : "") . ", ₱" . number_format($rate, 2) . "/night)";
                        $billing_month = $checkin_date ? (new DateTime($checkin_date))->format('Y-m-d') : date('Y-m-d');
                        $due_date = $checkin_date ? (new DateTime($checkin_date))->format('Y-m-d') : date('Y-m-d');
                        $bill_stmt = $conn->prepare("
                            INSERT INTO bills (tenant_id, room_id, billing_month, amount_due, due_date, status, notes, checkin_date, checkout_date, created_at, updated_at)
                            VALUES (:tenant_id, :room_id, :billing_month, :amount_due, :due_date, 'pending', :notes, :checkin_date, :checkout_date, NOW(), NOW())
                        ");
                        $bill_stmt->execute([
                            'tenant_id' => $tenant_id,
                            'room_id' => $room_id,
                            'billing_month' => $billing_month,
                            'amount_due' => $total_cost,
                            'due_date' => $due_date,
                            'notes' => $bill_notes,
                            'checkin_date' => $checkin_date ?: null,
                            'checkout_date' => $checkout_date ?: null
                        ]);

                        // Mark room as booked (will be occupied once admin approves the request)
                        $update_room_booked = $conn->prepare("UPDATE rooms SET status = 'booked' WHERE id = :room_id");
                        $update_room_booked->execute(['room_id' => $room_id]);

                        // Save co-tenants if this is a shared/bedspace room with multiple occupants
                        if ($tenant_count > 1) {
                            // Ensure room_id is valid to avoid foreign key errors
                            $valid_room_id = null;
                            if (!empty($room_id)) {
                                try {
                                    $check_room = $conn->prepare("SELECT id FROM rooms WHERE id = :id LIMIT 1");
                                    $check_room->execute(['id' => $room_id]);
                                    $found = $check_room->fetch(PDO::FETCH_ASSOC);
                                    if ($found) $valid_room_id = $room_id;
                                } catch (Exception $e) {
                                    // fallback: treat as null
                                    $valid_room_id = null;
                                }
                            }

                            for ($i = 1; $i < $tenant_count; $i++) {
                                $co_name = isset($_POST['co_tenant_name_' . $i]) ? trim($_POST['co_tenant_name_' . $i]) : '';
                                $co_email = isset($_POST['co_tenant_email_' . $i]) ? trim($_POST['co_tenant_email_' . $i]) : '';
                                $co_phone = isset($_POST['co_tenant_phone_' . $i]) ? trim($_POST['co_tenant_phone_' . $i]) : '';
                                $co_address = isset($_POST['co_tenant_address_' . $i]) ? trim($_POST['co_tenant_address_' . $i]) : '';

                                if (!empty($co_name)) {
                                    $co_stmt = $conn->prepare(
                                        "INSERT INTO co_tenants (primary_tenant_id, room_id, name, email, phone, address) \n                                        VALUES (:primary_tenant_id, :room_id, :name, :email, :phone, :address)"
                                    );
                                    $co_stmt->execute([
                                        'primary_tenant_id' => $tenant_id,
                                        'room_id' => $valid_room_id, // will be NULL if invalid
                                        'name' => $co_name,
                                        'email' => $co_email,
                                        'phone' => $co_phone,
                                        'address' => $co_address
                                    ]);
                                }
                            }
                        }

                        $conn->commit();

                        // Notify admins about new room request and confirm to tenant
                        try {
                            notifyAdminsNewRoomRequest($conn, $roomRequestId, $tenant_id, $tenant_count);
                        } catch (Exception $e) {
                            error_log('notifyAdminsNewRoomRequest failed: ' . $e->getMessage());
                        }

                        try {
                            createNotification(
                                $conn,
                                'tenant',
                                $tenant_id,
                                'room_request_submitted',
                                'Room Request Submitted',
                                'Your room request has been submitted. Please proceed to payment to complete your booking.',
                                $roomRequestId,
                                'room_request',
                                'tenant_payments.php?room_request_id=' . $roomRequestId
                            );
                        } catch (Exception $e) {
                            error_log('tenant room request notification failed: ' . $e->getMessage());
                        }

                        $message = "Room request submitted! Please proceed to payment to complete your booking.";
                        $message_type = "success";
                    } catch (Exception $e) {
                        $conn->rollBack();
                        $message = "Error submitting request: " . $e->getMessage();
                        $message_type = "danger";
                    }
                }
            } catch (Exception $e) {
                $message = "Error submitting request: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// Fetch all rooms with availability status (including co-tenants) and image
// Match admin_rooms.php: show all rooms with status = 'available'
try {
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
} catch (Exception $e) {
    $message = "Error loading rooms: " . $e->getMessage();
    $message_type = "danger";
    $rooms = [];
}

// Fetch tenant's existing requests
try {
    $stmt = $conn->prepare("
        SELECT rr.*, r.room_number, r.rate
        FROM room_requests rr
        JOIN rooms r ON rr.room_id = r.id
        WHERE rr.tenant_id = :tenant_id
        ORDER BY rr.request_date DESC
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $my_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $my_requests = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Room - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar {
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
            color: white;
            min-height: 100vh;
            padding: 2rem 0;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.7);
            padding: 1rem 1.5rem;
            border-left: 3px solid transparent;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
            border-left-color: white;
        }
        .user-info {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 1rem;
        }
        .user-info h5 {
            margin-bottom: 0.25rem;
        }
        .user-info p {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-bottom: 0;
        }
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .room-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 2rem;
        }
        .room-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 1rem;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .room-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-4px);
        }
        .room-card.available {
            border-left: 4px solid #28a745;
        }
        .room-card.occupied {
            border-left: 4px solid #ffc107;
        }
        .room-card.unavailable {
            border-left: 4px solid #dc3545;
            opacity: 0.7;
        }
        .room-card img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 6px;
            margin-bottom: 0.8rem;
        }
        .room-card-content {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .room-card-content h6 {
            margin: 0 0 0.5rem 0;
            font-size: 1.1rem;
        }
        .room-card-content p {
            margin: 0.3rem 0;
            font-size: 0.85rem;
            color: #666;
        }
        .room-card-actions {
            margin-top: 0.8rem;
        }
        .filter-section {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin: 2rem 0;
            flex-wrap: wrap;
        }
        .filter-section label {
            margin-bottom: 0;
            font-weight: 500;
        }
        .filter-section select {
            min-width: 200px;
        }
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-available {
            background-color: #d4edda;
            color: #155724;
        }
        .status-occupied {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-unavailable {
            background-color: #f8d7da;
            color: #721c24;
        }
        .request-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .request-status {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 16px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .request-pending {
            background-color: #cfe2ff;
            color: #084298;
        }
        .request-approved {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .request-rejected {
            background-color: #f8d7da;
            color: #842029;
        }
        .btn-logout {
            background: #dc3545;
            color: white;
            border: none;
            margin-top: 1rem;
            width: 100%;
        }
        .btn-logout:hover {
            background: #c82333;
            color: white;
        }
        .room-info {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }
        .room-details h5 {
            margin-bottom: 0.5rem;
            color: #333;
        }
        .room-details p {
            margin-bottom: 0.25rem;
            color: #666;
            font-size: 0.95rem;
        }
        .rate {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
        }
    </style>
</head>
<body>
    <?php include 'templates/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'templates/tenant_sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="header-banner">
                    <h1><i class="bi bi-search"></i> Browse Room</h1>
                    <p class="mb-0">View available rooms, see details, and request a booking. Fill in your information and select check-in/check-out dates. The system will check availability.</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Approved Booking Confirmation -->
                <?php if ($has_approved_payment): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                        <i class="bi bi-check-circle"></i>
                        <strong>Payment Approved!</strong> Your booking for Room <?php echo htmlspecialchars($approved_payment_info['room_number']); ?> has been confirmed.
                        Your payment of <strong>₱<?php echo number_format($approved_payment_info['payment_amount'], 2); ?></strong> has been verified and approved.
                        You can view your booking details on the dashboard.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- My Requests Section (Improved UI) - Only show if no approved payment -->
                <?php if (!$has_approved_payment): ?>
                <div class="col-12 mt-4 mb-4">
                    <div class="card shadow-lg border-primary" style="width: 100%;">
                        <div class="card-header bg-primary text-white text-center" style="font-size: 1.5rem; font-weight: bold; letter-spacing: 1px;">
                            <i class="bi bi-clock-history"></i> My Requests
                        </div>
                        <div class="card-body" style="background: #fafdff;">
                            <?php if (empty($my_requests)): ?>
                                <p class="text-muted text-center">You haven't submitted any room requests yet.</p>
                            <?php else: ?>
                                <?php $request = $my_requests[0]; ?>
                                    <div class="request-card mb-4 p-3 border border-2 rounded-3" style="background: #f4f8ff;">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="fs-5 fw-bold text-primary">Room <?php echo htmlspecialchars($request['room_number']); ?></span>
                                            <span class="request-status request-<?php echo htmlspecialchars(strtolower($request['status'])); ?> px-3 py-1" style="font-size:1rem;">
                                                <?php
                                                    // Show 'Approved' if status is 'approved' or if status is 'pending_payment' but payment is already made and approved
                                                    if ($request['status'] === 'approved') {
                                                        echo 'Approved';
                                                    } elseif ($request['status'] === 'pending_payment') {
                                                        // Check if there is a verified/approved payment for this request's bill
                                                        $bill_stmt = $conn->prepare("SELECT id FROM bills WHERE tenant_id = :tenant_id AND room_id = :room_id AND notes LIKE '%ADVANCE PAYMENT%' LIMIT 1");
                                                        $bill_stmt->execute(['tenant_id' => $tenant_id, 'room_id' => $request['room_id']]);
                                                        $bill = $bill_stmt->fetch(PDO::FETCH_ASSOC);
                                                        $is_paid = false;
                                                        if ($bill) {
                                                            $pay_stmt = $conn->prepare("SELECT COUNT(*) FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified','approved')");
                                                            $pay_stmt->execute(['bill_id' => $bill['id']]);
                                                            $is_paid = $pay_stmt->fetchColumn() > 0;
                                                        }
                                                        if ($is_paid) {
                                                            echo 'Approved';
                                                        } else {
                                                            echo 'Awaiting Payment';
                                                        }
                                                    } else {
                                                        echo htmlspecialchars(ucfirst($request['status']));
                                                    }
                                                ?>
                                            </span>
                                        </div>
                                        <div class="mb-1"><strong>Rate:</strong> <span class="text-success">₱<?php echo number_format($request['rate'], 2); ?></span></div>
                                        <div class="mb-1"><strong>Occupants:</strong> <?php echo intval($request['tenant_count'] ?? 1); ?> person(s)</div>
                                        <div class="mb-1"><strong>Requested:</strong> <?php echo date('M d, Y', strtotime($request['request_date'])); ?></div>
                                        <?php if (!empty($request['tenant_info_name'])): ?>
                                            <div class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($request['tenant_info_name']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($request['notes']): ?>
                                            <div class="mb-0 text-muted small"><strong>Notes:</strong> <?php echo htmlspecialchars($request['notes']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($request['status'] === 'pending_payment') : ?>
                                            <form method="get" action="tenant_make_payment.php" class="mt-2">
                                                <input type="hidden" name="room_request_id" value="<?php echo $request['id']; ?>">
                                                <button type="submit" class="btn btn-warning w-100">Proceed to Payment</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filter Section (Centered) - Only show if no approved payment -->
                <?php if (!$has_approved_payment): ?>
                <div class="filter-section">
                    <label for="filterType">Filter by Room Type:</label>
                    <select class="form-select" id="filterType" name="type" onchange="filterRooms()">
                        <option value="">All Rooms</option>
                        <option value="Single">Single</option>
                        <option value="Double">Double</option>
                        <option value="Family">Family</option>
                    </select>
                </div>

                <!-- Available Rooms Section -->
                <div>
                    <div id="roomsContainer" class="room-grid">
                                <?php if (empty($rooms)): ?>
                                    <div class="alert alert-info w-100">No rooms available at the moment.</div>
                                <?php else: ?>
                                    <?php foreach ($rooms as $room): ?>
                                        <?php
                                        // Calculate actual occupancy and status
                                        $total_occupancy = intval($room['tenant_count']) + intval($room['co_tenant_count']);

                                        // Prefer the canonical rooms.status (admin view) to determine availability.
                                        $room_status = strtolower(trim($room['status'] ?? ''));
                                        if ($room_status === 'available') {
                                            $actual_status = 'available';
                                            $status_label = 'Available';
                                        } elseif ($room_status === 'booked' || $room_status === 'occupied') {
                                            // Treat booked/occupied as not available for customers
                                            $actual_status = 'occupied';
                                            $status_label = ucfirst($room_status);
                                        } elseif ($room_status === 'under maintenance' || $room_status === 'under_maintenance' || $room_status === 'maintenance') {
                                            $actual_status = 'unavailable';
                                            $status_label = 'Under Maintenance';
                                        } elseif ($room_status === 'unavailable') {
                                            $actual_status = 'unavailable';
                                            $status_label = 'Unavailable';
                                        } else {
                                            // Fallback: infer from tenant/co-tenant counts
                                            $actual_status = $total_occupancy > 0 ? 'occupied' : 'available';
                                            $status_label = ucfirst($actual_status);
                                        }
                                        
                                        $room_type = strtolower($room['room_type']);
                                        $max_occupancy = 4;
                                        if ($room_type === 'single') $max_occupancy = 1;
                                        elseif ($room_type === 'double') $max_occupancy = 2;
                                        elseif ($room_type === 'family') $max_occupancy = 5;
                                        ?>
                                        <?php if (strtolower(trim($room['status'] ?? '')) === 'available'): ?>
                                        <div class="room-card <?php echo htmlspecialchars(strtolower($actual_status)); ?>" data-rate="<?php echo htmlspecialchars($room['rate']); ?>" data-room-type="<?php echo htmlspecialchars($room['room_type']); ?>">
                                            <!-- Room Image -->
                                            <img src="<?php echo !empty($room['image_url']) ? htmlspecialchars($room['image_url']) : 'public/img/room-placeholder.png'; ?>" alt="Room <?php echo htmlspecialchars($room['room_number']); ?>">
                                            
                                            <!-- Room Details -->
                                            <div class="room-card-content">
                                                <h6><?php echo htmlspecialchars($room['room_number']); ?></h6>
                                                <p><strong><?php echo htmlspecialchars($room['room_type']); ?></strong></p>
                                                <p><strong>₱<?php echo number_format($room['rate'], 0); ?>/night</strong></p>
                                                <p class="text-muted small">Guests: <?php echo $max_occupancy; ?></p>
                                                <div class="status-badge status-<?php echo htmlspecialchars(strtolower($actual_status)); ?>">
                                                    <?php echo htmlspecialchars($status_label); ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Request Button - Opens Modal -->
                                            <div class="room-card-actions">
                                                <button class="btn btn-sm btn-primary w-100" type="button" data-bs-toggle="modal" data-bs-target="#requestRoomModal" data-room-id="<?php echo htmlspecialchars($room['id']); ?>" data-room-number="<?php echo htmlspecialchars($room['room_number']); ?>" data-room-type="<?php echo htmlspecialchars($room['room_type']); ?>" data-room-rate="<?php echo htmlspecialchars($room['rate']); ?>" data-max-occupancy="<?php echo $max_occupancy; ?>" onclick="populateModalWithRoom(this)">Request</button>
                                            </div>
                                        </div>
                                        <?php elseif ($actual_status === 'unavailable'): ?>
                                        <div class="room-card unavailable">
                                            <img src="<?php echo !empty($room['image_url']) ? htmlspecialchars($room['image_url']) : 'public/img/room-placeholder.png'; ?>" alt="Room <?php echo htmlspecialchars($room['room_number']); ?>">
                                            <div class="room-card-content">
                                                <h6><?php echo htmlspecialchars($room['room_number']); ?></h6>
                                                <p><strong><?php echo htmlspecialchars($room['room_type']); ?></strong></p>
                                                <p><strong>₱<?php echo number_format($room['rate'], 0); ?>/night</strong></p>
                                                <div class="status-badge status-unavailable">Unavailable</div>
                                            </div>
                                        </div>
                                        <?php elseif ($actual_status === 'occupied'): ?>
                                        <div class="room-card occupied">
                                            <img src="<?php echo !empty($room['image_url']) ? htmlspecialchars($room['image_url']) : 'public/img/room-placeholder.png'; ?>" alt="Room <?php echo htmlspecialchars($room['room_number']); ?>">
                                            <div class="room-card-content">
                                                <h6><?php echo htmlspecialchars($room['room_number']); ?></h6>
                                                <p><strong><?php echo htmlspecialchars($room['room_type']); ?></strong></p>
                                                <p><strong>₱<?php echo number_format($room['rate'], 0); ?>/night</strong></p>
                                                <div class="status-badge status-occupied">Occupied</div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <script>
                    // Cancel request handler: sends AJAX POST to cancel the request
                    document.addEventListener('DOMContentLoaded', function() {
                        // Delegated click handler for cancel buttons (works for dynamically rendered elements)
                        document.body.addEventListener('click', function(e) {
                            var btn = e.target.closest('.cancel-request-btn');
                            if (!btn) return;
                            e.preventDefault();
                            if (!confirm('Are you sure you want to cancel this room request?')) return;
                            var reqId = btn.getAttribute('data-request-id');
                            var formData = new FormData();
                            formData.append('action', 'cancel_request');
                            formData.append('request_id', reqId);

                            fetch(window.location.href, {
                                method: 'POST',
                                body: formData,
                                credentials: 'same-origin'
                            }).then(function(resp) {
                                return resp.json();
                            }).then(function(json) {
                                if (json && json.success) {
                                    alert(json.message || 'Request cancelled.');
                                    window.location.reload();
                                } else {
                                    alert('Failed to cancel: ' + (json.message || 'Unknown error'));
                                }
                            }).catch(function(err) {
                                console.error(err);
                                alert('Failed to cancel request.');
                            });
                        });
                    });
                        // Auto-calculate checkout datetime based on check-in and nights, using Flatpickr format
                        document.querySelectorAll('.room-card').forEach(function(card) {
                            const checkinInput = card.querySelector('.checkin-date');
                            const checkoutInput = card.querySelector('.checkout-date');
                            const nightsInput = card.querySelector('.nights-input'); // If you have a nights input, otherwise set nights = 1
                            let nights = 1;
                            if (nightsInput) {
                                nightsInput.addEventListener('input', function() {
                                    nights = parseInt(nightsInput.value) || 1;
                                    updateCheckout();
                                });
                            }
                            function pad(n) { return n.toString().padStart(2, '0'); }
                            function formatFlatpickr(dt) {
                                // Format: Y-m-d h:i K (e.g., 2026-02-07 05:00 PM)
                                let hours = dt.getHours();
                                let minutes = pad(dt.getMinutes());
                                let ampm = hours >= 12 ? 'PM' : 'AM';
                                let hour12 = hours % 12;
                                if (hour12 === 0) hour12 = 12;
                                return `${dt.getFullYear()}-${pad(dt.getMonth()+1)}-${pad(dt.getDate())} ${pad(hour12)}:${minutes} ${ampm}`;
                            }
                            function updateCheckout() {
                                if (checkinInput.value) {
                                    // Parse using Flatpickr's format
                                    let checkinDate = null;
                                    // Try to parse as Date
                                    if (window.flatpickr && flatpickr.parseDate) {
                                        checkinDate = flatpickr.parseDate(checkinInput.value, "Y-m-d h:i K");
                                    }
                                    if (!checkinDate || isNaN(checkinDate.getTime())) {
                                        checkinDate = new Date(checkinInput.value);
                                    }
                                    if (!isNaN(checkinDate.getTime())) {
                                        const checkoutDate = new Date(checkinDate.getTime());
                                        checkoutDate.setDate(checkoutDate.getDate() + nights);
                                        // Set time to match check-in
                                        checkoutDate.setHours(checkinDate.getHours());
                                        checkoutDate.setMinutes(checkinDate.getMinutes());
                                        // Format for Flatpickr
                                        checkoutInput.value = formatFlatpickr(checkoutDate);
                                        // If Flatpickr instance exists, setDate for UI sync
                                        if (checkoutInput._flatpickr) {
                                            checkoutInput._flatpickr.setDate(checkoutDate, true, "Y-m-d h:i K");
                                        }
                                    }
                                }
                            }
                            if (checkinInput && checkoutInput) {
                                checkinInput.addEventListener('change', updateCheckout);
                                // If you have a nights input, also update on its change
                                if (nightsInput) nightsInput.addEventListener('change', updateCheckout);
                            }
                        });
                function filterRooms() {
                    const type = document.getElementById('filterType').value;
                    const cards = document.querySelectorAll('.room-card');
                    cards.forEach(card => {
                        let show = true;
                        if (type) {
                            const cardRoomType = card.getAttribute('data-room-type');
                            show = cardRoomType === type;
                        }
                        card.style.display = show ? '' : 'none';
                    });
                }
                </script>
                                <!-- Flatpickr initialization for date fields -->
                                <script>
                                    // Wait for Flatpickr to load then initialize ALL date inputs
                                    function initializeFlatpickr() {
                                        if (typeof flatpickr === 'undefined') {
                                            // Flatpickr not loaded yet, try again
                                            setTimeout(initializeFlatpickr, 100);
                                            return;
                                        }
                                        
                                        const modalCheckinInput = document.getElementById('modalCheckinDate');
                                        const modalCheckoutInput = document.getElementById('modalCheckoutDate');
                                        
                                        // Initialize all date inputs
                                        document.querySelectorAll('.checkin-date').forEach(function(input) {
                                            if (!input._flatpickr) {
                                                flatpickr(input, {
                                                    enableTime: true,
                                                    dateFormat: "Y-m-d h:i K",
                                                    time_24hr: false,
                                                    minuteIncrement: 1,
                                                    allowInput: true,
                                                    onChange: function(selectedDates, dateStr, instance) {
                                                        // Auto-set checkout for modal check-in
                                                        if (input === modalCheckinInput && modalCheckoutInput && selectedDates.length > 0) {
                                                            const checkinDate = selectedDates[0];
                                                            const checkoutDate = new Date(checkinDate.getTime());
                                                            checkoutDate.setDate(checkoutDate.getDate() + 1);
                                                            
                                                            // Set checkout using its Flatpickr instance with explicit date object
                                                            if (modalCheckoutInput._flatpickr) {
                                                                modalCheckoutInput._flatpickr.setDate(checkoutDate);
                                                            }
                                                        }
                                                    }
                                                });
                                            }
                                        });
                                        
                                        document.querySelectorAll('.checkout-date').forEach(function(input) {
                                            if (!input._flatpickr) {
                                                flatpickr(input, {
                                                    enableTime: true,
                                                    dateFormat: "Y-m-d h:i K",
                                                    time_24hr: false,
                                                    minuteIncrement: 1,
                                                    allowInput: true
                                                });
                                            }
                                        });
                                    }
                                    
                                    // Initialize when DOM is ready
                                    if (document.readyState === 'loading') {
                                        document.addEventListener('DOMContentLoaded', initializeFlatpickr);
                                    } else {
                                        initializeFlatpickr();
                                    }
                                </script>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Room Request Modal -->
    <div class="modal fade" id="requestRoomModal" tabindex="-1" aria-labelledby="requestRoomModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="requestRoomModalLabel">Request Room</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <!-- Room Information Display -->
                    <div class="alert alert-info mb-4">
                        <strong>Room:</strong> <span id="modalRoomNumber"></span><br>
                        <strong>Type:</strong> <span id="modalRoomType"></span><br>
                        <strong>Rate:</strong> ₱<span id="modalRoomRate"></span>/night
                    </div>
                    
                    <form method="POST" id="requestRoomForm">
                        <input type="hidden" name="action" value="request_room">
                        <input type="hidden" id="modalRoomId" name="room_id" value="">
                        
                        <h6 class="mb-3"><i class="bi bi-person-check"></i> Guest Information</h6>
                        
                        <div class="mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="modalTenantName" name="tenant_info_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="modalTenantEmail" name="tenant_info_email" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Phone <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" id="modalTenantPhone" name="tenant_info_phone" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Address <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="modalTenantAddress" name="tenant_info_address" rows="2" required></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Check-in Date <span class="text-danger">*</span></label>
                                <input type="text" class="form-control modalCheckinDate checkin-date" id="modalCheckinDate" name="checkin_date" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Check-out Date <span class="text-danger">*</span></label>
                                <input type="text" class="form-control modalCheckoutDate checkout-date" id="modalCheckoutDate" name="checkout_date" required>
                            </div>
                        </div>

                        <div class="mb-3 p-3 bg-light rounded" id="modalCostDisplay" style="display: none;">
                            <div class="row">
                                <div class="col-6">
                                    <small class="text-muted">Total Cost</small>
                                    <div class="fs-5 fw-bold text-success" id="modalCostValue">-</div>
                                </div>
                                <div class="col-6 text-end">
                                    <small class="text-muted" id="modalNightCount">-</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Number of Guests <span class="text-danger">*</span></label>
                            <input type="number" class="form-control guest-count-input" id="modalGuestCount" name="tenant_count" min="1" value="1" required onchange="renderRoommateFields();" oninput="renderRoommateFields();">
                            <small class="text-muted">Max: <span id="modalMaxOccupancy">1</span> guest(s)</small>
                        </div>
                        
                        <div class="co-guests-section" id="modalCoGuestsSection" style="display: none; border-top: 1px solid #ddd; padding-top: 15px; margin-top: 15px;"></div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="modalNotes" name="notes" rows="2"></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-success w-100">Submit Request</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Validate form before submission
        document.getElementById('requestRoomForm').addEventListener('submit', function(e) {
            // Ensure co-guest fields are present and filled when tenant_count > 1
            try {
                const guestCount = parseInt(document.getElementById('modalGuestCount').value) || 1;
                if (guestCount > 1) {
                    // Check if co-guest inputs exist
                    const firstCoGuest = document.querySelector('[name="co_tenant_name_1"]');
                    if (!firstCoGuest) {
                        // Fields missing - generate them
                        if (window.renderModalCoGuests) window.renderModalCoGuests();
                        alert('Roommate fields have been displayed. Please fill in the details.');
                        e.preventDefault();
                        return;
                    }
                    // Validate roommate names are present; email/phone/address are optional
                    for (let i = 1; i < guestCount; i++) {
                        const nameField = document.querySelector('[name="co_tenant_name_' + i + '"]');
                        if (!nameField || !nameField.value.trim()) {
                            alert('Please enter name for roommate ' + i);
                            e.preventDefault();
                            return;
                        }
                    }
                }
            } catch (err) {
                console.error('Co-guest validation error', err);
            }
        });
        
        // Set default times when modal opens
        const requestRoomModal = document.getElementById('requestRoomModal');
        if (requestRoomModal) {
            requestRoomModal.addEventListener('show.bs.modal', function(e) {
                // Set check-in time to 2:00 PM (default) if not already set
                const checkinHour = document.getElementById('modalCheckinHour');
                const checkinMinute = document.getElementById('modalCheckinMinute');
                const checkinAmpm = document.getElementById('modalCheckinAmpm');
                
                if (!checkinHour.value) {
                    checkinHour.value = 14; // 2:00 PM
                    checkinMinute.value = 0;
                    checkinAmpm.value = 'PM';
                }
                
                // Set check-out time to 11:00 AM (default) if not already set
                const checkoutHour = document.getElementById('modalCheckoutHour');
                const checkoutMinute = document.getElementById('modalCheckoutMinute');
                const checkoutAmpm = document.getElementById('modalCheckoutAmpm');
                
                if (!checkoutHour.value) {
                    checkoutHour.value = 11; // 11:00 AM
                    checkoutMinute.value = 0;
                    checkoutAmpm.value = 'AM';
                }
            });
        }
        
        // Handle dynamic co-tenant fields based on occupant count
        document.querySelectorAll('.tenant-count-input').forEach(input => {
            input.addEventListener('change', function() {
                const roomId = this.dataset.roomId;
                const count = parseInt(this.value);
                const coTenantSection = document.getElementById('co_tenants_' + roomId);
                const fieldsContainer = document.getElementById('co_tenant_fields_' + roomId);
                if (count > 1) {
                    coTenantSection.style.display = 'block';
                    let html = '';
                    for (let i = 1; i < count; i++) {
                        html += `
                            <div class="card mb-3 border-secondary">
                                <div class="card-header bg-secondary bg-opacity-10">
                                    <h6 class="mb-0"><i class="bi bi-person-badge"></i> Roommate ${i}</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-2">
                                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="co_tenant_name_${i}" placeholder="Enter roommate's full name" required>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" name="co_tenant_email_${i}" placeholder="Enter roommate's email">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Phone Number</label>
                                        <input type="tel" class="form-control" name="co_tenant_phone_${i}" placeholder="Enter roommate's phone">
                                    </div>
                                    <div class="mb-0">
                                        <label class="form-label">Address</label>
                                        <textarea class="form-control" name="co_tenant_address_${i}" rows="2" placeholder="Enter roommate's address"></textarea>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    fieldsContainer.innerHTML = html;
                } else {
                    coTenantSection.style.display = 'none';
                    fieldsContainer.innerHTML = '';
                }
            });
        });

        // Cost calculation and payment button logic
        document.querySelectorAll('.room-card').forEach(function(card) {
            const roomId = card.querySelector('input[name="room_id"]').value;
            // Get the rate from the data attribute
            let rate = 0;
            const infoDiv = card.querySelector('.room-info');
            if (infoDiv && infoDiv.dataset.rate) {
                rate = parseFloat(infoDiv.dataset.rate);
            }
            const checkinInput = card.querySelector('.checkin-date');
            const checkoutInput = card.querySelector('.checkout-date');
            const costDisplay = card.querySelector('#cost_display_' + roomId);
            const costValue = card.querySelector('#cost_value_' + roomId);
            const payBtn = card.querySelector('#pay_btn_' + roomId);
            function updateCost() {
                if (!checkinInput.value || !checkoutInput.value) {
                    costDisplay.style.display = 'none';
                    payBtn.style.display = 'none';
                    costValue.innerHTML = '';
                    return;
                }
                const checkin = new Date(checkinInput.value);
                const checkout = new Date(checkoutInput.value);
                if (checkout <= checkin) {
                    costDisplay.style.display = 'block';
                    costValue.innerHTML = '<span class="text-danger">Check-out must be after check-in.</span>';
                    payBtn.style.display = 'none';
                    return;
                }
                const diffTime = Math.abs(checkout - checkin);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                const totalCost = diffDays * rate;
                costValue.innerHTML = `<span class="fs-5 fw-bold text-success">₱${totalCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</span> <span class="text-muted">(${diffDays} night${diffDays>1?'s':''})</span>`;
                costDisplay.style.display = 'block';
                payBtn.style.display = 'inline-block';
            }
            if (checkinInput && checkoutInput) {
                checkinInput.addEventListener('change', updateCost);
                checkoutInput.addEventListener('change', updateCost);
                // Show cost if already filled (e.g. browser autofill)
                if (checkinInput.value && checkoutInput.value) updateCost();
            }
            if (payBtn) {
                payBtn.addEventListener('click', function() {
                    // Redirect to payment page with booking details
                    const params = new URLSearchParams({
                        room_id: roomId,
                        checkin: checkinInput.value,
                        checkout: checkoutInput.value,
                        total_cost: costValue.textContent.replace(/[^\d.]/g, '')
                    });
                    window.location.href = `tenant_make_payment.php?${params.toString()}`;
                });
            }
        });

        // Function to update cost display in modal based on date inputs
        function updateModalCost() {
            const checkinInput = document.getElementById('modalCheckinDate');
            const checkoutInput = document.getElementById('modalCheckoutDate');
            const roomRateText = document.getElementById('modalRoomRate');
            const costDisplay = document.getElementById('modalCostDisplay');
            const costValue = document.getElementById('modalCostValue');
            const nightCount = document.getElementById('modalNightCount');
            
            if (!checkinInput.value || !checkoutInput.value || !roomRateText) {
                costDisplay.style.display = 'none';
                return;
            }
            
            const rate = parseFloat(roomRateText.textContent.replace(/[₱,]/g, '')) || 0;
            const checkin = new Date(checkinInput.value);
            const checkout = new Date(checkoutInput.value);
            
            if (checkout <= checkin) {
                costDisplay.style.display = 'block';
                costValue.innerHTML = '<span class="text-danger">Check-out must be after check-in.</span>';
                nightCount.innerHTML = '';
                return;
            }
            
            const diffTime = Math.abs(checkout - checkin);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            const totalCost = diffDays * rate;
            
            costValue.innerHTML = `₱${totalCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
            nightCount.innerHTML = `${diffDays} night${diffDays > 1 ? 's' : ''}`;
            costDisplay.style.display = 'block';
        }

        // Populate modal with room details when Request button is clicked
        function populateModalWithRoom(button) {
            const roomId = button.getAttribute('data-room-id');
            const roomNumber = button.getAttribute('data-room-number');
            const roomType = button.getAttribute('data-room-type');
            const roomRate = button.getAttribute('data-room-rate');
            const maxOccupancy = button.getAttribute('data-max-occupancy');

            // Populate modal header info
            document.getElementById('modalRoomNumber').textContent = roomNumber;
            document.getElementById('modalRoomType').textContent = roomType;
            document.getElementById('modalRoomRate').textContent = roomRate;
            document.getElementById('modalMaxOccupancy').textContent = maxOccupancy;
            
            // Set room ID in hidden field
            document.getElementById('modalRoomId').value = roomId;
            
            // Clear form fields
            document.getElementById('requestRoomForm').reset();
            
            // Set max occupancy on guest count field
            const guestCountEl = document.getElementById('modalGuestCount');
            if (guestCountEl) {
                guestCountEl.max = maxOccupancy;
                guestCountEl.value = 1;
            }
            
            // Reset co-guests section
            const coGuestsSection = document.getElementById('modalCoGuestsSection');
            if (coGuestsSection) {
                coGuestsSection.innerHTML = '';
                coGuestsSection.style.display = 'none';
            }
            
            // Clear Flatpickr instances if they exist
            const checkinInput = document.getElementById('modalCheckinDate');
            const checkoutInput = document.getElementById('modalCheckoutDate');
            
            if (checkinInput && checkinInput._flatpickr) {
                checkinInput._flatpickr.clear();
            }
            if (checkoutInput && checkoutInput._flatpickr) {
                checkoutInput._flatpickr.clear();
            }
            
            // Pre-fill guest info with logged-in customer's details
            try {
                document.getElementById('modalTenantName').value = <?php echo json_encode($tenant_name); ?> || '';
                document.getElementById('modalTenantEmail').value = <?php echo json_encode($tenant_email); ?> || '';
                document.getElementById('modalTenantPhone').value = <?php echo json_encode($tenant_phone); ?> || '';
            } catch (e) {
                // ignore
            }

            // Add event listeners for cost calculation
            const modalCheckinInput = document.getElementById('modalCheckinDate');
            const modalCheckoutInput = document.getElementById('modalCheckoutDate');
            if (modalCheckinInput) {
                modalCheckinInput.removeEventListener('change', updateModalCost);
                modalCheckinInput.addEventListener('change', updateModalCost);
            }
            if (modalCheckoutInput) {
                modalCheckoutInput.removeEventListener('change', updateModalCost);
                modalCheckoutInput.addEventListener('change', updateModalCost);
            }
        }

        // Simple, direct roommate field rendering
        function renderRoommateFields() {
            var guestCount = document.getElementById('modalGuestCount');
            var section = document.getElementById('modalCoGuestsSection');
            
            if (!guestCount || !section) return;
            
            var count = parseInt(guestCount.value) || 1;
            
            if (count <= 1) {
                section.style.display = 'none';
                section.innerHTML = '';
                return;
            }
            
            section.style.display = 'block';
            var html = '';
            
            for (var i = 1; i < count; i++) {
                html += '<div class="card mb-3 border-secondary">' +
                    '<div class="card-header bg-secondary bg-opacity-10">' +
                    '<h6 class="mb-0"><i class="bi bi-person-badge"></i> Roommate ' + i + '</h6>' +
                    '</div>' +
                    '<div class="card-body">' +
                    '<div class="mb-2">' +
                    '<label class="form-label small">Name <span class="text-danger">*</span></label>' +
                    '<input type="text" class="form-control form-control-sm" name="co_tenant_name_' + i + '" required>' +
                    '</div>' +
                    '<div class="mb-2">' +
                    '<label class="form-label small">Email</label>' +
                    '<input type="email" class="form-control form-control-sm" name="co_tenant_email_' + i + '">' +
                    '</div>' +
                    '<div class="mb-2">' +
                    '<label class="form-label small">Phone</label>' +
                    '<input type="tel" class="form-control form-control-sm" name="co_tenant_phone_' + i + '">' +
                    '</div>' +
                    '<div class="mb-2">' +
                    '<label class="form-label small">Address</label>' +
                    '<textarea class="form-control form-control-sm" name="co_tenant_address_' + i + '" rows="2"></textarea>' +
                    '</div>' +
                    '</div>' +
                    '</div>';
            }
            
            section.innerHTML = html;
        }
        
        // Attach listener when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            var guestCountEl = document.getElementById('modalGuestCount');
            if (guestCountEl) {
                guestCountEl.addEventListener('change', renderRoommateFields);
                guestCountEl.addEventListener('input', renderRoommateFields);
            }
        });

        // Ensure pickers are fresh when modal is shown
        document.addEventListener('DOMContentLoaded', function() {
            const requestModal = document.getElementById('requestRoomModal');
            if (requestModal) {
                requestModal.addEventListener('show.bs.modal', function() {
                    // Clear values when modal opens for new request
                    const modalCheckinInput = document.getElementById('modalCheckinDate');
                    const modalCheckoutInput = document.getElementById('modalCheckoutDate');
                    if (modalCheckinInput) modalCheckinInput.value = '';
                    if (modalCheckoutInput) modalCheckoutInput.value = '';
                });
            }
        });

        // Real-time room availability refresh using API
        function refreshRoomAvailability() {
            const roomsContainer = document.getElementById('roomsContainer');
            const filterSelect = document.getElementById('filterType');
            
            if (!roomsContainer) return;
            
            // Fetch room data from API
            fetch('api_get_available_rooms.php', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => {
                if (!response.ok) throw new Error('API Error: ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success && data.rooms) {
                    // Rebuild room grid with updated data
                    let html = '';
                    
                    if (data.rooms.length === 0) {
                        html = '<div class="alert alert-info w-100">No rooms available at the moment.</div>';
                    } else {
                        data.rooms.forEach(room => {
                            if (room.available) {
                                const roomType = room.room_type.toLowerCase();
                                let maxOccupancy = 4;
                                if (roomType === 'single') maxOccupancy = 1;
                                else if (roomType === 'double') maxOccupancy = 2;
                                else if (roomType === 'family') maxOccupancy = 5;
                                
                                const imageUrl = room.image_url || 'public/img/room-placeholder.png';
                                const matchesFilter = !filterSelect || !filterSelect.value || room.room_type === filterSelect.value;
                                const displayStyle = matchesFilter ? '' : 'style="display: none;" data-hidden="true"';
                                
                                html += `
                                    <div class="room-card available" data-rate="${room.rate}" data-room-type="${room.room_type}" ${displayStyle}>
                                        <img src="${imageUrl}" alt="Room ${room.room_number}">
                                        <div class="room-card-content">
                                            <h6>${room.room_number}</h6>
                                            <p><strong>${room.room_type}</strong></p>
                                            <p><strong>₱${room.rate.toLocaleString('en-PH', {maximumFractionDigits: 0})}/night</strong></p>
                                            <p class="text-success" style="margin-bottom: 0.5rem;">● Available</p>
                                            <button class="btn btn-sm btn-primary w-100" onclick="showRequestModal(${room.id}, '${room.room_number}', '${room.room_type}', ${room.rate})">
                                                Request This Room
                                            </button>
                                        </div>
                                    </div>
                                `;
                            }
                        });
                    }
                    
                    roomsContainer.innerHTML = html;
                }
            })
            .catch(error => {
                console.log('Room availability check error (non-critical):', error.message);
            });
        }
        
        // Start real-time refresh - check every 10 seconds
        document.addEventListener('DOMContentLoaded', function() {
            // Only start refresh if on the room selection page
            const roomsContainer = document.getElementById('roomsContainer');
            if (roomsContainer) {
                // Initial check after 2 seconds
                setTimeout(refreshRoomAvailability, 2000);
                
                // Then refresh every 10 seconds
                setInterval(refreshRoomAvailability, 10000);
            }
        });
    </script>
</body>
</html>
