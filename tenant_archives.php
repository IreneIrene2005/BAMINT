<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "tenant") {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";
require_once "db/ArchiveManager.php";

$tenant_id = $_SESSION["tenant_id"];
$tab = $_GET['tab'] ?? 'payments'; // payments or maintenance

try {
    $archive_manager = new ArchiveManager($conn);
    
    // Get archived data based on tab
    if ($tab === 'maintenance') {
        $archived_records = $archive_manager->getArchivedMaintenanceRequests($tenant_id);
    } else {
        $archived_records = $archive_manager->getArchivedPayments($tenant_id);
    }
    
    // Get archive statistics
    $stats = $archive_manager->getArchiveStats();
    
} catch (Exception $e) {
    $archived_records = [];
    $stats = ['archived_payments' => 0, 'archived_maintenance' => 0];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archives - BAMINT Tenant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { background: linear-gradient(180deg, #667eea 0%, #764ba2 100%); color: white; }
        .sidebar a { color: rgba(255,255,255,0.8); text-decoration: none; }
        .sidebar a:hover { color: white; }
        .sidebar .active { color: white; font-weight: bold; }
        .archive-card { border-left: 4px solid #667eea; margin-bottom: 1rem; }
        .badge-archived { background-color: #6c757d; }
        .table-hover tbody tr:hover { background-color: #f0f0f0; }
    </style>
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <nav class="col-md-2 d-md-block sidebar">
            <div class="position-sticky pt-3">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="tenant_dashboard.php">
                            <i class="bi bi-house-door"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="tenant_profile.php">
                            <i class="bi bi-person"></i> Profile
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="tenant_bills.php">
                            <i class="bi bi-receipt"></i> Bills
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="tenant_maintenance.php">
                            <i class="bi bi-tools"></i> Maintenance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="tenant_archives.php">
                            <i class="bi bi-archive"></i> Archives
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Main content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-4 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-archive"></i> Archives
                </h1>
            </div>

            <!-- Archive Statistics -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-receipt text-info"></i> Archived Payments
                            </h5>
                            <p class="card-text display-6 text-info"><?php echo $stats['archived_payments']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-tools text-success"></i> Archived Maintenance
                            </h5>
                            <p class="card-text display-6 text-success"><?php echo $stats['archived_maintenance']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-collection text-primary"></i> Total Archived
                            </h5>
                            <p class="card-text display-6 text-primary"><?php echo $stats['total_archived']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs for different archive types -->
            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $tab === 'payments' ? 'active' : ''; ?>" 
                               href="?tab=payments">
                                <i class="bi bi-receipt"></i> Archived Payments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $tab === 'maintenance' ? 'active' : ''; ?>" 
                               href="?tab=maintenance">
                                <i class="bi bi-tools"></i> Archived Maintenance
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <?php if (empty($archived_records)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            No archived <?php echo $tab === 'maintenance' ? 'maintenance requests' : 'payments'; ?> yet. 
                            Records are archived after 30 days of completion.
                        </div>
                    <?php else: ?>
                        <?php if ($tab === 'payments'): ?>
                            <!-- Archived Payments Table -->
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Bill Month</th>
                                            <th>Amount Paid</th>
                                            <th>Payment Method</th>
                                            <th>Status</th>
                                            <th>Archived Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($archived_records as $record): ?>
                                        <tr>
                                            <td><?php echo date('F Y', strtotime($record['billing_month'])); ?></td>
                                            <td><strong>₱<?php echo number_format($record['payment_amount'], 2); ?></strong></td>
                                            <td><?php echo ucfirst($record['payment_method']); ?></td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-check-circle"></i> Verified
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('M d, Y', strtotime($record['archived_at'])); ?>
                                                </small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <!-- Archived Maintenance Requests Table -->
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Category</th>
                                            <th>Description</th>
                                            <th>Priority</th>
                                            <th>Completion Date</th>
                                            <th>Archived Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($archived_records as $record): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo ucfirst($record['category']); ?></span>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars(substr($record['description'], 0, 50)); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $record['priority'] === 'high' ? 'danger' : 
                                                         ($record['priority'] === 'medium' ? 'warning' : 'success');
                                                ?>">
                                                    <?php echo ucfirst($record['priority']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($record['completion_date'])); ?>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('M d, Y', strtotime($record['archived_at'])); ?>
                                                </small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="alert alert-info mt-4">
                <i class="bi bi-info-circle"></i>
                <strong>About Archives:</strong> Completed payments and maintenance requests are automatically archived after 30 days for record-keeping. 
                This helps keep your active records clean while maintaining a complete audit trail.
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
