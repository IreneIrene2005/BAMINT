<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "tenant") {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";

$tenant_id = $_SESSION["tenant_id"];
$filter_status = isset($_GET['status']) && !empty($_GET['status']) ? $_GET['status'] : '';
$error = '';
$bills = [];
$balance = 0;
$unpaid_count = 0;
$next_due = null;

try {
    // Get bills with filtering
    $sql = "SELECT * FROM bills WHERE tenant_id = :tenant_id";
    $params = ['tenant_id' => $tenant_id];
    
    if ($filter_status) {
        $sql .= " AND status = :status";
        $params['status'] = $filter_status;
    }
    
    $sql .= " ORDER BY billing_month DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total balance
    $stmt = $conn->prepare("SELECT SUM(amount_due - amount_paid) as balance FROM bills WHERE tenant_id = :tenant_id");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $balance = ($result && $result['balance'] !== null) ? (float)$result['balance'] : 0;

    // Get unpaid bills count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bills WHERE tenant_id = :tenant_id AND status IN ('pending', 'unpaid', 'overdue')");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $unpaid_count = ($result && $result['count']) ? (int)$result['count'] : 0;

    // Get next due date
    $stmt = $conn->prepare("SELECT MIN(due_date) as next_due FROM bills WHERE tenant_id = :tenant_id AND status IN ('pending', 'unpaid')");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_due = ($result && $result['next_due']) ? $result['next_due'] : null;

} catch (Exception $e) {
    $error = "Error loading bills: " . $e->getMessage();
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
                <!-- Header -->
                <div class="header-banner">
                    <h1><i class="bi bi-receipt"></i> My Bills</h1>
                    <p class="mb-0">View and manage your billing information</p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Key Metrics -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-danger bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-exclamation-circle"></i> Current Balance</p>
                                <p class="metric-value text-danger">₱<?php echo number_format(abs($balance), 2); ?></p>
                                <small class="text-muted"><?php echo $balance >= 0 ? 'Amount due' : 'Credit'; ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-warning bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-clock"></i> Unpaid Bills</p>
                                <p class="metric-value text-warning"><?php echo $unpaid_count; ?></p>
                                <small class="text-muted">Pending payment</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-info bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-calendar"></i> Next Due Date</p>
                                <p class="metric-value text-info"><?php echo $next_due ? date('M d', strtotime($next_due)) : 'N/A'; ?></p>
                                <small class="text-muted"><?php echo $next_due ? date('Y', strtotime($next_due)) : ''; ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-success bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-check-circle"></i> Total Bills</p>
                                <p class="metric-value text-success"><?php echo count($bills); ?></p>
                                <small class="text-muted">All time</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-6">
                                <label for="status" class="form-label">Filter by Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="unpaid" <?php echo $filter_status === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                    <option value="partial" <?php echo $filter_status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                    <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                    <option value="overdue" <?php echo $filter_status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-filter"></i> Filter
                                </button>
                                <a href="tenant_bills.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-clockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bills List -->
                <div class="row g-4">
                    <?php if (!empty($bills)): ?>
                        <?php foreach ($bills as $bill): ?>
                            <div class="col-md-6">
                                <div class="card bill-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h5 class="card-title mb-1">
                                                    <?php echo date('F Y', strtotime($bill['billing_month'])); ?>
                                                </h5>
                                                <small class="text-muted">
                                                    Due: <?php echo date('M d, Y', strtotime($bill['due_date'])); ?>
                                                </small>
                                            </div>
                                            <span class="badge bg-<?php 
                                                $status = $bill['status'];
                                                echo $status === 'paid' ? 'success' : ($status === 'overdue' ? 'danger' : ($status === 'partial' ? 'info' : 'warning'));
                                            ?>">
                                                <?php echo ucfirst($bill['status']); ?>
                                            </span>
                                        </div>

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
                                <i class="bi bi-info-circle"></i> No bills found.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
