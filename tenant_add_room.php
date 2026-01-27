<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "tenant") {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";

$tenant_id = $_SESSION["tenant_id"];
$message = '';
$message_type = '';

// Handle room request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_room') {
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
                    $stmt = $conn->prepare("
                        INSERT INTO room_requests (tenant_id, room_id, tenant_count, tenant_info_name, tenant_info_email, tenant_info_phone, tenant_info_address, notes, status) 
                        VALUES (:tenant_id, :room_id, :tenant_count, :tenant_info_name, :tenant_info_email, :tenant_info_phone, :tenant_info_address, :notes, 'pending')
                    ");
                    $stmt->execute([
                        'tenant_id' => $tenant_id,
                        'room_id' => $room_id,
                        'tenant_count' => $tenant_count,
                        'tenant_info_name' => $tenant_info_name,
                        'tenant_info_email' => $tenant_info_email,
                        'tenant_info_phone' => $tenant_info_phone,
                        'tenant_info_address' => $tenant_info_address,
                        'notes' => $notes
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
                    $message = "Room request submitted successfully! The admin will review your request soon.";
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

// Fetch all rooms with availability status (including co-tenants)
try {
    $stmt = $conn->prepare("
        SELECT 
            r.id,
            r.room_number,
            r.room_type,
            r.description,
            r.rate,
            r.status,
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
                                <i class="bi bi-plus-square"></i> Add Room
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
                    <h1><i class="bi bi-plus-square"></i> Room Request</h1>
                    <p class="mb-0">Browse and request available rooms</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Two Column Layout -->
                <div class="row">
                    <!-- Available Rooms Section -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="bi bi-building"></i> Available Rooms</h5>
                            </div>
                            <div class="card-body">
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
                                        <div class="room-card <?php echo htmlspecialchars(strtolower($actual_status)); ?>">
                                            <div class="room-info">
                                                <div class="room-details">
                                                    <h5><?php echo htmlspecialchars($room['room_number']); ?></h5>
                                                    <?php if ($room['room_type']): ?>
                                                        <p><strong>Type:</strong> <?php echo htmlspecialchars($room['room_type']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($room['description']): ?>
                                                        <p><strong>Description:</strong> <?php echo htmlspecialchars($room['description']); ?></p>
                                                    <?php endif; ?>
                                                    <p><strong>Current Occupancy:</strong> <?php echo $total_occupancy; ?> person(s)</p>
                                                    <p><strong>Max Occupancy:</strong> <?php echo $max_occupancy; ?> person(s)</p>
                                                </div>
                                                <div class="text-end">
                                                    <div class="rate">₱<?php echo number_format($room['rate'], 2); ?></div>
                                                    <div class="status-badge status-<?php echo htmlspecialchars(strtolower($actual_status)); ?>">
                                                        <?php echo htmlspecialchars($status_label); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Collapsible Form - Only show for available rooms -->
                                            <?php if ($actual_status === 'available'): ?>
                                            <button class="btn btn-sm btn-outline-primary mt-3 w-100" type="button" data-bs-toggle="collapse" data-bs-target="#room-form-<?php echo htmlspecialchars($room['id']); ?>" aria-expanded="false">
                                                <i class="bi bi-plus-circle"></i> Request Room
                                            </button>

                                            <div class="collapse mt-3" id="room-form-<?php echo htmlspecialchars($room['id']); ?>">
                                                <form method="POST" class="border-top pt-3">
                                                    <input type="hidden" name="action" value="request_room">
                                                    <input type="hidden" name="room_id" value="<?php echo htmlspecialchars($room['id']); ?>">

                                                    <h6 class="mb-3"><i class="bi bi-person-check"></i> Occupant Information</h6>

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
                                                        <label for="tenant_count_<?php echo htmlspecialchars($room['id']); ?>" class="form-label">Number of Occupants <span class="text-danger">*</span></label>
                                                        <input type="number" class="form-control tenant-count-input" id="tenant_count_<?php echo htmlspecialchars($room['id']); ?>" name="tenant_count" min="1" max="<?php echo $max_occupancy; ?>" value="1" required data-room-id="<?php echo htmlspecialchars($room['id']); ?>">
                                                        <small class="text-muted">Maximum <?php echo $max_occupancy; ?> person(s) for this room type</small>
                                                    </div>

                                                    <!-- Co-Tenants Section (shown when occupants > 1) -->
                                                    <div class="co-tenants-section" id="co_tenants_<?php echo htmlspecialchars($room['id']); ?>" style="display: none;">
                                                        <div class="alert alert-info">
                                                            <i class="bi bi-info-circle"></i> Please provide information for all roommates. You will be the primary tenant responsible for payments.
                                                        </div>
                                                        <div id="co_tenant_fields_<?php echo htmlspecialchars($room['id']); ?>"></div>
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

                    <!-- My Requests Section -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="bi bi-clock-history"></i> My Requests</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($my_requests)): ?>
                                    <p class="text-muted">You haven't submitted any room requests yet.</p>
                                <?php else: ?>
                                    <?php foreach ($my_requests as $request): ?>
                                        <div class="request-card">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="mb-0"><?php echo htmlspecialchars($request['room_number']); ?></h6>
                                                <span class="request-status request-<?php echo htmlspecialchars(strtolower($request['status'])); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($request['status'])); ?>
                                                </span>
                                            </div>
                                            <p class="mb-1"><strong>Rate:</strong> $<?php echo number_format($request['rate'], 2); ?></p>
                                            <p class="mb-1"><strong>Occupants:</strong> <?php echo intval($request['tenant_count'] ?? 1); ?> person(s)</p>
                                            <p class="mb-1"><strong>Requested:</strong> <?php echo date('M d, Y', strtotime($request['request_date'])); ?></p>
                                            <?php if (!empty($request['tenant_info_name'])): ?>
                                                <p class="mb-1 text-muted small"><strong>Name:</strong> <?php echo htmlspecialchars($request['tenant_info_name']); ?></p>
                                            <?php endif; ?>
                                            <?php if ($request['notes']): ?>
                                                <p class="mb-0 text-muted small"><strong>Notes:</strong> <?php echo htmlspecialchars($request['notes']); ?></p>
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
    </script>
</body>
</html>
