<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

// Date range filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Calculate financial metrics
try {
    // Total Income
    $stmt = $conn->prepare("SELECT SUM(amount_paid) as total FROM bills WHERE DATE(paid_date) BETWEEN :start AND :end AND status = 'paid'");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $total_income = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Total Due
    $stmt = $conn->prepare("SELECT SUM(amount_due) as total FROM bills WHERE DATE(billing_month) BETWEEN :start AND :end");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $total_due = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Total Paid
    $stmt = $conn->prepare("SELECT SUM(amount_paid) as total FROM bills WHERE DATE(billing_month) BETWEEN :start AND :end");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $total_paid = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Unpaid Bills Count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bills WHERE status IN ('pending', 'unpaid') AND DATE(billing_month) BETWEEN :start AND :end");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $unpaid_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Overdue Amount
    $stmt = $conn->prepare("SELECT SUM(amount_due - amount_paid) as total FROM bills WHERE status = 'overdue' AND DATE(due_date) < CURDATE()");
    $stmt->execute();
    $overdue_amount = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Overdue Bills Count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bills WHERE status = 'overdue' AND DATE(due_date) < CURDATE()");
    $stmt->execute();
    $overdue_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Monthly Revenue Breakdown
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(billing_month, '%Y-%m') as month,
            SUM(amount_paid) as income,
            COUNT(*) as bill_count,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count
        FROM bills
        WHERE DATE(billing_month) BETWEEN :start AND :end
        GROUP BY DATE_FORMAT(billing_month, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $monthly_revenue = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top Paying Tenants
    $stmt = $conn->prepare("
        SELECT 
            t.id,
            t.name,
            SUM(b.amount_paid) as total_paid,
            COUNT(b.id) as bill_count
        FROM tenants t
        LEFT JOIN bills b ON t.id = b.tenant_id
        WHERE DATE(b.billing_month) BETWEEN :start AND :end OR b.id IS NULL
        GROUP BY t.id, t.name
        ORDER BY total_paid DESC
        LIMIT 10
    ");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $top_tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Payment Methods Distribution
    $stmt = $conn->prepare("
        SELECT 
            payment_method,
            COUNT(*) as count,
            SUM(payment_amount) as total
        FROM payment_transactions
        WHERE DATE(payment_date) BETWEEN :start AND :end
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $stmt->execute(['start' => $start_date, 'end' => $end_date]);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Collection Rate
    $collection_rate = $total_due > 0 ? round(($total_paid / $total_due) * 100, 2) : 0;

} catch (Exception $e) {
    $error = "Error loading report: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .metric-card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .metric-card .card-body {
            padding: 1.5rem;
        }
        .metric-value {
            font-size: 1.75rem;
            font-weight: 700;
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 2rem;
        }
        .table-sm { font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'templates/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Page Header -->
                <div class="mb-4">
                    <h1 class="mb-1"><i class="bi bi-cash-flow"></i> Financial Reports</h1>
                    <p class="text-muted">Income, bills, payments, and financial analysis</p>
                </div>

                <!-- Date Filter -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                            </div>
                            <div class="col-md-6 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-filter"></i> Filter
                                </button>
                                <a href="financial_reports.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-clockwise"></i> Reset
                                </a>
                                <button type="button" class="btn btn-success" onclick="printReport()">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-success bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2">Total Income</p>
                                <p class="metric-value text-success">₱<?php echo number_format($total_income, 2); ?></p>
                                <small class="text-muted">Period: <?php echo $start_date; ?> to <?php echo $end_date; ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-info bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2">Total Billed</p>
                                <p class="metric-value text-info">₱<?php echo number_format($total_due, 2); ?></p>
                                <small class="text-muted"><?php echo count($monthly_revenue); ?> months</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-warning bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2">Collection Rate</p>
                                <p class="metric-value text-warning"><?php echo $collection_rate; ?>%</p>
                                <small class="text-muted">of total billed amount</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-danger bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2">Overdue Amount</p>
                                <p class="metric-value text-danger">₱<?php echo number_format($overdue_amount, 2); ?></p>
                                <small class="text-muted"><?php echo $overdue_count; ?> bills overdue</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="row g-4 mb-4">
                    <!-- Monthly Revenue Chart -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header bg-success bg-opacity-10">
                                <h6 class="mb-0"><i class="bi bi-graph-up"></i> Monthly Revenue Trend</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="revenueChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Methods Chart -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header bg-info bg-opacity-10">
                                <h6 class="mb-0"><i class="bi bi-pie-chart"></i> Payment Methods</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="methodsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Paying Tenants -->
                <div class="card mb-4">
                    <div class="card-header bg-primary bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-people"></i> Top 10 Paying Tenants</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Tenant Name</th>
                                        <th class="text-end">Total Paid</th>
                                        <th class="text-center">Bills Paid</th>
                                        <th class="text-end">Avg Payment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($top_tenants)): ?>
                                        <?php foreach ($top_tenants as $tenant): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($tenant['name'] ?? 'N/A'); ?></td>
                                                <td class="text-end"><strong>₱<?php echo number_format($tenant['total_paid'] ?? 0, 2); ?></strong></td>
                                                <td class="text-center"><span class="badge bg-success"><?php echo $tenant['bill_count'] ?? 0; ?></span></td>
                                                <td class="text-end">₱<?php echo number_format(($tenant['total_paid'] ?? 0) / max(1, $tenant['bill_count'] ?? 1), 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">No data available</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Monthly Breakdown -->
                <div class="card">
                    <div class="card-header bg-secondary bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-calendar-month"></i> Monthly Breakdown</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th class="text-end">Income</th>
                                        <th class="text-center">Bills Issued</th>
                                        <th class="text-center">Paid</th>
                                        <th class="text-end">Collection %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($monthly_revenue)): ?>
                                        <?php foreach ($monthly_revenue as $month): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($month['month']); ?></td>
                                                <td class="text-end"><strong>₱<?php echo number_format($month['income'] ?? 0, 2); ?></strong></td>
                                                <td class="text-center"><?php echo $month['bill_count']; ?></td>
                                                <td class="text-center"><span class="badge bg-success"><?php echo $month['paid_count']; ?></span></td>
                                                <td class="text-end"><?php echo round(($month['paid_count'] / max(1, $month['bill_count'])) * 100, 1); ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No data available for selected period</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart');
        if (revenueCtx) {
            const revenueData = <?php echo json_encode($monthly_revenue); ?>;
            const months = revenueData.map(d => d.month);
            const income = revenueData.map(d => parseFloat(d.income) || 0);

            new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Monthly Income',
                        data: income,
                        borderColor: '#198754',
                        backgroundColor: 'rgba(25, 135, 84, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointBackgroundColor: '#198754'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { callback: function(value) { return '₱' + value.toLocaleString(); } }
                        }
                    }
                }
            });
        }

        // Payment Methods Chart
        const methodsCtx = document.getElementById('methodsChart');
        if (methodsCtx) {
            const methodsData = <?php echo json_encode($payment_methods); ?>;
            const methods = methodsData.map(d => d.payment_method || 'Unknown');
            const amounts = methodsData.map(d => parseFloat(d.total) || 0);

            new Chart(methodsCtx, {
                type: 'doughnut',
                data: {
                    labels: methods,
                    datasets: [{
                        data: amounts,
                        backgroundColor: [
                            '#0d6efd', '#198754', '#0dcaf0', '#ffc107', '#dc3545', '#6f42c1'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }

        function printReport() {
            window.print();
        }
    </script>
</body>
</html>
