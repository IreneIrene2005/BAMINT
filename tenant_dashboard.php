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

    // Get current bills (exclude bills that are only additional charges billed from maintenance_requests)
    // Get all active bills for this tenant (should be only one after merge)
    $stmt = $conn->prepare("
        SELECT * FROM bills 
        WHERE tenant_id = :customer_id
        AND (status != 'paid' OR DATE_ADD(updated_at, INTERVAL 7 DAY) >= NOW())
        ORDER BY billing_month DESC
        LIMIT 5
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

    // Get approved advance payment notification (only if not dismissed)
    $advance_payment = null;
    $stmt = $conn->prepare("
        SELECT advance_payment_dismissed FROM tenants WHERE id = :customer_id
    ");
    $stmt->execute(['customer_id' => $customer_id]);
    $customer_flags = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Only fetch advance payment if not dismissed
    if (!$customer_flags || !$customer_flags['advance_payment_dismissed']) {
        $stmt = $conn->prepare("
            SELECT b.id, b.amount_due, pt.verified_by, pt.verification_date, r.room_number
            FROM bills b
            LEFT JOIN payment_transactions pt ON b.id = pt.bill_id AND pt.payment_status = 'verified'
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.tenant_id = :customer_id 
            AND b.notes LIKE '%ADVANCE PAYMENT%'
            AND b.status = 'paid'
            ORDER BY b.created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['customer_id' => $customer_id]);
        $advance_payment = $stmt->fetch(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    $error = "Error loading customer data: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Dashboard - BAMINT</title>
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
                            <a class="nav-link active" href="tenant_dashboard.php">
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
                            <a class="nav-link" href="tenant_messages.php">
                                <i class="bi bi-envelope"></i> Messages
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_add_room.php">
                                <i class="bi bi-plus-square"></i> Add Room
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_profile.php">
                                <i class="bi bi-person"></i> My Profile
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_archives.php">
                                <i class="bi bi-archive"></i> Archives
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
                    <h1><i class="bi bi-house-door"></i> Tenant Dashboard</h1>
                    <p class="mb-0">Welcome back, <?php echo htmlspecialchars($customer['name'] ?? 'Tenant'); ?>!</p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Advance Payment Approval Notification -->
                <?php if ($advance_payment && $customer['start_date']): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                        <div class="d-flex align-items-start gap-3">
                            <div class="flex-shrink-0">
                                <i class="bi bi-check-circle-fill" style="font-size: 1.5rem;"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="alert-heading mb-2">
                                    <i class="bi bi-check2-square"></i> Advance Payment Approved!
                                </h5>
                                <p class="mb-2">
                                    Your advance payment of <strong>₱<?php echo number_format($advance_payment['amount_due'], 2); ?></strong> has been verified and approved by admin.
                                </p>
                                <hr class="my-2">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Room Number</small>
                                        <strong class="text-dark"><?php echo htmlspecialchars($advance_payment['room_number']); ?></strong>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Move-in Date & Time</small>
                                        <?php
                                            // Show stay duration from room_requests if available
                                            $room_req_stmt = $conn->prepare("SELECT checkin_date, checkout_date FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                            $room_req_stmt->execute(['tenant_id' => $customer_id, 'room_id' => $customer['room_id']]);
                                            $dates = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                            if ($dates && $dates['checkin_date'] && $dates['checkout_date']) {
                                                echo '<strong class="text-dark">' . date('M d, Y', strtotime($dates['checkin_date'])) . ' - ' . date('M d, Y', strtotime($dates['checkout_date'])) . '</strong>';
                                            } else {
                                                echo '<strong class="text-dark">' . date('M d, Y • H:i A', strtotime($customer['start_date'])) . '</strong>';
                                            }
                                        ?>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <p class="mb-0 text-muted">
                                        <i class="bi bi-info-circle"></i> You are now approved to move in. Please contact management if you have any questions.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" onclick="dismissAdvancePaymentNotification()"></button>
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
                                <p class="text-muted mb-2"><i class="bi bi-exclamation-circle"></i> Remaining Balance</p>
                                <p class="metric-value <?php echo $remaining_balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php
                                    // Remaining Balance = sum of displayed Balance from Recent Bills + Remaining from Additional Charges
                                    $final_remaining_balance = 0.0;
                                    // Add up all bill balances (room)
                                    foreach ($bills as $bill) {
                                        $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id");
                                        $sum_stmt->execute(['bill_id' => $bill['id']]);
                                        $live_paid = floatval($sum_stmt->fetchColumn());
                                        $balance = max(0, floatval($bill['amount_due']) - $live_paid);
                                        $final_remaining_balance += $balance;
                                    }
                                    // Add up all amenity balances (Remaining column in Additional Charges)
                                    foreach ($additional_items as $ai) {
                                        $final_remaining_balance += max(0, floatval($ai['unpaid_alloc']));
                                    }
                                    echo '₱' . number_format($final_remaining_balance, 2);
                                    ?>
                                </p>
                                <small class="text-muted"><?php echo ($final_remaining_balance > 0) ? 'Amount due (downpayment + amenities)' : 'All paid up!'; ?></small>
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
                                            <th>Month</th>
                                            <th class="text-end">Amount Due</th>
                                            <th class="text-end">Paid</th>
                                            <th class="text-end">Balance</th>
                                            <th>Status</th>
                                            <th>Due Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bills as $bill): ?>
                                            <?php
                                            // Live sum of all payments for this bill (used for accurate paid and balance)
                                            $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id");
                                            $sum_stmt->execute(['bill_id' => $bill['id']]);
                                            $live_paid = floatval($sum_stmt->fetchColumn());

                                            // Fetch stay duration from room_requests (used for month display and due date fallback)
                                            $room_req_stmt = $conn->prepare("SELECT checkin_date, checkout_date FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                            $room_req_stmt->execute(['tenant_id' => $bill['tenant_id'], 'room_id' => $bill['room_id']]);
                                            $dates = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                            $checkin = $dates ? $dates['checkin_date'] : null;
                                            $checkout = $dates ? $dates['checkout_date'] : null;

                                            // Only show bills with a valid stay (checkin and checkout)
                                            if (!$checkin || !$checkout) continue;

                                            $month_display = date('M d, Y \a\t h:i A', strtotime($checkin)) . ' - ' . date('M d, Y \a\t h:i A', strtotime($checkout));

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

                                            // Show only the room cost as Amount Due
                                            $display_amount_due = floatval($bill['amount_due']);
                                            $paid_on_base = $live_paid;
                                            $base_balance = $display_amount_due - $paid_on_base;

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
                                                <td>
                                                    <?php echo $month_display; ?>
                                                    <?php
                                                    // Debug marker: show if this bill includes additional charges (maintenance requests)
                                                    $has_additional_flag = false;
                                                    if (!empty($bill['notes']) && preg_match('/Request #(\d+)/', $bill['notes'])) {
                                                        $has_additional_flag = true;
                                                    } else {
                                                        foreach ($additional_items as $ai_check) {
                                                            if (!empty($ai_check['bill_id']) && $ai_check['bill_id'] == $bill['id']) { $has_additional_flag = true; break; }
                                                        }
                                                    }
                                                    if ($has_additional_flag) {
                                                        echo '<div><small class="text-muted">(Contains additional charges)</small></div>';
                                                    }
                                                    ?>
                                                    <!-- Includes suppressed here to avoid duplication. See Additional Charges section below for amenity details. -->
                                                </td>
                                                <td class="text-end">₱<?php echo number_format($display_amount_due, 2); ?></td>
                                                <td class="text-end">₱<?php echo number_format($paid_on_base, 2); ?></td>
                                                <td class="text-end">₱<?php echo number_format($base_balance, 2); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $status_class; ?>"><?php echo ucfirst($display_status); ?></span>
                                                </td>
                                                <td><?php echo $due_display; ?></td>
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
    </script>
</body>
</html>
