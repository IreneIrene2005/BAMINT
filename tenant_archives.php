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
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .header-banner h1 {
            margin-bottom: 0.5rem;
        }
        .header-banner p {
            margin-bottom: 0;
        }
        .archive-card { border-left: 4px solid #667eea; margin-bottom: 1rem; }
        .badge-archived { background-color: #6c757d; }
        .table-hover tbody tr:hover { background-color: #f0f0f0; }
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

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'templates/tenant_sidebar.php'; ?>

        <!-- Main content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <!-- Header -->
            <div class="header-banner">
                <h1><i class="bi bi-archive"></i> Archives</h1>
                <p>View your archived payments and maintenance records</p>
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
