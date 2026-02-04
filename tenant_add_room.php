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
try {
    $status_stmt = $conn->prepare("SELECT room_id FROM tenants WHERE id = :tenant_id");
    $status_stmt->execute(['tenant_id' => $tenant_id]);
    $tenant_status = $status_stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_has_room = ($tenant_status && !empty($tenant_status['room_id']));
} catch (Exception $e) {
    $tenant_has_room = false;
}

// Handle room request submission
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
                        $checkin_date = isset($_POST['checkin_date']) ? $_POST['checkin_date'] : null;
                        $checkout_date = isset($_POST['checkout_date']) ? $_POST['checkout_date'] : null;
                        $stmt = $conn->prepare("
                            INSERT INTO room_requests (tenant_id, room_id, tenant_count, tenant_info_name, tenant_info_email, tenant_info_phone, tenant_info_address, notes, status, checkin_date, checkout_date)
                            VALUES (:tenant_id, :room_id, :tenant_count, :tenant_info_name, :tenant_info_email, :tenant_info_phone, :tenant_info_address, :notes, 'pending_payment', :checkin_date, :checkout_date)
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
                            'checkout_date' => $checkout_date
                        ]);
                        $roomRequestId = $conn->lastInsertId();

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
                        $billing_month = $checkin_date ? (new DateTime($checkin_date))->format('Y-m') : date('Y-m');
                        $due_date = $checkin_date ? (new DateTime($checkin_date))->format('Y-m-d') : date('Y-m-d');
                        $bill_stmt = $conn->prepare("
                            INSERT INTO bills (tenant_id, room_id, billing_month, amount_due, due_date, status, notes, created_at, updated_at)
                            VALUES (:tenant_id, :room_id, :billing_month, :amount_due, :due_date, 'pending', :notes, NOW(), NOW())
                        ");
                        $bill_stmt->execute([
                            'tenant_id' => $tenant_id,
                            'room_id' => $room_id,
                            'billing_month' => $billing_month,
                            'amount_due' => $total_cost,
                            'due_date' => $due_date,
                            'notes' => $bill_notes
                        ]);

                        // Save co-tenants if this is a shared/bedspace room with multiple occupants
                        if ($tenant_count > 1) {
                            for ($i = 1; $i < $tenant_count; $i++) {
                                $co_name = isset($_POST['co_tenant_name_' . $i]) ? trim($_POST['co_tenant_name_' . $i]) : '';
                                $co_email = isset($_POST['co_tenant_email_' . $i]) ? trim($_POST['co_tenant_email_' . $i]) : '';
                                $co_phone = isset($_POST['co_tenant_phone_' . $i]) ? trim($_POST['co_tenant_phone_' . $i]) : '';
                                $co_address = isset($_POST['co_tenant_address_' . $i]) ? trim($_POST['co_tenant_address_' . $i]) : '';

                                if (!empty($co_name)) {
                                    $co_stmt = $conn->prepare("
                                        INSERT INTO co_tenants (primary_tenant_id, room_id, name, email, phone, address) 
                                        VALUES (:primary_tenant_id, :room_id, :name, :email, :phone, :address)
                                    ");
                                    $co_stmt->execute([
                                        'primary_tenant_id' => $tenant_id,
                                        'room_id' => $room_id,
                                        'name' => $co_name,
                                        'email' => $co_email,
                                        'phone' => $co_phone,
                                        'address' => $co_address
                                    ]);
                                }
                            }
                        }

                        $conn->commit();
                        
                        // Do NOT notify admins yet; wait for payment
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
            COALESCE(COUNT(DISTINCT t.id), 0) as tenant_count,
            COALESCE(COUNT(DISTINCT ct.id), 0) as co_tenant_count
        FROM rooms r
        LEFT JOIN tenants t ON r.id = t.room_id AND t.status = 'active'
        LEFT JOIN co_tenants ct ON r.id = ct.room_id
        GROUP BY r.id
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
        .room-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s;
        }
        .room-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
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
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 sidebar">
                <div class="position-sticky pt-3">
                    <!-- User Info -->
                    <div class="user-info">
                        <h5><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION["name"]); ?></h5>
                        <p><?php echo htmlspecialchars($_SESSION["email"]); ?></p>
                    </div>

                    <!-- Navigation -->
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_dashboard.php">
                                <i class="bi bi-house-door"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_bills.php">
                                <i class="bi bi-receipt"></i> My Bills
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_payments.php">
                                <i class="bi bi-coin"></i> Payments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_maintenance.php">
                                <i class="bi bi-tools"></i> Maintenance
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="tenant_add_room.php">
                                <i class="bi bi-search"></i> Browse Room
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_profile.php">
                                <i class="bi bi-person"></i> My Profile
                            </a>
                        </li>
                    </ul>

                    <!-- Logout Button -->
                    <form action="logout.php" method="post">
                        <button type="submit" class="btn btn-logout">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </form>
                </div>
            </nav>

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

                <!-- Two Column Layout -->
                <div class="row">
                    <!-- Filters Section -->
                    <div class="col-lg-4 mb-4">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <i class="bi bi-funnel"></i> Filter Rooms
                            </div>
                            <div class="card-body">
                                <form id="roomFilterForm">
                                    <div class="mb-3">
                                        <label for="filterType" class="form-label">Room Type</label>
                                        <select class="form-select" id="filterType" name="type">
                                            <option value="">All</option>
                                            <option value="Single">Single</option>
                                            <option value="Shared">Shared</option>
                                            <option value="Bedspace">Bedspace</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="filterPrice" class="form-label">Max Price (₱)</label>
                                        <input type="number" class="form-control" id="filterPrice" name="price" min="0" placeholder="No limit">
                                    </div>
                                    <div class="mb-3">
                                        <label for="filterGuests" class="form-label">Max Guests</label>
                                        <input type="number" class="form-control" id="filterGuests" name="guests" min="1" placeholder="No limit">
                                    </div>
                                    <button type="button" class="btn btn-primary w-100" onclick="filterRooms()"><i class="bi bi-search"></i> Apply Filters</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- Available Rooms Section -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="bi bi-building"></i> Available Rooms</h5>
                            </div>
                            <div class="card-body" id="roomsContainer">
                                <?php if (empty($rooms)): ?>
                                    <div class="alert alert-info">No rooms available at the moment.</div>
                                <?php else: ?>
                                    <?php foreach ($rooms as $room): ?>
                                        <?php
                                        // Calculate actual occupancy and status
                                        $total_occupancy = intval($room['tenant_count']) + intval($room['co_tenant_count']);
                                        
                                        // Check database status first (for unavailable rooms)
                                        if ($room['status'] === 'unavailable') {
                                            $actual_status = 'unavailable';
                                            $status_label = 'Unavailable (Maintenance)';
                                        } else {
                                            $actual_status = $total_occupancy > 0 ? 'occupied' : 'available';
                                            $status_label = ucfirst($actual_status);
                                        }
                                        
                                        $room_type = strtolower($room['room_type']);
                                        $max_occupancy = 4;
                                        if ($room_type === 'single') $max_occupancy = 1;
                                        elseif ($room_type === 'shared') $max_occupancy = 2;
                                        elseif ($room_type === 'bedspace') $max_occupancy = 4;
                                        ?>
                                        <?php if ($actual_status === 'available'): ?>
                                        <div class="room-card <?php echo htmlspecialchars(strtolower($actual_status)); ?>">
                                            <div class="room-info row" data-rate="<?php echo htmlspecialchars($room['rate']); ?>">
                                                <div class="col-md-4">
                                                    <!-- Room Images Placeholder -->
                                                    <img src="<?php echo !empty($room['image_url']) ? htmlspecialchars($room['image_url']) : 'public/img/room-placeholder.png'; ?>" class="img-fluid rounded mb-2" alt="Room Image">
                                                </div>
                                                <div class="col-md-8">
                                                    <h5><?php echo htmlspecialchars($room['room_number']); ?></h5>
                                                    <p><strong>Type:</strong> <?php echo htmlspecialchars($room['room_type']); ?></p>
                                                    <p><strong>Price per Night:</strong> ₱<?php echo number_format($room['rate'], 2); ?></p>
                                                    <p><strong>Description:</strong> <?php echo htmlspecialchars($room['description']); ?></p>
                                                    <p><strong>Max Guests:</strong> <?php echo $max_occupancy; ?></p>
                                                    <div class="status-badge status-<?php echo htmlspecialchars(strtolower($actual_status)); ?>">
                                                        <?php echo htmlspecialchars($status_label); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Collapsible Form for available rooms -->
                                                <?php if ($tenant_has_room): ?>
                                                    <div class="alert alert-warning mt-3 mb-0">
                                                        <i class="bi bi-exclamation-triangle"></i> 
                                                        <strong>Room Already Assigned</strong><br>
                                                        You already have a room assigned to you. You cannot request another room while your current room is active. Please contact admin if you need to change rooms.
                                                    </div>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-primary mt-3 w-100" type="button" data-bs-toggle="collapse" data-bs-target="#room-form-<?php echo htmlspecialchars($room['id']); ?>" aria-expanded="false">
                                                        <i class="bi bi-plus-circle"></i> Request Room
                                                    </button>

                                                    <div class="collapse mt-3" id="room-form-<?php echo htmlspecialchars($room['id']); ?>">
                                                <form method="POST" class="border-top pt-3">
                                                    <input type="hidden" name="action" value="request_room">
                                                    <input type="hidden" name="room_id" value="<?php echo htmlspecialchars($room['id']); ?>">

                                                    <h6 class="mb-3"><i class="bi bi-person-check"></i> Guest Information</h6>

                                                    <div class="mb-3">
                                                        <label for="name_<?php echo htmlspecialchars($room['id']); ?>" class="form-label">Full Name <span class="text-danger">*</span></label>
                                                        <input type="text" class="form-control" id="name_<?php echo htmlspecialchars($room['id']); ?>" name="tenant_info_name" required placeholder="Enter your full name">
                                                    </div>

                                                    <div class="mb-3">
                                                        <label for="email_<?php echo htmlspecialchars($room['id']); ?>" class="form-label">Email <span class="text-danger">*</span></label>
                                                        <input type="email" class="form-control" id="email_<?php echo htmlspecialchars($room['id']); ?>" name="tenant_info_email" required placeholder="Enter your email">
                                                    </div>

                                                    <div class="mb-3">
                                                        <label for="phone_<?php echo htmlspecialchars($room['id']); ?>" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                                        <input type="tel" class="form-control" id="phone_<?php echo htmlspecialchars($room['id']); ?>" name="tenant_info_phone" required placeholder="Enter your phone number">
                                                    </div>

                                                    <div class="mb-3">
                                                        <label for="address_<?php echo htmlspecialchars($room['id']); ?>" class="form-label">Address <span class="text-danger">*</span></label>
                                                        <textarea class="form-control" id="address_<?php echo htmlspecialchars($room['id']); ?>" name="tenant_info_address" rows="2" required placeholder="Enter your address"></textarea>
                                                    </div>


                                                    <div class="mb-3">
                                                        <label for="checkin_<?php echo htmlspecialchars($room['id']); ?>" class="form-label">Check-in Date & Time <span class="text-danger">*</span></label>
                                                        <input type="text" class="form-control checkin-date" id="checkin_<?php echo htmlspecialchars($room['id']); ?>" name="checkin_date" required data-room-id="<?php echo htmlspecialchars($room['id']); ?>">
                                                    </div>

                                                    <div class="mb-3">
                                                        <label for="checkout_<?php echo htmlspecialchars($room['id']); ?>" class="form-label">Check-out Date & Time <span class="text-danger">*</span></label>
                                                        <input type="text" class="form-control checkout-date" id="checkout_<?php echo htmlspecialchars($room['id']); ?>" name="checkout_date" required data-room-id="<?php echo htmlspecialchars($room['id']); ?>">
                                                    </div>

                                                    <div class="mb-3" id="cost_display_<?php echo htmlspecialchars($room['id']); ?>" style="display:none;">
                                                        <label class="form-label"><strong>Total Cost of Stay:</strong></label>
                                                        <div class="alert alert-info mb-2" id="cost_value_<?php echo htmlspecialchars($room['id']); ?>"></div>

                                                    </div>

                                                    <div class="mb-3">
                                                        <label for="guest_count_<?php echo htmlspecialchars($room['id']); ?>" class="form-label">Number of Guests <span class="text-danger">*</span></label>
                                                        <input type="number" class="form-control guest-count-input" id="guest_count_<?php echo htmlspecialchars($room['id']); ?>" name="guest_count" min="1" max="<?php echo $max_occupancy; ?>" value="1" required data-room-id="<?php echo htmlspecialchars($room['id']); ?>">
                                                        <small class="text-muted">Maximum <?php echo $max_occupancy; ?> guest(s) for this room type</small>
                                                    </div>

                                                    <!-- Co-Guests Section (shown when guests > 1) -->
                                                    <div class="co-guests-section" id="co_guests_<?php echo htmlspecialchars($room['id']); ?>" style="display: none;">
                                                        <div class="alert alert-info">
                                                            <i class="bi bi-info-circle"></i> Please provide information for all guests. You will be the primary guest responsible for payments.
                                                        </div>
                                                        <div id="co_guest_fields_<?php echo htmlspecialchars($room['id']); ?>"></div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label for="notes_<?php echo htmlspecialchars($room['id']); ?>" class="form-label">Notes (Optional)</label>
                                                        <textarea class="form-control" id="notes_<?php echo htmlspecialchars($room['id']); ?>" name="notes" rows="2" placeholder="Add any notes about your request..."></textarea>
                                                    </div>

                                                    <button type="submit" class="btn btn-success w-100">
                                                        <i class="bi bi-check-circle"></i> Submit Request
                                                    </button>
                                                </form>
                                            </div>
                                                <?php endif; ?>
                                            <?php elseif ($actual_status === 'unavailable'): ?>
                                            <div class="alert alert-warning mt-3 mb-0">
                                                <i class="bi bi-exclamation-triangle"></i>
                                                <strong>Unavailable:</strong> This room is currently under maintenance. Please check back later.
                                            </div>
                                            <?php elseif ($actual_status === 'occupied'): ?>
                                            <button class="btn btn-sm btn-outline-secondary mt-3 w-100 disabled">
                                                <i class="bi bi-ban"></i> Room Occupied
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
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
                    const price = document.getElementById('filterPrice').value;
                    const guests = document.getElementById('filterGuests').value;
                    const cards = document.querySelectorAll('.room-card');
                    cards.forEach(card => {
                        let show = true;
                        if (type && !card.innerHTML.includes(type)) show = false;
                        if (price) {
                            const priceText = card.querySelector('.rate')?.textContent.replace(/[^\d.]/g, '') || '0';
                            if (parseFloat(priceText) > parseFloat(price)) show = false;
                        }
                        if (guests) {
                            const maxGuestsText = card.innerHTML.match(/Max Guests:\s*(\d+)/);
                            if (maxGuestsText && parseInt(maxGuestsText[1]) > parseInt(guests)) show = false;
                        }
                        card.style.display = show ? '' : 'none';
                    });
                }
                </script>
                                <!-- Flatpickr CSS & JS -->
                                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
                                <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
                                <script>
                                    // Initialize Flatpickr for both check-in and check-out fields (both editable)
                                    document.querySelectorAll('.checkin-date, .checkout-date').forEach(function(input) {
                                        flatpickr(input, {
                                            enableTime: true,
                                            dateFormat: "Y-m-d h:i K", // 12-hour format with AM/PM
                                            time_24hr: false,
                                            minuteIncrement: 1,
                                            allowInput: true
                                        });
                                    });
                                </script>
                            </div>
                        </div>
                    </div>

                    <!-- My Requests Section (Improved UI) -->
                    <div class="col-12 mt-4">
                        <div class="card shadow-lg border-primary" style="max-width: 500px; margin: 0 auto;">
                            <div class="card-header bg-primary text-white text-center" style="font-size: 1.5rem; font-weight: bold; letter-spacing: 1px;">
                                <i class="bi bi-clock-history"></i> My Requests
                            </div>
                            <div class="card-body" style="background: #fafdff;">
                                <?php if (empty($my_requests)): ?>
                                    <p class="text-muted text-center">You haven't submitted any room requests yet.</p>
                                <?php else: ?>
                                    <?php foreach ($my_requests as $request): ?>
                                        <div class="request-card mb-4 p-3 border border-2 rounded-3" style="background: #f4f8ff;">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="fs-5 fw-bold text-primary">Room <?php echo htmlspecialchars($request['room_number']); ?></span>
                                                <span class="request-status request-<?php echo htmlspecialchars(strtolower($request['status'])); ?> px-3 py-1" style="font-size:1rem;">
                                                    <?php
                                                        if ($request['status'] === 'pending_payment') {
                                                            echo 'Awaiting Payment';
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
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
    </script>
</body>
</html>
