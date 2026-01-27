<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

$message = '';
$message_type = '';

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
    $action = $_POST['action'];

    if ($request_id > 0 && in_array($action, ['approve', 'reject'])) {
        try {
            if ($action === 'approve') {
                // Get room request details
                $request_stmt = $conn->prepare("
                    SELECT rr.*, r.room_number, r.rate, t.name as existing_tenant_name
                    FROM room_requests rr
                    JOIN rooms r ON rr.room_id = r.id
                    LEFT JOIN tenants t ON rr.tenant_id = t.id
                    WHERE rr.id = :id
                ");
                $request_stmt->execute(['id' => $request_id]);
                $request = $request_stmt->fetch(PDO::FETCH_ASSOC);

                if ($request) {
                    $conn->beginTransaction();
                    
                    // Get the existing tenant's initial values if not provided in request
                    $tenant_name = !empty($request['tenant_info_name']) ? $request['tenant_info_name'] : $request['existing_tenant_name'];
                    $tenant_email = !empty($request['tenant_info_email']) ? $request['tenant_info_email'] : '';
                    $tenant_phone = !empty($request['tenant_info_phone']) ? $request['tenant_info_phone'] : '';

                    // STEP 1: Update tenant to pending payment status (not active yet)
                    // Set room_id but status remains 'inactive' until payment is confirmed
                    $tenant_update = $conn->prepare("
                        UPDATE tenants 
                        SET room_id = :room_id, 
                            name = :name,
                            email = :email,
                            phone = :phone
                        WHERE id = :tenant_id
                    ");
                    $tenant_update->execute([
                        'room_id' => $request['room_id'],
                        'name' => $tenant_name,
                        'email' => $tenant_email,
                        'phone' => $tenant_phone,
                        'tenant_id' => $request['tenant_id']
                    ]);

                    // STEP 2: Keep room status as 'available' until payment is confirmed
                    // Room will be marked as 'occupied' after payment verification

                    // STEP 3: Update room request status to 'pending_payment'
                    $request_update = $conn->prepare("
                        UPDATE room_requests 
                        SET status = 'pending_payment',
                            approved_date = NOW()
                        WHERE id = :id
                    ");
                    $request_update->execute(['id' => $request_id]);

                    // STEP 4: Create initial advance payment bill
                    // Advance payment = 1 month rent
                    $advance_amount = $request['rate'];
                    $billing_month = date('Y-m-01'); // First of current month
                    $due_date = date('Y-m-t'); // Last day of current month
                    
                    $bill_insert = $conn->prepare("
                        INSERT INTO bills (
                            tenant_id, room_id, billing_month, amount_due, 
                            amount_paid, status, due_date, notes
                        ) VALUES (
                            :tenant_id, :room_id, :billing_month, :amount_due,
                            0, 'unpaid', :due_date, :notes
                        )
                    ");
                    
                    $bill_insert->execute([
                        'tenant_id' => $request['tenant_id'],
                        'room_id' => $request['room_id'],
                        'billing_month' => $billing_month,
                        'amount_due' => $advance_amount,
                        'due_date' => $due_date,
                        'notes' => 'ADVANCE PAYMENT - Move-in fee (1 month rent)'
                    ]);
                    
                    $bill_id = $conn->lastInsertId();
                    
                    // Create a payment record to track this as move-in advance payment
                    $payment_insert = $conn->prepare("
                        INSERT INTO payment_transactions (
                            bill_id, tenant_id, payment_amount, payment_type, 
                            payment_status, notes, created_at
                        ) VALUES (
                            :bill_id, :tenant_id, 0, 'online', 'pending', 
                            'Move-in advance payment pending', NOW()
                        )
                    ");
                    
                    $payment_insert->execute([
                        'bill_id' => $bill_id,
                        'tenant_id' => $request['tenant_id']
                    ]);

                    $conn->commit();
                    
                    $message = "Room request approved! Advance payment bill created for " . htmlspecialchars($request['room_number']) . 
                               ". Tenant must complete payment (₱" . number_format($advance_amount, 2) . 
                               ") before move-in.";
                    $message_type = "success";
                } else {
                    $conn->rollBack();
                    $message = "Error: Room request not found.";
                    $message_type = "danger";
                }
            } else {
                // Reject request - also delete associated co-tenants
                // Get room_id and tenant_id first
                $get_request = $conn->prepare("SELECT room_id, tenant_id FROM room_requests WHERE id = :id");
                $get_request->execute(['id' => $request_id]);
                $request_info = $get_request->fetch(PDO::FETCH_ASSOC);
                
                if ($request_info) {
                    // Delete co-tenants for this request
                    $delete_co_tenants = $conn->prepare("
                        DELETE FROM co_tenants 
                        WHERE room_id = :room_id AND primary_tenant_id = :tenant_id
                    ");
                    $delete_co_tenants->execute([
                        'room_id' => $request_info['room_id'],
                        'tenant_id' => $request_info['tenant_id']
                    ]);
                }
                
                // Update request status to rejected
                $stmt = $conn->prepare("
                    UPDATE room_requests 
                    SET status = :status 
                    WHERE id = :id
                ");
                $stmt->execute([
                    'status' => $new_status,
                    'id' => $request_id
                ]);

                $message = "Room request rejected successfully! Co-tenant records have been removed.";
                $message_type = "success";
            }
        } catch (Exception $e) {
            $message = "Error updating request: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Fetch all room requests with related data
try {
    $filter_status = isset($_GET['status']) ? $_GET['status'] : '';
    
    // Try the new query with additional columns (after migration)
    $sql = "
        SELECT 
            rr.id,
            rr.tenant_id,
            rr.room_id,
            rr.request_date,
            rr.status,
            rr.notes,
            COALESCE(rr.tenant_count, 1) as tenant_count,
            COALESCE(rr.tenant_info_name, '') as tenant_info_name,
            COALESCE(rr.tenant_info_email, '') as tenant_info_email,
            COALESCE(rr.tenant_info_phone, '') as tenant_info_phone,
            COALESCE(rr.tenant_info_address, '') as tenant_info_address,
            t.name as tenant_name,
            t.email as tenant_email,
            t.phone as tenant_phone,
            r.room_number,
            r.room_type,
            r.rate,
            r.status as room_status
        FROM room_requests rr
        JOIN tenants t ON rr.tenant_id = t.id
        JOIN rooms r ON rr.room_id = r.id
        WHERE 1=1
    ";

    if ($filter_status && in_array($filter_status, ['pending', 'pending_payment', 'approved', 'rejected'])) {
        $sql .= " AND rr.status = :status";
    }

    $sql .= " ORDER BY rr.request_date DESC";

    $stmt = $conn->prepare($sql);
    
    if ($filter_status) {
        $stmt->execute(['status' => $filter_status]);
    } else {
        $stmt->execute();
    }
    
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If query fails due to missing columns (before migration), try fallback query
    if (strpos($e->getMessage(), 'Unknown column') !== false) {
        try {
            $filter_status = isset($_GET['status']) ? $_GET['status'] : '';
            
            // Fallback query without new columns (before migration)
            $sql = "
                SELECT 
                    rr.id,
                    rr.tenant_id,
                    rr.room_id,
                    rr.request_date,
                    rr.status,
                    rr.notes,
                    1 as tenant_count,
                    '' as tenant_info_name,
                    '' as tenant_info_email,
                    '' as tenant_info_phone,
                    '' as tenant_info_address,
                    t.name as tenant_name,
                    t.email as tenant_email,
                    t.phone as tenant_phone,
                    r.room_number,
                    r.room_type,
                    r.rate,
                    r.status as room_status
                FROM room_requests rr
                JOIN tenants t ON rr.tenant_id = t.id
                JOIN rooms r ON rr.room_id = r.id
                WHERE 1=1
            ";
            
            if ($filter_status && in_array($filter_status, ['pending', 'pending_payment', 'approved', 'rejected'])) {
                $sql .= " AND rr.status = :status";
            }

            $sql .= " ORDER BY rr.request_date DESC";

            $stmt = $conn->prepare($sql);
            
            if ($filter_status) {
                $stmt->execute(['status' => $filter_status]);
            } else {
                $stmt->execute();
            }
            
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Show migration notice
            $message = "⚠️ <strong>Database Migration Required:</strong> Please run the migration script at <code>db/migrate_room_occupancy.php</code> to enable the new occupancy features.";
            $message_type = "warning";
        } catch (Exception $fallback_error) {
            $message = "Error loading requests: " . $fallback_error->getMessage();
            $message_type = "danger";
            $requests = [];
        }
    } else {
        $message = "Error loading requests: " . $e->getMessage();
        $message_type = "danger";
        $requests = [];
    }
} catch (Exception $e) {
    $message = "Error loading requests: " . $e->getMessage();
    $message_type = "danger";
    $requests = [];
}

// Get statistics
try {
    $total_stmt = $conn->query("SELECT COUNT(*) as count FROM room_requests");
    $total_count = $total_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $pending_stmt = $conn->query("SELECT COUNT(*) as count FROM room_requests WHERE status = 'pending'");
    $pending_count = $pending_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $pending_payment_stmt = $conn->query("SELECT COUNT(*) as count FROM room_requests WHERE status = 'pending_payment'");
    $pending_payment_count = $pending_payment_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $approved_stmt = $conn->query("SELECT COUNT(*) as count FROM room_requests WHERE status = 'approved'");
    $approved_count = $approved_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $rejected_stmt = $conn->query("SELECT COUNT(*) as count FROM room_requests WHERE status = 'rejected'");
    $rejected_count = $rejected_stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (Exception $e) {
    $total_count = $pending_count = $pending_payment_count = $approved_count = $rejected_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Requests Queue - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .stat-card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }
        .stat-label {
            color: #666;
            font-size: 0.95rem;
        }
        .request-row {
            border-bottom: 1px solid #e0e0e0;
            padding: 1rem 0;
            transition: background-color 0.2s;
        }
        .request-row:hover {
            background-color: #f8f9fa;
        }
        .request-row:last-child {
            border-bottom: none;
        }
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-pending {
            background-color: #cfe2ff;
            color: #084298;
        }
        .status-pending_payment {
            background-color: #fff3cd;
            color: #664d03;
        }
        .status-approved {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .status-rejected {
            background-color: #f8d7da;
            color: #842029;
        }
        .room-info {
            font-size: 0.95rem;
        }
        .room-number {
            font-weight: 600;
            color: #333;
        }
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        .action-buttons form {
            display: inline;
        }
        .filter-btn {
            margin-right: 0.5rem;
        }
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <!-- Header -->
            <div class="header-banner">
                <h1><i class="bi bi-door-closed"></i> Room Requests Queue</h1>
                <p class="mb-0">Manage tenant room change requests</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="stat-card bg-white">
                        <div class="stat-value text-primary"><?php echo intval($total_count); ?></div>
                        <div class="stat-label">Total Requests</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card bg-white">
                        <div class="stat-value text-warning"><?php echo intval($pending_count); ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card bg-white">
                        <div class="stat-value text-info"><?php echo intval($pending_payment_count); ?></div>
                        <div class="stat-label">Awaiting Payment</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card bg-white">
                        <div class="stat-value text-success"><?php echo intval($approved_count); ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card bg-white">
                        <div class="stat-value text-danger"><?php echo intval($rejected_count); ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>
            </div>

            <!-- Filter Buttons -->
            <div class="mb-3">
                <a href="room_requests_queue.php" class="btn btn-sm btn-outline-secondary filter-btn <?php echo !isset($_GET['status']) ? 'active' : ''; ?>">
                    <i class="bi bi-funnel"></i> All Requests
                </a>
                <a href="room_requests_queue.php?status=pending" class="btn btn-sm btn-outline-warning filter-btn <?php echo (isset($_GET['status']) && $_GET['status'] === 'pending') ? 'active' : ''; ?>">
                    <i class="bi bi-clock"></i> Pending
                </a>
                <a href="room_requests_queue.php?status=pending_payment" class="btn btn-sm btn-outline-info filter-btn <?php echo (isset($_GET['status']) && $_GET['status'] === 'pending_payment') ? 'active' : ''; ?>">
                    <i class="bi bi-cash-coin"></i> Awaiting Payment
                </a>
                <a href="room_requests_queue.php?status=approved" class="btn btn-sm btn-outline-success filter-btn <?php echo (isset($_GET['status']) && $_GET['status'] === 'approved') ? 'active' : ''; ?>">
                    <i class="bi bi-check-circle"></i> Approved
                </a>
                <a href="room_requests_queue.php?status=rejected" class="btn btn-sm btn-outline-danger filter-btn <?php echo (isset($_GET['status']) && $_GET['status'] === 'rejected') ? 'active' : ''; ?>">
                    <i class="bi bi-x-circle"></i> Rejected
                </a>
            </div>

            <!-- Requests List -->
            <div class="card">
                <div class="card-body">
                    <?php if (empty($requests)): ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle"></i> No room requests found.
                        </div>
                    <?php else: ?>
                        <?php foreach ($requests as $request): ?>
                            <div class="request-row">
                                <div class="row align-items-center">
                                    <!-- Request Info -->
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <h6 class="mb-1">
                                                <i class="bi bi-person"></i> 
                                                <strong><?php echo htmlspecialchars($request['tenant_info_name'] ?? $request['tenant_name']); ?></strong>
                                            </h6>
                                            <p class="mb-1 text-muted small">
                                                <i class="bi bi-envelope"></i> 
                                                <?php echo htmlspecialchars($request['tenant_info_email'] ?? $request['tenant_email']); ?> | 
                                                <i class="bi bi-telephone"></i> 
                                                <?php echo htmlspecialchars($request['tenant_info_phone'] ?? $request['tenant_phone']); ?>
                                            </p>
                                            <?php if ($request['tenant_info_address']): ?>
                                                <p class="mb-1 text-muted small">
                                                    <i class="bi bi-geo-alt"></i> 
                                                    <?php echo htmlspecialchars($request['tenant_info_address']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="room-info">
                                            <span class="room-number">
                                                <i class="bi bi-door-closed"></i> 
                                                <?php echo htmlspecialchars($request['room_number']); ?>
                                            </span>
                                            <?php if ($request['room_type']): ?>
                                                <span class="ms-2 text-muted">
                                                    (<?php echo htmlspecialchars($request['room_type']); ?>)
                                                </span>
                                            <?php endif; ?>
                                            <div class="mt-1 text-muted small">
                                                <strong>Rate:</strong> $<?php echo number_format($request['rate'], 2); ?> | 
                                                <strong>Room Status:</strong> 
                                                <span class="badge bg-<?php echo ($request['room_status'] === 'available') ? 'success' : 'warning'; ?>">
                                                    <?php echo htmlspecialchars(ucfirst($request['room_status'])); ?>
                                                </span>
                                            </div>
                                            <div class="mt-1 text-muted small">
                                                <strong>Occupants:</strong> <?php echo intval($request['tenant_count']); ?> person(s)
                                            </div>
                                            <?php if ($request['notes']): ?>
                                                <div class="mt-2">
                                                    <p class="mb-0 text-muted small"><strong>Notes:</strong> <?php echo htmlspecialchars($request['notes']); ?></p>
                                                </div>
                                            <?php endif; ?>
                                            <div class="mt-1 text-muted small">
                                                <i class="bi bi-calendar"></i> 
                                                Requested: <?php echo date('M d, Y \a\t H:i A', strtotime($request['request_date'])); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Status and Actions -->
                                    <div class="col-md-6">
                                        <div class="d-flex flex-column align-items-end h-100">
                                            <div class="mb-3">
                                                <span class="status-badge status-<?php echo htmlspecialchars(strtolower($request['status'])); ?>">
                                                    <i class="bi bi-info-circle"></i>
                                                    <?php 
                                                        $status_labels = [
                                                            'pending' => 'Pending Review',
                                                            'pending_payment' => 'Awaiting Payment',
                                                            'approved' => 'Approved',
                                                            'rejected' => 'Rejected'
                                                        ];
                                                        echo $status_labels[$request['status']] ?? ucfirst($request['status']);
                                                    ?>
                                                </span>
                                                </span>
                                            </div>

                                            <?php if ($request['status'] === 'pending'): ?>
                                                <div class="action-buttons">
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to approve this request?');">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['id']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-success">
                                                            <i class="bi bi-check-circle"></i> Approve
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to reject this request?');">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['id']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="bi bi-x-circle"></i> Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-muted small">
                                                    No actions available
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
