<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

$message = '';
$message_type = '';

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $request_id = intval($_POST['request_id'] ?? 0);
    $action = $_POST['action'];

    if ($request_id > 0 && in_array($action, ['approve', 'reject'])) {
        try {
            if ($action === 'approve') {

            // START TRANSACTION
            $conn->beginTransaction();

            // Update room_requests status to 'approved'
            $update_request = $conn->prepare("UPDATE room_requests SET status = 'approved' WHERE id = :id");
            $update_request->execute(['id' => $request_id]);

            // Set room status to 'occupied'
            $room_stmt = $conn->prepare("SELECT room_id FROM room_requests WHERE id = :id");
            $room_stmt->execute(['id' => $request_id]);
            $room_row = $room_stmt->fetch(PDO::FETCH_ASSOC);
            if ($room_row && $room_row['room_id']) {
                $update_room = $conn->prepare("UPDATE rooms SET status = 'occupied' WHERE id = :room_id");
                $update_room->execute(['room_id' => $room_row['room_id']]);
            }

            // Fetch tenant_id for notification
            $stmt = $conn->prepare("SELECT tenant_id FROM room_requests WHERE id = :id");
            $stmt->execute(['id' => $request_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $tenant_id = $row ? $row['tenant_id'] : null;
            $conn->commit();

            if ($tenant_id) {
                notifyTenantRoomRequestStatus($conn, $tenant_id, $request_id, 'approved');
            }

            $message = "Room request approved successfully. Room is now occupied.";
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


// Fetch all room requests with related data
try {
    // Show only requests with status 'pending_payment' (awaiting payment) or 'approved' by default
    $filter_status = isset($_GET['status']) ? $_GET['status'] : '';
    $allowed_statuses = ['pending', 'pending_payment', 'approved', 'rejected'];
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
    // Default: show only pending_payment and approved unless a filter is set
    if ($filter_status && in_array($filter_status, $allowed_statuses)) {
        $sql .= " AND rr.status = :status";
    } else {
        $sql .= " AND (rr.status = 'pending_payment' OR rr.status = 'approved')";
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
                                                <span class="badge bg-<?php echo ($request['status'] === 'approved') ? 'danger' : 'info'; ?>">
                                                    <?php echo ($request['status'] === 'approved') ? 'Occupied' : 'Booked'; ?>
                                                </span>
                                            </div>
                                            <div class="mt-1 text-muted small">
                                                <strong>Occupants:</strong> <?php echo intval($request['tenant_count']); ?> person(s)
                                            </div>
                                            <div class="mt-1 text-muted small">
                                                <strong>Check-in:</strong> <?php echo !empty($request['checkin_date']) ? date('M d, Y \a\t h:i A', strtotime($request['checkin_date'])) : '<span class="text-danger">Missing</span>'; ?>
                                                <strong>Check-out:</strong> <?php echo !empty($request['checkout_date']) ? date('M d, Y \a\t h:i A', strtotime($request['checkout_date'])) : '<span class="text-danger">Missing</span>'; ?>
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
                                            // Show payment type (downpayment or full payment)
                                            $advance_bill = null;
                                            $advance_stmt = $conn->prepare("SELECT * FROM bills WHERE tenant_id = :tenant_id AND room_id = :room_id AND notes LIKE '%ADVANCE PAYMENT%' ORDER BY id DESC LIMIT 1");
                                            $advance_stmt->execute(['tenant_id' => $request['tenant_id'], 'room_id' => $request['room_id']]);
                                            $advance_bill = $advance_stmt->fetch(PDO::FETCH_ASSOC);
                                            $paid_amount = 0;
                                            $payment_type_label = '';
                                            if ($advance_bill) {
                                                $pay_stmt = $conn->prepare("SELECT SUM(payment_amount) as paid FROM payment_transactions WHERE bill_id = :bill_id");
                                                $pay_stmt->execute(['bill_id' => $advance_bill['id']]);
                                                $pay = $pay_stmt->fetch(PDO::FETCH_ASSOC);
                                                $paid_amount = $pay && $pay['paid'] ? floatval($pay['paid']) : 0;
                                                if ($paid_amount >= floatval($advance_bill['amount_due'])) {
                                                    $payment_type_label = 'Full Payment';
                                                } elseif ($paid_amount > 0) {
                                                    $payment_type_label = 'Downpayment';
                                                }
                                            }
                                            if ($payment_type_label) {
                                                echo '<div class="mt-1 text-muted small"><strong>Payment Type:</strong> ' . $payment_type_label . '</div>';
                                            }
                                            ?>
                                            <?php
                                            // Show payment status for this request (downpayment/full payment paid, etc)
                                            $advance_bill = null;
                                            $advance_stmt = $conn->prepare("SELECT * FROM bills WHERE tenant_id = :tenant_id AND room_id = :room_id AND notes LIKE '%ADVANCE PAYMENT%' ORDER BY id DESC LIMIT 1");
                                            $advance_stmt->execute(['tenant_id' => $request['tenant_id'], 'room_id' => $request['room_id']]);
                                            $advance_bill = $advance_stmt->fetch(PDO::FETCH_ASSOC);
                                            $paid_amount = 0;
                                            if ($advance_bill) {
                                                $pay_stmt = $conn->prepare("SELECT SUM(payment_amount) as paid FROM payment_transactions WHERE bill_id = :bill_id");
                                                $pay_stmt->execute(['bill_id' => $advance_bill['id']]);
                                                $pay = $pay_stmt->fetch(PDO::FETCH_ASSOC);
                                                $paid_amount = $pay && $pay['paid'] ? floatval($pay['paid']) : 0;
                                                if ($paid_amount >= floatval($advance_bill['amount_due'])) {
                                                    // No badge after payment, handled by status below
                                                } elseif ($paid_amount > 0) {
                                                    // No badge after payment, handled by status below
                                                } else {
                                                    echo '<div class="mt-2"><span class="badge bg-warning text-dark">No Payment Yet</span></div>';
                                                }
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
                                                        // Only show 'Awaiting Payment' if no payment yet
                                                        if ($request['status'] === 'pending_payment') {
                                                            $advance_bill = null;
                                                            $advance_stmt = $conn->prepare("SELECT * FROM bills WHERE tenant_id = :tenant_id AND room_id = :room_id AND notes LIKE '%ADVANCE PAYMENT%' ORDER BY id DESC LIMIT 1");
                                                            $advance_stmt->execute(['tenant_id' => $request['tenant_id'], 'room_id' => $request['room_id']]);
                                                            $advance_bill = $advance_stmt->fetch(PDO::FETCH_ASSOC);
                                                            $paid_amount = 0;
                                                            if ($advance_bill) {
                                                                $pay_stmt = $conn->prepare("SELECT SUM(payment_amount) as paid FROM payment_transactions WHERE bill_id = :bill_id");
                                                                $pay_stmt->execute(['bill_id' => $advance_bill['id']]);
                                                                $pay = $pay_stmt->fetch(PDO::FETCH_ASSOC);
                                                                $paid_amount = $pay && $pay['paid'] ? floatval($pay['paid']) : 0;
                                                            }
                                                            if ($paid_amount > 0) {
                                                                // No status, handled by admin action
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
                                                $advance_bill = null;
                                                $advance_stmt = $conn->prepare("SELECT * FROM bills WHERE tenant_id = :tenant_id AND room_id = :room_id AND notes LIKE '%ADVANCE PAYMENT%' ORDER BY id DESC LIMIT 1");
                                                $advance_stmt->execute(['tenant_id' => $request['tenant_id'], 'room_id' => $request['room_id']]);
                                                $advance_bill = $advance_stmt->fetch(PDO::FETCH_ASSOC);
                                                if ($advance_bill) {
                                                    $pay_stmt = $conn->prepare("SELECT SUM(payment_amount) as paid FROM payment_transactions WHERE bill_id = :bill_id");
                                                    $pay_stmt->execute(['bill_id' => $advance_bill['id']]);
                                                    $pay = $pay_stmt->fetch(PDO::FETCH_ASSOC);
                                                    if ($pay && $pay['paid'] > 0) {
                                                        $show_approve = true;
                                                    }
                                                }
                                            }
                                            if ($show_approve) {
                                                // Only show to admin
                                                if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
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
                                                    </div>
                                                    <?php
                                                }
                                            } elseif ($request['status'] === 'approved') {
                                                echo '<span class="badge bg-success">Approved</span>';
                                            } elseif ($request['status'] === 'rejected') {
                                                echo '<span class="badge bg-danger">Rejected</span>';
                                            }
                                            // End PHP block before modal markup
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
                                                                                                        // Payment details for modal
                                                                                                        $advance_stmt = $conn->prepare("SELECT * FROM bills WHERE tenant_id = :tenant_id AND room_id = :room_id AND notes LIKE '%ADVANCE PAYMENT%' ORDER BY id DESC LIMIT 1");
                                                                                                        $advance_stmt->execute(['tenant_id' => $request['tenant_id'], 'room_id' => $request['room_id']]);
                                                                                                        $advance_bill = $advance_stmt->fetch(PDO::FETCH_ASSOC);
                                                                                                        $paid_amount = 0;
                                                                                                        $amount_due = 0;
                                                                                                        if ($advance_bill) {
                                                                                                            $amount_due = floatval($advance_bill['amount_due']);
                                                                                                            $pay_stmt = $conn->prepare("SELECT SUM(payment_amount) as paid FROM payment_transactions WHERE bill_id = :bill_id");
                                                                                                            $pay_stmt->execute(['bill_id' => $advance_bill['id']]);
                                                                                                            $pay = $pay_stmt->fetch(PDO::FETCH_ASSOC);
                                                                                                            $paid_amount = $pay && $pay['paid'] ? floatval($pay['paid']) : 0;
                                                                                                        }
                                                                                                        $payment_status = 'No Payment Yet';
                                                                                                        if ($paid_amount >= $amount_due && $amount_due > 0) {
                                                                                                            $payment_status = 'Full Payment Paid';
                                                                                                        } elseif ($paid_amount > 0) {
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
                                                                                                            <dd class="col-sm-9"><?php echo !empty($request['checkin_date']) ? date('M d, Y \a\t h:i A', strtotime($request['checkin_date'])) : '<span class="text-danger">Missing</span>'; ?></dd>
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
</body>
</html>
