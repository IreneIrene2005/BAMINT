<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "tenant") {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";

// Get tenant information
$tenant_id = $_SESSION["tenant_id"];

try {
    // Get tenant details
    $stmt = $conn->prepare("
        SELECT t.*, r.room_number, r.rate 
        FROM tenants t
        LEFT JOIN rooms r ON t.room_id = r.id
        WHERE t.id = :tenant_id
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get current bills
    $stmt = $conn->prepare("
        SELECT * FROM bills 
        WHERE tenant_id = :tenant_id
        ORDER BY billing_month DESC
        LIMIT 5
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get maintenance requests
    $stmt = $conn->prepare("
        SELECT * FROM maintenance_requests 
        WHERE tenant_id = :tenant_id
        ORDER BY submitted_date DESC
        LIMIT 5
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get balance
    $stmt = $conn->prepare("
        SELECT 
            SUM(amount_due) as total_due,
            SUM(amount_paid) as total_paid
        FROM bills
        WHERE tenant_id = :tenant_id
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $balance = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = "Error loading tenant data: " . $e->getMessage();
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
                            <a class="nav-link" href="tenant_add_room.php">
                                <i class="bi bi-plus-square"></i> Add Room
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_profile.php">
                                <i class="bi bi-person"></i> My Profile
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
                    <p class="mb-0">Welcome back, <?php echo htmlspecialchars($tenant['name'] ?? 'Tenant'); ?>!</p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Key Metrics -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-door-open"></i> Room Number</p>
                                <p class="metric-value text-primary"><?php echo htmlspecialchars($tenant['room_number'] ?? 'N/A'); ?></p>
                                <small class="text-muted"><?php echo htmlspecialchars($tenant['room_type'] ?? 'Not assigned'); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-calendar"></i> Monthly Rent</p>
                                <p class="metric-value text-success">₱<?php echo number_format($tenant['rate'] ?? 0, 2); ?></p>
                                <small class="text-muted">Per month</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-cash"></i> Total Due</p>
                                <p class="metric-value text-warning">₱<?php echo number_format($balance['total_due'] ?? 0, 2); ?></p>
                                <small class="text-muted">All time</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-check-circle"></i> Total Paid</p>
                                <p class="metric-value text-info">₱<?php echo number_format($balance['total_paid'] ?? 0, 2); ?></p>
                                <small class="text-muted">All time</small>
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
                                            <th>Status</th>
                                            <th>Due Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bills as $bill): ?>
                                            <tr>
                                                <td><?php echo date('F Y', strtotime($bill['billing_month'])); ?></td>
                                                <td class="text-end">₱<?php echo number_format($bill['amount_due'], 2); ?></td>
                                                <td class="text-end">₱<?php echo number_format($bill['amount_paid'], 2); ?></td>
                                                <td>
                                                    <?php 
                                                    $status_class = $bill['status'] === 'paid' ? 'success' : ($bill['status'] === 'overdue' ? 'danger' : 'warning');
                                                    ?>
                                                    <span class="badge bg-<?php echo $status_class; ?>"><?php echo ucfirst($bill['status']); ?></span>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($bill['due_date'])); ?></td>
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
</body>
</html>
