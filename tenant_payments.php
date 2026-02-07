<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "tenant") {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";

$tenant_id = $_SESSION["tenant_id"];

try {
    // Get payment history (excluding $0 transactions used for tracking)
    $stmt = $conn->prepare("
        SELECT pt.*, b.billing_month, b.amount_due, b.room_id as bill_room_id
        FROM payment_transactions pt
        JOIN bills b ON pt.bill_id = b.id
        WHERE pt.tenant_id = :tenant_id AND pt.payment_amount > 0
        ORDER BY pt.payment_date DESC
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get payment summary (excluding $0 transactions)
    $result = $conn->query("
        SELECT 
            COUNT(*) as total_payments,
            SUM(payment_amount) as total_amount
        FROM payment_transactions
        WHERE tenant_id = $tenant_id AND payment_amount > 0
    ");
    $summary = $result->fetch(PDO::FETCH_ASSOC);

    // Get payment methods breakdown (excluding $0 transactions)
    $result = $conn->query("
        SELECT 
            payment_method,
            COUNT(*) as count,
            SUM(payment_amount) as total
        FROM payment_transactions
        WHERE tenant_id = $tenant_id AND payment_amount > 0
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $methods = $result->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = "Error loading payments: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Payments - BAMINT</title>
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
        .payment-row:hover {
            background-color: rgba(102, 126, 234, 0.05);
        }
        .payment-method-card {
            cursor: pointer;
            transition: all 0.3s;
        }
        .payment-method-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        .border-primary {
            border: 2px solid #667eea !important;
        }
        .border-success {
            border: 2px solid #28a745 !important;
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
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h1><i class="bi bi-coin"></i> Payment History & Methods</h1>
                            <p class="mb-0">View your payment records and choose a payment method</p>
                        </div>
                        <a href="tenant_make_payment.php" class="btn btn-light btn-lg">
                            <i class="bi bi-credit-card"></i> Make Payment
                        </a>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Payment Method Selection -->
                <div class="card mb-4">
                    <div class="card-header bg-info bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-credit-card"></i> Choose Your Payment Method</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card border-primary h-100 payment-method-card" onclick="window.location.href='tenant_make_payment.php'">
                                    <div class="card-body text-center py-4">
                                        <div style="font-size: 2.5rem; color: #667eea; margin-bottom: 1rem;">
                                            <i class="bi bi-credit-card"></i>
                                        </div>
                                        <h5 class="card-title">Online Payment</h5>
                                        <p class="text-muted mb-3">Pay via GCash, Bank Transfer, PayMaya, or Check</p>
                                        <p class="small text-secondary">Upload proof of payment for verification</p>
                                        <div class="mt-3">
                                            <a href="tenant_make_payment.php" class="btn btn-primary btn-sm">
                                                <i class="bi bi-arrow-right"></i> Pay Online
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-success h-100 payment-method-card" onclick="alert('Visit our office during business hours to pay with cash or check. Our admin staff will record your payment immediately.')" style="cursor: pointer;">
                                    <div class="card-body text-center py-4">
                                        <div style="font-size: 2.5rem; color: #28a745; margin-bottom: 1rem;">
                                            <i class="bi bi-cash-coin"></i>
                                        </div>
                                        <h5 class="card-title">Walk-in / Cash Payment</h5>
                                        <p class="text-muted mb-3">Pay with cash or check at our office</p>
                                        <p class="small text-secondary">Admin will process your payment immediately</p>
                                        <div class="mt-3">
                                            <button class="btn btn-success btn-sm" onclick="alert('Visit our office during business hours to pay with cash or check. Our admin staff will record your payment immediately.'); return false;">
                                                <i class="bi bi-info-circle"></i> Learn More
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-success bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-cash-coin"></i> Total Paid</p>
                                <p class="metric-value text-success">₱<?php echo number_format($summary['total_amount'] ?? 0, 2); ?></p>
                                <small class="text-muted">All time</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-info bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-receipt"></i> Total Payments</p>
                                <p class="metric-value text-info"><?php echo $summary['total_payments'] ?? 0; ?></p>
                                <small class="text-muted">Transaction count</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-primary bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-graph-up"></i> Avg Payment</p>
                                <p class="metric-value text-primary">
                                    ₱<?php echo number_format(($summary['total_amount'] ?? 0) / max(1, $summary['total_payments'] ?? 1), 2); ?>
                                </p>
                                <small class="text-muted">Per transaction</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-warning bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-calendar-event"></i> Last Payment</p>
                                <p class="metric-value text-warning" style="font-size: 1.25rem;">
                                    <?php echo !empty($payments) ? date('M d', strtotime($payments[0]['payment_date'])) : 'N/A'; ?>
                                </p>
                                <small class="text-muted"><?php echo !empty($payments) ? date('Y', strtotime($payments[0]['payment_date'])) : ''; ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods Breakdown -->
                <?php if (!empty($methods)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-secondary bg-opacity-10">
                            <h6 class="mb-0"><i class="bi bi-pie-chart"></i> Payment Methods Used</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($methods as $method): ?>
                                    <div class="col-md-6 col-lg-3 mb-3">
                                        <div class="text-center">
                                            <h6><?php echo htmlspecialchars($method['payment_method'] ?? 'Unknown'); ?></h6>
                                            <p class="text-muted mb-2">
                                                <span class="badge bg-primary"><?php echo $method['count']; ?> times</span>
                                            </p>
                                            <p class="h5 text-success">₱<?php echo number_format($method['total'], 2); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Payment History Table -->
                <div class="card">
                    <div class="card-header bg-primary bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-list-check"></i> Transaction History</h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($payments)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Stay Duration</th>
                                            <th class="text-end">Amount</th>
                                            <th>Method</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): ?>
                                            <tr class="payment-row">
                                                <td>
                                                    <strong><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></strong>
                                                </td>
                                                <td>
                                                    <?php
                                                    // Fetch stay duration from room_requests
                                                    $room_id = isset($payment['room_id']) ? $payment['room_id'] : (isset($payment['bill_room_id']) ? $payment['bill_room_id'] : null);
                                                    $room_req_stmt = $conn->prepare("SELECT checkin_date, checkout_date FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                                    $room_req_stmt->execute(['tenant_id' => $payment['tenant_id'], 'room_id' => $room_id]);
                                                    $dates = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                                    $checkin = $dates ? $dates['checkin_date'] : null;
                                                    $checkout = $dates ? $dates['checkout_date'] : null;
                                                    if ($checkin && $checkout) {
                                                        echo date('M d, Y', strtotime($checkin)) . ' - ' . date('M d, Y', strtotime($checkout));
                                                    } else {
                                                        echo date('F Y', strtotime($payment['billing_month']));
                                                    }
                                                    ?>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-success">₱<?php echo number_format($payment['payment_amount'], 2); ?></span>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <i class="bi bi-credit-card"></i>
                                                        <?php echo htmlspecialchars($payment['payment_method'] ?? 'N/A'); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if (!empty($payment['notes'])): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($payment['notes']); ?></small>
                                                    <?php else: ?>
                                                        <small class="text-muted text-secondary">-</small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No payments recorded yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
