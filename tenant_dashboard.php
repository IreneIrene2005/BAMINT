<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ["tenant", "customer"])) {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";

// Get customer information
$customer_id = isset($_SESSION["customer_id"]) ? $_SESSION["customer_id"] : (isset($_SESSION["tenant_id"]) ? $_SESSION["tenant_id"] : null);
if (!$customer_id) {
    // Not logged in properly, force logout
    session_destroy();
    header("location: index.php?role=tenant");
    exit;
}

try {
    // Get customer details
    $stmt = $conn->prepare("
        SELECT t.*, r.room_number, r.rate 
        FROM tenants t
        LEFT JOIN rooms r ON t.room_id = r.id
        WHERE t.id = :customer_id
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    // If tenant has no assigned room but there is a payment on a bill, auto-assign room_id from that bill
    if ($customer && empty($customer['room_number'])) {
        try {
            $assign_stmt = $conn->prepare("SELECT b.room_id FROM bills b LEFT JOIN payment_transactions pt ON pt.bill_id = b.id AND pt.payment_status IN ('verified','approved') WHERE b.tenant_id = :tenant_id AND (b.amount_paid > 0 OR b.status IN ('partial','paid') OR pt.id IS NOT NULL) ORDER BY b.id DESC LIMIT 1");
            $assign_stmt->execute(['tenant_id' => $customer_id]);
            $assign_row = $assign_stmt->fetch(PDO::FETCH_ASSOC);
            if ($assign_row && !empty($assign_row['room_id'])) {
                $update = $conn->prepare("UPDATE tenants SET room_id = :room_id WHERE id = :tenant_id");
                $update->execute(['room_id' => $assign_row['room_id'], 'tenant_id' => $customer_id]);
                // re-fetch customer details with room
                $stmt->execute(['customer_id' => $customer_id]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    // Get current bills: show advance-payment bills with verified payments, and regular bills
    $stmt = $conn->prepare("        
        SELECT b.* FROM bills b
        WHERE b.tenant_id = :customer_id
        AND (
            EXISTS (SELECT 1 FROM payment_transactions pt WHERE pt.bill_id = b.id AND pt.payment_status IN ('verified','approved'))
            OR (b.notes NOT LIKE '%ADVANCE PAYMENT%')
        )
        ORDER BY COALESCE(b.billing_month, b.created_at) DESC, b.id DESC
        LIMIT 10
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get maintenance requests
    $stmt = $conn->prepare("
        SELECT * FROM maintenance_requests 
        WHERE tenant_id = :customer_id
        ORDER BY submitted_date DESC
        LIMIT 5
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get balance
    $stmt = $conn->prepare("
        SELECT 
            SUM(amount_due) as total_due,
            SUM(amount_paid) as total_paid
        FROM bills
        WHERE tenant_id = :customer_id
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $balance = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get remaining balance (bills with unpaid amount)
        // Get total due and paid (all bills, including amenities and downpayments)
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_due),0) as total_due FROM bills WHERE tenant_id = :customer_id");
        $stmt->execute(['customer_id' => $customer_id]);
        $total_due = floatval($stmt->fetchColumn());

        $stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as total_paid FROM payment_transactions WHERE tenant_id = :customer_id AND payment_status IN ('verified','approved')");
        $stmt->execute(['customer_id' => $customer_id]);
        $total_paid = floatval($stmt->fetchColumn());

        $remaining_balance = $total_due - $total_paid;

        // Additional Charges: sum all completed amenities for this tenant
        $stmt = $conn->prepare("SELECT COALESCE(SUM(cost),0) as amenity_total FROM maintenance_requests WHERE tenant_id = :customer_id AND status = 'completed' AND cost > 0");
        $stmt->execute(['customer_id' => $customer_id]);
        $total_amenity_charges = floatval($stmt->fetchColumn());

        // Unbilled amenities (not linked to any bill)
        $stmt = $conn->prepare("SELECT COALESCE(SUM(mr.cost),0) as unbilled_total FROM maintenance_requests mr WHERE mr.tenant_id = :customer_id AND mr.status = 'completed' AND mr.cost > 0 AND NOT EXISTS (SELECT 1 FROM bills b WHERE b.tenant_id = mr.tenant_id AND b.notes LIKE CONCAT('%Request #', mr.id, '%'))");
        $stmt->execute(['customer_id' => $customer_id]);
        $unbilled_amenity_total = floatval($stmt->fetchColumn());

    // Prepare Additional Charges (amenities that were completed and billed)
    $additional_items = [];
    $additional_total_unpaid = 0.0;
    try {
        $aStmt = $conn->prepare("SELECT id, category, cost, billed, billed_bill_id FROM maintenance_requests WHERE tenant_id = :customer_id AND status = 'completed' AND cost IS NOT NULL AND cost > 0 ORDER BY submitted_date DESC");
        $aStmt->execute(['customer_id' => $customer_id]);
        $amenities_completed = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($amenities_completed as $a) {
            $bill_id = $a['billed'] && $a['billed_bill_id'] ? $a['billed_bill_id'] : null;
            $billing_month = null;
            $bill_status = 'not_billed';
            $bill_remaining = null;
            $amenity_payment_status = 'pending';
            // Only show 'Paid' if the amenity is billed (linked to a bill) AND the bill has enough verified/approved payment to cover the amenity cost
            if ($bill_id) {
                $billLookupStmt = $conn->prepare("SELECT id, billing_month, amount_due, amount_paid, status FROM bills WHERE id = :bill_id LIMIT 1");
                $billLookupStmt->execute(['bill_id' => $bill_id]);
                $bill = $billLookupStmt->fetch(PDO::FETCH_ASSOC);
                if ($bill) {
                    $billing_month = $bill['billing_month'];
                    $bill_status = $bill['status'];
                    $bill_remaining = max(0, floatval($bill['amount_due']) - floatval($bill['amount_paid']));
                    if ($bill_status !== 'not_billed') {
                        $pay_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified','approved')");
                        $pay_stmt->execute(['bill_id' => $bill_id]);
                        $paid = floatval($pay_stmt->fetchColumn());
                        if ($paid >= floatval($a['cost'])) {
                            $amenity_payment_status = 'paid';
                        }
                    }
                }
            }
            $alloc = $bill_remaining !== null ? min(floatval($a['cost']), $bill_remaining) : 0;
            if ($alloc > 0) $additional_total_unpaid += $alloc;
            // Display month logic: prefer bill's billing_month if billed, else amenity's submitted_date
            $display_month = null;
            if ($bill_id && $billing_month && $billing_month != '0000-00-00' && strtotime($billing_month) > 0) {
                $display_month = date('F Y', strtotime($billing_month));
            } elseif (!empty($a['submitted_date'])) {
                $display_month = date('F Y', strtotime($a['submitted_date']));
            } else {
                $display_month = 'Not billed';
            }
            $additional_items[] = [
                'request_id' => $a['id'],
                'category' => $a['category'],
                'cost' => floatval($a['cost']),
                'bill_id' => $bill_id,
                'billing_month' => $billing_month,
                'bill_status' => $bill_status,
                'bill_remaining' => $bill_remaining,
                'unpaid_alloc' => $alloc,
                'amenity_payment_status' => $amenity_payment_status,
                'display_month' => $display_month
            ];
        }
    } catch (Exception $e) {
        // ignore errors and continue
        $additional_items = [];
        $additional_total_unpaid = 0.0;
    }
    // Compute total amenity charges and unbilled amenity total (so dashboard shows them even if not yet added to bills)
    $total_amenity_charges = 0.0;
    $unbilled_amenity_total = 0.0;
    try {
        $tStmt = $conn->prepare("SELECT COALESCE(SUM(cost),0) as total_amenity FROM maintenance_requests WHERE tenant_id = :customer_id AND status = 'completed' AND cost > 0");
        $tStmt->execute(['customer_id' => $customer_id]);
        $tmp = $tStmt->fetch(PDO::FETCH_ASSOC);
        $total_amenity_charges = isset($tmp['total_amenity']) ? floatval($tmp['total_amenity']) : 0.0;

        $uStmt = $conn->prepare("SELECT COALESCE(SUM(mr.cost),0) as unbilled_total FROM maintenance_requests mr WHERE mr.tenant_id = :customer_id AND mr.status = 'completed' AND mr.cost > 0 AND NOT EXISTS (SELECT 1 FROM bills b WHERE b.tenant_id = mr.tenant_id AND b.notes LIKE CONCAT('%Request #', mr.id, '%'))");
        $uStmt->execute(['customer_id' => $customer_id]);
        $tmp2 = $uStmt->fetch(PDO::FETCH_ASSOC);
        $unbilled_amenity_total = isset($tmp2['unbilled_total']) ? floatval($tmp2['unbilled_total']) : 0.0;
    } catch (Exception $e) {
        $total_amenity_charges = 0.0;
        $unbilled_amenity_total = 0.0;
    }
    // Get overdue bills (bills with pending/partial status where billing_month is past current month)
    $stmt = $conn->prepare("
        SELECT * FROM bills
        WHERE tenant_id = :customer_id 
        AND status IN ('pending', 'partial')
        AND billing_month < DATE_FORMAT(NOW(), '%Y-%m-01')
        ORDER BY billing_month ASC
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $overdue_bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total overdue amount
    $stmt = $conn->prepare("
        SELECT SUM(amount_due - amount_paid) as overdue_amount FROM bills
        WHERE tenant_id = :customer_id 
        AND status IN ('pending', 'partial')
        AND billing_month < DATE_FORMAT(NOW(), '%Y-%m-01')
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $overdue_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get approved payment notification for any customer with approved payment (advance or regular billing)
    $advance_payment = null;
    $stmt = $conn->prepare("
        SELECT advance_payment_dismissed FROM tenants WHERE id = :customer_id
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $customer_flags = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Only fetch approved payment if not dismissed - show for any verified/approved payment with room booking
    if (!$customer_flags || !$customer_flags['advance_payment_dismissed']) {
        $stmt = $conn->prepare("
            SELECT DISTINCT 
                b.id, 
                b.amount_due, 
                pt.payment_amount,
                pt.verified_by, 
                pt.verification_date, 
                r.room_number,
                r.id as room_id
            FROM bills b
            INNER JOIN payment_transactions pt ON b.id = pt.bill_id AND pt.payment_status IN ('verified', 'approved')
            INNER JOIN rooms r ON b.room_id = r.id
            WHERE b.tenant_id = :customer_id
            ORDER BY pt.verification_date DESC
            LIMIT 1
        ");
        $stmt->execute(['customer_id' => $customer_id]);
        $advance_payment = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Check for approved cancellation
    $cancellation_approved = null;
    $stmt = $conn->prepare("
        SELECT bc.*, r.room_number
        FROM booking_cancellations bc
        LEFT JOIN rooms r ON bc.room_id = r.id
        WHERE bc.tenant_id = :customer_id AND bc.refund_approved = 1
        ORDER BY bc.refund_date DESC
        LIMIT 1
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $cancellation_approved = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = "Error loading customer data: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard - BAMINT</title>
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
        .metric-card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .metric-card:hover {
            transform: translateY(-5px);
        }
        .metric-value {
            font-size: 2rem;
            font-weight: 700;
        }
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .table-striped tbody tr:hover {
            background-color: rgba(102, 126, 234, 0.05);
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
        @media print {
            body {
                background: white;
            }
            .sidebar, .btn-toolbar, .header-banner, .alert:not(#checkInReceipt), 
            .metric-card, .table-responsive, .modal, .d-flex.gap-2, 
            [data-bs-toggle="modal"] {
                display: none !important;
            }
            main {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            .row {
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            #checkInReceipt {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                page-break-inside: avoid;
            }
            #checkInReceipt .card-header {
                background: #fff !important;
                color: #333 !important;
                border-bottom: 2px solid #333;
            }
            #checkInReceipt h5 {
                color: #333;
            }
            #checkInReceipt strong {
                color: #000;
            }
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
                    <h1><i class="bi bi-house-door"></i> Customer Dashboard</h1>
                    <p class="mb-0">Welcome back, <?php echo htmlspecialchars($customer['name'] ?? 'Customer'); ?>!</p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Cancellation Approved Notification -->
                <?php if ($cancellation_approved): ?>
                    <div class="alert alert-info fade show mb-4" role="alert" style="border-left: 5px solid #17a2b8;">
                        <div class="d-flex align-items-start gap-3">
                            <div class="flex-shrink-0">
                                <i class="bi bi-check-circle-fill" style="font-size: 1.5rem; color: #17a2b8;"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="alert-heading mb-3">
                                    <i class="bi bi-x-circle"></i> Booking Cancelled
                                </h5>
                                <p class="mb-3">
                                    Your booking has been cancelled.
                                </p>
                                
                                <div class="bg-light p-3 rounded mb-3">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <small class="text-muted d-block"><i class="bi bi-door-open"></i> Room</small>
                                            <strong class="text-dark" style="font-size: 1.2rem;"><?php echo htmlspecialchars($cancellation_approved['room_number'] ?? 'N/A'); ?></strong>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="text-muted d-block"><i class="bi bi-calendar-check"></i> Original Check-in</small>
                                            <strong class="text-dark" style="font-size: 1.1rem;"><?php echo date('M d, Y', strtotime($cancellation_approved['checkin_date'])); ?></strong>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($cancellation_approved['refund_notes']): ?>
                                    <div class="bg-light p-3 rounded">
                                        <small class="text-muted"><strong>Admin Notes:</strong></small>
                                        <p class="mb-0 mt-2"><?php echo htmlspecialchars($cancellation_approved['refund_notes']); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <!-- Advance Payment Approval Notification -->
                <?php elseif ($advance_payment && $customer['start_date']): ?>
                    <div class="alert alert-success fade show mb-4" role="alert" style="border-left: 5px solid #28a745;">
                        <div class="d-flex align-items-start gap-3">
                            <div class="flex-shrink-0">
                                <i class="bi bi-check-circle-fill" style="font-size: 1.5rem; color: #28a745;"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="alert-heading mb-3">
                                    <i class="bi bi-check2-square"></i> 
                                    <?php 
                                        if ($customer['checkin_time'] && $customer['checkin_time'] !== '0000-00-00 00:00:00'): 
                                            echo "Check-in Successful! You are now in the hotel";
                                        else: 
                                            echo "Payment Approved! Your Room is Reserved";
                                        endif; 
                                    ?>
                                </h5>
                                <p class="mb-3">
                                    <?php if ($customer['checkin_time'] && $customer['checkin_time'] !== '0000-00-00 00:00:00'): ?>
                                        Your check-in was approved at <strong><?php echo date('M d, Y \a\t h:i A', strtotime($customer['checkin_time'])); ?></strong>. Welcome to our hotel!
                                    <?php else: ?>
                                        Your payment of <strong>₱<?php echo number_format($advance_payment['payment_amount'] ?? $advance_payment['amount_due'], 2); ?></strong> has been verified and approved.
                                    <?php endif; ?>
                                </p>
                                
                                <div class="bg-light p-3 rounded mb-3">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <small class="text-muted d-block"><i class="bi bi-door-open"></i> Room Number</small>
                                            <strong class="text-dark" style="font-size: 1.2rem;"><?php echo htmlspecialchars($advance_payment['room_number']); ?></strong>
                                        </div>
                                        <div class="col-md-4">
                                            <small class="text-muted d-block"><i class="bi bi-calendar-check"></i> Check-in Date</small>
                                            <?php
                                                $room_id_for_dates = $customer['room_id'] ?? ($advance_payment['room_id'] ?? null);
                                                $dates = null;
                                                if ($room_id_for_dates) {
                                                    $room_req_stmt = $conn->prepare("SELECT checkin_date, checkout_date FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                                    $room_req_stmt->execute(['tenant_id' => $customer_id, 'room_id' => $room_id_for_dates]);
                                                    $dates = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                                }
                                                $checkin_date = $dates && $dates['checkin_date'] ? $dates['checkin_date'] : ($customer['start_date'] ?? date('Y-m-d'));
                                                $checkout_date = $dates && $dates['checkout_date'] ? $dates['checkout_date'] : null;
                                            ?>
                                            <strong class="text-dark" style="font-size: 1.1rem;"><?php echo date('M d, Y', strtotime($checkin_date)); ?></strong>
                                        </div>
                                        <div class="col-md-4">
                                            <small class="text-muted d-block"><i class="bi bi-calendar-x"></i> Check-out Date</small>
                                            <strong class="text-dark" style="font-size: 1.1rem;">
                                                <?php echo $checkout_date ? date('M d, Y', strtotime($checkout_date)) : 'TBD'; ?>
                                            </strong>
                                        </div>
                                    </div>
                                </div>

                                <!-- Non-Refundable Warning -->
                                <?php if (!($customer['checkin_time'] && $customer['checkin_time'] !== '0000-00-00 00:00:00')): ?>
                                <div class="alert alert-warning mb-3" style="border-left: 4px solid #ffc107;">
                                    <div class="d-flex gap-2">
                                        <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.3rem; color: #ff6b6b;"></i>
                                        <div>
                                            <strong>Important: Non-Refundable Booking</strong>
                                            <p class="mb-0 mt-1" style="font-size: 0.95rem;">
                                                Once you check in, your payment is <strong>non-refundable</strong>. Even if you cancel before or after check-in, no refund will be issued as the hotel has reserved and booked your room. Please ensure your travel plans are final before proceeding.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Check-in Receipt -->
                                <?php if (!($customer['checkin_time'] && $customer['checkin_time'] !== '0000-00-00 00:00:00')): ?>
                                <div class="card border-success mb-3" id="checkInReceipt" style="page-break-inside: avoid;">
                                    <div class="card-header bg-success text-white">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0"><i class="bi bi-receipt"></i> Check-in Receipt</h6>
                                            <button type="button" class="btn btn-sm btn-light" onclick="window.print();" title="Print Receipt">
                                                <i class="bi bi-printer"></i> Print
                                            </button>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <!-- Receipt Header -->
                                        <div class="text-center border-bottom pb-3 mb-3">
                                            <h5 class="mb-1">Hotel Check-in Receipt</h5>
                                            <small class="text-muted">Please present this receipt at the front desk upon arrival</small>
                                        </div>

                                        <!-- Confirmation Number -->
                                        <div class="mb-3">
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Confirmation Number</small>
                                                    <strong style="font-size: 1.1rem; font-family: monospace;">RES-<?php echo str_pad($advance_payment['id'], 6, '0', STR_PAD_LEFT); ?></strong>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <small class="text-muted d-block">Verified Date</small>
                                                    <strong><?php echo date('M d, Y', strtotime($advance_payment['verification_date'])); ?></strong>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Guest Information -->
                                        <div class="border-top border-bottom py-3 mb-3">
                                            <h6 class="mb-2">Guest Information</h6>
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Name</small>
                                                    <strong><?php echo htmlspecialchars($customer['name']); ?></strong>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Email</small>
                                                    <small><?php echo htmlspecialchars($customer['email']); ?></small>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Room & Dates -->
                                        <div class="border-bottom py-3 mb-3">
                                            <h6 class="mb-2">Room Details</h6>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block"><i class="bi bi-door-open"></i> Room Number</small>
                                                    <strong style="font-size: 1.2rem;"><?php echo htmlspecialchars($advance_payment['room_number']); ?></strong>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block"><i class="bi bi-calendar-check"></i> Scheduled Check-in</small>
                                                    <strong><?php echo date('M d, Y', strtotime($checkin_date)); ?></strong>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block"><i class="bi bi-calendar-x"></i> Scheduled Check-out</small>
                                                    <strong><?php echo $checkout_date ? date('M d, Y', strtotime($checkout_date)) : 'TBD'; ?></strong>
                                                </div>
                                                <?php if ($customer['checkin_time'] && $customer['checkin_time'] !== '0000-00-00 00:00:00'): ?>
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block"><i class="bi bi-clock-history"></i> Actual Check-in Time</small>
                                                    <strong class="text-success"><?php echo date('M d, Y \a\t h:i A', strtotime($customer['checkin_time'])); ?></strong>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($customer['checkout_time'] && $customer['checkout_time'] !== '0000-00-00 00:00:00'): ?>
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block"><i class="bi bi-clock-history"></i> Actual Check-out Time</small>
                                                    <strong class="text-info"><?php echo date('M d, Y \a\t h:i A', strtotime($customer['checkout_time'])); ?></strong>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Payment Summary -->
                                        <div class="border-bottom py-3 mb-3">
                                            <h6 class="mb-2">Payment Summary</h6>
                                            <div class="row mb-2">
                                                <div class="col-8">
                                                    <small class="text-muted">Amount Paid</small>
                                                </div>
                                                <div class="col-4 text-end">
                                                    <strong>₱<?php echo number_format($advance_payment['payment_amount'] ?? $advance_payment['amount_due'], 2); ?></strong>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-8">
                                                    <small class="text-muted">Status</small>
                                                </div>
                                                <div class="col-4 text-end">
                                                    <span class="badge bg-success">Verified & Approved</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Instructions -->
                                        <div class="bg-light p-3 rounded">
                                            <small class="d-block mb-2"><strong>📋 Check-in Instructions:</strong></small>
                                            <ul class="small mb-0 ps-3">
                                                <li>Please present this receipt at the front desk upon arrival</li>
                                                <li>Ensure you arrive on or after your scheduled check-in time</li>
                                                <li>Have a valid ID ready for verification</li>
                                                <li>Contact the front desk immediately if you have any issues</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex gap-2 mt-3">
                                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelBookingModal">
                                        <i class="bi bi-x-circle"></i> Cancel Booking
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Cancel Booking Confirmation Modal -->
                    <div class="modal fade" id="cancelBookingModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-danger">
                                <div class="modal-header bg-danger text-white">
                                    <h5 class="modal-title">
                                        <i class="bi bi-exclamation-circle"></i> Cancel Booking Confirmation
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="alert alert-danger mb-3">
                                        <strong><i class="bi bi-exclamation-triangle"></i> WARNING: Non-Refundable Payment</strong>
                                        <p class="mt-2 mb-0">
                                            Your payment of <strong>₱<?php echo number_format($advance_payment['amount_due'], 2); ?></strong> is <strong>NON-REFUNDABLE</strong>. 
                                            If you cancel this booking, whether before or after check-in, <strong>you will not receive a refund</strong> 
                                            because the hotel has already reserved and booked your room.
                                        </p>
                                    </div>

                                    <h6 class="mb-3">Booking Details:</h6>
                                    <div class="row g-2 mb-3">
                                        <div class="col-6">
                                            <small class="text-muted">Room:</small>
                                            <div class="fw-bold"><?php echo htmlspecialchars($advance_payment['room_number']); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Check-in:</small>
                                            <div class="fw-bold"><?php echo date('M d, Y', strtotime($checkin_date)); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Check-out:</small>
                                            <div class="fw-bold"><?php echo $checkout_date ? date('M d, Y', strtotime($checkout_date)) : 'TBD'; ?></div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Payment Amount:</small>
                                            <div class="fw-bold text-danger">₱<?php echo number_format($advance_payment['payment_amount'] ?? $advance_payment['amount_due'], 2); ?></div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="cancellationReason" class="form-label">Reason for Cancellation <span class="text-muted">(Optional)</span></label>
                                        <textarea class="form-control" id="cancellationReason" placeholder="Please tell us why you're cancelling (optional)..." rows="3"></textarea>
                                    </div>

                                    <p class="text-muted mb-0">
                                        <i class="bi bi-info-circle"></i> Please confirm that you understand the cancellation policy and wish to proceed.
                                    </p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Booking</button>
                                    <button type="button" class="btn btn-danger" onclick="confirmBookingCancellation()">
                                        <i class="bi bi-x-circle"></i> Cancel Booking
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Overdue notification removed per request -->

                <!-- Key Metrics -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-door-open"></i> Room Number</p>
                                <p class="metric-value text-primary"><?php echo htmlspecialchars($customer['room_number'] ?? 'N/A'); ?></p>
                                <small class="text-muted"><?php echo htmlspecialchars($customer['room_type'] ?? 'Not assigned'); ?></small>
                            </div>
                        </div>
                    </div>



                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-check-circle"></i> Total Paid</p>
                                <p class="metric-value text-info">
                                    <?php
                                    // Live sum of all payments for this tenant
                                        echo '₱' . number_format($total_paid, 2);
                                    ?>
                                </p>
                                <small class="text-muted">All time</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card <?php echo $remaining_balance > 0 ? 'border-danger' : 'border-success'; ?>">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-exclamation-circle"></i> Balance</p>
                                <p class="metric-value <?php echo $remaining_balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo '₱' . number_format($remaining_balance, 2); ?>
                                </p>
                                <small class="text-muted">Room balance + amenities</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-info bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-cart-plus"></i> Additional Charges</p>
                                <p class="metric-value text-info">₱<?php echo number_format($total_amenity_charges, 2); ?></p>
                                <small class="text-muted">Total: ₱<?php echo number_format($total_amenity_charges,2); ?> • <?php echo $total_amenity_charges > 0 ? 'All billed' : 'No charges'; ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bills Section -->
                <div class="card mb-4">
                    <div class="card-header bg-primary bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-receipt"></i> Recent Bills</h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($bills)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-sm">
                                    <thead>
                                        <tr>
                                            <th>Stay Duration</th>
                                            <th class="text-end">Total Cost (₱)</th>
                                            <th class="text-end">Amount Paid (₱)</th>
                                            <th class="text-end">Balance (₱)</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bills as $bill): ?>
                                            <?php
                                            // Live sum of verified/approved payments for this bill (used for accurate paid and balance)
                                            $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified','approved')");
                                            $sum_stmt->execute(['bill_id' => $bill['id']]);
                                            $live_paid = floatval($sum_stmt->fetchColumn());

                                            // Fetch stay duration from room_requests
                                            // First, try to extract Request ID from notes (e.g., "Request #123")
                                            $request_id = null;
                                            if (!empty($bill['notes']) && preg_match('/Request #(\d+)/', $bill['notes'], $m)) {
                                                $request_id = intval($m[1]);
                                            }
                                            
                                            $dates = null;
                                            if ($request_id) {
                                                // Use the specific request ID
                                                $room_req_stmt = $conn->prepare("SELECT checkin_date, checkout_date FROM room_requests WHERE id = :request_id LIMIT 1");
                                                $room_req_stmt->execute(['request_id' => $request_id]);
                                                $dates = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                            }
                                            
                                            if (!$dates) {
                                                // Fall back to fetching by tenant_id and room_id (most recent)
                                                $room_req_stmt = $conn->prepare("SELECT checkin_date, checkout_date FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                                $room_req_stmt->execute(['tenant_id' => $bill['tenant_id'], 'room_id' => $bill['room_id']]);
                                                $dates = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                            }
                                            
                                            $checkin = $dates ? $dates['checkin_date'] : null;
                                            $checkout = $dates ? $dates['checkout_date'] : null;

                                            // Use actual check-in/check-out times from tenants table if available, otherwise use scheduled dates
                                            $actual_checkin = $customer['checkin_time'] && $customer['checkin_time'] !== '0000-00-00 00:00:00' ? $customer['checkin_time'] : null;
                                            $actual_checkout = $customer['checkout_time'] && $customer['checkout_time'] !== '0000-00-00 00:00:00' ? $customer['checkout_time'] : null;
                                            
                                            // Display month - prioritize actual times, then requested dates
                                            if ($actual_checkin && $actual_checkout) {
                                                $month_display = date('M d, Y \a\t h:i A', strtotime($actual_checkin)) . ' - ' . date('M d, Y \a\t h:i A', strtotime($actual_checkout));
                                            } elseif ($checkin && $checkout && strtotime($checkin) > 0 && strtotime($checkout) > 0) {
                                                $month_display = date('M d, Y \a\t h:i A', strtotime($checkin)) . ' - ' . date('M d, Y \a\t h:i A', strtotime($checkout));
                                            } elseif (!empty($bill['billing_month']) && strtotime($bill['billing_month']) > 0) {
                                                $month_display = date('F Y', strtotime($bill['billing_month']));
                                            } else {
                                                $month_display = 'Billing period N/A';
                                            }

                                            // Compute total amenity cost that has been billed to this bill (notes contain 'Request #<id>')
                                            $amenity_sum = 0.0;
                                            if (!empty($bill['notes'])) {
                                                preg_match_all('/Request #(\d+)/', $bill['notes'], $matches);
                                                if (!empty($matches[1])) {
                                                    $ids = array_map('intval', $matches[1]);
                                                    $inQuery = implode(',', array_fill(0, count($ids), '?'));
                                                    try {
                                                        $stmt = $conn->prepare("SELECT COALESCE(SUM(cost),0) as total_cost FROM maintenance_requests WHERE id IN ($inQuery)");
                                                        foreach ($ids as $k => $v) {
                                                            $stmt->bindValue($k+1, $v, PDO::PARAM_INT);
                                                        }
                                                        $stmt->execute();
                                                        $tmp = $stmt->fetch(PDO::FETCH_ASSOC);
                                                        $amenity_sum = isset($tmp['total_cost']) ? floatval($tmp['total_cost']) : 0.0;
                                                    } catch (Exception $e) {
                                                        $amenity_sum = 0.0;
                                                    }
                                                }
                                            }

                                            // Determine due date to display (prefer checkout date if available)
                                            if (!empty($checkout) && strtotime($checkout) > 0) {
                                                $due_display = date('M d, Y', strtotime($checkout));
                                                $due_ts = strtotime($checkout);
                                            } elseif (!empty($bill['due_date']) && strtotime($bill['due_date']) > 0) {
                                                $due_display = date('M d, Y', strtotime($bill['due_date']));
                                                $due_ts = strtotime($bill['due_date']);
                                            } else {
                                                $due_display = 'Not set';
                                                $due_ts = null;
                                            }

                                            // Show only the room cost as Total Cost Room (room rate x nights, or bill amount if dates unavailable)
                                            // Fetch the room rate for the bill's room
                                            $room_rate_stmt = $conn->prepare("SELECT rate FROM rooms WHERE id = :room_id LIMIT 1");
                                            $room_rate_stmt->execute(['room_id' => $bill['room_id']]);
                                            $rate_row = $room_rate_stmt->fetch(PDO::FETCH_ASSOC);
                                            $room_rate = $rate_row ? floatval($rate_row['rate']) : (isset($customer['rate']) ? floatval($customer['rate']) : 0.0);
                                            
                                            $nights = 1;
                                            if ($checkin && $checkout && strtotime($checkin) > 0 && strtotime($checkout) > 0) {
                                                $dt1 = new DateTime($checkin);
                                                $dt2 = new DateTime($checkout);
                                                $interval = $dt1->diff($dt2);
                                                $nights = max(1, (int)$interval->format('%a'));
                                                $room_total = $room_rate * $nights;
                                            } else {
                                                // Fall back to bill's amount_due if dates are unavailable
                                                $room_total = floatval($bill['amount_due']);
                                            }
                                            $display_amount_due = $room_total;
                                            // Paid and balance should be based on room only (not amenities)
                                            // If payments exceed room_total, cap paid at room_total for display
                                            $paid_on_base = min($live_paid, $room_total);
                                            $base_balance = $room_total - $paid_on_base;

                                            // Recompute status based on full bill totals (keeps status accurate for overall bill)
                                            $overall_balance = floatval($bill['amount_due']) - $live_paid;
                                            if ($overall_balance <= 0) {
                                                $display_status = 'paid';
                                                $status_class = 'success';
                                            } elseif ($live_paid > 0) {
                                                $display_status = 'partial';
                                                $status_class = 'info';
                                            } elseif ($due_ts !== null && $due_ts < time()) {
                                                $display_status = 'overdue';
                                                $status_class = 'danger';
                                            } else {
                                                $display_status = 'pending';
                                                $status_class = 'warning';
                                            }
                                            ?>
                                            <tr>
                                                <td><small><?php echo $month_display; ?></small></td>
                                                <td class="text-end">₱<?php echo number_format($display_amount_due, 2); ?></td>
                                                <td class="text-end">₱<?php echo number_format($paid_on_base, 2); ?></td>
                                                <td class="text-end">₱<?php echo number_format($base_balance, 2); ?></td>
                                                <td><span class="badge bg-<?php echo $status_class; ?>"><?php echo ucfirst($display_status); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <a href="tenant_bills.php" class="btn btn-sm btn-primary mt-2">View All Bills</a>
                        <?php else: ?>
                            <p class="text-muted">No bills yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Maintenance Section -->
                <div class="card">
                    <div class="card-header bg-warning bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-tools"></i> Maintenance Requests</h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($maintenance)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-sm">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Description</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($maintenance as $req): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($req['category']); ?></td>
                                                <td><?php echo htmlspecialchars(substr($req['description'], 0, 30)) . '...'; ?></td>
                                                <td>
                                                    <?php 
                                                    $priority_class = $req['priority'] === 'high' ? 'danger' : ($req['priority'] === 'normal' ? 'warning' : 'info');
                                                    ?>
                                                    <span class="badge bg-<?php echo $priority_class; ?>"><?php echo ucfirst($req['priority']); ?></span>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $status_class = $req['status'] === 'completed' ? 'success' : ($req['status'] === 'in_progress' ? 'primary' : ($req['status'] === 'pending' ? 'warning' : 'secondary'));
                                                    $status_label = $req['status'] === 'completed' ? '✓ Resolved' : ($req['status'] === 'in_progress' ? '▶ Ongoing' : ($req['status'] === 'pending' ? '⏳ Pending' : ucfirst(str_replace('_', ' ', $req['status']))));
                                                    ?>
                                                    <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($req['submitted_date'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <a href="tenant_maintenance.php" class="btn btn-sm btn-warning mt-2">View All Requests</a>
                        <?php else: ?>
                            <p class="text-muted">No maintenance requests yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        /**
         * Dismiss advance payment notification permanently
         * Calls API to mark the notification as dismissed in the database
         */
        function dismissAdvancePaymentNotification() {
            fetch('api_dismiss_notification.php?action=dismiss_advance_payment')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Notification dismissed successfully in database
                        console.log('Advance payment notification dismissed permanently');
                    } else {
                        console.error('Error dismissing notification:', data.error);
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        /**
         * Confirm and process booking cancellation
         * Calls API to record cancellation and notify admins
         */
        function confirmBookingCancellation() {
            // Get cancellation reason
            const reason = document.getElementById('cancellationReason').value;

            // Close the modal
            const cancelModal = bootstrap.Modal.getInstance(document.getElementById('cancelBookingModal'));
            if (cancelModal) {
                cancelModal.hide();
            }

            // Call the cancellation API with reason
            fetch('api_cancel_booking.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'reason=' + encodeURIComponent(reason)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Your booking cancellation has been submitted successfully.\n\nManagement will review your request. Please contact us for any questions regarding your non-refundable payment.');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to cancel booking'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error processing cancellation. Please try again.');
            });
        }
    </script>
</body>
</html>
