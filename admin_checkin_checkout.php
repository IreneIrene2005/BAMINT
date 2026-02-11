<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'front_desk'])) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

$message = '';
$message_type = '';
$search_query = '';
$search_type = 'name';
$guest_info = null;
$booking_details = null;
$verification_issues = [];
$active_tab = isset($_POST['active_tab']) ? $_POST['active_tab'] : 'checkin';
$checkout_info = null;
$checkout_details = null;

// Fetch available guests for dropdown
$available_guests = [];
$available_rooms = [];
$available_bookings = [];
$checked_in_guests = []; // For checkout

try {
    // Get all active guests with bookings
    $guest_stmt = $conn->query("SELECT DISTINCT t.id, t.name FROM tenants t LEFT JOIN room_requests rr ON t.id = rr.tenant_id WHERE rr.id IS NOT NULL AND t.status = 'active' ORDER BY t.name ASC");
    $available_guests = $guest_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all occupied rooms
    $room_stmt = $conn->query("SELECT DISTINCT r.id, r.room_number FROM rooms r WHERE r.status IN ('available', 'occupied') ORDER BY r.room_number ASC");
    $available_rooms = $room_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all active bookings (only from active customers)
    $booking_stmt = $conn->query("SELECT DISTINCT rr.id, CONCAT('Ref #', rr.id, ' - ', t.name) as booking_desc FROM room_requests rr LEFT JOIN tenants t ON rr.tenant_id = t.id WHERE t.status = 'active' ORDER BY rr.id DESC");
    $available_bookings = $booking_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get currently checked-in active guests
    $checked_in_stmt = $conn->query("SELECT DISTINCT t.id, t.name FROM tenants t WHERE t.status = 'active' AND t.checkin_time IS NOT NULL AND t.checkin_time != '0000-00-00 00:00:00' AND (t.checkout_time IS NULL OR t.checkout_time = '0000-00-00 00:00:00') ORDER BY t.name ASC");
    $checked_in_guests = $checked_in_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Silently fail on dropdown population
}

