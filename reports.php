<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .report-card {
            border: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
        }
        .report-card .card-body {
            padding: 2rem;
            text-align: center;
        }
        .report-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .report-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .report-description {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
        .btn-report {
            width: 100%;
            padding: 0.75rem;
            font-weight: 500;
        }
        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'templates/sidebar.php'; ?>
            
            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="header-section">
                    <h1 class="mb-2"><i class="bi bi-file-earmark-bar-graph"></i> Reports & Analytics</h1>
                    <p class="mb-0">Generate comprehensive reports for decision making and insights</p>
                </div>

                <!-- Report Categories Grid -->
                <div class="row g-4">
                    <!-- Financial Reports -->
                    <div class="col-md-6 col-lg-4">
                        <div class="card report-card h-100">
                            <div class="card-body">
                                <div class="report-icon text-success">
                                    <i class="bi bi-cash-flow"></i>
                                </div>
                                <h5 class="report-title">Financial Reports</h5>
                                <p class="report-description">
                                    Total income, unpaid bills, revenue trends, and financial summaries
                                </p>
                                <a href="financial_reports.php" class="btn btn-success btn-report">
                                    <i class="bi bi-arrow-right"></i> View Reports
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Occupancy Reports -->
                    <div class="col-md-6 col-lg-4">
                        <div class="card report-card h-100">
                            <div class="card-body">
                                <div class="report-icon text-info">
                                    <i class="bi bi-building"></i>
                                </div>
                                <h5 class="report-title">Occupancy Reports</h5>
                                <p class="report-description">
                                    Room status, vacant/occupied breakdown, and utilization rates
                                </p>
                                <a href="occupancy_reports.php" class="btn btn-info btn-report">
                                    <i class="bi bi-arrow-right"></i> View Reports
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Tenant Reports -->
                    <div class="col-md-6 col-lg-4">
                        <div class="card report-card h-100">
                            <div class="card-body">
                                <div class="report-icon text-primary">
                                    <i class="bi bi-people"></i>
                                </div>
                                <h5 class="report-title">Tenant Reports</h5>
                                <p class="report-description">
                                    Active tenants, move-outs, demographics, and tenant analytics
                                </p>
                                <a href="tenant_reports.php" class="btn btn-primary btn-report">
                                    <i class="bi bi-arrow-right"></i> View Reports
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Reports -->
                    <div class="col-md-6 col-lg-4">
                        <div class="card report-card h-100">
                            <div class="card-body">
                                <div class="report-icon text-warning">
                                    <i class="bi bi-tools"></i>
                                </div>
                                <h5 class="report-title">Maintenance Reports</h5>
                                <p class="report-description">
                                    Pending requests, completion rates, costs, and maintenance history
                                </p>
                                <a href="maintenance_reports.php" class="btn btn-warning btn-report">
                                    <i class="bi bi-arrow-right"></i> View Reports
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Reports -->
                    <div class="col-md-6 col-lg-4">
                        <div class="card report-card h-100">
                            <div class="card-body">
                                <div class="report-icon text-danger">
                                    <i class="bi bi-graph-up"></i>
                                </div>
                                <h5 class="report-title">Payment Reports</h5>
                                <p class="report-description">
                                    Payment collection, methods, trends, and transaction history
                                </p>
                                <a href="payment_reports.php" class="btn btn-danger btn-report">
                                    <i class="bi bi-arrow-right"></i> View Reports
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Dashboard Analytics -->
                    <div class="col-md-6 col-lg-4">
                        <div class="card report-card h-100">
                            <div class="card-body">
                                <div class="report-icon text-secondary">
                                    <i class="bi bi-graph-up-arrow"></i>
                                </div>
                                <h5 class="report-title">Dashboard Analytics</h5>
                                <p class="report-description">
                                    Real-time metrics, charts, trends, and system performance overview
                                </p>
                                <a href="dashboard.php" class="btn btn-secondary btn-report">
                                    <i class="bi bi-arrow-right"></i> View Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats Summary -->
                <div class="row g-4 mt-4">
                    <div class="col-md-12">
                        <h4 class="mb-3">Quick Statistics</h4>
                    </div>
                    <?php
                    try {
                        // Total Income
                        $result = $conn->query("SELECT SUM(amount_paid) as total FROM bills");
                        $total_income = $result->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

                        // Unpaid Bills
                        $result = $conn->query("SELECT COUNT(*) as count FROM bills WHERE status IN ('pending', 'overdue')");
                        $unpaid_count = $result->fetch(PDO::FETCH_ASSOC)['count'];

                        // Unpaid Amount
                        $result = $conn->query("SELECT SUM(amount_due - amount_paid) as total FROM bills WHERE status IN ('pending', 'overdue')");
                        $unpaid_amount = $result->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

                        // Occupancy Rate
                        $result = $conn->query("SELECT COUNT(*) as occupied FROM rooms WHERE status = 'occupied'");
                        $occupied = $result->fetch(PDO::FETCH_ASSOC)['occupied'];
                        $result = $conn->query("SELECT COUNT(*) as total FROM rooms");
                        $total_rooms = $result->fetch(PDO::FETCH_ASSOC)['total'];
                        $occupancy_rate = $total_rooms > 0 ? round(($occupied / $total_rooms) * 100, 1) : 0;

                        // Active Tenants
                        $result = $conn->query("SELECT COUNT(*) as count FROM tenants WHERE status = 'active'");
                        $active_tenants = $result->fetch(PDO::FETCH_ASSOC)['count'];

                        // Pending Maintenance
                        $result = $conn->query("SELECT COUNT(*) as count FROM maintenance_requests WHERE status = 'pending'");
                        $pending_maintenance = $result->fetch(PDO::FETCH_ASSOC)['count'];
                    } catch (Exception $e) {
                        // Default values if query fails
                        $total_income = 0;
                        $unpaid_count = 0;
                        $unpaid_amount = 0;
                        $occupancy_rate = 0;
                        $active_tenants = 0;
                        $pending_maintenance = 0;
                    }
                    ?>
                    
                    <div class="col-md-6 col-lg-3">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="text-muted mb-2">Total Income</p>
                                        <h5 class="text-success mb-0">₱<?php echo number_format($total_income, 2); ?></h5>
                                    </div>
                                    <i class="bi bi-cash-coin text-success" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="text-muted mb-2">Unpaid Amount</p>
                                        <h5 class="text-warning mb-0">₱<?php echo number_format($unpaid_amount, 2); ?></h5>
                                    </div>
                                    <i class="bi bi-exclamation-circle text-warning" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="text-muted mb-2">Occupancy Rate</p>
                                        <h5 class="text-info mb-0"><?php echo $occupancy_rate; ?>%</h5>
                                    </div>
                                    <i class="bi bi-percent text-info" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="text-muted mb-2">Active Tenants</p>
                                        <h5 class="text-primary mb-0"><?php echo $active_tenants; ?></h5>
                                    </div>
                                    <i class="bi bi-people text-primary" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
