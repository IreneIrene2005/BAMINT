<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

// Allow admin and front_desk access
if (!in_array($_SESSION['role'], ['admin', 'front_desk'])) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

$message = '';
$message_type = '';

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Allow admins to update request guest info
    if ($_POST['action'] === 'update_request') {
        $request_id = intval($_POST['request_id'] ?? 0);
        $name = trim($_POST['tenant_info_name'] ?? '');
        $email = trim($_POST['tenant_info_email'] ?? '');
        $phone = trim($_POST['tenant_info_phone'] ?? '');
        $address = trim($_POST['tenant_info_address'] ?? '');
        if ($request_id > 0) {
            try {
                $upd = $conn->prepare("UPDATE room_requests SET tenant_info_name = :name, tenant_info_email = :email, tenant_info_phone = :phone, tenant_info_address = :address, updated_at = NOW() WHERE id = :id");
                $upd->execute(['name'=>$name, 'email'=>$email, 'phone'=>$phone, 'address'=>$address, 'id'=>$request_id]);
                // Sync provided address into tenants and tenant_accounts
                try {
                    $syncTenant = $conn->prepare("UPDATE tenants t JOIN room_requests rr ON rr.tenant_id = t.id SET t.address = :address WHERE rr.id = :id");
                    $syncTenant->execute(['address' => $address, 'id' => $request_id]);

                    $syncAccount = $conn->prepare("UPDATE tenant_accounts ta JOIN room_requests rr ON ta.tenant_id = rr.tenant_id SET ta.address = :address WHERE rr.id = :id");
                    $syncAccount->execute(['address' => $address, 'id' => $request_id]);
                } catch (Exception $e) {
                    // non-fatal: continue even if sync fails
                    error_log('Address sync failed: ' . $e->getMessage());
                }
                $message = 'Request details updated.';
                $message_type = 'success';
            } catch (Exception $e) {
                $message = 'Failed to update request: ' . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }
    $request_id = intval($_POST['request_id'] ?? 0);
    $action = $_POST['action'];

    // Archive request handler
    if ($request_id > 0 && $action === 'archive_request') {
        try {
            // Add archived_at timestamp to request
            $archive = $conn->prepare("UPDATE room_requests SET status = 'archived', updated_at = NOW() WHERE id = :id");
            $archive->execute(['id' => $request_id]);
            $message = 'Request archived successfully.';
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Failed to archive request: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }

    // Delete request handler
    if ($request_id > 0 && $action === 'delete_request') {
        try {
            // Mark request as deleted to keep audit trail
            $del = $conn->prepare("UPDATE room_requests SET status = 'deleted', updated_at = NOW() WHERE id = :id");
            $del->execute(['id' => $request_id]);

            // If the room was only booked (not occupied), make it available again
            $rstmt = $conn->prepare("SELECT room_id FROM room_requests WHERE id = :id");
            $rstmt->execute(['id' => $request_id]);
            $rrow = $rstmt->fetch(PDO::FETCH_ASSOC);
            if ($rrow && $rrow['room_id']) {
                $roomId = $rrow['room_id'];
                $roomStatusStmt = $conn->prepare("SELECT status FROM rooms WHERE id = :id");
                $roomStatusStmt->execute(['id' => $roomId]);
                $current = $roomStatusStmt->fetchColumn();
                if ($current !== 'occupied') {
                    $conn->prepare("UPDATE rooms SET status = 'available' WHERE id = :id")->execute(['id' => $roomId]);
                }
            }

            $message = 'Request deleted.';
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Failed to delete request: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }

    if ($request_id > 0 && in_array($action, ['approve', 'reject'])) {
        try {
            if ($action === 'approve') {

            // START TRANSACTION
            $conn->beginTransaction();

            // Update room_requests status to 'approved'
            $update_request = $conn->prepare("UPDATE room_requests SET status = 'approved' WHERE id = :id");
            $update_request->execute(['id' => $request_id]);

            // If the request had an address, copy it into tenants and tenant_accounts
            try {
                $addr_stmt = $conn->prepare("SELECT tenant_info_address, tenant_id FROM room_requests WHERE id = :id");
                $addr_stmt->execute(['id' => $request_id]);
                $addr_row = $addr_stmt->fetch(PDO::FETCH_ASSOC);
                if ($addr_row && !empty(trim($addr_row['tenant_info_address']))) {
                    $address_val = $addr_row['tenant_info_address'];
                    $tid = $addr_row['tenant_id'];
                    $conn->prepare("UPDATE tenants SET address = :address WHERE id = :id")->execute(['address' => $address_val, 'id' => $tid]);
                    $conn->prepare("UPDATE tenant_accounts SET address = :address WHERE tenant_id = :tenant_id")->execute(['address' => $address_val, 'tenant_id' => $tid]);
                }
            } catch (Exception $e) {
                // non-fatal
                error_log('Address sync on approve failed: ' . $e->getMessage());
            }
            // Set room status to 'occupied'
            $room_stmt = $conn->prepare("SELECT room_id, tenant_id FROM room_requests WHERE id = :id");
            $room_stmt->execute(['id' => $request_id]);
            $room_row = $room_stmt->fetch(PDO::FETCH_ASSOC);
            if ($room_row && $room_row['room_id']) {
                $update_room = $conn->prepare("UPDATE rooms SET status = 'occupied' WHERE id = :room_id");
                $update_room->execute(['room_id' => $room_row['room_id']]);
                
                // Also update tenant status to 'active' and set room_id and start_date
                $update_tenant = $conn->prepare("
                    UPDATE tenants 
                    SET status = 'active', room_id = :room_id, start_date = CURDATE()
                    WHERE id = :tenant_id
                ");
                $update_tenant->execute(['room_id' => $room_row['room_id'], 'tenant_id' => $room_row['tenant_id']]);
            }

            // Fetch tenant_id for notification
            $stmt = $conn->prepare("SELECT tenant_id FROM room_requests WHERE id = :id");
            $stmt->execute(['id' => $request_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $tenant_id = $row ? $row['tenant_id'] : null;
            $conn->commit();

            if ($tenant_id) {
                // Send booking receipt notification with approval details
                notifyTenantBookingApproved($conn, $tenant_id, $request_id);
            }

            $message = "Room request approved successfully. Room is now occupied. Customer notified with booking receipt.";
            $message_type = "success";

            } elseif ($action === 'reject') {
                // 👉 reject logic here
                // delete co-tenants (if any)
                // reset room status (if needed)
                // update room_requests status
                $update_request = $conn->prepare("UPDATE room_requests SET status = 'rejected' WHERE id = :id");
                $update_request->execute(['id' => $request_id]);

                // Fetch tenant_id for notification
                $stmt = $conn->prepare("SELECT tenant_id FROM room_requests WHERE id = :id");
                $stmt->execute(['id' => $request_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $tenant_id = $row ? $row['tenant_id'] : null;
                if ($tenant_id) {
                    notifyTenantRoomRequestStatus($conn, $tenant_id, $request_id, 'rejected');
                }

                $message = "Room request rejected successfully.";
                $message_type = "success";
            }

        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $message = "Error updating request: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// DISABLED: Manual approval required - auto-approve logic removed
// Admin must now click Approve button in room_requests_queue.php
// to mark room as occupied and notify customer with booking receipt
/*
try {
    // Find all pending_payment requests with FULL payment (amount paid >= amount due)
    $auto_approve_sql = "
        SELECT DISTINCT rr.id, rr.tenant_id, rr.room_id
        FROM room_requests rr
        JOIN bills b ON rr.tenant_id = b.tenant_id AND rr.room_id = b.room_id
        JOIN payment_transactions pt ON b.id = pt.bill_id
        WHERE rr.status = 'pending_payment'
        GROUP BY rr.id
        HAVING SUM(pt.payment_amount) >= b.amount_due
    ";
    
    $auto_approve_stmt = $conn->prepare($auto_approve_sql);
    $auto_approve_stmt->execute();
    $auto_approve_requests = $auto_approve_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($auto_approve_requests as $req) {
        try {
            $conn->beginTransaction();
            
            // Update room_requests status to 'approved'
            $update_request = $conn->prepare("UPDATE room_requests SET status = 'approved' WHERE id = :id");
            $update_request->execute(['id' => $req['id']]);
            
            // Set room status to 'occupied'
            $update_room = $conn->prepare("UPDATE rooms SET status = 'occupied' WHERE id = :room_id");
            $update_room->execute(['room_id' => $req['room_id']]);
            
            $conn->commit();
            
            // Send notification
            notifyTenantRoomRequestStatus($conn, $req['tenant_id'], $req['id'], 'approved');
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
        }
    }
} catch (Exception $e) {
    // Silently skip auto-approval errors
}
*/


// Fetch all room requests with related data
try {
    // Show only requests with status 'pending_payment' (awaiting payment) or 'approved' by default
    // Can filter by status using query param
    $filter_status = isset($_GET['status']) ? $_GET['status'] : '';
    $view_archived = isset($_GET['view']) && $_GET['view'] === 'archived';
    $allowed_statuses = ['pending', 'pending_payment', 'approved', 'rejected', 'cancelled', 'archived'];
    
    $sql = "
        SELECT 
            rr.id,
            rr.tenant_id,
            rr.room_id,
            rr.request_date,
            rr.status,
            rr.notes,
            rr.checkin_date,
            rr.checkout_date,
            rr.checkin_time,
            rr.checkout_time,
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
    
    // Show archived or active based on view parameter
    if ($view_archived) {
        $sql .= " AND rr.status = 'archived'";
    } else {
        $sql .= " AND rr.status != 'archived' AND rr.status != 'deleted'";
    }
    
    // Additional filter by specific status if provided
    if ($filter_status && in_array($filter_status, $allowed_statuses)) {
        $sql .= " AND rr.status = :status";
    }
    
    $sql .= " ORDER BY rr.request_date DESC";
    $stmt = $conn->prepare($sql);
    if ($filter_status && in_array($filter_status, $allowed_statuses)) {
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
            
            if ($filter_status && in_array($filter_status, ['pending', 'pending_payment', 'approved', 'rejected', 'cancelled'])) {
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

    // Awaiting Payment stat removed; keep variable for compatibility
    $pending_payment_count = 0;

    $approved_stmt = $conn->query("SELECT COUNT(*) as count FROM room_requests WHERE status = 'approved'");
    $approved_count = $approved_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $rejected_stmt = $conn->query("SELECT COUNT(*) as count FROM room_requests WHERE status = 'rejected'");
    $rejected_count = $rejected_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $cancelled_stmt = $conn->query("SELECT COUNT(*) as count FROM room_requests WHERE status = 'cancelled'");
    $cancelled_count = $cancelled_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $archived_stmt = $conn->query("SELECT COUNT(*) as count FROM room_requests WHERE status = 'archived'");
    $archived_count = $archived_stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (Exception $e) {
    $total_count = $pending_count = $pending_payment_count = $approved_count = $rejected_count = $cancelled_count = $archived_count = 0;
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
        /* Awaiting Payment status styling removed from UI */
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

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-door-closed"></i> Room Requests Queue
                </h1>
                <button class="btn btn-outline-secondary btn-sm" onclick="location.reload();" title="Refresh data">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
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
                <div class="col-md-2">
                    <div class="stat-card bg-white">
                        <div class="stat-value text-secondary"><?php echo intval($archived_count); ?></div>
                        <div class="stat-label">Archived</div>
                    </div>
                </div>
            </div>

            <!-- View Toggle (Active vs Archive) -->
            <div class="mb-3 d-flex gap-2">
                <a href="<?php echo isset($_GET['status']) ? 'room_requests_queue.php?status=' . htmlspecialchars($_GET['status']) : 'room_requests_queue.php'; ?>" class="btn btn-sm btn-primary <?php echo !isset($_GET['view']) || $_GET['view'] !== 'archived' ? 'active' : 'btn-outline-primary'; ?>">
                    <i class="bi bi-inbox"></i> Active Requests
                </a>
                <a href="room_requests_queue.php?view=archived<?php echo isset($_GET['status']) ? '&status=' . htmlspecialchars($_GET['status']) : ''; ?>" class="btn btn-sm btn-outline-primary <?php echo isset($_GET['view']) && $_GET['view'] === 'archived' ? 'active' : ''; ?>">
                    <i class="bi bi-archive"></i> Archived Requests
                </a>
            </div>

            <!-- Filter Buttons -->
            <div class="mb-3">
                <a href="room_requests_queue.php" class="btn btn-sm btn-outline-secondary filter-btn <?php echo !isset($_GET['status']) ? 'active' : ''; ?>">
                    <i class="bi bi-funnel"></i> All Requests
                </a>
                <a href="room_requests_queue.php?status=pending" class="btn btn-sm btn-outline-warning filter-btn <?php echo (isset($_GET['status']) && $_GET['status'] === 'pending') ? 'active' : ''; ?>">
                    <i class="bi bi-clock"></i> Pending
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
                        <?php
                        // Initialize payment cache for all requests
                        $request_payment_cache = [];
                        foreach ($requests as $request): 
                        ?>
                            <div class="request-row">
                                <div class="row align-items-center">
                                    <!-- Request Info -->
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <h6 class="mb-1">
                                                <i class="bi bi-person"></i> 
                                                <strong><?php echo htmlspecialchars($request['tenant_info_name'] ?? $request['tenant_name']); ?></strong>
                                                &nbsp; <a href="#" class="btn btn-link btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $request['id']; ?>">Edit</a>
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
                                                <strong>Rate:</strong> ₱<?php echo number_format($request['rate'], 2); ?> | 
                                                <strong>Total Cost:</strong> ₱<?php 
                                                    $total_cost = 0;
                                                    if (!empty($request['checkin_date']) && !empty($request['checkout_date'])) {
                                                        $checkin_dt = new DateTime($request['checkin_date']);
                                                        $checkout_dt = new DateTime($request['checkout_date']);
                                                        $interval = $checkin_dt->diff($checkout_dt);
                                                        $nights = (int)$interval->days;
                                                        $total_cost = $nights * floatval($request['rate']);
                                                    }
                                                    echo number_format($total_cost, 2);
                                                ?> |
                                                <strong>Room Status:</strong> 
                                                <span class="badge bg-<?php 
                                                    if ($request['status'] === 'approved') { 
                                                        echo 'danger';
                                                    } elseif ($request['status'] === 'cancelled') {
                                                        echo 'dark';
                                                    } else {
                                                        echo 'info';
                                                    }
                                                ?>">
                                                    <?php 
                                                        if ($request['status'] === 'approved') {
                                                            echo 'Occupied';
                                                        } elseif ($request['status'] === 'cancelled') {
                                                            echo 'Cancelled';
                                                        } else {
                                                            echo 'Booked';
                                                        }
                                                    ?>
                                                </span>
                                            </div>
                                            <div class="mt-1 text-muted small">
                                                <strong>Occupants:</strong> <?php echo intval($request['tenant_count']); ?> person(s)
                                            </div>
                                            <div class="mt-1 text-muted small">
                                                <strong>Check-in:</strong> <?php
                                                    if (!empty($request['checkin_date'])) {
                                                        $ci = date('M d, Y', strtotime($request['checkin_date']));
                                                        if (!empty($request['checkin_time']) && $request['checkin_time'] !== '00:00:00') {
                                                            $ci .= ' at ' . date('g:i A', strtotime($request['checkin_time']));
                                                        }
                                                        echo $ci;
                                                    } else {
                                                        echo '<span class="text-danger">Missing</span>';
                                                    }
                                                ?>
                                                <strong>Check-out:</strong> <?php
                                                    if (!empty($request['checkout_date'])) {
                                                        $co = date('M d, Y', strtotime($request['checkout_date']));
                                                        if (!empty($request['checkout_time']) && $request['checkout_time'] !== '00:00:00') {
                                                            $co .= ' at ' . date('g:i A', strtotime($request['checkout_time']));
                                                        }
                                                        echo $co;
                                                    } else {
                                                        echo '<span class="text-danger">Missing</span>';
                                                    }
                                                ?>
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
                                            <?php
                                            // Calculate payment status once for reuse - works for both advance payments and walk-in
                                            $cache_key = $request['tenant_id'] . '_' . $request['room_id'];
                                            
                                            if (!isset($request_payment_cache[$cache_key])) {
                                                $advance_bill = null;
                                                $advance_stmt = $conn->prepare("SELECT id, amount_due FROM bills WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                                $advance_stmt->execute(['tenant_id' => $request['tenant_id'], 'room_id' => $request['room_id']]);
                                                $advance_bill = $advance_stmt->fetch(PDO::FETCH_ASSOC);
                                                
                                                $paid_amount = 0;
                                                $amount_due = 0;
                                                $payment_type = 'no_payment'; // no_payment, downpayment, full_payment
                                                
                                                if ($advance_bill) {
                                                    $amount_due = floatval($advance_bill['amount_due']);
                                                    $pay_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount), 0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_amount > 0 AND payment_status IN ('verified', 'approved')");
                                                    $pay_stmt->execute(['bill_id' => $advance_bill['id']]);
                                                    $pay = $pay_stmt->fetch(PDO::FETCH_ASSOC);
                                                    $paid_amount = $pay && $pay['paid'] ? floatval($pay['paid']) : 0;
                                                    
                                                    // Determine payment type with proper comparison
                                                    if ($paid_amount >= $amount_due && $amount_due > 0) {
                                                        $payment_type = 'full_payment';
                                                    } elseif ($paid_amount > 0) {
                                                        $payment_type = 'downpayment';
                                                    }
                                                }
                                                
                                                $request_payment_cache[$cache_key] = [
                                                    'payment_type' => $payment_type,
                                                    'paid_amount' => $paid_amount,
                                                    'amount_due' => $amount_due,
                                                    'bill_id' => $advance_bill['id'] ?? null
                                                ];
                                            }
                                            
                                            $payment_info = $request_payment_cache[$cache_key];
                                            $payment_type = $payment_info['payment_type'];
                                            $paid_amount = $payment_info['paid_amount'];
                                            $amount_due = $payment_info['amount_due'];
                                            
                                            // Display payment type badge
                                            if ($payment_type === 'full_payment') {
                                                echo '<div class="mt-1 text-muted small"><strong>Payment Type:</strong> <span class="badge bg-success">Full Payment</span></div>';
                                            } elseif ($payment_type === 'downpayment') {
                                                echo '<div class="mt-1 text-muted small"><strong>Payment Type:</strong> <span class="badge bg-info">Downpayment</span></div>';
                                            } else {
                                                echo '<div class="mt-2"><span class="badge bg-warning text-dark">No Payment Yet</span></div>';
                                            }
                                            ?>
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
                                                        // Show status based on cached payment info
                                                        if ($request['status'] === 'pending_payment') {
                                                            $cache_key = $request['tenant_id'] . '_' . $request['room_id'];
                                                            if (isset($request_payment_cache[$cache_key]) && $request_payment_cache[$cache_key]['payment_type'] !== 'no_payment') {
                                                                // Payment made, no status shown (admin action buttons will show)
                                                                echo '';
                                                            } else {
                                                                echo $status_labels[$request['status']];
                                                            }
                                                        } else {
                                                            echo $status_labels[$request['status']] ?? ucfirst($request['status']);
                                                        }
                                                    ?>
                                                </span>
                                                <a href="#" class="btn btn-link btn-sm p-0 ms-2" data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $request['id']; ?>">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                                </span>
                                            </div>

                                            <?php
                                            // Show approve/reject buttons if payment is made and status is still pending_payment
                                            $show_approve = false;
                                            if ($request['status'] === 'pending_payment') {
                                                $cache_key = $request['tenant_id'] . '_' . $request['room_id'];
                                                if (isset($request_payment_cache[$cache_key]) && $request_payment_cache[$cache_key]['payment_type'] !== 'no_payment') {
                                                    $show_approve = true;
                                                }
                                            }
                                            if ($show_approve) {
                                                // Show to admin and front_desk
                                                if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'front_desk'])) {
                                                    ?>
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
                                                        <form method="POST" style="display: inline; margin-left:8px;" onsubmit="return confirm('Archive this request?');">
                                                            <input type="hidden" name="action" value="archive_request">
                                                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['id']); ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                                <i class="bi bi-archive"></i> Archive
                                                            </button>
                                                        </form>
                                                        <form method="POST" style="display: inline; margin-left:4px;" onsubmit="return confirm('Permanently delete this request? This cannot be undone.');">
                                                            <input type="hidden" name="action" value="delete_request">
                                                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['id']); ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                <i class="bi bi-trash"></i> Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                    <?php
                                                }
                                            } elseif ($request['status'] === 'approved') {
                                                echo '<span class="badge bg-success">Approved</span>';
                                                if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
                                                    ?>
                                                    <form method="POST" style="display: inline; margin-left:8px;" onsubmit="return confirm('Archive this request?');">
                                                        <input type="hidden" name="action" value="archive_request">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['id']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                            <i class="bi bi-archive"></i> Archive
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline; margin-left:4px;" onsubmit="return confirm('Permanently delete this request? This cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete_request">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['id']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                    <?php
                                                }
                                            } elseif ($request['status'] === 'rejected') {
                                                echo '<span class="badge bg-danger">Rejected</span>';
                                                if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
                                                    ?>
                                                    <form method="POST" style="display: inline; margin-left:8px;" onsubmit="return confirm('Archive this request?');">
                                                        <input type="hidden" name="action" value="archive_request">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['id']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                            <i class="bi bi-archive"></i> Archive
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline; margin-left:4px;" onsubmit="return confirm('Permanently delete this request? This cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete_request">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['id']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                    <?php
                                                }
                                            }
                                            ?>
                                        <!-- Modal for Details -->
                                        <div class="modal fade" id="detailsModal<?php echo $request['id']; ?>" tabindex="-1" aria-labelledby="detailsModalLabel<?php echo $request['id']; ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                                                                        <h5 class="modal-title" id="detailsModalLabel<?php echo $request['id']; ?>">Room Request Details</h5>
                                                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                                                    </div>
                                                                                                    <div class="modal-body">
                                                                                                        <?php
                                                                                                        // Use cached payment info for modal
                                                                                                        $cache_key = $request['tenant_id'] . '_' . $request['room_id'];
                                                                                                        $payment_info = isset($request_payment_cache[$cache_key]) ? $request_payment_cache[$cache_key] : [
                                                                                                            'payment_type' => 'no_payment',
                                                                                                            'paid_amount' => 0,
                                                                                                            'amount_due' => 0,
                                                                                                            'bill_id' => null
                                                                                                        ];
                                                                                                        $paid_amount = $payment_info['paid_amount'];
                                                                                                        $amount_due = $payment_info['amount_due'];
                                                                                                        $payment_status = 'No Payment Yet';
                                                                                                        if ($payment_info['payment_type'] === 'full_payment') {
                                                                                                            $payment_status = 'Full Payment Paid';
                                                                                                        } elseif ($payment_info['payment_type'] === 'downpayment') {
                                                                                                            $payment_status = 'Downpayment Paid';
                                                                                                        }
                                                                                                        $remaining = max(0, $amount_due - $paid_amount);
                                                                                                        ?>
                                                                                                        <dl class="row">
                                                                                                            <dt class="col-sm-3">Name</dt>
                                                                                                            <dd class="col-sm-9"><?php echo htmlspecialchars($request['tenant_info_name'] ?? $request['tenant_name']); ?></dd>
                                                                                                            <dt class="col-sm-3">Email</dt>
                                                                                                            <dd class="col-sm-9"><?php echo htmlspecialchars($request['tenant_info_email'] ?? $request['tenant_email']); ?></dd>
                                                                                                            <dt class="col-sm-3">Phone</dt>
                                                                                                            <dd class="col-sm-9"><?php echo htmlspecialchars($request['tenant_info_phone'] ?? $request['tenant_phone']); ?></dd>
                                                                                                            <dt class="col-sm-3">Address</dt>
                                                                                                            <dd class="col-sm-9"><?php echo htmlspecialchars($request['tenant_info_address']); ?></dd>
                                                                                                            <dt class="col-sm-3">Room</dt>
                                                                                                            <dd class="col-sm-9"><?php echo htmlspecialchars($request['room_number']); ?> (<?php echo htmlspecialchars($request['room_type']); ?>)</dd>
                                                                                                            <dt class="col-sm-3">Rate</dt>
                                                                                                            <dd class="col-sm-9">₱<?php echo number_format($request['rate'], 2); ?></dd>
                                                                                                            <dt class="col-sm-3">Room Status</dt>
                                                                                                            <dd class="col-sm-9"><?php echo htmlspecialchars(ucfirst($request['room_status'])); ?></dd>
                                                                                                            <dt class="col-sm-3">Occupants</dt>
                                                                                                            <dd class="col-sm-9"><?php echo intval($request['tenant_count']); ?> person(s)</dd>
                                                                                                            <dt class="col-sm-3">Check-in</dt>
                                                                                                            <dd class="col-sm-9"><?php
                                                                                                                if (!empty($request['checkin_date'])) {
                                                                                                                    $ci = date('M d, Y', strtotime($request['checkin_date']));
                                                                                                                    if (!empty($request['checkin_time']) && $request['checkin_time'] !== '00:00:00') {
                                                                                                                        $ci .= ' at ' . date('g:i A', strtotime($request['checkin_time']));
                                                                                                                    }
                                                                                                                    echo $ci;
                                                                                                                } else {
                                                                                                                    echo '<span class="text-danger">Missing</span>';
                                                                                                                }
                                                                                                            ?></dd>
                                                                                                            <dt class="col-sm-3">Check-out</dt>
                                                                                                            <dd class="col-sm-9"><?php echo !empty($request['checkout_date']) ? date('M d, Y \a\t h:i A', strtotime($request['checkout_date'])) : '<span class="text-danger">Missing</span>'; ?></dd>
                                                                                                            <dt class="col-sm-3">Notes</dt>
                                                                                                            <dd class="col-sm-9"><?php echo htmlspecialchars($request['notes']); ?></dd>
                                                                                                            <dt class="col-sm-3">Requested</dt>
                                                                                                            <dd class="col-sm-9"><?php echo date('M d, Y \a\t H:i A', strtotime($request['request_date'])); ?></dd>
                                                                                                            <dt class="col-sm-3">Status</dt>
                                                                                                            <dd class="col-sm-9"><?php echo $status_labels[$request['status']] ?? ucfirst($request['status']); ?></dd>
                                                                                                            <dt class="col-sm-3">Payment Status</dt>
                                                                                                            <dd class="col-sm-9"><?php echo $payment_status; ?></dd>
                                                                                                            <dt class="col-sm-3">Amount Paid</dt>
                                                                                                            <dd class="col-sm-9">₱<?php echo number_format($paid_amount, 2); ?></dd>
                                                                                                            <dt class="col-sm-3">Remaining Balance</dt>
                                                                                                            <dd class="col-sm-9">₱<?php echo number_format($remaining, 2); ?></dd>
                                                                                                        </dl>
                                                                                                    </div>
                                                                                                </div>
                                                                                            </div>
                                                                                        </div>
                                        </div>
                        <!-- Edit Request Modal -->
                        <div class="modal fade" id="editModal<?php echo $request['id']; ?>" tabindex="-1" aria-labelledby="editModalLabel<?php echo $request['id']; ?>" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="editModalLabel<?php echo $request['id']; ?>">Edit Request - <?php echo htmlspecialchars($request['room_number']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update_request">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Full Name</label>
                                                <input type="text" name="tenant_info_name" class="form-control" value="<?php echo htmlspecialchars($request['tenant_info_name'] ?? $request['tenant_name']); ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Email</label>
                                                <input type="email" name="tenant_info_email" class="form-control" value="<?php echo htmlspecialchars($request['tenant_info_email'] ?? $request['tenant_email']); ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Phone</label>
                                                <input type="tel" name="tenant_info_phone" class="form-control" value="<?php echo htmlspecialchars($request['tenant_info_phone'] ?? $request['tenant_phone']); ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Address</label>
                                                <textarea name="tenant_info_address" class="form-control" rows="2"><?php echo htmlspecialchars($request['tenant_info_address']); ?></textarea>
                                            </div>
                                            <div class="text-end">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                                    </div>
                                </div>
                            </div>
                                                                <!-- Payment History Modal -->
                                                                <div class="modal fade" id="paymentHistoryModal<?php echo $request['tenant_id']; ?>" tabindex="-1" aria-labelledby="paymentHistoryModalLabel<?php echo $request['tenant_id']; ?>" aria-hidden="true">
                                                                    <div class="modal-dialog modal-lg modal-dialog-centered">
                                                                        <div class="modal-content">
                                                                            <div class="modal-header">
                                                                                <h5 class="modal-title" id="paymentHistoryModalLabel<?php echo $request['tenant_id']; ?>">Payment History for <?php echo htmlspecialchars($request['tenant_info_name'] ?? $request['tenant_name']); ?></h5>
                                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                            </div>
                                                                            <div class="modal-body">
                                                                                <table class="table table-bordered table-sm">
                                                                                    <thead>
                                                                                        <tr>
                                                                                            <th>Date</th>
                                                                                            <th>Amount</th>
                                                                                            <th>Status</th>
                                                                                            <th>Bill Type</th>
                                                                                        </tr>
                                                                                    </thead>
                                                                                    <tbody>
                                                                                        <?php
                                                                                        $ph_stmt = $conn->prepare("SELECT pt.*, b.notes as bill_notes FROM payment_transactions pt LEFT JOIN bills b ON pt.bill_id = b.id WHERE pt.tenant_id = :tenant_id ORDER BY pt.payment_date DESC");
                                                                                        $ph_stmt->execute(['tenant_id' => $request['tenant_id']]);
                                                                                        $history = $ph_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                                                        if ($history) {
                                                                                                foreach ($history as $row) {
                                                                                                        echo '<tr>';
                                                                                                        echo '<td>' . date('M d, Y h:i A', strtotime($row['payment_date'])) . '</td>';
                                                                                                        echo '<td>₱' . number_format($row['payment_amount'], 2) . '</td>';
                                                                                                        echo '<td>' . htmlspecialchars(ucfirst($row['payment_status'])) . '</td>';
                                                                                                        echo '<td>' . htmlspecialchars($row['bill_notes']) . '</td>';
                                                                                                        echo '</tr>';
                                                                                                }
                                                                                        } else {
                                                                                                echo '<tr><td colspan="4" class="text-center text-muted">No payment history found.</td></tr>';
                                                                                        }
                                                                                        ?>
                                                                                    </tbody>
                                                                                </table>
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
<script>
// Auto-refresh page every 10 seconds to show real-time payment updates
setInterval(function() {
    location.reload();
}, 10000);
</script>
</body>
</html>
