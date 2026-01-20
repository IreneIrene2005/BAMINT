<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

// ========== METRICS DATA ==========

// Total tenants
$sql_tenants = "SELECT COUNT(*) FROM tenants";
$total_tenants = $conn->query($sql_tenants)->fetchColumn();

// Total rooms
$sql_rooms = "SELECT COUNT(*) FROM rooms";
$total_rooms = $conn->query($sql_rooms)->fetchColumn();

// Occupied rooms
$sql_occupied = "SELECT COUNT(*) FROM rooms WHERE status = 'occupied'";
$occupied_rooms = $conn->query($sql_occupied)->fetchColumn();

// Occupancy rate percentage
$occupancy_rate = $total_rooms > 0 ? round(($occupied_rooms / $total_rooms) * 100, 1) : 0;

// Total income this month (from bills with current month)
$current_month = date('Y-m');
$sql_income = "SELECT COALESCE(SUM(amount_paid), 0) FROM bills WHERE billing_month = :month";
$stmt = $conn->prepare($sql_income);
$stmt->execute(['month' => $current_month]);
$total_income = $stmt->fetchColumn();

// Overdue payments count
$sql_overdue = "SELECT COUNT(*) FROM bills WHERE status = 'overdue'";
$overdue_payments = $conn->query($sql_overdue)->fetchColumn();

// Pending maintenance requests
$sql_pending_maintenance = "SELECT COUNT(*) FROM maintenance_requests WHERE status = 'pending'";
$pending_maintenance = $conn->query($sql_pending_maintenance)->fetchColumn();

// ========== REVENUE TRENDS (Last 6 months) ==========
$revenue_data = [];
$revenue_labels = [];

for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $sql = "SELECT COALESCE(SUM(amount_paid), 0) FROM bills WHERE billing_month = :month";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['month' => $month]);
    $amount = $stmt->fetchColumn();
    
    $revenue_data[] = (int)$amount;
    $revenue_labels[] = date('M Y', strtotime($month));
}

// ========== ROOM OCCUPANCY BY TYPE ==========
$room_types_data = [];
$room_types_labels = [];

$sql_room_types = "SELECT room_type, COUNT(*) as count FROM rooms GROUP BY room_type ORDER BY count DESC";
$room_types_result = $conn->query($sql_room_types);

while ($row = $room_types_result->fetch(PDO::FETCH_ASSOC)) {
    $room_types_labels[] = $row['room_type'];
    $room_types_data[] = (int)$row['count'];
}

// ========== ACTIVE TENANTS BY OCCUPANCY ==========
$occupancy_chart_labels = [];
$occupancy_chart_data = [];

$sql_occupancy = "SELECT 
    CASE 
        WHEN status = 'occupied' THEN 'Occupied'
        ELSE 'Vacant'
    END as status, COUNT(*) as count
    FROM rooms GROUP BY status";

$occupancy_result = $conn->query($sql_occupancy);

