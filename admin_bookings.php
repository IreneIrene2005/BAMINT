<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

$filter_status = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$tab = $_GET['tab'] ?? 'active'; // 'active', 'archived', or 'room_cancellations'
$success_msg = "";
$error_msg = "";
$stats = [];
$cancellations = [];
$archived_cancellations = [];
$room_cancellations = [];

try {
    if ($tab === 'room_cancellations') {
        // Build query for approved room requests (customers who haven't checked in yet)
        $query = "
            SELECT rr.*, t.name, t.email, t.phone, r.room_number, r.room_type, 
                   DATEDIFF(rr.checkin_date, CURDATE()) as days_until_checkin
            FROM room_requests rr
            INNER JOIN tenants t ON rr.tenant_id = t.id
            INNER JOIN rooms r ON rr.room_id = r.id
            WHERE rr.status IN ('pending', 'pending_payment', 'pending_approval')
            AND (t.checkin_time IS NULL OR t.checkin_time = '0000-00-00 00:00:00')
        ";

        $params = [];

        if (!empty($search_query)) {
            $query .= " AND (t.name LIKE :search OR t.email LIKE :search OR t.phone LIKE :search OR r.room_number LIKE :search)";
            $params['search'] = "%$search_query%";
        }

        $date_filter = $_GET['date_filter'] ?? 'all';
        if ($date_filter === 'today') {
            $query .= " AND DATE(rr.checkin_date) = CURDATE()";
        } elseif ($date_filter === 'week') {
            $query .= " AND DATE(rr.checkin_date) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($date_filter === 'month') {
            $query .= " AND DATE(rr.checkin_date) <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
        }

        $query .= " ORDER BY rr.checkin_date ASC";

        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $room_cancellations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($tab === 'archived') {
        // Build query for archived cancellations
        $query = "
            SELECT bca.*, t.name, t.email, t.phone, r.room_number, r.room_type
            FROM booking_cancellations_archive bca
            INNER JOIN tenants t ON bca.tenant_id = t.id
            INNER JOIN rooms r ON bca.room_id = r.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($search_query)) {
            $query .= " AND (t.name LIKE :search OR t.email LIKE :search OR t.phone LIKE :search OR r.room_number LIKE :search)";
            $params['search'] = "%$search_query%";
        }

        $date_filter = $_GET['date_filter'] ?? 'all';
        if ($date_filter === 'today') {
            $query .= " AND DATE(bca.archived_at) = CURDATE()";
        } elseif ($date_filter === 'week') {
            $query .= " AND DATE(bca.archived_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($date_filter === 'month') {
            $query .= " AND DATE(bca.archived_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        }

        $query .= " ORDER BY bca.archived_at DESC";

        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $archived_cancellations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Build query for active cancellations
        $query = "
            SELECT bc.*, t.name, t.email, t.phone, r.room_number, r.room_type
            FROM booking_cancellations bc
            INNER JOIN tenants t ON bc.tenant_id = t.id
            INNER JOIN rooms r ON bc.room_id = r.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($search_query)) {
            $query .= " AND (t.name LIKE :search OR t.email LIKE :search OR t.phone LIKE :search OR r.room_number LIKE :search)";
            $params['search'] = "%$search_query%";
        }

        $date_filter = $_GET['date_filter'] ?? 'all';
        if ($date_filter === 'today') {
            $query .= " AND DATE(bc.cancelled_at) = CURDATE()";
        } elseif ($date_filter === 'week') {
            $query .= " AND DATE(bc.cancelled_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($date_filter === 'month') {
            $query .= " AND DATE(bc.cancelled_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        }

        $query .= " ORDER BY bc.cancelled_at DESC";

        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $cancellations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get statistics for all cancellations (active + archived) so totals never decrease
    $stats_query = "
        SELECT 
            COUNT(*) as total_cancellations,
            COUNT(CASE WHEN DATE(cancelled_at) = CURDATE() THEN 1 END) as today_cancellations,
            SUM(payment_amount) as total_cancelled_payment,
            COUNT(DISTINCT tenant_id) as unique_customers
        FROM (
            SELECT cancelled_at, payment_amount, tenant_id FROM booking_cancellations
            UNION ALL
            SELECT archived_at as cancelled_at, payment_amount, tenant_id FROM booking_cancellations_archive
        ) combined
    ";
    
    $stmt = $conn->prepare($stats_query);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error_msg = "Error loading cancellations: " . $e->getMessage();
}

// Handle actions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $cancellation_id = intval($_POST['cancellation_id'] ?? 0);
    
    if ($_POST['action'] === 'approve_refund') {
        try {
            $refund_notes = $_POST['refund_notes'] ?? '';
            $refund_amount = floatval($_POST['refund_amount'] ?? 0);

            $conn->beginTransaction();

            // Get tenant_id from cancellation record first
            $cancel_stmt = $conn->prepare("SELECT tenant_id FROM booking_cancellations WHERE id = :id");
            $cancel_stmt->execute(['id' => $cancellation_id]);
            $cancel_row = $cancel_stmt->fetch(PDO::FETCH_ASSOC);
            $tenant_id = $cancel_row ? $cancel_row['tenant_id'] : null;

            // Update cancellation record with refund info
            $stmt = $conn->prepare("
                UPDATE booking_cancellations 
                SET refund_approved = 1, refund_amount = :amount, refund_notes = :notes, refund_date = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'amount' => $refund_amount,
                'notes' => $refund_notes,
                'id' => $cancellation_id
            ]);

            $conn->commit();
            
            // Send notification to customer about approved cancellation
            if ($tenant_id) {
                createNotification($conn, 'tenant', $tenant_id, 'booking_cancelled', 'Booking Cancelled', 'Your booking has been cancelled.', $cancellation_id, 'cancellation', 'tenant_dashboard.php');
            }
            
            $success_msg = "Refund approved and processed.";
        } catch (Exception $e) {
            $conn->rollBack();
            $error_msg = "Error processing refund: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'review_note') {
        try {
            $review_note = $_POST['review_note'] ?? '';

            $stmt = $conn->prepare("
                UPDATE booking_cancellations 
                SET admin_notes = :notes, reviewed_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'notes' => $review_note,
                'id' => $cancellation_id
            ]);

            $success_msg = "Review note added.";
        } catch (Exception $e) {
            $error_msg = "Error saving note: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'archive_record') {
        try {
            $archived_reason = $_POST['archived_reason'] ?? 'Admin archived';
            $admin_id = $_SESSION['id'] ?? 1;

            // Get cancellation details
            $get_cancel = $conn->prepare("SELECT * FROM booking_cancellations WHERE id = :id");
            $get_cancel->execute(['id' => $cancellation_id]);
            $cancel_data = $get_cancel->fetch(PDO::FETCH_ASSOC);

            if ($cancel_data) {
                // Insert into archive table
                $archive_stmt = $conn->prepare("
                    INSERT INTO booking_cancellations_archive 
                    (original_cancellation_id, tenant_id, room_id, booking_id, checkin_date, checkout_date, 
                     payment_amount, reason, refund_approved, refund_amount, refund_notes, refund_date, 
                     admin_notes, archived_by, archived_reason)
                    VALUES (:orig_id, :tenant_id, :room_id, :booking_id, :checkin, :checkout, 
                            :payment, :reason, :refund_approved, :refund_amount, :refund_notes, :refund_date,
                            :admin_notes, :archived_by, :archived_reason)
                ");
                $archive_stmt->execute([
                    'orig_id' => $cancellation_id,
                    'tenant_id' => $cancel_data['tenant_id'],
                    'room_id' => $cancel_data['room_id'],
                    'booking_id' => $cancel_data['booking_id'] ?? null,
                    'checkin' => $cancel_data['checkin_date'],
                    'checkout' => $cancel_data['checkout_date'],
                    'payment' => $cancel_data['payment_amount'],
                    'reason' => $cancel_data['reason'],
                    'refund_approved' => $cancel_data['refund_approved'],
                    'refund_amount' => $cancel_data['refund_amount'],
                    'refund_notes' => $cancel_data['refund_notes'],
                    'refund_date' => $cancel_data['refund_date'],
                    'admin_notes' => $cancel_data['admin_notes'],
                    'archived_by' => $admin_id,
                    'archived_reason' => $archived_reason
                ]);

                // Delete from active cancellations
                $delete_stmt = $conn->prepare("DELETE FROM booking_cancellations WHERE id = :id");
                $delete_stmt->execute(['id' => $cancellation_id]);

                $success_msg = "Record archived successfully.";
            } else {
                $error_msg = "Cancellation record not found.";
            }
        } catch (Exception $e) {
            $error_msg = "Error archiving record: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'restore_record') {
        try {
            $archive_id = $cancellation_id;

            // Get archived record details
            $get_archived = $conn->prepare("SELECT * FROM booking_cancellations_archive WHERE id = :id");
            $get_archived->execute(['id' => $archive_id]);
            $archived_data = $get_archived->fetch(PDO::FETCH_ASSOC);

            if ($archived_data) {
                // Insert back into active cancellations
                $restore_stmt = $conn->prepare("
                    INSERT INTO booking_cancellations 
                    (tenant_id, room_id, booking_id, checkin_date, checkout_date, 
                     payment_amount, reason, refund_approved, refund_amount, refund_notes, refund_date, 
                     admin_notes, cancelled_at)
                    VALUES (:tenant_id, :room_id, :booking_id, :checkin, :checkout, 
                            :payment, :reason, :refund_approved, :refund_amount, :refund_notes, :refund_date,
                            :admin_notes, :cancelled_at)
                ");
                $restore_stmt->execute([
                    'tenant_id' => $archived_data['tenant_id'],
                    'room_id' => $archived_data['room_id'],
                    'booking_id' => $archived_data['booking_id'],
                    'checkin' => $archived_data['checkin_date'],
                    'checkout' => $archived_data['checkout_date'],
                    'payment' => $archived_data['payment_amount'],
                    'reason' => $archived_data['reason'],
                    'refund_approved' => $archived_data['refund_approved'],
                    'refund_amount' => $archived_data['refund_amount'],
                    'refund_notes' => $archived_data['refund_notes'],
                    'refund_date' => $archived_data['refund_date'],
                    'admin_notes' => $archived_data['admin_notes'],
                    'cancelled_at' => $archived_data['archived_at']
                ]);

                // Delete from archive
                $delete_archived = $conn->prepare("DELETE FROM booking_cancellations_archive WHERE id = :id");
                $delete_archived->execute(['id' => $archive_id]);

                $success_msg = "Record restored successfully.";
                header("Location: admin_bookings.php?tab=active");
                exit;
            } else {
                $error_msg = "Archived record not found.";
            }
        } catch (Exception $e) {
            $error_msg = "Error restoring record: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'cancel_room_request') {
        try {
            $room_request_id = intval($_POST['room_request_id'] ?? 0);
            
            if (!$room_request_id) {
                throw new Exception('Invalid room request ID');
            }
            
            // Get room request details
            $rr_stmt = $conn->prepare("
                SELECT rr.*, t.id as tenant_id, t.name 
                FROM room_requests rr
                INNER JOIN tenants t ON rr.tenant_id = t.id
                WHERE rr.id = :id
            ");
            $rr_stmt->execute(['id' => $room_request_id]);
            $room_request = $rr_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$room_request) {
                throw new Exception('Room request not found');
            }
            
            // Get associated bill if exists
            $bill_stmt = $conn->prepare("
                SELECT id, amount_due FROM bills 
                WHERE tenant_id = :tenant_id AND room_id = :room_id
                LIMIT 1
            ");
            $bill_stmt->execute([
                'tenant_id' => $room_request['tenant_id'],
                'room_id' => $room_request['room_id']
            ]);
            $bill = $bill_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Record cancellation in booking_cancellations
            $cancel_stmt = $conn->prepare("
                INSERT INTO booking_cancellations 
                (tenant_id, room_id, booking_id, checkin_date, checkout_date, 
                 payment_amount, reason, cancelled_at)
                VALUES (:tenant_id, :room_id, :booking_id, :checkin, :checkout, 
                        :payment, :reason, NOW())
            ");
            $cancel_stmt->execute([
                'tenant_id' => $room_request['tenant_id'],
                'room_id' => $room_request['room_id'],
                'booking_id' => $room_request['id'],
                'checkin' => $room_request['checkin_date'],
                'checkout' => $room_request['checkout_date'],
                'payment' => $bill ? $bill['amount_due'] : 0,
                'reason' => 'Admin cancelled room request'
            ]);
            
            // Update room request status to rejected
            $update_stmt = $conn->prepare("
                UPDATE room_requests 
                SET status = 'rejected'
                WHERE id = :id
            ");
            $update_stmt->execute(['id' => $room_request_id]);
            
            // Free up the room if it was assigned
            if ($room_request['room_id']) {
                $room_stmt = $conn->prepare("
                    UPDATE rooms 
                    SET status = 'available'
                    WHERE id = :id
                ");
                $room_stmt->execute(['id' => $room_request['room_id']]);
            }
            
            // Send notification to tenant
            try {
                createNotification(
                    $conn,
                    'tenant',
                    $room_request['tenant_id'],
                    'booking_cancelled',
                    'Room Reservation Cancelled',
                    'Your room reservation has been cancelled by management.',
                    $room_request_id,
                    'booking',
                    'tenant_dashboard.php'
                );
            } catch (Exception $e) {
                error_log('Notification error: ' . $e->getMessage());
            }
            
            $success_msg = "Room request cancelled successfully.";
        } catch (Exception $e) {
            $error_msg = "Error cancelling room request: " . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Cancellations - BAMINT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .metric-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        .metric-value {
            font-size: 2.5rem;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .metric-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        .cancellation-card {
            background: white;
            border-radius: 8px;
            border-left: 4px solid #dc3545;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: transform 0.3s;
        }
        .cancellation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .customer-name {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.9rem;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #6c757d;
            font-weight: 500;
        }
        .info-value {
            color: #333;
            font-weight: 500;
        }
        .badge-status {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <?php include 'templates/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'templates/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="header-banner">
                    <h1><i class="bi bi-x-circle"></i> Booking Management</h1>
                    <p class="mb-0">View and manage customer booking cancellations and archives</p>
                </div>

                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Tab Navigation -->
                <ul class="nav nav-pills nav-fill mb-4" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $tab === 'active' ? 'active' : ''; ?>" 
                           href="?tab=active&search=<?php echo htmlspecialchars($search_query); ?>&date_filter=<?php echo htmlspecialchars($_GET['date_filter'] ?? 'all'); ?>" role="tab">
                            <i class="bi bi-hourglass-split"></i> Active Cancellations
                            <span class="badge bg-danger ms-2"><?php echo count($cancellations); ?></span>
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                                <a class="nav-link <?php echo $tab === 'room_cancellations' ? 'active' : ''; ?>" 
                                    href="?tab=room_cancellations&search=<?php echo htmlspecialchars($search_query); ?>&date_filter=<?php echo htmlspecialchars($_GET['date_filter'] ?? 'all'); ?>" role="tab">
                                     <i class="bi bi-hourglass-split"></i> Pending Approvals
                                     <span class="badge bg-warning ms-2"><?php echo count($room_cancellations); ?></span>
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $tab === 'archived' ? 'active' : ''; ?>" 
                           href="?tab=archived&search=<?php echo htmlspecialchars($search_query); ?>&date_filter=<?php echo htmlspecialchars($_GET['date_filter'] ?? 'all'); ?>" role="tab">
                            <i class="bi bi-archive"></i> Archived Records
                            <span class="badge bg-secondary ms-2"><?php echo count($archived_cancellations); ?></span>
                        </a>
                    </li>
                </ul>

                <!-- Statistics -->
                <?php if ($tab === 'active'): ?>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value"><?php echo $stats['total_cancellations'] ?? 0; ?></div>
                            <div class="metric-label">Total Cancellations</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"><?php echo $stats['today_cancellations'] ?? 0; ?></div>
                            <div class="metric-label">Cancelled Today</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">₱<?php echo number_format($stats['total_cancelled_payment'] ?? 0, 0); ?></div>
                            <div class="metric-label">Total Cancelled Payment</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"><?php echo $stats['unique_customers'] ?? 0; ?></div>
                            <div class="metric-label">Customers Cancelled</div>
                        </div>
                    </div>
                </div>
                <?php elseif ($tab === 'room_cancellations'): ?>
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="metric-card">
                            <div class="metric-value text-warning"><?php echo count($room_cancellations); ?></div>
                            <div class="metric-label">Pending Approvals</div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Archived Records - No Statistics -->
                <?php endif; ?>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                            <div class="col-md-5">
                                <label for="search" class="form-label">Search by Name, Email, Phone, or Room</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search...">
                            </div>
                            <div class="col-md-4">
                                <label for="date_filter" class="form-label">Filter by Date</label>
                                <select class="form-select" id="date_filter" name="date_filter">
                                    <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Time</option>
                                    <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                                    <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                                    <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Cancellations List -->
                <div>
                    <?php if ($tab === 'active'): ?>
                        <?php if (empty($cancellations)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No active cancellations found.
                            </div>
                        <?php else: ?>
                            <?php foreach ($cancellations as $cancel): ?>
                                <div class="cancellation-card">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="customer-name">
                                                <?php echo htmlspecialchars($cancel['name']); ?>
                                                <span class="badge badge-status" style="background: #dc3545; color: white;">
                                                    Pending
                                                </span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-envelope"></i> Email:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($cancel['email'] ?? 'N/A'); ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-telephone"></i> Phone:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($cancel['phone'] ?? 'N/A'); ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-door-open"></i> Room:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($cancel['room_number']); ?> (<?php echo htmlspecialchars($cancel['room_type']); ?>)</span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-calendar-check"></i> Check-in:</span>
                                                <span class="info-value"><?php echo $cancel['checkin_date'] ? date('M d, Y', strtotime($cancel['checkin_date'])) : 'N/A'; ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-calendar-x"></i> Check-out:</span>
                                                <span class="info-value"><?php echo $cancel['checkout_date'] ? date('M d, Y', strtotime($cancel['checkout_date'])) : 'N/A'; ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-cash-coin"></i> Payment Amount:</span>
                                                <span class="info-value text-danger">₱<?php echo number_format($cancel['payment_amount'], 2); ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-clock"></i> Cancelled on:</span>
                                                <span class="info-value"><?php echo date('M d, Y • H:i A', strtotime($cancel['cancelled_at'])); ?></span>
                                            </div>

                                            <?php if (!empty($cancel['reason']) && $cancel['reason'] !== 'Customer initiated cancellation'): ?>
                                                <div class="info-row" style="background: #f8f9fa; padding: 0.75rem; border-radius: 4px; margin-top: 0.5rem; border-left: 3px solid #6c757d;">
                                                    <div style="width: 100%;">
                                                        <span class="info-label"><i class="bi bi-chat-left-text"></i> Cancellation Reason:</span>
                                                        <div class="info-value" style="margin-top: 0.3rem; color: #555;"><?php echo htmlspecialchars($cancel['reason']); ?></div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                        </div>

                                        <div class="col-md-4">
                                            <div class="d-flex flex-column gap-2">
                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                                                        data-bs-target="#notesModal" 
                                                        onclick="setNotesData(<?php echo $cancel['id']; ?>, '<?php echo htmlspecialchars($cancel['admin_notes'] ?? ''); ?>')">
                                                    <i class="bi bi-sticky"></i> Add Note
                                                </button>

                                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal"
                                                        data-bs-target="#archiveModal"
                                                        onclick="setArchiveData(<?php echo $cancel['id']; ?>, '<?php echo htmlspecialchars($cancel['name']); ?>')">
                                                    <i class="bi bi-archive"></i> Archive
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php elseif ($tab === 'room_cancellations'): ?>
                        <!-- Room Cancellations Section -->
                        <?php if (empty($room_cancellations)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No pending booking requests found.
                            </div>
                        <?php else: ?>
                            <?php foreach ($room_cancellations as $room_req): ?>
                                <div class="cancellation-card" style="border-left-color: #ffc107;">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="customer-name">
                                                <?php echo htmlspecialchars($room_req['name']); ?>
                                                <span class="badge badge-status" style="background: #ffc107; color: black;">
                                                    Pending Approval
                                                </span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-envelope"></i> Email:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($room_req['email'] ?? 'N/A'); ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-telephone"></i> Phone:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($room_req['phone'] ?? 'N/A'); ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-door-open"></i> Room:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($room_req['room_number']); ?> (<?php echo htmlspecialchars($room_req['room_type']); ?>)</span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-calendar-check"></i> Requested Check-in:</span>
                                                <span class="info-value"><?php echo date('M d, Y', strtotime($room_req['checkin_date'])); ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-calendar-x"></i> Check-out Date:</span>
                                                <span class="info-value"><?php echo date('M d, Y', strtotime($room_req['checkout_date'])); ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-hourglass"></i> Days Until Check-in:</span>
                                                <span class="info-value">
                                                    <?php 
                                                        $days = $room_req['days_until_checkin'];
                                                        if ($days === 0) {
                                                            echo '<span class="text-danger"><strong>TODAY</strong></span>';
                                                        } elseif ($days === 1) {
                                                            echo '<span class="text-warning"><strong>Tomorrow</strong></span>';
                                                        } else {
                                                            echo htmlspecialchars($days) . ' day' . ($days > 1 ? 's' : '');
                                                        }
                                                    ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="d-flex flex-column gap-2">
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Cancel this pending booking request for <?php echo htmlspecialchars($room_req['name']); ?>?');">
                                                    <input type="hidden" name="action" value="cancel_room_request">
                                                    <input type="hidden" name="room_request_id" value="<?php echo htmlspecialchars($room_req['id']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger w-100">
                                            <i class="bi bi-x-circle"></i> Cancel Request
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- Archived Records Section -->
                        <?php if (empty($archived_cancellations)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No archived records found.
                            </div>
                        <?php else: ?>
                            <?php foreach ($archived_cancellations as $archive): ?>
                                <div class="cancellation-card" style="border-left-color: #6c757d; opacity: 0.95;">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="customer-name">
                                                <?php echo htmlspecialchars($archive['name']); ?>
                                                <span class="badge badge-status" style="background: #6c757d; color: white;">
                                                    Archived
                                                </span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-envelope"></i> Email:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($archive['email'] ?? 'N/A'); ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-telephone"></i> Phone:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($archive['phone'] ?? 'N/A'); ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-door-open"></i> Room:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($archive['room_number']); ?> (<?php echo htmlspecialchars($archive['room_type']); ?>)</span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-calendar-check"></i> Check-in:</span>
                                                <span class="info-value"><?php echo $archive['checkin_date'] ? date('M d, Y', strtotime($archive['checkin_date'])) : 'N/A'; ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-calendar-x"></i> Check-out:</span>
                                                <span class="info-value"><?php echo $archive['checkout_date'] ? date('M d, Y', strtotime($archive['checkout_date'])) : 'N/A'; ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-cash-coin"></i> Payment Amount:</span>
                                                <span class="info-value text-danger">₱<?php echo number_format($archive['payment_amount'], 2); ?></span>
                                            </div>

                                            <div class="info-row">
                                                <span class="info-label"><i class="bi bi-archive"></i> Archived on:</span>
                                                <span class="info-value"><?php echo date('M d, Y • H:i A', strtotime($archive['archived_at'])); ?></span>
                                            </div>

                                            <?php if (!empty($archive['archived_reason'])): ?>
                                                <div class="info-row" style="background: #e9ecef; padding: 0.75rem; border-radius: 4px; margin-top: 0.5rem; border-left: 3px solid #495057;">
                                                    <span class="info-label" style="color: #495057;"><i class="bi bi-info-circle"></i> Archive Reason:</span>
                                                    <span class="info-value" style="color: #495057;"><?php echo htmlspecialchars($archive['archived_reason']); ?></span>
                                                </div>
                                            <?php endif; ?>

                                        </div>

                                        <div class="col-md-4">
                                            <div class="d-flex flex-column gap-2">
                                                <button class="btn btn-sm btn-success" data-bs-toggle="modal"
                                                        data-bs-target="#restoreModal"
                                                        onclick="setRestoreData(<?php echo $archive['id']; ?>, '<?php echo htmlspecialchars($archive['name']); ?>')">
                                                    <i class="bi bi-arrow-counterclockwise"></i> Restore
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Notes Modal -->
    <div class="modal fade" id="notesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-sticky"></i> Add Review Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="cancellation_id" id="notesCancellationId">
                        <input type="hidden" name="action" value="review_note">
                        
                        <div class="mb-3">
                            <label for="reviewNote" class="form-label">Add Review Note</label>
                            <textarea class="form-control" id="reviewNote" name="review_note" rows="4"
                                      placeholder="Add internal notes about this cancellation..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Save Note
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Archive Modal -->
    <div class="modal fade" id="archiveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-archive"></i> Archive Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="cancellation_id" id="archiveCancellationId">
                        <input type="hidden" name="action" value="archive_record">
                        
                        <p>Are you sure you want to archive this record?</p>
                        <p><strong id="archiveCustomerName"></strong></p>

                        <div class="mb-3">
                            <label for="archivedReason" class="form-label">Archive Reason</label>
                            <select class="form-select" id="archivedReason" name="archived_reason">
                                <option value="Completed">Completed</option>
                                <option value="Refund Processed">Refund Processed</option>
                                <option value="Closed - Customer Inactive">Closed - Customer Inactive</option>
                                <option value="Admin Archived">Admin Archived</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-archive"></i> Archive Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Restore Modal -->
    <div class="modal fade" id="restoreModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise"></i> Restore Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="cancellation_id" id="restoreCancellationId">
                        <input type="hidden" name="action" value="restore_record">
                        
                        <p>Restore this archived record back to active cancellations?</p>
                        <p><strong id="restoreCustomerName"></strong></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-arrow-counterclockwise"></i> Restore Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setNotesData(cancellationId, existingNotes) {
            document.getElementById('notesCancellationId').value = cancellationId;
            document.getElementById('reviewNote').value = existingNotes;
        }

        function setArchiveData(cancellationId, customerName) {
            document.getElementById('archiveCancellationId').value = cancellationId;
            document.getElementById('archiveCustomerName').textContent = customerName;
        }

        function setRestoreData(archiveId, customerName) {
            document.getElementById('restoreCancellationId').value = archiveId;
            document.getElementById('restoreCustomerName').textContent = customerName;
        }

        function viewCancellationDetails(cancellationId) {
            // Could implement a detailed view modal
            console.log('View details for cancellation:', cancellationId);
        }

        function confirmArchiveAccount(cancellationId, tenantId, customerName) {
            if (confirm(`Are you sure you want to archive the account for ${customerName}? This action will move their account to the archive.`)) {
                archiveAccount(cancellationId, tenantId);
            }
        }

        function archiveAccount(cancellationId, tenantId) {
            const formData = new FormData();
            formData.append('cancellation_id', cancellationId);
            formData.append('tenant_id', tenantId);

            fetch('api_archive_tenant.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    alert('Account archived successfully!');
                    // Reload the page to reflect changes
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to archive account'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error archiving account: ' + error.message);
            });
        }
    </script>
</body>
</html>
