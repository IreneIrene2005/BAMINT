<?php
// admin_reports.php
// Reports & Analytics page for Admin
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}
require_once "db/database.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports & Analytics - BAMINT Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="header-banner mb-4">
                <h1 class="h2 mb-0"><i class="bi bi-bar-chart"></i> Reports & Analytics</h1>
                <p class="mb-0">Generate and view detailed reports and analytics for bookings, revenue, occupancy, and payments.</p>
            </div>
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <i class="bi bi-clipboard-data"></i> Generate Reports
                        </div>
                        <div class="card-body">
                            <form class="row g-3">
                                <div class="col-md-3">
                                    <label for="reportType" class="form-label">Report Type</label>
                                    <select class="form-select" id="reportType" name="reportType">
                                        <option value="bookings">Bookings</option>
                                        <option value="revenue">Revenue</option>
                                        <option value="occupancy">Occupancy</option>
                                        <option value="payments">Payment Summary</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="period" class="form-label">Period</label>
                                    <select class="form-select" id="period" name="period">
                                        <option value="daily">Daily</option>
                                        <option value="monthly">Monthly</option>
                                        <option value="yearly">Yearly</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="date" class="form-label">Date</label>
                                    <input type="date" class="form-control" id="date" name="date">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Generate</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mb-4">
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <i class="bi bi-bar-chart"></i> Bookings (Bar Chart)
                        </div>
                        <div class="card-body">
                            <canvas id="bookingsBarChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <i class="bi bi-pie-chart"></i> Revenue (Pie Chart)
                        </div>
                        <div class="card-body">
                            <canvas id="revenuePieChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-warning text-white">
                            <i class="bi bi-graph-up"></i> Occupancy (Line Chart)
                        </div>
                        <div class="card-body">
                            <canvas id="occupancyLineChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <i class="bi bi-cash-coin"></i> Payment Summary (Bar Chart)
                        </div>
                        <div class="card-body">
                            <canvas id="paymentBarChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Placeholder chart data for demonstration
const bookingsBarChart = new Chart(document.getElementById('bookingsBarChart'), {
    type: 'bar',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        datasets: [{
            label: 'Bookings',
            data: [12, 19, 3, 5, 2, 3],
            backgroundColor: '#0d6efd'
        }]
    }
});
const revenuePieChart = new Chart(document.getElementById('revenuePieChart'), {
    type: 'pie',
    data: {
        labels: ['Room', 'Service', 'Other'],
        datasets: [{
            label: 'Revenue',
            data: [12000, 5000, 2000],
            backgroundColor: ['#198754', '#ffc107', '#dc3545']
        }]
    }
});
const occupancyLineChart = new Chart(document.getElementById('occupancyLineChart'), {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        datasets: [{
            label: 'Occupancy Rate (%)',
            data: [80, 85, 78, 90, 88, 92],
            borderColor: '#17a2b8',
            fill: false
        }]
    }
});
const paymentBarChart = new Chart(document.getElementById('paymentBarChart'), {
    type: 'bar',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        datasets: [{
            label: 'Payments',
            data: [8000, 9500, 7000, 11000, 10500, 12000],
            backgroundColor: '#6c757d'
        }]
    }
});
</script>
</body>
</html>
