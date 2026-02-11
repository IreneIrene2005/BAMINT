<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'front_desk'])) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

$filter_status = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$date_filter = $_GET['date_filter'] ?? 'all';
$tab = $_GET['tab'] ?? 'active'; // 'active', 'archived', or 'room_cancellations'
$success_msg = "";
$error_msg = "";
$stats = [];
$pending_cancellations = [];
$approved_cancellations = [];
$archived_cancellations = [];
$room_cancellations = [];

// Check if approval was successful
if (isset($_SESSION['approval_success']) && $_SESSION['approval_success']) {
    $success_msg = "Cancellation request approved and processed.";
    unset($_SESSION['approval_success']);
}

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
        // Build query for PENDING cancellations awaiting approval
        $query = "
            SELECT bc.*, t.name, t.email, t.phone, r.room_number, r.room_type
            FROM booking_cancellations bc
            INNER JOIN tenants t ON bc.tenant_id = t.id
            INNER JOIN rooms r ON bc.room_id = r.id
            WHERE bc.refund_approved = 0
        ";

        $params = [];

        if (!empty($search_query)) {
            $query .= " AND (t.name LIKE :search OR t.email LIKE :search OR t.phone LIKE :search OR r.room_number LIKE :search)";
            $params['search'] = "%$search_query%";
        }

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
        $pending_cancellations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build query for APPROVED cancellations (already processed)
        $approved_query = "
            SELECT bc.*, t.name, t.email, t.phone, r.room_number, r.room_type
            FROM booking_cancellations bc
            INNER JOIN tenants t ON bc.tenant_id = t.id
            INNER JOIN rooms r ON bc.room_id = r.id
            WHERE bc.refund_approved = 1
        ";

        $approved_params = [];

        if (!empty($search_query)) {
            $approved_query .= " AND (t.name LIKE :search OR t.email LIKE :search OR t.phone LIKE :search OR r.room_number LIKE :search)";
            $approved_params['search'] = "%$search_query%";
        }

        if ($date_filter === 'today') {
            $approved_query .= " AND DATE(bc.refund_date) = CURDATE()";
        } elseif ($date_filter === 'week') {
            $approved_query .= " AND DATE(bc.refund_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($date_filter === 'month') {
            $approved_query .= " AND DATE(bc.refund_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        }

        $approved_query .= " ORDER BY bc.refund_date DESC";

        $stmt = $conn->prepare($approved_query);
        $stmt->execute($approved_params);
        $approved_cancellations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get statistics for APPROVED cancellations + ARCHIVED records (preserved history)
    $stats_query = "
        SELECT 
            COUNT(*) as total_cancellations,
            COUNT(CASE WHEN DATE(refund_date) = CURDATE() THEN 1 END) as today_cancellations,
            SUM(refund_amount) as total_cancelled_payment,
            COUNT(DISTINCT tenant_id) as unique_customers
        FROM (
            SELECT refund_date, refund_amount, tenant_id FROM booking_cancellations WHERE refund_approved = 1
            UNION ALL
            SELECT archived_at as refund_date, payment_amount as refund_amount, tenant_id FROM booking_cancellations_archive
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
    
    if ($_POST['action'] === 'approve_cancellation_request') {
        try {
            $refund_notes = $_POST['refund_notes'] ?? '';
            $refund_amount = floatval($_POST['refund_amount'] ?? 0);

            $conn->beginTransaction();

            // Get cancellation details
            $cancel_stmt = $conn->prepare("
                SELECT bc.*, b.id as bill_id, r.id as room_id
                FROM booking_cancellations bc
                INNER JOIN bills b ON bc.bill_id = b.id
                INNER JOIN rooms r ON bc.room_id = r.id
                WHERE bc.id = :id
            ");
            $cancel_stmt->execute(['id' => $cancellation_id]);
            $cancellation = $cancel_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cancellation) {
                $error_msg = "Cancellation request not found.";
            } else {
                $tenant_id = $cancellation['tenant_id'];
                $bill_id = $cancellation['bill_id'];
                $room_id = $cancellation['room_id'];

                // Update cancellation record with approval
                $approve_stmt = $conn->prepare("
                    UPDATE booking_cancellations 
                    SET refund_approved = 1, refund_amount = :amount, refund_notes = :notes, refund_date = NOW(), reviewed_at = NOW()
                    WHERE id = :id
                ");
                $approve_stmt->execute([
                    'amount' => $refund_amount,
                    'notes' => $refund_notes,
                    'id' => $cancellation_id
                ]);

                // Create refund as a negative payment transaction so it shows in tenant records
                if ($refund_amount > 0) {
                    $refund_stmt = $conn->prepare("
                        INSERT INTO payment_transactions (bill_id, tenant_id, payment_amount, payment_status, payment_date, notes)
                        VALUES (:bill_id, :tenant_id, :payment_amount, 'refunded', CURDATE(), :notes)
                    ");
                    $refund_notes_full = 'Cancellation Refund - ' . ($refund_notes ?: 'Customer requested cancellation');
                    $refund_stmt->execute([
                        'bill_id' => $bill_id,
                        'tenant_id' => $tenant_id,
                        'payment_amount' => -$refund_amount, // negative to represent refund
                        'notes' => $refund_notes_full
                    ]);
                }

                // Now process the actual cancellation
                // Update bill status to cancelled
                $bill_update = $conn->prepare("UPDATE bills SET status = 'cancelled', updated_at = NOW() WHERE id = :bill_id");
                $bill_update->execute(['bill_id' => $bill_id]);

                // Mark room as available again
                $room_update = $conn->prepare("UPDATE rooms SET status = 'available' WHERE id = :room_id");
                $room_update->execute(['room_id' => $room_id]);

                // Clear tenant's room assignment
                $tenant_clear = $conn->prepare("UPDATE tenants SET room_id = NULL WHERE id = :tenant_id");
                $tenant_clear->execute(['tenant_id' => $tenant_id]);

                // Update room_request status to cancelled
                $room_request_update = $conn->prepare("UPDATE room_requests SET status = 'cancelled' WHERE tenant_id = :tenant_id AND room_id = :room_id AND status NOT IN ('archived', 'deleted', 'rejected', 'cancelled')");
                $room_request_update->execute(['tenant_id' => $tenant_id, 'room_id' => $room_id]);

                $conn->commit();

                // Send notification to customer about approved cancellation
                createNotification(
                    $conn,
                    'tenant',
                    $tenant_id,
                    'cancellation_approved',
                    'Cancellation Approved',
                    'Your booking cancellation has been approved. Refund Amount: ₱' . number_format($refund_amount, 2) . '.',
                    $bill_id,
                    'booking',
                    'tenant_dashboard.php'
                );

                $success_msg = "Cancellation request approved and processed.";
                
                // Redirect to refresh the page and show the approved cancellation in the modal
                $_SESSION['approval_success'] = true;
                header("Location: ?tab=active&search=" . urlencode($search_query) . "&date_filter=" . urlencode($date_filter));
                exit;
            }
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error_msg = "Error approving cancellation: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'reject_cancellation_request') {
        try {
            $rejection_reason = $_POST['rejection_reason'] ?? 'Your cancellation request was rejected.';

            // Get tenant_id
            $cancel_stmt = $conn->prepare("SELECT tenant_id FROM booking_cancellations WHERE id = :id");
            $cancel_stmt->execute(['id' => $cancellation_id]);
            $cancel_row = $cancel_stmt->fetch(PDO::FETCH_ASSOC);
            $tenant_id = $cancel_row ? $cancel_row['tenant_id'] : null;

            // Delete the cancellation request (since it was rejected)
            $stmt = $conn->prepare("DELETE FROM booking_cancellations WHERE id = :id");
            $stmt->execute(['id' => $cancellation_id]);

            // Send notification to customer about rejection
            if ($tenant_id) {
                createNotification(
                    $conn,
                    'tenant',
                    $tenant_id,
                    'cancellation_rejected',
                    'Cancellation Request Rejected',
                    'Your cancellation request has been rejected. ' . $rejection_reason,
                    null,
                    'booking',
                    'tenant_dashboard.php'
                );
            }

            $success_msg = "Cancellation request rejected.";
        } catch (Exception $e) {
            $error_msg = "Error rejecting cancellation: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'approve_refund') {
        try {
            $refund_notes = $_POST['refund_notes'] ?? '';
            $refund_amount = floatval($_POST['refund_amount'] ?? 0);

            $conn->beginTransaction();

            // Get tenant_id and bill_id from cancellation record first
            $cancel_stmt = $conn->prepare("SELECT tenant_id, bill_id FROM booking_cancellations WHERE id = :id");
            $cancel_stmt->execute(['id' => $cancellation_id]);
            $cancel_row = $cancel_stmt->fetch(PDO::FETCH_ASSOC);
            $tenant_id = $cancel_row ? $cancel_row['tenant_id'] : null;
            $bill_id = $cancel_row ? $cancel_row['bill_id'] : null;

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

            // Create refund as a negative payment transaction so it shows in tenant records
            if ($bill_id && $refund_amount > 0) {
                $refund_stmt = $conn->prepare("
                    INSERT INTO payment_transactions (bill_id, tenant_id, payment_amount, payment_status, payment_date, notes)
                    VALUES (:bill_id, :tenant_id, :payment_amount, 'refunded', CURDATE(), :notes)
                ");
                $refund_notes_full = 'Cancellation Refund - ' . ($refund_notes ?: 'Customer requested cancellation');
                $refund_stmt->execute([
                    'bill_id' => $bill_id,
                    'tenant_id' => $tenant_id,
                    'payment_amount' => -$refund_amount, // negative to represent refund
                    'notes' => $refund_notes_full
                ]);
            }

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
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .metric-card {
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 1.5rem;
            background: white;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .metric-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .metric-value {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        .metric-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <?php include 'templates/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'templates/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
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
                            <span class="badge bg-danger ms-2"><?php echo count($pending_cancellations) + count($approved_cancellations); ?></span>
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
                            <div class="metric-value" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"><?php echo $stats['total_cancellations'] ?? 0; ?></div>
                            <div class="metric-label">Total Cancellations</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">₱<?php echo number_format($stats['total_cancelled_payment'] ?? 0, 0); ?></div>
                            <div class="metric-label">Total Cancelled Payment</div>
                        </div>
                    </div>
                    <!-- 'Cancelled Today' and 'Customers Cancelled' metrics removed per request -->
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
                        <!-- PENDING CANCELLATIONS AWAITING APPROVAL -->
                        <?php if (empty($pending_cancellations)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No pending cancellation requests awaiting approval.
                            </div>
                        <?php else: ?>
                            <h5 class="mb-3"><i class="bi bi-hourglass-split"></i> Pending Approval</h5>
                            <div class="row">
                                <?php foreach ($pending_cancellations as $cancel): ?>
                                <div class="col-lg-6 mb-4">
                                    <div class="card border-danger shadow-sm h-100">
                                        <div class="card-header bg-danger text-white">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="card-title mb-1">
                                                        <i class="bi bi-clock-history"></i> 
                                                        <?php echo htmlspecialchars($cancel['name']); ?>
                                                    </h6>
                                                    <small>Room <?php echo htmlspecialchars($cancel['room_number']); ?> | <?php echo htmlspecialchars($cancel['room_type']); ?></small>
                                                </div>
                                                <span class="badge bg-light text-danger"><i class="bi bi-clock"></i> Awaiting Approval</span>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div class="row g-2 mb-3">
                                                <div class="col-6">
                                                    <small class="text-muted">Requested At:</small>
                                                    <div class="fw-bold"><?php echo date('M d, h:i A', strtotime($cancel['cancelled_at'])); ?></div>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Check-in Date:</small>
                                                    <div class="fw-bold"><?php echo $cancel['checkin_date'] ? date('M d, Y', strtotime($cancel['checkin_date'])) : 'N/A'; ?></div>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Email:</small>
                                                    <div class="fw-bold"><a href="mailto:<?php echo htmlspecialchars($cancel['email']); ?>"><?php echo htmlspecialchars($cancel['email']); ?></a></div>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Phone:</small>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($cancel['phone']); ?></div>
                                                </div>
                                                <div class="col-12">
                                                    <small class="text-muted">Payment Amount:</small>
                                                    <div class="fw-bold text-danger">₱<?php echo number_format($cancel['payment_amount'], 2); ?></div>
                                                </div>
                                            </div>

                                            <?php if ($cancel['reason']): ?>
                                            <div class="alert alert-light mb-3" style="border-left: 3px solid #ffc107;">
                                                <small class="text-muted">Cancellation Reason:</small>
                                                <p class="mb-0 mt-1 text-dark"><?php echo htmlspecialchars($cancel['reason']); ?></p>
                                            </div>
                                            <?php endif; ?>

                                            <!-- Approval Form -->
                                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="mb-3">
                                                <input type="hidden" name="action" value="approve_cancellation_request">
                                                <input type="hidden" name="cancellation_id" value="<?php echo $cancel['id']; ?>">
                                                
                                                <div class="mb-2">
                                                    <label for="refund_amount_<?php echo $cancel['id']; ?>" class="form-label">Amount</label>
                                                    <input type="number" class="form-control" id="refund_amount_<?php echo $cancel['id']; ?>" name="refund_amount" step="0.01" value="<?php echo $cancel['payment_amount']; ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="refund_notes_<?php echo $cancel['id']; ?>" class="form-label">Refund Notes (Optional)</label>
                                                    <textarea class="form-control form-control-sm" id="refund_notes_<?php echo $cancel['id']; ?>" name="refund_notes" rows="2" placeholder="Add any notes..."></textarea>
                                                </div>
                                                
                                                <div class="d-grid gap-2">
                                                    <button type="submit" class="btn btn-success btn-sm">
                                                        <i class="bi bi-check-circle"></i> Approve Cancellation
                                                    </button>
                                                </div>
                                            </form>

                                            <!-- Rejection Form -->
                                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                                                <input type="hidden" name="action" value="reject_cancellation_request">
                                                <input type="hidden" name="cancellation_id" value="<?php echo $cancel['id']; ?>">
                                                
                                                <div class="mb-2">
                                                    <label for="rejection_reason_<?php echo $cancel['id']; ?>" class="form-label">Rejection Reason (Optional)</label>
                                                    <textarea class="form-control form-control-sm" id="rejection_reason_<?php echo $cancel['id']; ?>" name="rejection_reason" rows="2" placeholder="Why are you rejecting this cancellation?"></textarea>
                                                </div>
                                                
                                                <div class="d-grid gap-2">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure you want to reject this cancellation request?');">
                                                        <i class="bi bi-x-circle"></i> Reject Request
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- APPROVED CANCELLATIONS (RECORDED) -->
                        <div class="mt-5">
                            <?php if (empty($approved_cancellations)): ?>
                                <!-- No approved cancellations yet -->
                            <?php else: ?>
                                <h5 class="mb-3"><i class="bi bi-check-circle"></i> Approved Cancellations</h5>
                                <div class="row">
                                    <?php foreach ($approved_cancellations as $approved): ?>
                                    <div class="col-lg-6 mb-4">
                                        <div class="card border-success shadow-sm h-100">
                                            <div class="card-header bg-success text-white">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="card-title mb-1">
                                                            <i class="bi bi-check-circle"></i> 
                                                            <?php echo htmlspecialchars($approved['name']); ?>
                                                        </h6>
                                                        <small>Room <?php echo htmlspecialchars($approved['room_number']); ?> | <?php echo htmlspecialchars($approved['room_type']); ?></small>
                                                    </div>
                                                    <span class="badge bg-light text-success"><i class="bi bi-check"></i> Approved</span>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <div class="row g-2 mb-3">
                                                    <div class="col-6">
                                                        <small class="text-muted">Cancelled At:</small>
                                                        <div class="fw-bold"><?php echo date('M d, h:i A', strtotime($approved['cancelled_at'])); ?></div>
                                                    </div>
                                                    <div class="col-6">
                                                        <small class="text-muted">Approved On:</small>
                                                        <div class="fw-bold"><?php echo date('M d, h:i A', strtotime($approved['refund_date'])); ?></div>
                                                    </div>
                                                    <div class="col-6">
                                                        <small class="text-muted">Email:</small>
                                                        <div class="fw-bold"><a href="mailto:<?php echo htmlspecialchars($approved['email']); ?>"><?php echo htmlspecialchars($approved['email']); ?></a></div>
                                                    </div>
                                                    <div class="col-6">
                                                        <small class="text-muted">Phone:</small>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($approved['phone']); ?></div>
                                                    </div>
                                                    <div class="col-6">
                                                        <small class="text-muted">Payment Amount:</small>
                                                        <div class="fw-bold text-danger">₱<?php echo number_format($approved['payment_amount'], 2); ?></div>
                                                    </div>
                                                    <div class="col-6">
                                                        <small class="text-muted">Amount:</small>
                                                        <div class="fw-bold text-success">₱<?php echo number_format($approved['refund_amount'], 2); ?></div>
                                                    </div>
                                                </div>

                                                <?php if ($approved['refund_notes']): ?>
                                                <div class="alert alert-light mb-3" style="border-left: 3px solid #28a745;">
                                                    <small class="text-muted">Refund Notes:</small>
                                                    <p class="mb-0 mt-1 text-dark"><?php echo htmlspecialchars($approved['refund_notes']); ?></p>
                                                </div>
                                                <?php endif; ?>

                                                <?php if ($approved['reason']): ?>
                                                <div class="alert alert-light mb-3" style="border-left: 3px solid #ffc107;">
                                                    <small class="text-muted">Customer Reason:</small>
                                                    <p class="mb-0 mt-1 text-dark"><?php echo htmlspecialchars($approved['reason']); ?></p>
                                                </div>
                                                <?php endif; ?>

                                                <!-- Archive Button -->
                                                <div class="d-grid gap-2">
                                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#archiveModal" onclick="setArchiveData(<?php echo $approved['id']; ?>, '<?php echo htmlspecialchars(addslashes($approved['name']), ENT_QUOTES); ?>')">
                                                        <i class="bi bi-archive"></i> Archive Record
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
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
                            <div class="row g-2">
                                <?php foreach ($archived_cancellations as $archive): ?>
                                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
                                        <div class="card h-100 shadow-sm" style="border: 1px solid #dee2e6; border-left: 4px solid #6c757d;">
                                            <div class="card-body p-2">
                                                <!-- Header with name and archived badge -->
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h6 class="card-title mb-0" style="font-size: 0.9rem;"><?php echo htmlspecialchars($archive['name']); ?></h6>
                                                    <span class="badge bg-secondary" style="font-size: 0.7rem;">Archived</span>
                                                </div>

                                                <!-- Card content -->
                                                <div class="text-sm" style="font-size: 0.85rem;">
                                                    <div class="mb-1">
                                                        <small class="text-muted d-block"><i class="bi bi-envelope"></i> Email</small>
                                                        <div style="font-size: 0.8rem;"><?php echo htmlspecialchars($archive['email'] ?? 'N/A'); ?></div>
                                                    </div>

                                                    <div class="mb-1">
                                                        <small class="text-muted d-block"><i class="bi bi-telephone"></i> Phone</small>
                                                        <div style="font-size: 0.8rem;"><?php echo htmlspecialchars($archive['phone'] ?? 'N/A'); ?></div>
                                                    </div>

                                                    <div class="mb-1">
                                                        <small class="text-muted d-block"><i class="bi bi-door-open"></i> Room</small>
                                                        <div style="font-size: 0.8rem;"><?php echo htmlspecialchars($archive['room_number']); ?> (<?php echo htmlspecialchars($archive['room_type']); ?>)</div>
                                                    </div>

                                                    <div class="mb-1">
                                                        <small class="text-muted d-block"><i class="bi bi-calendar-check"></i> Check-in</small>
                                                        <div style="font-size: 0.8rem;"><?php echo $archive['checkin_date'] ? date('M d, Y', strtotime($archive['checkin_date'])) : 'N/A'; ?></div>
                                                    </div>

                                                    <div class="mb-1">
                                                        <small class="text-muted d-block"><i class="bi bi-calendar-x"></i> Check-out</small>
                                                        <div style="font-size: 0.8rem;"><?php echo $archive['checkout_date'] ? date('M d, Y', strtotime($archive['checkout_date'])) : 'N/A'; ?></div>
                                                    </div>

                                                    <div class="mb-1">
                                                        <small class="text-muted d-block"><i class="bi bi-cash-coin"></i> Payment</small>
                                                        <div class="text-danger fw-bold" style="font-size: 0.8rem;">₱<?php echo number_format($archive['payment_amount'], 2); ?></div>
                                                    </div>

                                                    <div class="mb-1">
                                                        <small class="text-muted d-block"><i class="bi bi-archive"></i> Archived</small>
                                                        <div style="font-size: 0.8rem;"><?php echo date('M d, Y • H:i A', strtotime($archive['archived_at'])); ?></div>
                                                    </div>

                                                    <?php if (!empty($archive['archived_reason'])): ?>
                                                        <div class="mt-1 p-1" style="background: #f8f9fa; border-left: 2px solid #6c757d; border-radius: 3px; font-size: 0.75rem;">
                                                            <small class="text-muted"><i class="bi bi-info-circle"></i> Reason:</small>
                                                            <div><?php echo htmlspecialchars($archive['archived_reason']); ?></div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Card footer with action button -->
                                            <div class="card-footer bg-white border-top p-1">
                                                <button class="btn btn-sm btn-success w-100" style="font-size: 0.8rem; padding: 0.25rem 0.5rem;" data-bs-toggle="modal"
                                                        data-bs-target="#restoreModal"
                                                        onclick="setRestoreData(<?php echo $archive['id']; ?>, '<?php echo htmlspecialchars($archive['name']); ?>')">
                                                    <i class="bi bi-arrow-counterclockwise"></i> Restore
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Cancellation Approved Modal -->
    <div class="modal fade" id="cancellationApprovedModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-success">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-check-circle"></i> Cancellation Approved</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div style="font-size: 3rem; color: #28a745; margin-bottom: 1rem;">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <h6>Cancellation Approved Successfully</h6>
                    <p class="text-muted">The customer's booking cancellation has been approved and recorded.</p>
                    <p class="mb-0"><strong>Customer Notification:</strong> Sent</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal">
                        <i class="bi bi-check"></i> Done
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Archive Record Modal -->
    <div class="modal fade" id="archiveModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="modal-content">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title"><i class="bi bi-archive"></i> Archive Record</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="archive_record">
                    <input type="hidden" name="cancellation_id" id="archiveCancellationId">
                    
                    <div class="mb-3">
                        <p><strong>Customer:</strong> <span id="archiveCustomerName"></span></p>
                        <p class="text-muted mb-3">Are you sure you want to move this record to Archived Records?</p>
                    </div>
                    
                    <div class="mb-0">
                        <label for="archiveReason" class="form-label">Archival Reason (Optional)</label>
                        <textarea class="form-control" id="archiveReason" name="archived_reason" rows="2" placeholder="Why are you archiving this record?"></textarea>
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
        // Show approval modal if success message is present
        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                const alertText = successAlert.textContent || '';
                if (alertText.includes('approved and processed')) {
                    setTimeout(() => {
                        try {
                            const modalElement = document.getElementById('cancellationApprovedModal');
                            if (modalElement) {
                                const modal = new bootstrap.Modal(modalElement);
                                modal.show();
                            } else {
                                console.warn('Modal element not found');
                            }
                        } catch (error) {
                            console.error('Error showing modal:', error);
                        }
                    }, 500);
                }
            }
        });

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
