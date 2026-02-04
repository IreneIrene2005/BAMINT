<?php
session_start();


if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ["tenant", "customer"])) {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";

$customer_id = isset($_SESSION["customer_id"]) ? $_SESSION["customer_id"] : (isset($_SESSION["tenant_id"]) ? $_SESSION["tenant_id"] : null);
$filter_status = isset($_GET['status']) && !empty($_GET['status']) ? $_GET['status'] : '';
$view = isset($_GET['view']) ? $_GET['view'] : 'active'; // 'active' or 'archive'
$error = '';
$bills = [];
$archive_bills = [];
$balance = 0;
$unpaid_count = 0;
$next_due = null;

try {
    // Get ACTIVE bills (exclude paid bills older than 6 months)
    $sql = "SELECT * FROM bills WHERE tenant_id = :customer_id";
    $sql .= " AND NOT (status = 'paid' AND DATE_ADD(updated_at, INTERVAL 6 MONTH) < NOW())";
    $params = ['customer_id' => $customer_id];
    
    if ($filter_status) {
        $sql .= " AND status = :status";
        $params['status'] = $filter_status;
    }
    
    $sql .= " ORDER BY billing_month DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get ARCHIVED bills (paid bills older than 6 months)
    $archive_sql = "SELECT * FROM bills WHERE tenant_id = :customer_id AND status = 'paid' AND DATE_ADD(updated_at, INTERVAL 6 MONTH) < NOW()";
    $archive_sql .= " ORDER BY billing_month DESC";
    $archive_stmt = $conn->prepare($archive_sql);
    $archive_stmt->execute(['customer_id' => $customer_id]);
    $archive_bills = $archive_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total balance
    $stmt = $conn->prepare("SELECT SUM(amount_due - amount_paid) as balance FROM bills WHERE tenant_id = :customer_id");
    $stmt->execute(['customer_id' => $customer_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $balance = ($result && $result['balance'] !== null) ? (float)$result['balance'] : 0;

    // Get unpaid bills count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bills WHERE tenant_id = :customer_id AND status IN ('pending', 'unpaid', 'overdue')");
    $stmt->execute(['customer_id' => $customer_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $unpaid_count = ($result && $result['count']) ? (int)$result['count'] : 0;

    // Get next due date
    $stmt = $conn->prepare("SELECT MIN(due_date) as next_due FROM bills WHERE tenant_id = :customer_id AND status IN ('pending', 'unpaid')");
    $stmt->execute(['customer_id' => $customer_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_due = ($result && $result['next_due']) ? $result['next_due'] : null;

} catch (Exception $e) {
    $error = "Error loading bills: " . $e->getMessage();
}

// Fetch pending payments submitted by tenant
try {
    $pending_stmt = $conn->prepare("
        SELECT 
            pt.id,
            pt.payment_amount,
            pt.payment_method,
            pt.payment_type,
            pt.payment_status,
            pt.payment_date,
            b.billing_month,
            b.amount_due
        FROM payment_transactions pt
        JOIN bills b ON pt.bill_id = b.id
        WHERE pt.tenant_id = :customer_id AND pt.payment_status = 'pending'
        ORDER BY pt.payment_date DESC
    ");
    $pending_stmt->execute(['customer_id' => $customer_id]);
    $pending_payments = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);
    $pending_count = count($pending_payments);
} catch (Exception $e) {
    $pending_payments = [];
    $pending_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bills - BAMINT</title>
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
        .user-info h5 { margin-bottom: 0.25rem; }
        .user-info p { font-size: 0.9rem; opacity: 0.8; margin-bottom: 0; }
        .metric-card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .metric-value { font-size: 1.75rem; font-weight: 700; }
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
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
        .bill-card {
            border-left: 4px solid #667eea;
            transition: all 0.3s;
        }
        .bill-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
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
                    <div class="user-info">
                        <h5><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION["name"]); ?></h5>
                        <p><?php echo htmlspecialchars($_SESSION["email"]); ?></p>
                    </div>

                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_dashboard.php">
                                <i class="bi bi-house-door"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="tenant_bills.php">
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
                            <a class="nav-link" href="tenant_profile.php">
                                <i class="bi bi-person"></i> My Profile
                            </a>
                        </li>
                    </ul>

                    <form action="logout.php" method="post">
                        <button type="submit" class="btn btn-logout">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </form>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                        <!-- Always show Remaining Balance card at the top -->
                        <?php
                        // Calculate unpaid room balance (verified/approved payments only)
                        $bills_stmt = $conn->prepare("SELECT * FROM bills WHERE tenant_id = :customer_id AND status IN ('pending','partial','unpaid','overdue')");
                        $bills_stmt->execute(['customer_id' => $customer_id]);
                        $bills = $bills_stmt->fetchAll(PDO::FETCH_ASSOC);
                        $unpaid_room_total = 0.0;
                        foreach ($bills as $bill) {
                            $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified','approved')");
                            $sum_stmt->execute(['bill_id' => $bill['id']]);
                            $live_paid = floatval($sum_stmt->fetchColumn());
                            $unpaid_room_total += max(0, floatval($bill['amount_due']) - $live_paid);
                        }
                        ?>
                        <div class="row mb-4">
                            <!-- Removed duplicate Remaining Balance card at the top -->
                        </div>
                <!-- Header -->
                <div class="header-banner">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h1><i class="bi bi-receipt"></i> My Bills</h1>
                            <p class="mb-0">View and manage your billing information</p>
                        </div>
                        <a href="tenant_make_payment.php" class="btn btn-light btn-lg">
                            <i class="bi bi-credit-card"></i> Make a Payment
                        </a>
					</div>
				</div>

                <!-- Additional Charges Card (with accurate remaining balance calculation) -->
                <?php
                // Use $bills from the main query at the top of the file (already filtered for active/unpaid)

                // Fetch all completed amenities for this tenant
                $amenities_stmt = $conn->prepare("SELECT id, category, cost, billed, billed_bill_id FROM maintenance_requests WHERE tenant_id = :customer_id AND status = 'completed' AND cost > 0 ORDER BY submitted_date DESC");
                $amenities_stmt->execute(['customer_id' => $customer_id]);
                $amenities = $amenities_stmt->fetchAll(PDO::FETCH_ASSOC);

                // Prepare Additional Charges (amenities that were completed and billed)
                $additional_items = [];
                $additional_total_unpaid = 0.0;
                foreach ($amenities as $a) {
                    $bill_id = $a['billed'] && $a['billed_bill_id'] ? $a['billed_bill_id'] : null;
                    $billing_month = null;
                    $bill_status = 'not_billed';
                    $bill_remaining = null;
                    if ($bill_id) {
                        $billLookupStmt = $conn->prepare("SELECT id, billing_month, amount_due, amount_paid, status FROM bills WHERE id = :bill_id LIMIT 1");
                        $billLookupStmt->execute(['bill_id' => $bill_id]);
                        $bill = $billLookupStmt->fetch(PDO::FETCH_ASSOC);
                        if ($bill) {
                            $billing_month = $bill['billing_month'];
                            $bill_status = $bill['status'];
                            // Use live sum of verified/approved payments for this bill
                            $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified','approved')");
                            $sum_stmt->execute(['bill_id' => $bill['id']]);
                            $paid = floatval($sum_stmt->fetchColumn());
                            $bill_remaining = max(0, floatval($bill['amount_due']) - $paid);
                        }
                    }
                    $alloc = $bill_remaining !== null ? min(floatval($a['cost']), $bill_remaining) : 0;
                    if ($alloc > 0) $additional_total_unpaid += $alloc;
                    $additional_items[] = [
                        'request_id' => $a['id'],
                        'category' => $a['category'],
                        'cost' => floatval($a['cost']),
                        'bill_id' => $bill_id,
                        'billing_month' => $billing_month,
                        'bill_status' => $bill_status,
                        'bill_remaining' => $bill_remaining,
                        'unpaid_alloc' => $alloc
                    ];
                }

                // Calculate unpaid room balance (verified/approved payments only)
                $unpaid_room_total = 0.0;
                foreach ($bills as $bill) {
                    $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified','approved')");
                    $sum_stmt->execute(['bill_id' => $bill['id']]);
                    $live_paid = floatval($sum_stmt->fetchColumn());
                    $unpaid_room_total += max(0, floatval($bill['amount_due']) - $live_paid);
                }

                // Grand Total Due = unpaid room + unpaid amenities (unpaid_alloc)
                $grand_total_due = $unpaid_room_total + $additional_total_unpaid;
                ?>
                <div class="row g-4 mb-4">
                    <div class="col-12">
                        <div class="card bill-card border-info mb-4">
                            <div class="card-body">
                                <h5 class="card-title mb-1 text-info"><i class="bi bi-cart-plus"></i> Additional Charges & Remaining Balance</h5>
                                <div class="mb-2">
                                    <small class="text-muted d-block">Remaining Balance for Stay (Room Only)</small>
                                    <?php
                                    // Show the remaining balance for the most recent unpaid bill (room only, no amenities), regardless of stay validity
                                    $recent_bill_balance = 0.0;
                                    if (!empty($bills)) {
                                        usort($bills, function($a, $b) {
                                            $a_time = strtotime($a['billing_month']);
                                            $b_time = strtotime($b['billing_month']);
                                            if ($b_time === $a_time) {
                                                return intval($b['id']) - intval($a['id']);
                                            }
                                            return $b_time - $a_time;
                                        });
                                        foreach ($bills as $bill) {
                                            $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified','approved')");
                                            $sum_stmt->execute(['bill_id' => $bill['id']]);
                                            $live_paid = floatval($sum_stmt->fetchColumn());
                                            $recent_bill_balance = floatval($bill['amount_due']) - $live_paid;
                                            if ($recent_bill_balance < 0) $recent_bill_balance = 0.0;
                                            break;
                                        }
                                    }
                                    ?>
                                    <span class="fw-bold">Remaining Balance: </span>
                                    <strong class="text-dark">₱<?php echo number_format($recent_bill_balance, 2); ?></strong>
                                    <?php
                                    // Calculate Grand Total Due as remaining balance + additional charges
                                    $grand_total_due = $recent_bill_balance;
                                    foreach ($additional_items as $ai) {
                                        $grand_total_due += floatval($ai['cost']);
                                    }
                                    ?>
                                </div>
                                <?php if (count($amenities) > 0): ?>
                                    <ul class="list-group mb-3">
                                        <?php foreach ($amenities as $a): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><?php echo htmlspecialchars($a['category']); ?></span>
                                                <span class="fw-bold">₱<?php echo number_format($a['cost'], 2); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php
                                    // Fix: define $total_amenities as the sum of all amenity costs (matches $additional_items)
                                    $total_amenities = 0.0;
                                    foreach ($additional_items as $ai) {
                                        $total_amenities += floatval($ai['cost']);
                                    }
                                    ?>
                                    <div class="d-flex justify-content-end mb-2">
                                        <span class="me-2 fw-bold">Total Additional Charges:</span>
                                        <span class="fw-bold text-primary">₱<?php echo number_format($total_amenities, 2); ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info mb-0">No additional charges found.</div>
                                <?php endif; ?>
                                <hr>
                                <div class="d-flex justify-content-end">
                                    <span class="me-2 fw-bold">Grand Total Due:</span>
                                    <span class="fw-bold text-danger">₱<?php echo number_format($grand_total_due, 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>


                <!-- Pending Payments Section -->
                <?php if ($pending_count > 0): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-hourglass-split me-2" style="font-size: 1.5rem;"></i>
                        <div>
                            <h5 class="alert-heading mb-1">⏳ Pending Payment Status</h5>
                            <p class="mb-0">You have <strong><?php echo $pending_count; ?></strong> payment<?php echo $pending_count !== 1 ? 's' : ''; ?> awaiting admin review.</p>
                        </div>
                    </div>
                    <hr>
                    <div class="row g-3">
                        <?php foreach ($pending_payments as $payment): ?>
                        <div class="col-md-6">
                            <div class="card border-warning h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="card-title">
                                            <?php echo date('F Y', strtotime($payment['billing_month'])); ?>
                                        </h6>
                                        <span class="badge bg-warning">
                                            ⏳ Awaiting Review
                                        </span>
                                    </div>
                                    <p class="text-muted small mb-2">
                                        <i class="bi bi-credit-card"></i> <?php echo htmlspecialchars($payment['payment_method']); ?> | 
                                        <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?>
                                    </p>
                                    <h5 class="text-primary mb-0">₱<?php echo number_format($payment['payment_amount'], 2); ?></h5>
                                    <small class="text-muted">
                                        Waiting for admin approval
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active Bills View -->
                <div id="activeView" style="display: <?php echo $view === 'active' ? 'block' : 'none'; ?>;">
                    <!-- Bills List -->
                    <div class="row g-4">
                        <?php
                        // Calculate total additional charges (all amenities)
                        // Fetch all amenities for this tenant
                        $amenities_stmt = $conn->prepare("SELECT category, cost FROM maintenance_requests WHERE tenant_id = :customer_id AND status = 'completed' AND cost > 0 ORDER BY submitted_date DESC");
                        $amenities_stmt->execute(['customer_id' => $customer_id]);
                        $amenities = $amenities_stmt->fetchAll(PDO::FETCH_ASSOC);
                        $total_amenities = 0.0;
                        foreach ($amenities as $a) {
                            $total_amenities += floatval($a['cost']);
                        }
                        ?>
                        <div class="col-12">
                            <div class="card bill-card border-info mb-4">
                                <div class="card-body">
                                    <h5 class="card-title mb-1 text-info"><i class="bi bi-cart-plus"></i> Additional Charges</h5>
                                    <?php if (count($amenities) > 0): ?>
                                        <ul class="list-group mb-3">
                                            <?php foreach ($amenities as $a): ?>
                                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                                    <span><?php echo htmlspecialchars($a['category']); ?></span>
                                                    <span class="fw-bold">₱<?php echo number_format($a['cost'], 2); ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <div class="d-flex justify-content-end">
                                            <span class="me-2 fw-bold">Total:</span>
                                            <span class="fw-bold text-primary">₱<?php echo number_format($total_amenities, 2); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info mb-0">No additional charges found.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                                                <?php if ($checkin && $checkout): ?>
                                                    <br><small class="text-muted">Stay: <?php echo date('M d, Y', strtotime($checkin)); ?> - <?php echo date('M d, Y', strtotime($checkout)); ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Amount Paid</small>
                                                <strong class="text-success">₱<?php echo number_format($amount_paid_live, 2); ?></strong>
                                            </div>
                                        </div>

                                        <?php if ($bill['discount'] > 0): ?>
                                            <div class="row mb-3">
                                                <div class="col-12">
                                                    <small class="text-muted d-block">Discount Applied</small>
                                                    <strong class="text-info">₱<?php echo number_format($bill['discount'], 2); ?></strong>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="progress mb-3" style="height: 8px;">
                                            <div class="progress-bar <?php echo $bill['amount_paid'] >= $bill['amount_due'] ? 'bg-success' : 'bg-warning'; ?>" 
                                                 style="width: <?php echo min(100, round(($bill['amount_paid'] / max(1, $bill['amount_due'])) * 100)); ?>%"></div>
                                        </div>

                                        <?php if ($bill['status'] !== 'paid'): ?>
                                            <small class="text-muted">Balance: <strong>₱<?php echo number_format($bill['amount_due'] - $bill['amount_paid'], 2); ?></strong></small>
                                        <?php else: ?>
                                            <small class="text-success">
                                                <i class="bi bi-check-circle"></i> Paid on <?php echo date('M d, Y', strtotime($bill['paid_date'])); ?>
                                            </small>
                                        <?php endif; ?>

                                        <?php if (!empty($bill['notes'])): ?>
                                            <?php if (
                                                ($bill['status'] === 'pending' || $bill['status'] === 'pending_payment') &&
                                                stripos($bill['notes'], 'ADVANCE PAYMENT') !== false
                                            ): ?>
                                                <div class="mt-3">
                                                    <a href="tenant_make_payment.php?bill_id=<?php echo $bill['id']; ?>" class="btn btn-primary w-100">
                                                        <i class="bi bi-credit-card"></i> Pay Downpayment
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <?php endif; ?>
                    </div>
                </div><!-- End activeView -->

                <!-- Archive Bills View -->
                <div id="archiveView" style="display: <?php echo $view === 'archive' ? 'block' : 'none'; ?>;">
                    <!-- Archived Bills List -->
                    <div class="row g-4">
                        <?php if (!empty($archive_bills)): ?>
                            <?php foreach ($archive_bills as $bill): ?>
                                <div class="col-md-6">
                                    <div class="card bill-card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <div>
                                                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Archived</span>
                                                    <h6 class="card-title mt-2">Billing Month: <strong><?php echo date('F Y', strtotime($bill['billing_month'])); ?></strong></h6>
                                                </div>
                                            </div>

                                            <hr>

                                            <div class="row mb-3">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Amount Due</small>
                                                    <strong class="text-dark">₱<?php echo number_format($bill['amount_due'], 2); ?></strong>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Amount Paid</small>
                                                    <strong class="text-success">₱<?php echo number_format($bill['amount_paid'], 2); ?></strong>
                                                </div>
                                            </div>

                                            <?php if ($bill['discount'] > 0): ?>
                                                <div class="row mb-3">
                                                    <div class="col-12">
                                                        <small class="text-muted d-block">Discount Applied</small>
                                                        <strong class="text-info">₱<?php echo number_format($bill['discount'], 2); ?></strong>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <div class="progress mb-3" style="height: 8px;">
                                                <div class="progress-bar bg-success" 
                                                     style="width: 100%"></div>
                                            </div>

                                            <small class="text-success">
                                                <i class="bi bi-check-circle"></i> Paid on <?php echo date('M d, Y', strtotime($bill['paid_date'])); ?>
                                            </small>

                                            <?php if (!empty($bill['notes'])): ?>
                                                <div class="mt-3 p-2 bg-light rounded">
                                                    <small class="text-muted">Notes: <?php echo htmlspecialchars($bill['notes']); ?></small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> No archived bills found.
                                </div>
                            </div>
                        <?php endif; ?>
                        </div>
                    </div><!-- End archiveView -->
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function switchView(view) {
            // Update URL to reflect current view
            const url = new URL(window.location);
            url.searchParams.set('view', view);
            window.history.pushState({}, '', url);
            
            // Toggle view display
            const activeView = document.getElementById('activeView');
            const archiveView = document.getElementById('archiveView');
            
            if (view === 'active') {
                activeView.style.display = 'block';
                archiveView.style.display = 'none';
                // Update active button styling
                document.querySelectorAll('.nav-link').forEach(btn => btn.classList.remove('active'));
                event.target.closest('.nav-link').classList.add('active');
            } else if (view === 'archive') {
                activeView.style.display = 'none';
                archiveView.style.display = 'block';
                // Update active button styling
                document.querySelectorAll('.nav-link').forEach(btn => btn.classList.remove('active'));
                event.target.closest('.nav-link').classList.add('active');
            }
        }
    </script>
</body>
</html>
