<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}

require_once "db/database.php";

$admin_id = $_SESSION["admin_id"];
$message = '';
$message_type = '';

// Handle verification/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;
    $verification_notes = isset($_POST['verification_notes']) ? trim($_POST['verification_notes']) : '';

    if ($action === 'verify' || $action === 'reject') {
        try {
            if ($action === 'verify') {
                // Update payment to verified
                $stmt = $conn->prepare("
                    UPDATE payment_transactions 
                    SET payment_status = 'verified', verified_by = :admin_id, verification_date = NOW()
                    WHERE id = :id AND payment_status = 'pending'
                ");
                $stmt->execute(['id' => $payment_id, 'admin_id' => $admin_id]);

                // If verified, mark bill as paid (if fully paid)
                $payment_stmt = $conn->prepare("
                    SELECT pt.bill_id, pt.tenant_id, b.amount_due FROM payment_transactions pt
                    JOIN bills b ON pt.bill_id = b.id
                    WHERE pt.id = :id
                ");
                $payment_stmt->execute(['id' => $payment_id]);
                $payment_info = $payment_stmt->fetch(PDO::FETCH_ASSOC);

                if ($payment_info) {
                    // Check if bill is fully paid now
                    $bill_check = $conn->prepare("
                        SELECT COALESCE(SUM(payment_amount), 0) as total_paid FROM payment_transactions 
                        WHERE bill_id = :bill_id AND payment_status IN ('verified', 'approved')
                    ");
                    $bill_check->execute(['bill_id' => $payment_info['bill_id']]);
                    $paid_info = $bill_check->fetch(PDO::FETCH_ASSOC);

                    $total_paid = $paid_info['total_paid'];
                    $bill_status = ($total_paid >= $payment_info['amount_due']) ? 'paid' : 'partial';

                    $update_bill = $conn->prepare("
                        UPDATE bills SET status = :status WHERE id = :id
                    ");
                    $update_bill->execute(['status' => $bill_status, 'id' => $payment_info['bill_id']]);

                    // If advance payment (move-in) is verified and paid, activate tenant and mark room as occupied
                    if ($bill_status === 'paid') {
                        // Get tenant room info and details
                        $tenant_stmt = $conn->prepare("
                            SELECT t.id, t.room_id, t.start_date, r.rate 
                            FROM tenants t
                            LEFT JOIN rooms r ON t.room_id = r.id
                            WHERE t.id = :tenant_id
                        ");
                        $tenant_stmt->execute(['tenant_id' => $payment_info['tenant_id']]);
                        $tenant_info = $tenant_stmt->fetch(PDO::FETCH_ASSOC);

                        if ($tenant_info && $tenant_info['room_id']) {
                            // Update tenant status to active
                            $activate_tenant = $conn->prepare("
                                UPDATE tenants SET status = 'active' WHERE id = :tenant_id
                            ");
                            $activate_tenant->execute(['tenant_id' => $payment_info['tenant_id']]);

                            // Mark room as occupied
                            $occupy_room = $conn->prepare("
                                UPDATE rooms SET status = 'occupied' WHERE id = :room_id
                            ");
                            $occupy_room->execute(['room_id' => $tenant_info['room_id']]);
                        }
                    }
                }

                $message = "✓ Payment verified and recorded successfully!";
            } else {
                // Update payment to rejected
                $stmt = $conn->prepare("
                    UPDATE payment_transactions 
                    SET payment_status = 'rejected', verified_by = :admin_id, verification_date = NOW()
                    WHERE id = :id AND payment_status = 'pending'
                ");
                $stmt->execute(['id' => $payment_id, 'admin_id' => $admin_id]);
                $message = "✓ Payment rejected. Tenant will be notified.";
            }
            
            $message_type = "success";
        } catch (Exception $e) {
            $message = "Error processing verification: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Fetch pending online payments
try {
    $stmt = $conn->prepare("
        SELECT pt.*, t.name as tenant_name, t.email as tenant_email, t.phone as tenant_phone,
               b.billing_month, b.amount_due, b.amount_paid,
               a.name as recorded_by_name
        FROM payment_transactions pt
        JOIN tenants t ON pt.tenant_id = t.id
        JOIN bills b ON pt.bill_id = b.id
        LEFT JOIN admins a ON pt.recorded_by = a.id
        WHERE pt.payment_type = 'online' AND pt.payment_status = 'pending'
        ORDER BY pt.created_at DESC
    ");
    $stmt->execute();
    $pending_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = "Error loading payments: " . $e->getMessage();
    $message_type = "danger";
    $pending_payments = [];
}

// Fetch recent verified payments (last 30 days)
try {
    $stmt = $conn->prepare("
        SELECT pt.*, t.name as tenant_name, b.billing_month, a.name as verified_by_name
        FROM payment_transactions pt
        JOIN tenants t ON pt.tenant_id = t.id
        JOIN bills b ON pt.bill_id = b.id
        LEFT JOIN admins a ON pt.verified_by = a.id
        WHERE pt.payment_type = 'online' AND pt.payment_status IN ('verified', 'rejected')
        AND pt.verification_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY pt.verification_date DESC
    ");
    $stmt->execute();
    $recent_verifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_verifications = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Verification - BAMINT Admin</title>
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
        .pending-badge {
            display: inline-block;
            background: #ffc107;
            color: #000;
            padding: 0.35rem 0.75rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .verified-badge {
            display: inline-block;
            background: #28a745;
            color: #fff;
            padding: 0.35rem 0.75rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .rejected-badge {
            display: inline-block;
            background: #dc3545;
            color: #fff;
            padding: 0.35rem 0.75rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .proof-preview {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            margin: 1rem 0;
            border: 1px solid #ddd;
        }
        .payment-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background: white;
            transition: all 0.3s;
        }
        .payment-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .tenant-info {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .stat-card h3 {
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        .stat-card p {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 sidebar">
                <div class="position-sticky pt-3">
                    <div class="user-info">
                        <h5><i class="bi bi-shield-lock"></i> Admin</h5>
                        <p><?php echo htmlspecialchars($_SESSION["name"]); ?></p>
                    </div>

                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="bi bi-house-door"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenants.php">
                                <i class="bi bi-people"></i> Tenants
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="rooms.php">
                                <i class="bi bi-door-closed"></i> Rooms
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="bills.php">
                                <i class="bi bi-receipt"></i> Bills
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="admin_payment_verification.php">
                                <i class="bi bi-check-circle"></i> Payment Verification
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_record_payment.php">
                                <i class="bi bi-cash-coin"></i> Record Cash Payment
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="payment_history.php">
                                <i class="bi bi-clock-history"></i> Payment History
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
                <!-- Header -->
                <div class="header-banner">
                    <h1><i class="bi bi-check-circle"></i> Online Payment Verification</h1>
                    <p class="mb-0">Review and verify proof of online payments from tenants</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics -->
                <div class="stats-row">
                    <div class="stat-card">
                        <h3><?php echo count($pending_payments); ?></h3>
                        <p>Pending Verification</p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo count(array_filter($recent_verifications, fn($p) => $p['payment_status'] === 'verified')); ?></h3>
                        <p>Verified (30 days)</p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo count(array_filter($recent_verifications, fn($p) => $p['payment_status'] === 'rejected')); ?></h3>
                        <p>Rejected (30 days)</p>
                    </div>
                </div>

                <!-- Pending Payments Section -->
                <div class="mb-5">
                    <h4 class="mb-3"><i class="bi bi-hourglass-split"></i> Pending Online Payments</h4>
                    
                    <?php if (empty($pending_payments)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No pending online payments at this time.
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_payments as $payment): ?>
                            <div class="payment-card">
                                <!-- Tenant Info -->
                                <div class="tenant-info">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($payment['tenant_name']); ?></h6>
                                            <small class="text-muted">
                                                <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($payment['tenant_email']); ?><br>
                                                <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($payment['tenant_phone']); ?>
                                            </small>
                                        </div>
                                        <div class="col-md-6 text-md-end">
                                            <h6 class="mb-1">Billing Month</h6>
                                            <p class="text-muted mb-0"><?php echo date('F Y', strtotime($payment['billing_month'])); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Payment Details -->
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Amount</strong><br>
                                        <h5 class="text-primary">₱<?php echo number_format($payment['payment_amount'], 2); ?></h5>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Payment Method</strong><br>
                                        <p class="mb-0"><?php echo htmlspecialchars($payment['payment_method']); ?></p>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Submitted</strong><br>
                                        <p class="mb-0"><?php echo date('M d, Y h:i A', strtotime($payment['created_at'])); ?></p>
                                    </div>
                                    <div class="col-md-3 text-end">
                                        <span class="pending-badge">
                                            <i class="bi bi-clock"></i> Pending
                                        </span>
                                    </div>
                                </div>

                                <!-- Proof of Payment Display -->
                                <?php if ($payment['proof_of_payment']): ?>
                                    <div class="mb-3">
                                        <strong>Proof of Payment:</strong><br>
                                        <?php 
                                        $proof_path = "public/payment_proofs/" . htmlspecialchars($payment['proof_of_payment']);
                                        $file_ext = strtolower(pathinfo($proof_path, PATHINFO_EXTENSION));
                                        ?>
                                        <?php if (in_array($file_ext, ['jpg', 'jpeg', 'png'])): ?>
                                            <img src="<?php echo $proof_path; ?>" alt="Proof" class="proof-preview">
                                        <?php else: ?>
                                            <a href="<?php echo $proof_path; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-file-pdf"></i> View PDF
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Notes -->
                                <?php if ($payment['notes']): ?>
                                    <div class="mb-3">
                                        <strong>Tenant Notes:</strong><br>
                                        <p class="text-muted"><?php echo htmlspecialchars($payment['notes']); ?></p>
                                    </div>
                                <?php endif; ?>

                                <!-- Verification Form -->
                                <form method="POST" class="mt-4">
                                    <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                    
                                    <div class="mb-3">
                                        <label class="form-label"><strong>Verification Decision</strong></label>
                                        <div class="btn-group w-100" role="group">
                                            <input type="radio" class="btn-check" name="action" id="verify_yes" value="verify" required>
                                            <label class="btn btn-outline-success" for="verify_yes">
                                                <i class="bi bi-check-circle"></i> Verify & Approve
                                            </label>

                                            <input type="radio" class="btn-check" name="action" id="verify_no" value="reject" required>
                                            <label class="btn btn-outline-danger" for="verify_no">
                                                <i class="bi bi-x-circle"></i> Reject
                                            </label>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="notes_<?php echo $payment['id']; ?>" class="form-label">Verification Notes (Optional)</label>
                                        <textarea class="form-control" name="verification_notes" id="notes_<?php echo $payment['id']; ?>" 
                                                  rows="2" placeholder="Add notes about this verification..."></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-check-lg"></i> Submit Verification
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Recent Verifications -->
                <div class="mb-5">
                    <h4 class="mb-3"><i class="bi bi-clock-history"></i> Recent Verifications (Last 30 Days)</h4>
                    
                    <?php if (empty($recent_verifications)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No recent verifications.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Tenant Name</th>
                                        <th>Billing Month</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Verified By</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_verifications as $verification): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($verification['tenant_name']); ?></td>
                                            <td><?php echo date('F Y', strtotime($verification['billing_month'])); ?></td>
                                            <td>₱<?php echo number_format($verification['payment_amount'], 2); ?></td>
                                            <td>
                                                <?php if ($verification['payment_status'] === 'verified'): ?>
                                                    <span class="verified-badge"><i class="bi bi-check-circle"></i> Verified</span>
                                                <?php else: ?>
                                                    <span class="rejected-badge"><i class="bi bi-x-circle"></i> Rejected</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($verification['verified_by_name'] ?? 'System'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($verification['verification_date'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