// Fetch lists for check-in/check-out UI
$ready_list = [];
$checkedin_list = [];
try {
    // Guests ready to check-in: ONLY active (non-inactive) tenants, payment verified, not yet checked in
    // Excludes guests whose room_requests were cancelled or bills were cancelled
    // Works for both regular customers and walk-in customers
    // Get room from: tenants.room_id (regular) or room_requests.room_id (walk-in)
    $ready_stmt = $conn->prepare("
        SELECT DISTINCT 
            t.id, 
            t.name, 
            t.room_id,
            COALESCE(r.room_number, r2.room_number) as room_number,
            COALESCE(rr.checkin_date, rr2.checkin_date, b.checkin_date) as checkin_date,
            COALESCE(rr.checkin_time, rr2.checkin_time, b.checkin_time) as checkin_time
        FROM tenants t
        LEFT JOIN rooms r ON r.id = t.room_id
        LEFT JOIN room_requests rr ON rr.tenant_id = t.id AND rr.status IN ('approved', 'occupied') AND rr.room_id IS NOT NULL
        LEFT JOIN room_requests rr2 ON rr2.tenant_id = t.id AND rr2.status = 'pending_payment' AND rr2.room_id IS NOT NULL
        LEFT JOIN rooms r2 ON r2.id = COALESCE(rr.room_id, rr2.room_id)
        LEFT JOIN bills b ON b.tenant_id = t.id AND b.room_id = COALESCE(rr.room_id, rr2.room_id, t.room_id) AND b.status IN ('pending', 'partial', 'paid')
        WHERE t.status = 'active'
        AND (t.checkin_time IS NULL OR t.checkin_time = '0000-00-00 00:00:00')
        AND (COALESCE(r.id, r2.id) IS NOT NULL)
        AND NOT EXISTS (
            SELECT 1 FROM room_requests rr_cancelled 
            WHERE rr_cancelled.tenant_id = t.id 
            AND rr_cancelled.status = 'cancelled'
        )
        AND NOT EXISTS (
            SELECT 1 FROM bills b_cancelled
            WHERE b_cancelled.tenant_id = t.id
            AND b_cancelled.status = 'cancelled'
        )
        AND EXISTS (
            SELECT 1 FROM bills b2
            INNER JOIN payment_transactions pt ON b2.id = pt.bill_id 
            WHERE b2.tenant_id = t.id 
            AND b2.status IN ('pending', 'partial', 'paid')
            AND pt.payment_status IN ('verified', 'approved')
        )
        ORDER BY COALESCE(rr.checkin_date, rr2.checkin_date, b.checkin_date) ASC
    ");
    $ready_stmt->execute();
    $ready_list = $ready_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Currently checked-in guests for checkout - ONLY active accounts
    $checkedin_stmt = $conn->prepare("SELECT t.id, t.name, t.room_id, r.room_number, t.checkin_time
                                      FROM tenants t
                                      INNER JOIN rooms r ON r.id = t.room_id
                                      WHERE t.status != 'inactive'
                                      AND t.checkin_time IS NOT NULL
                                      AND t.checkin_time != '0000-00-00 00:00:00'
                                      AND (t.checkout_time IS NULL OR t.checkout_time = '0000-00-00 00:00:00')
                                      ORDER BY t.checkin_time DESC");
    $checkedin_stmt->execute();
    $checkedin_list = $checkedin_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $ready_list = [];
    $checkedin_list = [];
}

// Handle guest search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_guest'])) {
    $search_query = trim($_POST['search_query'] ?? '');
    $search_type = $_POST['search_type'] ?? 'name';
    
    if (empty($search_query)) {
        $message = 'Please select a guest or booking.';
        $message_type = 'warning';
    } else {
        try {
            // First get basic booking info
                 $sql = "SELECT t.id, t.name, t.email, t.phone, t.room_id, t.checkin_time, t.checkout_time,
                          r.room_number, r.status as room_status,
                          rr.id as booking_id, rr.checkin_date, rr.checkout_date, rr.checkin_time AS rr_checkin_time, rr.checkout_time AS rr_checkout_time,
                          b.id as bill_id, b.amount_paid, b.amount_due, b.status as bill_status
                    FROM tenants t
                    LEFT JOIN rooms r ON t.room_id = r.id
                    LEFT JOIN room_requests rr ON t.id = rr.tenant_id
                    LEFT JOIN bills b ON t.id = b.tenant_id
                    WHERE t.status = 'active' AND (";
            
            if ($search_type === 'name') {
                $sql .= "t.id = :query";
                $query_param = intval($search_query);
            } elseif ($search_type === 'room') {
                $sql .= "r.id = :query";
                $query_param = intval($search_query);
            } elseif ($search_type === 'booking_ref') {
                $sql .= "rr.id = :query";
                $query_param = intval($search_query);
            }
            
            $sql .= ") ORDER BY rr.checkin_date DESC, b.id DESC LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute(['query' => $query_param]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If we have a bill_id, check for payment transactions
            if ($result && $result['bill_id']) {
                $payment_stmt = $conn->prepare("SELECT payment_status FROM payment_transactions WHERE bill_id = :bill_id ORDER BY id DESC LIMIT 1");
                $payment_stmt->execute(['bill_id' => $result['bill_id']]);
                $payment_row = $payment_stmt->fetch(PDO::FETCH_ASSOC);
                $result['payment_status'] = $payment_row ? ($payment_row['payment_status'] ?? 'pending') : 'pending';
            } else {
                $result['payment_status'] = 'pending';
            }
            
            if ($result) {
                $guest_info = $result;

                // Format scheduled check-in/check-out using room_request date + time when available
                $formatScheduled = function($date, $time) {
                    if (empty($date)) return 'N/A';
                    $fmt = date('M d, Y', strtotime($date));
                    if (!empty($time) && $time !== '00:00:00') {
                        $fmt .= ' ' . date('g:i A', strtotime($time));
                    }
                    return $fmt;
                };

                $booking_details = [
                    'guest_name' => $result['name'],
                    'email' => $result['email'],
                    'phone' => $result['phone'],
                    'room_number' => $result['room_number'] ?? 'Not Assigned',
                    'room_status' => $result['room_status'] ?? 'N/A',
                    'checkin_scheduled' => $formatScheduled($result['checkin_date'] ?? null, $result['rr_checkin_time'] ?? $result['checkin_time'] ?? null),
                    'checkout_scheduled' => $formatScheduled($result['checkout_date'] ?? null, $result['rr_checkout_time'] ?? $result['checkout_time'] ?? null),
                    'checkin_actual' => ($result['checkin_time'] && $result['checkin_time'] !== '0000-00-00 00:00:00') ? date('M d, Y g:i A', strtotime($result['checkin_time'])) : null,
                    'payment_status' => $result['payment_status'] ?? 'No payment found',
                    'amount_paid' => $result['amount_paid'] ?? 0,
                    'amount_due' => $result['amount_due'] ?? 0
                ];
                
                // Verify booking conditions
                if (!$result['room_id']) {
                    $verification_issues[] = ['type' => 'error', 'msg' => 'No room assigned to this booking'];
                }
                
                if ($result['room_status'] !== 'available' && $result['room_status'] !== 'occupied') {
                    $verification_issues[] = ['type' => 'warning', 'msg' => 'Room status is: ' . $result['room_status']];
                }
                
                // Check if today is check-in date
                $checkin_date_obj = $result['checkin_date'] ? new DateTime($result['checkin_date']) : null;
                $today = new DateTime();
                if (!$checkin_date_obj || $checkin_date_obj->format('Y-m-d') !== $today->format('Y-m-d')) {
                    $verification_issues[] = ['type' => 'warning', 'msg' => 'Today is not the scheduled check-in date'];
                }
                
                // Check payment status
                if (!$result['payment_status']) {
                    $verification_issues[] = ['type' => 'error', 'msg' => 'No payment record found'];
                } elseif ($result['payment_status'] === 'pending') {
                    $verification_issues[] = ['type' => 'error', 'msg' => 'Payment is pending - not verified'];
                } elseif ($result['payment_status'] === 'partially_paid') {
                    $verification_issues[] = ['type' => 'info', 'msg' => 'Downpayment received: ₱' . number_format($result['amount_paid'], 2)];
                } elseif ($result['payment_status'] === 'verified' || $result['payment_status'] === 'approved') {
                    // Payment verified - OK
                }
                
                // Check if already checked in
                if ($result['checkin_time'] && $result['checkin_time'] !== '0000-00-00 00:00:00') {
                    $verification_issues[] = ['type' => 'warning', 'msg' => 'Guest already checked in at ' . date('M d, Y g:i A', strtotime($result['checkin_time']))];
                }
            } else {
                $message = 'Guest not found. Please verify the selection.';
                $message_type = 'warning';
            }
        } catch (Exception $e) {
            $message = 'Search error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Handle check-in approval
// Handle delete guest from check-in list
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_checkin_guest'])) {
    $tenant_id = intval($_POST['tenant_id'] ?? 0);
    if ($tenant_id > 0) {
        try {
            $conn->beginTransaction();

            // Get tenant's room and bill info
            $get_info = $conn->prepare("SELECT room_id FROM tenants WHERE id = :id");
            $get_info->execute(['id' => $tenant_id]);
            $tenant_info = $get_info->fetch(PDO::FETCH_ASSOC);
            $room_id = $tenant_info['room_id'] ?? null;

            // Update room_request status to cancelled
            $update_rr = $conn->prepare("UPDATE room_requests SET status = 'cancelled' WHERE tenant_id = :tenant_id AND status IN ('pending_payment', 'approved') LIMIT 1");
            $update_rr->execute(['tenant_id' => $tenant_id]);

            // Update bill status to cancelled
            $update_bill = $conn->prepare("UPDATE bills SET status = 'cancelled' WHERE tenant_id = :tenant_id AND status IN ('pending', 'partial', 'paid') LIMIT 1");
            $update_bill->execute(['tenant_id' => $tenant_id]);

            // Free the room if assigned
            if ($room_id) {
                $free_room = $conn->prepare("UPDATE rooms SET status = 'available' WHERE id = :room_id");
                $free_room->execute(['room_id' => $room_id]);
            }

            // Clear tenant's room assignment
            $clear_tenant = $conn->prepare("UPDATE tenants SET room_id = NULL WHERE id = :tenant_id");
            $clear_tenant->execute(['tenant_id' => $tenant_id]);

            $conn->commit();

            $message = '✓ Guest removed from check-in and booking cancelled!';
            $message_type = 'success';
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_checkin'])) {
    $tenant_id = intval($_POST['tenant_id'] ?? 0);
    if ($tenant_id > 0) {
        try {
            $conn->beginTransaction();

            // Set actual check-in time
            $stmt = $conn->prepare("UPDATE tenants SET checkin_time = NOW() WHERE id = :id");
            $stmt->execute(['id' => $tenant_id]);

            // Activate tenant and set start_date if not set
            $activate = $conn->prepare("UPDATE tenants SET status = 'active', start_date = COALESCE(start_date, DATE(NOW())) WHERE id = :id");
            $activate->execute(['id' => $tenant_id]);

            // If tenant has a room assigned, mark that room as occupied
            $rstmt = $conn->prepare("SELECT room_id FROM tenants WHERE id = :id");
            $rstmt->execute(['id' => $tenant_id]);
            $rrow = $rstmt->fetch(PDO::FETCH_ASSOC);
            if ($rrow && $rrow['room_id']) {
                $occ = $conn->prepare("UPDATE rooms SET status = 'occupied' WHERE id = :id");
                $occ->execute(['id' => $rrow['room_id']]);
            }

            $conn->commit();

            $message = '✓ Guest checked in successfully!';
            $message_type = 'success';
            $guest_info = null;
            $booking_details = null;
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Handle checkout search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_checkout'])) {
    $active_tab = 'checkout';
    $checkout_query = trim($_POST['checkout_query'] ?? '');
    
    if (empty($checkout_query)) {
        $message = 'Please select a guest for checkout.';
        $message_type = 'warning';
    } else {
        try {
                 $sql = "SELECT t.id, t.name, t.email, t.phone, t.room_id, t.checkin_time, t.checkout_time,
                          r.room_number, r.status as room_status,
                          rr.id as booking_id, rr.checkin_date, rr.checkout_date, rr.checkin_time AS rr_checkin_time, rr.checkout_time AS rr_checkout_time,
                          b.id as bill_id, b.amount_paid, b.amount_due, b.status as bill_status
                    FROM tenants t
                    LEFT JOIN rooms r ON t.room_id = r.id
                    LEFT JOIN room_requests rr ON t.id = rr.tenant_id
                    LEFT JOIN bills b ON t.id = b.tenant_id
                    WHERE t.id = :query AND t.checkin_time IS NOT NULL AND t.checkin_time != '0000-00-00 00:00:00'
                    AND (t.checkout_time IS NULL OR t.checkout_time = '0000-00-00 00:00:00')
                    ORDER BY b.id DESC LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute(['query' => intval($checkout_query)]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['bill_id']) {
                $payment_stmt = $conn->prepare("SELECT payment_status FROM payment_transactions WHERE bill_id = :bill_id ORDER BY id DESC LIMIT 1");
                $payment_stmt->execute(['bill_id' => $result['bill_id']]);
                $payment_row = $payment_stmt->fetch(PDO::FETCH_ASSOC);
                $result['payment_status'] = $payment_row ? ($payment_row['payment_status'] ?? 'pending') : 'pending';
            } else {
                $result['payment_status'] = 'pending';
            }
            
            if ($result) {
                $checkout_info = $result;
                
                // helper to format scheduled date+time
                $formatScheduled = function($date, $time) {
                    if (empty($date)) return 'N/A';
                    $fmt = date('M d, Y', strtotime($date));
                    if (!empty($time) && $time !== '00:00:00') {
                        $fmt .= ' ' . date('g:i A', strtotime($time));
                    }
                    return $fmt;
                };

                $checkout_details = [
                    'guest_name' => $result['name'],
                    'email' => $result['email'],
                    'phone' => $result['phone'],
                    'room_number' => $result['room_number'] ?? 'N/A',
                    'room_status' => $result['room_status'] ?? 'N/A',
                    'checkin_date' => $formatScheduled($result['checkin_date'] ?? null, $result['rr_checkin_time'] ?? $result['checkin_time'] ?? null),
                    'checkout_date' => $formatScheduled($result['checkout_date'] ?? null, $result['rr_checkout_time'] ?? $result['checkout_time'] ?? null),
                    'checkin_actual' => ($result['checkin_time'] && $result['checkin_time'] !== '0000-00-00 00:00:00') ? date('M d, Y g:i A', strtotime($result['checkin_time'])) : 'N/A',
                    'amount_due' => $result['amount_due'] ?? 0,
                    'amount_paid' => $result['amount_paid'] ?? 0
                ];
                
                // Calculate nights stayed
                if ($result['checkin_time'] && $result['checkout_date']) {
                    $checkin_dt = new DateTime($result['checkin_time']);
                    $checkout_dt = new DateTime($result['checkout_date']);
                    $checkout_details['nights_stayed'] = $checkout_dt->diff($checkin_dt)->days;
                }
            } else {
                $message = 'Guest not found or not currently checked in.';
                $message_type = 'warning';
            }
        } catch (Exception $e) {
            $message = 'Search error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Handle checkout approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_checkout'])) {
    $tenant_id = intval($_POST['tenant_id'] ?? 0);
    if ($tenant_id > 0) {
        try {
            // Get the tenant's current billing status
            $bill_stmt = $conn->prepare("SELECT 
                                           COALESCE(SUM(amount_due), 0) - COALESCE(SUM(amount_paid), 0) as outstanding_balance
                                        FROM bills 
                                        WHERE tenant_id = :tenant_id");
            $bill_stmt->execute(['tenant_id' => $tenant_id]);
            $bill_row = $bill_stmt->fetch(PDO::FETCH_ASSOC);
            $outstanding_balance = floatval($bill_row['outstanding_balance'] ?? 0);
            
            // Check if there's any outstanding balance
            if ($outstanding_balance > 0.01) {
                $message = 'Error: Guest cannot check out. Remaining balance: ₱' . number_format($outstanding_balance, 2) . '. Please collect payment before allowing checkout.';
                $message_type = 'danger';
            } else {
                // Balance is settled, proceed with checkout
                $stmt = $conn->prepare("UPDATE tenants SET checkout_time = NOW() WHERE id = :id");
                $stmt->execute(['id' => $tenant_id]);
                $message = '✓ Guest checked out successfully!';
                $message_type = 'success';
                $checkout_info = null;
                $checkout_details = null;
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Metrics
try {
    $ready = $conn->query("SELECT COUNT(DISTINCT t.id) FROM tenants t 
                           JOIN payment_transactions pt ON t.id = pt.tenant_id 
                           WHERE t.status = 'active'
                           AND pt.payment_status IN ('verified','approved', 'partially_paid') 
                           AND (t.checkin_time IS NULL OR t.checkin_time = '0000-00-00 00:00:00')")->fetchColumn();
    
    $checkedin = $conn->query("SELECT COUNT(DISTINCT t.id) FROM tenants t 
                              WHERE t.status = 'active'
                              AND t.checkin_time IS NOT NULL 
                              AND t.checkin_time != '0000-00-00 00:00:00'
                              AND (t.checkout_time IS NULL OR t.checkout_time = '0000-00-00 00:00:00')")->fetchColumn();
    
    $checkedout = $conn->query("SELECT COUNT(DISTINCT t.id) FROM tenants t 
                               WHERE t.status = 'active'
                               AND t.checkout_time IS NOT NULL 
                               AND t.checkout_time != '0000-00-00 00:00:00'")->fetchColumn();
} catch (Exception $e) {
    $ready = 0;
    $checkedin = 0;
    $checkedout = 0;
}

// Fetch lists for check-in/check-out UI
$ready_list = [];
$checkedin_list = [];
try {
    // Guests ready to check-in: have room assigned, payment verified, but not yet checked in
    // Works for both regular customers (room from tenants.room_id) and walk-in customers (room from room_requests)
    $ready_stmt = $conn->prepare("SELECT DISTINCT t.id, t.name, t.room_id, 
                                         COALESCE(r.room_number, r2.room_number) as room_number, 
                                         COALESCE(rr.checkin_date, rr2.checkin_date, b.checkin_date) as checkin_date
                                  FROM tenants t
                                  LEFT JOIN rooms r ON r.id = t.room_id
                                  LEFT JOIN room_requests rr ON rr.tenant_id = t.id AND rr.status IN ('approved', 'occupied') AND rr.room_id IS NOT NULL
                                  LEFT JOIN room_requests rr2 ON rr2.tenant_id = t.id AND rr2.status = 'pending_payment' AND rr2.room_id IS NOT NULL
                                  LEFT JOIN rooms r2 ON r2.id = COALESCE(rr.room_id, rr2.room_id)
                                  LEFT JOIN bills b ON b.tenant_id = t.id AND b.room_id = COALESCE(rr.room_id, rr2.room_id, t.room_id) AND b.status IN ('pending', 'partial', 'paid')
                                  WHERE t.status = 'active'
                                  AND (t.checkin_time IS NULL OR t.checkin_time = '0000-00-00 00:00:00')
                                  AND (COALESCE(r.id, r2.id) IS NOT NULL)
                                  AND EXISTS (SELECT 1 FROM payment_transactions pt JOIN bills b2 ON pt.bill_id = b2.id WHERE b2.tenant_id = t.id AND pt.payment_status IN ('verified', 'approved'))
                                  ORDER BY COALESCE(rr.checkin_date, rr2.checkin_date, b.checkin_date) ASC");
    $ready_stmt->execute();
    $ready_list = $ready_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Currently checked-in guests for checkout
    $checkedin_stmt = $conn->prepare("SELECT t.id, t.name, t.room_id, r.room_number, t.checkin_time
                                      FROM tenants t
                                      LEFT JOIN rooms r ON r.id = t.room_id
                                      WHERE t.status = 'active'
                                      AND t.checkin_time IS NOT NULL
                                      AND t.checkin_time != '0000-00-00 00:00:00'
                                      AND (t.checkout_time IS NULL OR t.checkout_time = '0000-00-00 00:00:00')
                                      ORDER BY t.checkin_time DESC");
    $checkedin_stmt->execute();
    $checkedin_list = $checkedin_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $ready_list = [];
    $checkedin_list = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-in & Check-out - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .metric-card { border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .metric-value { font-size: 1.6rem; font-weight: 700; }
    </style>
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="bi bi-door-open"></i> Front Desk Check-in & Check-out</h1>
            </div>

            <!-- TAB NAVIGATION -->
            <ul class="nav nav-tabs mb-4" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab === 'checkin' ? 'active' : ''; ?>" id="checkin-tab" 
                            onclick="switchTab('checkin')" type="button" role="tab">
                        <i class="bi bi-person-check"></i> Check-in Process
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab === 'checkout' ? 'active' : ''; ?>" id="checkout-tab" 
                            onclick="switchTab('checkout')" type="button" role="tab">
                        <i class="bi bi-person-lock"></i> Check-out Process
                    </button>
                </li>
            </ul>

            <input type="hidden" id="activeTab" name="active_tab" value="<?php echo $active_tab; ?>">

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- CHECK-IN TAB -->
            <div id="checkin-content" style="display: <?php echo $active_tab === 'checkin' ? 'block' : 'none'; ?>;">

            <!-- STEP 1: GUEST SEARCH -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white">
                    <span class="fw-semibold"><i class="bi bi-1-circle-fill"></i> Guest Arrival - Search Booking</span>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3" id="searchForm">
                        <div class="col-md-5">
                            <label class="form-label">Search by</label>
                            <select name="search_type" class="form-select" id="searchType" onchange="updateSearchOptions(); resetSearchInput();">
                                <option value="name">Guest Name</option>
                                <option value="room">Room Number</option>
                                <option value="booking_ref">Booking Reference</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Search & Select Guest/Room</label>
                            <div class="position-relative">
                                <input type="text" class="form-control" id="searchInput" placeholder="Start typing..." 
                                       onkeyup="filterSearchOptions()" onclear="resetSearchResults()" required>
                                <input type="hidden" name="search_query" id="selectedValue" value="">
                                <div id="searchResults" class="position-absolute w-100 bg-white border border-top-0 rounded-bottom" 
                                     style="max-height: 250px; overflow-y: auto; display: none; z-index: 10; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2" id="selectedDisplay"></small>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" name="search_guest" class="btn btn-primary w-100" 
                                    onclick="return validateSelection()">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            // Store all options by type
            const allOptions = {
                name: [
                    <?php foreach ($available_guests as $g): ?>
                        {id: '<?php echo $g['id']; ?>', display: '<?php echo htmlspecialchars(addslashes($g['name'])); ?>'},
                    <?php endforeach; ?>
                ],
                room: [
                    <?php foreach ($available_rooms as $r): ?>
                        {id: '<?php echo $r['id']; ?>', display: 'Room <?php echo htmlspecialchars(addslashes($r['room_number'])); ?>'},
                    <?php endforeach; ?>
                ],
                booking_ref: [
                    <?php foreach ($available_bookings as $b): ?>
                        {id: '<?php echo $b['id']; ?>', display: '<?php echo htmlspecialchars(addslashes($b['booking_desc'])); ?>'},
                    <?php endforeach; ?>
                ]
            };

            function resetSearchInput() {
                document.getElementById('searchInput').value = '';
                document.getElementById('selectedValue').value = '';
                document.getElementById('selectedDisplay').innerText = '';
                document.getElementById('searchResults').style.display = 'none';
            }

            function resetSearchResults() {
                document.getElementById('searchResults').style.display = 'none';
            }

            function filterSearchOptions() {
                const searchType = document.getElementById('searchType').value;
                const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
                const resultsDiv = document.getElementById('searchResults');
                const options = allOptions[searchType] || [];

                resultsDiv.innerHTML = '';

                if (searchTerm.length === 0) {
                    resultsDiv.style.display = 'none';
                    document.getElementById('selectedValue').value = '';
                    document.getElementById('selectedDisplay').innerText = '';
                    return;
                }

                const filtered = options.filter(opt => 
                    opt.display.toLowerCase().includes(searchTerm)
                );

                if (filtered.length === 0) {
                    resultsDiv.innerHTML = '<div class="p-2 text-muted">No results found</div>';
                    resultsDiv.style.display = 'block';
                    document.getElementById('selectedValue').value = '';
                    document.getElementById('selectedDisplay').innerText = '';
                    return;
                }

                filtered.forEach(opt => {
                    const div = document.createElement('div');
                    div.className = 'p-2 cursor-pointer border-bottom';
                    div.style.cursor = 'pointer';
                    div.style.transition = 'background-color 0.2s';
                    div.innerText = opt.display;
                    div.onmouseover = function() { this.style.backgroundColor = '#f0f0f0'; };
                    div.onmouseout = function() { this.style.backgroundColor = 'transparent'; };
                    div.onclick = function() { selectOption(opt.id, opt.display); };
                    resultsDiv.appendChild(div);
                });

                resultsDiv.style.display = 'block';
            }

            function selectOption(id, display) {
                document.getElementById('searchInput').value = display;
                document.getElementById('selectedValue').value = id;
                document.getElementById('selectedDisplay').innerText = '✓ Selected: ' + display;
                document.getElementById('searchResults').style.display = 'none';
            }

            function validateSelection() {
                const selectedValue = document.getElementById('selectedValue').value;
                if (!selectedValue) {
                    alert('Please select a guest or booking from the list');
                    return false;
                }
                return true;
            }

            // Show results on input focus
            document.getElementById('searchInput').addEventListener('focus', function() {
                if (this.value.length > 0) {
                    document.getElementById('searchResults').style.display = 'block';
                }
            });

            // Hide results on click outside
            document.addEventListener('click', function(event) {
                const searchInput = document.getElementById('searchInput');
                const searchResults = document.getElementById('searchResults');
                if (event.target !== searchInput && !searchResults.contains(event.target)) {
                    searchResults.style.display = 'none';
                }
            });
            </script>

            <!-- READY TO CHECK-IN LIST -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-success text-white">
                    <span class="fw-semibold"><i class="bi bi-list-check"></i> Guests Ready to Check-in (<?php echo count($ready_list ?? []); ?>)</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($ready_list)): ?>
                        <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Scheduled Check-in</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ready_list as $r): ?>
                                    <?php
                                        // Double-check status is active (not inactive)
                                        $verify_stmt = $conn->prepare("SELECT status FROM tenants WHERE id = :id");
                                        $verify_stmt->execute(['id' => $r['id']]);
                                        $tenant_status = $verify_stmt->fetchColumn();
                                        if ($tenant_status === 'inactive') {
                                            continue; // Skip this row
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['name']); ?></td>
                                        <td><?php echo htmlspecialchars($r['room_number'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php
                                                if ($r['checkin_date']) {
                                                    $ci_fmt = date('M d, Y', strtotime($r['checkin_date']));
                                                    if (!empty($r['checkin_time']) && $r['checkin_time'] !== '00:00:00') {
                                                        $ci_fmt .= ' ' . date('g:i A', strtotime($r['checkin_time']));
                                                    }
                                                    echo $ci_fmt;
                                                } else {
                                                    echo 'N/A';
                                                }
                                            ?>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="tenant_id" value="<?php echo intval($r['id']); ?>">
                                                <button type="submit" name="approve_checkin" class="btn btn-sm btn-primary">Approve & Check In</button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this guest from check-in? This will cancel their booking.');">
                                                <input type="hidden" name="tenant_id" value="<?php echo intval($r['id']); ?>">
                                                <button type="submit" name="delete_checkin_guest" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">No guests ready for check-in.</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($guest_info && $booking_details): ?>
            <!-- STEP 2: BOOKING VERIFICATION -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-info text-white">
                    <span class="fw-semibold"><i class="bi bi-2-circle-fill"></i> Booking Verification</span>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <!-- Guest Information -->
                        <div class="col-md-6">
                            <h6 class="fw-semibold mb-3 text-secondary">Guest Information</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="fw-semibold">Guest Name:</td>
                                    <td><strong><?php echo htmlspecialchars($booking_details['guest_name']); ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Email:</td>
                                    <td><?php echo htmlspecialchars($booking_details['email']); ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Phone:</td>
                                    <td><?php echo htmlspecialchars($booking_details['phone']); ?></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Check-in/Check-out Dates -->
                        <div class="col-md-6">
                            <h6 class="fw-semibold mb-3 text-secondary">Reservation Dates</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="fw-semibold">Check-in:</td>
                                    <td><?php echo $booking_details['checkin_scheduled']; ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Check-out:</td>
                                    <td><?php echo $booking_details['checkout_scheduled']; ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Days:</td>
                                    <td>
                                        <?php 
                                            if ($guest_info['checkin_date'] && $guest_info['checkout_date']) {
                                                $checkin = new DateTime($guest_info['checkin_date']);
                                                $checkout = new DateTime($guest_info['checkout_date']);
                                                $days = $checkout->diff($checkin)->days;
                                                echo $days . ' night' . ($days != 1 ? 's' : '');
                                            }
                                        ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Verification Checklist removed per request -->
                </div>
            </div>

            <!-- Check-in Approval section removed per request -->

            <?php endif; ?>
            </div><!-- END CHECK-IN TAB -->

            <!-- CHECK-OUT TAB -->
            <div id="checkout-content" style="display: <?php echo $active_tab === 'checkout' ? 'block' : 'none'; ?>;">

            <!-- CURRENTLY CHECKED-IN LIST -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-warning text-dark">
                    <span class="fw-semibold"><i class="bi bi-person-fill-door"></i> Currently Checked-in (<?php echo count($checkedin_list ?? []); ?>)</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($checkedin_list)): ?>
                        <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Checked-in At</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($checkedin_list as $c): ?>
                                    <?php
                                        // Double-check status is active (not inactive)
                                        $verify_stmt = $conn->prepare("SELECT status FROM tenants WHERE id = :id");
                                        $verify_stmt->execute(['id' => $c['id']]);
                                        $tenant_status = $verify_stmt->fetchColumn();
                                        if ($tenant_status === 'inactive') {
                                            continue; // Skip this row
                                        }
                                        
                                        // Check if guest has outstanding balance
                                        $balance_stmt = $conn->prepare("SELECT 
                                                                          COALESCE(SUM(amount_due), 0) - COALESCE(SUM(amount_paid), 0) as outstanding_balance
                                                                       FROM bills 
                                                                       WHERE tenant_id = :tenant_id");
                                        $balance_stmt->execute(['tenant_id' => $c['id']]);
                                        $balance_row = $balance_stmt->fetch(PDO::FETCH_ASSOC);
                                        $outstanding_balance = floatval($balance_row['outstanding_balance'] ?? 0);
                                        $has_balance = $outstanding_balance > 0.01;
                                    ?>
                                    <tr <?php echo $has_balance ? 'class="table-danger"' : ''; ?>>
                                        <td><?php echo htmlspecialchars($c['name']); ?></td>
                                        <td><?php echo htmlspecialchars($c['room_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo $c['checkin_time'] ? date('M d, Y g:i A', strtotime($c['checkin_time'])) : 'N/A'; ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="tenant_id" value="<?php echo intval($c['id']); ?>">
                                                <?php if ($has_balance): ?>
                                                    <button type="submit" name="approve_checkout" class="btn btn-sm btn-secondary" disabled title="Balance due: ₱<?php echo number_format($outstanding_balance, 2); ?>">
                                                        <i class="bi bi-lock-fill"></i> Balance Due
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" name="approve_checkout" class="btn btn-sm btn-danger">Approve & Checkout</button>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">No guests currently checked in.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STEP 1: CHECKOUT GUEST SEARCH -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white">
                    <span class="fw-semibold"><i class="bi bi-1-circle-fill"></i> Guest Departure - Select Guest</span>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3" id="checkoutForm">
                        <input type="hidden" name="active_tab" value="checkout">
                        <div class="col-md-10">
                            <label class="form-label">Search Checked-in Guests</label>
                            <div class="position-relative">
                                <input type="text" class="form-control" id="checkoutSearchInput" placeholder="Start typing guest name..." 
                                       onkeyup="filterCheckoutOptions()" onclear="resetCheckoutResults()" required>
                                <input type="hidden" name="checkout_query" id="checkoutSelectedValue" value="">
                                <div id="checkoutSearchResults" class="position-absolute w-100 bg-white border border-top-0 rounded-bottom" 
                                     style="max-height: 250px; overflow-y: auto; display: none; z-index: 10; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2" id="checkoutSelectedDisplay"></small>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" name="search_checkout" class="btn btn-primary w-100" 
                                    onclick="return validateCheckoutSelection()">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($checkout_info && $checkout_details): ?>
            <!-- STEP 2: CHECKOUT VERIFICATION -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-info text-white">
                    <span class="fw-semibold"><i class="bi bi-2-circle-fill"></i> Checkout Verification & Bill Review</span>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <!-- Guest Information -->
                        <div class="col-md-6">
                            <h6 class="fw-semibold mb-3 text-secondary">Guest Information</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="fw-semibold">Guest Name:</td>
                                    <td><strong><?php echo htmlspecialchars($checkout_details['guest_name']); ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Room #:</td>
                                    <td><strong><?php echo htmlspecialchars($checkout_details['room_number']); ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Email:</td>
                                    <td><?php echo htmlspecialchars($checkout_details['email']); ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Phone:</td>
                                    <td><?php echo htmlspecialchars($checkout_details['phone']); ?></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Stay Summary -->
                        <div class="col-md-6">
                            <h6 class="fw-semibold mb-3 text-secondary">Stay Summary</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="fw-semibold">Check-in:</td>
                                    <td><?php echo $checkout_details['checkin_actual']; ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Checkout Time:</td>
                                    <td><?php echo $checkout_details['checkout_date']; ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Nights Stayed:</td>
                                    <td><strong><?php echo isset($checkout_details['nights_stayed']) ? $checkout_details['nights_stayed'] : '-'; ?></strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Bill Summary -->
                    <hr>
                    <h6 class="fw-semibold mb-3">Bill Summary</h6>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card border-primary">
                                <div class="card-body text-center">
                                    <div class="text-muted small">Total Due</div>
                                    <div class="metric-value text-dark">₱<?php echo number_format($checkout_details['amount_due'], 2); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-success">
                                <div class="card-body text-center">
                                    <div class="text-muted small">Amount Paid</div>
                                    <div class="metric-value text-success">₱<?php echo number_format($checkout_details['amount_paid'], 2); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card <?php $balance = $checkout_details['amount_due'] - $checkout_details['amount_paid']; echo ($balance <= 0) ? 'border-success' : 'border-warning'; ?>">
                                <div class="card-body text-center">
                                    <div class="text-muted small"><?php echo ($balance > 0) ? 'Balance Due' : 'Credit'; ?></div>
                                    <div class="metric-value <?php echo ($balance > 0) ? 'text-warning' : 'text-success'; ?>">
                                        ₱<?php echo number_format(abs($balance), 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($balance > 0): ?>
                    <div class="alert alert-warning mt-3">
                        <i class="bi bi-exclamation-triangle-fill"></i> 
                        <strong>Balance Due:</strong> ₱<?php echo number_format($balance, 2); ?> - Guest needs to settle this before checkout completion.
                    </div>
                    <?php elseif ($balance < 0): ?>
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle-fill"></i> 
                        <strong>Credit/Overpayment:</strong> ₱<?php echo number_format(abs($balance), 2); ?> can be refunded or credited.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success mt-3">
                        <i class="bi bi-check-circle-fill"></i> 
                        <strong>Bill Settled:</strong> All charges have been paid in full.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STEP 3: CHECKOUT APPROVAL -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-success text-white">
                    <span class="fw-semibold"><i class="bi bi-3-circle-fill"></i> Checkout Approval</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="tenant_id" value="<?php echo $checkout_info['id']; ?>">
                        <input type="hidden" name="active_tab" value="checkout">
                        
                        <?php if ($balance > 0.01): ?>
                            <div class="alert alert-danger mb-3">
                                <i class="bi bi-exclamation-octagon-fill"></i>
                                <strong>Checkout Blocked!</strong> 
                                The guest has an outstanding balance of <strong>₱<?php echo number_format($balance, 2); ?></strong>. 
                                Payment must be settled before checkout can be completed.
                            </div>
                            <button type="submit" name="approve_checkout" class="btn btn-success btn-lg" disabled>
                                <i class="bi bi-lock-fill"></i> Checkout Blocked - Balance Due
                            </button>
                        <?php else: ?>
                            <div class="alert alert-info mb-3">
                                <strong>Ready to checkout guest?</strong> 
                                Click the button below to record the checkout time and complete the process.
                            </div>
                            <button type="submit" name="approve_checkout" class="btn btn-success btn-lg">
                                <i class="bi bi-check2-square"></i> Approve & Process Checkout
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <?php endif; ?>
            </div><!-- END CHECK-OUT TAB -->

            <!-- METRICS -->
            <div class="row mb-4 mt-5">
                <!-- 'Ready for Check-in' metric removed per request -->
                <div class="col-md-3 mb-3">
                    <div class="card metric-card bg-info text-white h-100">
                        <div class="card-body text-center">
                            <div class="metric-icon mb-2"><i class="bi bi-check-circle" style="font-size: 2rem;"></i></div>
                            <div class="metric-label small">Checked In</div>
                            <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;"><?php echo $checkedin; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card metric-card bg-success text-white h-100">
                        <div class="card-body text-center">
                            <div class="metric-icon mb-2"><i class="bi bi-check-all" style="font-size: 2rem;"></i></div>
                            <div class="metric-label small">Checked Out</div>
                            <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;"><?php echo $checkedout; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Tab switching
function switchTab(tab) {
    document.getElementById('activeTab').value = tab;
    document.getElementById('checkin-content').style.display = (tab === 'checkin') ? 'block' : 'none';
    document.getElementById('checkout-content').style.display = (tab === 'checkout') ? 'block' : 'none';
    document.getElementById('checkin-tab').classList.toggle('active', tab === 'checkin');
    document.getElementById('checkout-tab').classList.toggle('active', tab === 'checkout');
}

// Checkout search functions
const checkedInGuests = [
    <?php foreach ($checked_in_guests as $g): ?>
        {id: '<?php echo $g['id']; ?>', name: '<?php echo htmlspecialchars(addslashes($g['name'])); ?>'},
    <?php endforeach; ?>
];

function filterCheckoutOptions() {
    const searchTerm = document.getElementById('checkoutSearchInput').value.toLowerCase().trim();
    const resultsDiv = document.getElementById('checkoutSearchResults');
    
    resultsDiv.innerHTML = '';
    
    if (searchTerm.length === 0) {
        resultsDiv.style.display = 'none';
        document.getElementById('checkoutSelectedValue').value = '';
        document.getElementById('checkoutSelectedDisplay').innerText = '';
        return;
    }
    
    const filtered = checkedInGuests.filter(g => g.name.toLowerCase().includes(searchTerm));
    
    if (filtered.length === 0) {
        resultsDiv.innerHTML = '<div class="p-2 text-muted">No checked-in guests found</div>';
        resultsDiv.style.display = 'block';
        document.getElementById('checkoutSelectedValue').value = '';
        document.getElementById('checkoutSelectedDisplay').innerText = '';
        return;
    }
    
    filtered.forEach(guest => {
        const div = document.createElement('div');
        div.className = 'p-2 cursor-pointer border-bottom';
        div.style.cursor = 'pointer';
        div.style.transition = 'background-color 0.2s';
        div.innerText = guest.name;
        div.onmouseover = function() { this.style.backgroundColor = '#f0f0f0'; };
        div.onmouseout = function() { this.style.backgroundColor = 'transparent'; };
        div.onclick = function() { selectCheckoutGuest(guest.id, guest.name); };
        resultsDiv.appendChild(div);
    });
    
    resultsDiv.style.display = 'block';
}

function selectCheckoutGuest(id, name) {
    document.getElementById('checkoutSearchInput').value = name;
    document.getElementById('checkoutSelectedValue').value = id;
    document.getElementById('checkoutSelectedDisplay').innerText = '✓ Selected: ' + name;
    document.getElementById('checkoutSearchResults').style.display = 'none';
}

function validateCheckoutSelection() {
    const selectedValue = document.getElementById('checkoutSelectedValue').value;
    if (!selectedValue) {
        alert('Please select a guest for checkout');
        return false;
    }
    return true;
}

function resetCheckoutResults() {
    document.getElementById('checkoutSearchResults').style.display = 'none';
}

// Hide results on click outside
document.addEventListener('click', function(event) {
    const checkoutInput = document.getElementById('checkoutSearchInput');
    const checkoutResults = document.getElementById('checkoutSearchResults');
    if (checkoutInput && checkoutResults && event.target !== checkoutInput && !checkoutResults.contains(event.target)) {
        checkoutResults.style.display = 'none';
    }
});

// Original check-in search functions (kept for compatibility)
function resetSearchInput() {
    document.getElementById('searchInput').value = '';
    document.getElementById('selectedValue').value = '';
    document.getElementById('selectedDisplay').innerText = '';
    document.getElementById('searchResults').style.display = 'none';
}

function resetSearchResults() {
    document.getElementById('searchResults').style.display = 'none';
}

function filterSearchOptions() {
    const searchType = document.getElementById('searchType').value;
    const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    const resultsDiv = document.getElementById('searchResults');
    
    resultsDiv.innerHTML = '';
    
    if (searchTerm.length === 0) {
        resultsDiv.style.display = 'none';
        document.getElementById('selectedValue').value = '';
        document.getElementById('selectedDisplay').innerText = '';
        return;
    }
    
    const allOptions = {
        name: [
            <?php foreach ($available_guests as $g): ?>
                {id: '<?php echo $g['id']; ?>', display: '<?php echo htmlspecialchars(addslashes($g['name'])); ?>'},
            <?php endforeach; ?>
        ],
        room: [
            <?php foreach ($available_rooms as $r): ?>
                {id: '<?php echo $r['id']; ?>', display: 'Room <?php echo htmlspecialchars(addslashes($r['room_number'])); ?>'},
            <?php endforeach; ?>
        ],
        booking_ref: [
            <?php foreach ($available_bookings as $b): ?>
                {id: '<?php echo $b['id']; ?>', display: '<?php echo htmlspecialchars(addslashes($b['booking_desc'])); ?>'},
            <?php endforeach; ?>
        ]
    };
    
    const options = allOptions[searchType] || [];
    const filtered = options.filter(opt => opt.display.toLowerCase().includes(searchTerm));
    
    if (filtered.length === 0) {
        resultsDiv.innerHTML = '<div class="p-2 text-muted">No results found</div>';
        resultsDiv.style.display = 'block';
        document.getElementById('selectedValue').value = '';
        document.getElementById('selectedDisplay').innerText = '';
        return;
    }
    
    filtered.forEach(opt => {
        const div = document.createElement('div');
        div.className = 'p-2 cursor-pointer border-bottom';
        div.style.cursor = 'pointer';
        div.style.transition = 'background-color 0.2s';
        div.innerText = opt.display;
        div.onmouseover = function() { this.style.backgroundColor = '#f0f0f0'; };
        div.onmouseout = function() { this.style.backgroundColor = 'transparent'; };
        div.onclick = function() { selectOption(opt.id, opt.display); };
        resultsDiv.appendChild(div);
    });
    
    resultsDiv.style.display = 'block';
}

function selectOption(id, display) {
    document.getElementById('searchInput').value = display;
    document.getElementById('selectedValue').value = id;
    document.getElementById('selectedDisplay').innerText = '✓ Selected: ' + display;
    document.getElementById('searchResults').style.display = 'none';
}

function validateSelection() {
    const selectedValue = document.getElementById('selectedValue').value;
    if (!selectedValue) {
        alert('Please select a guest or booking from the list');
        return false;
    }
    return true;
}

// Show results on input focus
if (document.getElementById('searchInput')) {
    document.getElementById('searchInput').addEventListener('focus', function() {
        if (this.value.length > 0) {
            document.getElementById('searchResults').style.display = 'block';
        }
    });
}

if (document.getElementById('checkoutSearchInput')) {
    document.getElementById('checkoutSearchInput').addEventListener('focus', function() {
        filterCheckoutOptions();
    });
}

// Hide check-in results on click outside
document.addEventListener('click', function(event) {
    if (!document.getElementById('searchInput')) return;
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    if (event.target !== searchInput && !searchResults.contains(event.target)) {
        searchResults.style.display = 'none';
    }
});
</script>
</body>
</html>
