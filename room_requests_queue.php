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

require_once "db_pdo.php";
require_once "db/notifications.php";

// Rename $pdo to $conn for compatibility
$conn = $pdo;

function getRequestPaymentInfo($conn, $tenant_id, $room_id) {
    $advance_stmt = $conn->prepare("SELECT id, amount_due FROM bills WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
    $advance_stmt->execute(['tenant_id' => $tenant_id, 'room_id' => $room_id]);
    $advance_bill = $advance_stmt->fetch(PDO::FETCH_ASSOC);

    $paid_amount = 0;
    $amount_due = 0;
    $payment_type = 'no_payment';

    if ($advance_bill) {
        $amount_due = floatval($advance_bill['amount_due']);
        $pay_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount), 0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_amount > 0 AND payment_status IN ('verified', 'approved')");
        $pay_stmt->execute(['bill_id' => $advance_bill['id']]);
        $pay = $pay_stmt->fetch(PDO::FETCH_ASSOC);
        $paid_amount = $pay && $pay['paid'] ? floatval($pay['paid']) : 0;

        if ($paid_amount >= $amount_due && $amount_due > 0) {
            $payment_type = 'full_payment';
        } elseif ($paid_amount > 0) {
            $payment_type = 'downpayment';
        }
    }

    return [
        'payment_type' => $payment_type,
        'paid_amount' => $paid_amount,
        'amount_due' => $amount_due,
        'bill_id' => $advance_bill['id'] ?? null
    ];
}

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

    // Group approval for same-tenant concurrent bookings
    if ($_POST['action'] === 'approve_group') {
        $tenant_id = intval($_POST['tenant_id'] ?? 0);
        $request_ids_raw = trim($_POST['request_ids'] ?? '');
        $request_ids = $request_ids_raw !== '' ? array_filter(array_map('intval', explode(',', $request_ids_raw))) : [];

        if ($tenant_id > 0) {
            try {
                $conn->beginTransaction();

                // Fetch eligible requests for this tenant
                $sql = "SELECT id, room_id, status FROM room_requests WHERE tenant_id = ? AND status = 'pending_payment'";
                $params = [$tenant_id];
                if (!empty($request_ids)) {
                    $in = implode(',', array_fill(0, count($request_ids), '?'));
                    $sql .= " AND id IN ($in)";
                    $params = array_merge($params, $request_ids);
                }

                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $requests_to_approve = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($requests_to_approve)) {
                    throw new Exception('No eligible requests found for group approval.');
                }

                $eligible_requests = [];
                $unpaid_requests = [];

                foreach ($requests_to_approve as $req) {
                    $payment_info = getRequestPaymentInfo($conn, $tenant_id, $req['room_id']);
                    if ($payment_info['payment_type'] === 'no_payment') {
                        $unpaid_requests[] = $req;
                    }
                    $eligible_requests[] = $req; // group approval must allow all in selected set
                }

                if (empty($eligible_requests)) {
                    throw new Exception('No eligible requests found for approval.');
                }

                foreach ($eligible_requests as $req) {
                    $room_stmt = $conn->prepare("UPDATE rooms SET status = 'occupied' WHERE id = :room_id");
                    $room_stmt->execute(['room_id' => $req['room_id']]);

                    $update_rr = $conn->prepare("UPDATE room_requests SET status = 'approved' WHERE id = :id");
                    $update_rr->execute(['id' => $req['id']]);
                }

                // Activate tenant account and keep room reference
                $first_room_id = $eligible_requests[0]['room_id'] ?? null;
                $activate = $conn->prepare("UPDATE tenants SET status = 'active', start_date = COALESCE(start_date, CURDATE()), room_id = COALESCE(room_id, :room_id) WHERE id = :id");
                $activate->execute(['id' => $tenant_id, 'room_id' => $first_room_id]);

                $conn->commit();

                // Notify tenant for each request approved
                foreach ($requests_to_approve as $req) {
                    notifyTenantBookingApproved($conn, $tenant_id, $req['id']);
                }

                if (!empty($unpaid_requests)) {
                    $message = 'Group approval completed. ' . count($eligible_requests) . ' room(s) approved and occupied (including ' . count($unpaid_requests) . ' unpaid request(s) forced to approved).';
                } else {
                    $message = 'Group approval successful. All related rooms are now occupied.';
                }
                $message_type = 'success';
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $message = 'Group approval failed: ' . $e->getMessage();
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

// Group requests by tenant and request date (minute precision) to merge same-time bookings
$grouped_requests = [];
$request_payment_cache = [];

foreach ($requests as $request) {
    $group_key = $request['tenant_id'] . '_' . date('Y-m-d_H-i', strtotime($request['request_date']));

    // Compute payment status for each room request
    $payment_cache_key = $request['tenant_id'] . '_' . $request['room_id'];
    if (!isset($request_payment_cache[$payment_cache_key])) {
        $advance_stmt = $conn->prepare("SELECT id, amount_due FROM bills WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
        $advance_stmt->execute(['tenant_id' => $request['tenant_id'], 'room_id' => $request['room_id']]);
        $advance_bill = $advance_stmt->fetch(PDO::FETCH_ASSOC);

        $paid_amount = 0;
        $amount_due = 0;
        $payment_type = 'no_payment';

        if ($advance_bill) {
            $amount_due = floatval($advance_bill['amount_due']);
            $pay_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount), 0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_amount > 0 AND payment_status IN ('verified', 'approved')");
            $pay_stmt->execute(['bill_id' => $advance_bill['id']]);
            $pay = $pay_stmt->fetch(PDO::FETCH_ASSOC);
            $paid_amount = $pay && $pay['paid'] ? floatval($pay['paid']) : 0;

            if ($paid_amount >= $amount_due && $amount_due > 0) {
                $payment_type = 'full_payment';
            } elseif ($paid_amount > 0) {
                $payment_type = 'downpayment';
            }
        }

        $request_payment_cache[$payment_cache_key] = [
            'payment_type' => $payment_type,
            'paid_amount' => $paid_amount,
            'amount_due' => $amount_due,
            'bill_id' => $advance_bill['id'] ?? null
        ];
    }

    $request['payment_info'] = $request_payment_cache[$payment_cache_key];

    if (!isset($grouped_requests[$group_key])) {
        $grouped_requests[$group_key] = [
            'tenant_id' => $request['tenant_id'],
            'tenant_name' => $request['tenant_info_name'] ?: $request['tenant_name'],
            'tenant_email' => $request['tenant_info_email'] ?: $request['tenant_email'],
            'tenant_phone' => $request['tenant_info_phone'] ?: $request['tenant_phone'],
            'tenant_address' => $request['tenant_info_address'] ?: '',
            'request_date' => $request['request_date'],
            'status' => $request['status'],
            'rooms' => [],
            'can_approve_all' => false,
            'request_ids' => []
        ];
    }

    $grouped_requests[$group_key]['rooms'][] = $request;
    $grouped_requests[$group_key]['request_ids'][] = $request['id'];

    if (in_array($request['status'], ['pending_payment', 'pending'], true)) {
        // Allow group approval when any room in the group is still pending payment/booking
        $grouped_requests[$group_key]['can_approve_all'] = true;
    }
}

// Reindex grouped requests for rendering ease (preserve insertion order)
$grouped_requests = array_values($grouped_requests);

// Adjust group status: only 'approved' when all requests are approved, otherwise pending_group if any not approved
foreach ($grouped_requests as $idx => $group) {
    $statuses = array_column($group['rooms'], 'status');
    if (!empty($statuses) && count(array_unique($statuses)) === 1 && $statuses[0] === 'approved') {
        $grouped_requests[$idx]['status'] = 'approved';
        $grouped_requests[$idx]['can_approve_all'] = false;
    } elseif (in_array('pending', $statuses, true) || in_array('pending_payment', $statuses, true)) {
        $grouped_requests[$idx]['status'] = 'pending_group';
    } elseif (in_array('approved', $statuses, true)) {
        $grouped_requests[$idx]['status'] = 'approved';
    } else {
        $grouped_requests[$idx]['status'] = $group['status'];
    }
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
            </div>

            <!-- Requests List -->
            <div class="card">
                <div class="card-body">
                    <?php if (empty($grouped_requests)): ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle"></i> No room requests found.
                        </div>
                    <?php else: ?>
                        <?php foreach ($grouped_requests as $group): ?>
                            <div class="request-row">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <h6 class="mb-1">
                                                <i class="bi bi-person"></i>
                                                <strong><?php echo htmlspecialchars($group['tenant_name']); ?></strong>
                                            </h6>
                                            <p class="mb-1 text-muted small">
                                                <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($group['tenant_email']); ?> | 
                                                <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($group['tenant_phone']); ?>
                                            </p>
                                            <?php if (!empty($group['tenant_address'])): ?>
                                                <p class="mb-1 text-muted small">
                                                    <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($group['tenant_address']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <?php foreach ($group['rooms'] as $request): ?>
                                            <?php
                                                $total_cost = 0;
                                                if (!empty($request['checkin_date']) && !empty($request['checkout_date'])) {
                                                    $checkin_dt = new DateTime($request['checkin_date']);
                                                    $checkout_dt = new DateTime($request['checkout_date']);
                                                    $interval = $checkin_dt->diff($checkout_dt);
                                                    $nights = (int)$interval->days;
                                                    $total_cost = $nights * floatval($request['rate']);
                                                }
                                                $status_label = 'Booked';
                                                $status_badge = 'info';
                                                if ($request['status'] === 'approved') {
                                                    $status_label = 'Occupied';
                                                    $status_badge = 'danger';
                                                } elseif ($request['status'] === 'cancelled') {
                                                    $status_label = 'Cancelled';
                                                    $status_badge = 'dark';
                                                }
                                            ?>
                                            <div class="room-info border rounded p-2 mb-2">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <div>
                                                        <strong class="room-number"><?php echo htmlspecialchars($request['room_number']); ?></strong>
                                                        <?php if ($request['room_type']): ?>
                                                            <span class="text-muted">(<?php echo htmlspecialchars($request['room_type']); ?>)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="badge bg-<?php echo $status_badge; ?>"><?php echo $status_label; ?></span>
                                                </div>
                                                <div class="small text-muted">
                                                    Rate: ₱<?php echo number_format($request['rate'], 2); ?> | Total Cost: ₱<?php echo number_format($total_cost, 2); ?> | Occupants: <?php echo intval($request['tenant_count']); ?>
                                                </div>
                                                <div class="small text-muted">
                                                    Check-in: <?php echo !empty($request['checkin_date']) ? date('M d, Y', strtotime($request['checkin_date'])) . ( !empty($request['checkin_time']) && $request['checkin_time'] !== '00:00:00' ? ' at '.date('g:i A', strtotime($request['checkin_time'])) : '' ) : '<span class="text-danger">Missing</span>'; ?>
                                                    <br>
                                                    Check-out: <?php echo !empty($request['checkout_date']) ? date('M d, Y', strtotime($request['checkout_date'])) . ( !empty($request['checkout_time']) && $request['checkout_time'] !== '00:00:00' ? ' at '.date('g:i A', strtotime($request['checkout_time'])) : '' ) : '<span class="text-danger">Missing</span>'; ?>
                                                </div>
                                                <?php if (!empty($request['notes'])): ?>
                                                    <div class="small text-muted mt-1"><strong>Notes:</strong> <?php echo htmlspecialchars($request['notes']); ?></div>
                                                <?php endif; ?>
                                                <div class="small text-muted mt-1">Requested: <?php echo date('M d, Y \a\t H:i A', strtotime($request['request_date'])); ?></div>
                                                <div class="small mt-1">
                                                    <?php $payment_info = $request['payment_info']; ?>
                                                    <?php if ($payment_info['payment_type'] === 'full_payment'): ?>
                                                        <span class="badge bg-success">Full Payment</span>
                                                    <?php elseif ($payment_info['payment_type'] === 'downpayment'): ?>
                                                        <span class="badge bg-info">Downpayment</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">No Payment Yet</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="d-flex flex-column align-items-end h-100">
                                            <div class="mb-3">
                                                <span class="status-badge status-<?php echo htmlspecialchars(strtolower($group['status'] === 'pending_group' ? 'pending' : $group['status'])); ?>">
                                                    <i class="bi bi-info-circle"></i>
                                                    <?php
                                                        if ($group['status'] === 'approved') {
                                                            echo 'Approved';
                                                        } elseif ($group['status'] === 'pending_group') {
                                                            echo 'Pending Booking Group';
                                                        } else {
                                                            echo ucfirst($group['status']);
                                                        }
                                                    ?>
                                                </span>
                                            </div>

                                            <?php if ($group['can_approve_all'] && in_array($_SESSION['role'] ?? '', ['admin', 'front_desk'])): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Approve all requests in this group?');">
                                                    <input type="hidden" name="action" value="approve_group">
                                                    <input type="hidden" name="tenant_id" value="<?php echo intval($group['tenant_id']); ?>">
                                                    <input type="hidden" name="request_ids" value="<?php echo htmlspecialchars(implode(',', $group['request_ids'])); ?>">
                                                    <button type="submit" class="btn btn-sm btn-success">
                                                        <i class="bi bi-check-circle"></i> Approve Group
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'front_desk'])): ?>
                                                <form method="POST" style="display: inline; margin-top: 8px;" onsubmit="return confirm('Archive all requests in this group?');">
                                                    <input type="hidden" name="action" value="archive_request">
                                                    <input type="hidden" name="request_id" value="<?php echo intval($group['request_ids'][0]); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-archive"></i> Archive First
                                                    </button>
                                                </form>
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
<script>
// Auto-refresh page every 10 seconds to show real-time payment updates
setInterval(function() {
    location.reload();
}, 10000);
</script>
</body>
</html>