while ($row = $occupancy_result->fetch(PDO::FETCH_ASSOC)) {
    $occupancy_chart_labels[] = $row['status'];
    $occupancy_chart_data[] = (int)$row['count'];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - BAMINT</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .metric-card {
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .metric-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .metric-value {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        .metric-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 5px;
        }
        .metric-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        .refresh-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .last-updated {
            font-size: 0.85rem;
            color: #999;
            text-align: right;
            margin-top: 10px;
        }
    </style>
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </h1>
                <button class="btn btn-outline-secondary btn-sm" onclick="location.reload();" title="Refresh data">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>

            <!-- KEY METRICS SECTION -->
            <div class="row mb-4">
                <h4 class="mb-3">Key Metrics</h4>
                
                <!-- Total Tenants -->
                <div class="col-md-2 col-sm-4 mb-3">
                    <div class="card metric-card bg-primary text-white h-100">
                        <div class="card-body text-center">
                            <div class="metric-icon">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="metric-label">Total Tenants</div>
                            <div class="metric-value"><?php echo $total_tenants; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Total Rooms -->
                <div class="col-md-2 col-sm-4 mb-3">
                    <div class="card metric-card bg-success text-white h-100">
                        <div class="card-body text-center">
                            <div class="metric-icon">
                                <i class="bi bi-building"></i>
                            </div>
                            <div class="metric-label">Total Rooms</div>
                            <div class="metric-value"><?php echo $total_rooms; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Occupancy Rate -->
                <div class="col-md-2 col-sm-4 mb-3">
                    <div class="card metric-card bg-info text-white h-100">
                        <div class="card-body text-center">
                            <div class="metric-icon">
                                <i class="bi bi-percent"></i>
                            </div>
                            <div class="metric-label">Occupancy Rate</div>
                            <div class="metric-value"><?php echo $occupancy_rate; ?>%</div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Income -->
                <div class="col-md-2 col-sm-4 mb-3">
                    <div class="card metric-card bg-success text-white h-100">
                        <div class="card-body text-center">
                            <div class="metric-icon">
                                <i class="bi bi-cash-coin"></i>
                            </div>
                            <div class="metric-label">This Month</div>
                            <div class="metric-value">₱<?php echo number_format($total_income, 0); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Overdue Payments -->
                <div class="col-md-2 col-sm-4 mb-3">
                    <div class="card metric-card bg-warning text-white h-100">
                        <div class="card-body text-center">
                            <div class="metric-icon">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="metric-label">Overdue Bills</div>
                            <div class="metric-value"><?php echo $overdue_payments; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Pending Maintenance -->
                <div class="col-md-2 col-sm-4 mb-3">
                    <div class="card metric-card bg-danger text-white h-100">
                        <div class="card-body text-center">
                            <div class="metric-icon">
                                <i class="bi bi-tools"></i>
                            </div>
                            <div class="metric-label">Pending Requests</div>
                            <div class="metric-value"><?php echo $pending_maintenance; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CHARTS SECTION -->
            <div class="row">
                <!-- Revenue Trend Chart -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <i class="bi bi-graph-up"></i> Revenue Trend (Last 6 Months)
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="revenueChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Room Occupancy Chart -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <i class="bi bi-pie-chart"></i> Room Occupancy Status
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="occupancyChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Room Types Chart -->
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <i class="bi bi-columns-gap"></i> Room Distribution by Type
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="roomTypesChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <i class="bi bi-lightning"></i> Quick Actions
                        </div>
                        <div class="card-body">
                            <div class="list-group">
                                <a href="tenants.php" class="list-group-item list-group-item-action">
                                    <i class="bi bi-people"></i> Manage Tenants
                                </a>
                                <a href="rooms.php" class="list-group-item list-group-item-action">
                                    <i class="bi bi-building"></i> Manage Rooms
                                </a>
                                <a href="bills.php" class="list-group-item list-group-item-action">
                                    <i class="bi bi-receipt"></i> Manage Bills
                                </a>
                                <a href="maintenance_requests.php" class="list-group-item list-group-item-action">
                                    <i class="bi bi-tools"></i> Maintenance Requests
                                </a>
                                <a href="overdue_reminders.php" class="list-group-item list-group-item-action">
                                    <i class="bi bi-exclamation-triangle"></i> Overdue Accounts
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="last-updated">
                <small>Last updated: <span id="updateTime"><?php echo date('M d, Y H:i:s'); ?></span></small>
            </div>
        </main>
    </div>
</div>

<!-- Floating Refresh Button -->
<button class="btn btn-primary refresh-btn" onclick="refreshDashboard();" title="Auto-refresh dashboard">
    <i class="bi bi-arrow-clockwise"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ========== CHART CONFIGURATION ==========

// Revenue Trend Chart
const revenueCtx = document.getElementById('revenueChart').getContext('2d');
const revenueChart = new Chart(revenueCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($revenue_labels); ?>,
        datasets: [{
            label: 'Revenue (₱)',
            data: <?php echo json_encode($revenue_data); ?>,
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13, 110, 253, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#0d6efd',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₱' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Room Occupancy Chart
const occupancyCtx = document.getElementById('occupancyChart').getContext('2d');
const occupancyChart = new Chart(occupancyCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($occupancy_chart_labels); ?>,
        datasets: [{
            data: <?php echo json_encode($occupancy_chart_data); ?>,
            backgroundColor: [
                '#198754',
                '#dc3545'
            ],
            borderColor: '#fff',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Room Types Chart
<?php if (!empty($room_types_labels)): ?>
const roomTypesCtx = document.getElementById('roomTypesChart').getContext('2d');
const roomTypesChart = new Chart(roomTypesCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($room_types_labels); ?>,
        datasets: [{
            label: 'Number of Rooms',
            data: <?php echo json_encode($room_types_data); ?>,
            backgroundColor: [
                '#0d6efd',
                '#198754',
                '#ffc107',
                '#17a2b8',
                '#e83e8c',
                '#6c757d'
            ],
            borderColor: '#fff',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        },
        plugins: {
            legend: {
                display: true,
                position: 'top'
            }
        }
    }
});
<?php endif; ?>

// ========== AUTO-REFRESH FUNCTION ==========

function refreshDashboard() {
    const now = new Date();
    document.getElementById('updateTime').textContent = now.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    
    // Reload page data via AJAX
    fetch('dashboard.php')
        .then(response => response.text())
        .then(data => {
            console.log('Dashboard data refreshed');
        })
        .catch(error => console.error('Error refreshing dashboard:', error));
}

// Auto-refresh every 5 minutes (300000 ms)
setInterval(refreshDashboard, 300000);

// Update time display every minute
setInterval(function() {
    const now = new Date();
    document.getElementById('updateTime').textContent = now.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
}, 60000);
</script>

</body>
</html>