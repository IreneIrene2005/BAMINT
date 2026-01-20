<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}

require_once "db/database.php";

$filter_status = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$success_msg = "";
$error_msg = "";

try {
    // Build query based on filters
    $query = "
        SELECT t.id, t.name, t.phone, t.id_number, t.status, t.start_date, t.end_date,
               r.room_number, r.room_type, r.rate,
               ta.email,
               COUNT(pt.id) as total_payments,
               SUM(CASE WHEN pt.date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN pt.amount ELSE 0 END) as payments_last_30_days
        FROM tenants t
        LEFT JOIN tenant_accounts ta ON t.id = ta.tenant_id
        LEFT JOIN rooms r ON t.room_id = r.id
        LEFT JOIN payment_transactions pt ON t.id = pt.tenant_id
        WHERE 1=1
    ";

    $params = [];

    if ($filter_status !== 'all') {
        $query .= " AND t.status = :status";
        $params['status'] = $filter_status;
    }

    if (!empty($search_query)) {
        $query .= " AND (t.name LIKE :search OR ta.email LIKE :search OR t.phone LIKE :search)";
        $params['search'] = "%$search_query%";
    }

    $query .= " GROUP BY t.id ORDER BY t.created_at DESC";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_tenants,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_tenants,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_tenants,
            SUM(CASE WHEN room_id IS NOT NULL THEN 1 ELSE 0 END) as assigned_rooms
        FROM tenants
    ";
    
    $stmt = $conn->prepare($stats_query);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error_msg = "Error loading tenants: " . $e->getMessage();
}

// Handle tenant verification (mark as reviewed)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && isset($_POST['tenant_id'])) {
    $tenant_id = intval($_POST['tenant_id']);
    
    if ($_POST['action'] === 'verify') {
        try {
            $stmt = $conn->prepare("UPDATE tenants SET verification_notes = :notes WHERE id = :tenant_id");
            $stmt->execute([
                'notes' => $_POST['notes'] ?? 'Profile verified by admin',
                'tenant_id' => $tenant_id
            ]);
            $success_msg = "Tenant profile verified successfully!";
        } catch (Exception $e) {
            $error_msg = "Error verifying profile: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Management - BAMINT Admin</title>
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
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .metric-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        .metric-value {
            font-size: 2.5rem;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .metric-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        .tenant-card {
            background: white;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: transform 0.3s;
        }
        .tenant-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .tenant-name {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .tenant-detail {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.9rem;
        }
        .tenant-detail:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: #6c757d;
            font-weight: 500;
        }
        .detail-value {
            color: #333;
        }
        .badge-status {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 sidebar">
                <div class="position-sticky pt-3">
                    <div class="user-info" style="padding: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.2); margin-bottom: 1rem;">
                        <h5 style="margin-bottom: 0.25rem;"><i class="bi bi-person-circle"></i> Admin</h5>
                        <p style="font-size: 0.9rem; opacity: 0.8; margin-bottom: 0;"><?php echo htmlspecialchars($_SESSION["username"]); ?></p>
                    </div>

                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="rooms.php">
                                <i class="bi bi-door-open"></i> Rooms
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="admin_tenants.php">
                                <i class="bi bi-people"></i> Tenants
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="bills.php">
                                <i class="bi bi-receipt"></i> Bills
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reports.php">
                                <i class="bi bi-bar-chart"></i> Reports
                            </a>
                        </li>
                    </ul>

                    <form action="logout.php" method="post" style="margin-top: 2rem;">
                        <button type="submit" class="btn w-100" style="background: #dc3545; color: white; border: none;">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </form>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="header-banner">
                    <h1><i class="bi bi-people"></i> Tenant Management</h1>
                    <p class="mb-0">View and verify tenant information and changes</p>
                </div>

                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value"><?php echo $stats['total_tenants'] ?? 0; ?></div>
                            <div class="metric-label">Total Tenants</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"><?php echo $stats['active_tenants'] ?? 0; ?></div>
                            <div class="metric-label">Active Tenants</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"><?php echo $stats['assigned_rooms'] ?? 0; ?></div>
                            <div class="metric-label">Rooms Assigned</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"><?php echo ($stats['total_tenants'] - $stats['assigned_rooms']) ?? 0; ?></div>
                            <div class="metric-label">Unassigned</div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-6">
                                <label for="search" class="form-label">Search by Name, Email, or Phone</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search...">
                            </div>
                            <div class="col-md-4">
                                <label for="status" class="form-label">Filter by Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tenant List -->
                <div>
                    <?php if (empty($tenants)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No tenants found matching the criteria.
                        </div>
                    <?php else: ?>
                        <?php foreach ($tenants as $tenant): ?>
                            <div class="tenant-card">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="tenant-name">
                                            <?php echo htmlspecialchars($tenant['name']); ?>
                                            <span class="badge badge-status" style="background: <?php echo $tenant['status'] === 'active' ? '#10b981' : '#6c757d'; ?>;color: white;">
                                                <?php echo ucfirst($tenant['status']); ?>
                                            </span>
                                        </div>

                                        <div class="tenant-detail">
                                            <span class="detail-label"><i class="bi bi-envelope"></i> Email:</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($tenant['email'] ?? 'N/A'); ?></span>
                                        </div>

                                        <div class="tenant-detail">
                                            <span class="detail-label"><i class="bi bi-telephone"></i> Phone:</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($tenant['phone'] ?? 'N/A'); ?></span>
                                        </div>

                                        <div class="tenant-detail">
                                            <span class="detail-label"><i class="bi bi-card-text"></i> ID Number:</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($tenant['id_number'] ?? 'N/A'); ?></span>
                                        </div>

                                        <?php if ($tenant['room_id']): ?>
                                            <div class="tenant-detail">
                                                <span class="detail-label"><i class="bi bi-door-open"></i> Room:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($tenant['room_number']); ?> - <?php echo htmlspecialchars($tenant['room_type']); ?></span>
                                            </div>

                                            <div class="tenant-detail">
                                                <span class="detail-label"><i class="bi bi-calendar"></i> Move-in Date:</span>
                                                <span class="detail-value"><?php echo date('M d, Y', strtotime($tenant['start_date'])); ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <div class="tenant-detail">
                                            <span class="detail-label"><i class="bi bi-percent"></i> Payments Last 30 Days:</span>
                                            <span class="detail-value text-success">₱<?php echo number_format($tenant['payments_last_30_days'] ?? 0, 2); ?></span>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="d-flex flex-column gap-2">
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                                                    data-bs-target="#tenantModal" 
                                                    onclick="loadTenantDetails(<?php echo $tenant['id']; ?>)">
                                                <i class="bi bi-eye"></i> View Details
                                            </button>
                                            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" 
                                                    data-bs-target="#verifyModal" 
                                                    onclick="setVerifyTenant(<?php echo $tenant['id']; ?>)">
                                                <i class="bi bi-check-circle"></i> Verify Profile
                                            </button>
                                            <a href="tenant_actions.php?action=edit&id=<?php echo $tenant['id']; ?>" 
                                               class="btn btn-sm btn-outline-warning">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal fade" id="tenantModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tenant Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="tenantDetailsBody">
                    <p class="text-center"><i class="bi bi-hourglass-split"></i> Loading...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Verify Profile Modal -->
    <div class="modal fade" id="verifyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Verify Tenant Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="tenant_id" id="verifyTenantId">
                        <input type="hidden" name="action" value="verify">
                        <div class="mb-3">
                            <label for="verifyNotes" class="form-label">Verification Notes (Optional)</label>
                            <textarea class="form-control" id="verifyNotes" name="notes" rows="3" 
                                      placeholder="Add any verification notes..."></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            Marking this profile as verified indicates you have reviewed the tenant's information and it is accurate.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Verify Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setVerifyTenant(tenantId) {
            document.getElementById('verifyTenantId').value = tenantId;
        }

        function loadTenantDetails(tenantId) {
            const body = document.getElementById('tenantDetailsBody');
            body.innerHTML = '<p class="text-center"><i class="bi bi-hourglass-split"></i> Loading...</p>';
            
            fetch(`tenant_actions.php?action=get_details&id=${tenantId}`)
                .then(response => response.text())
                .then(html => {
                    body.innerHTML = html;
                })
                .catch(error => {
                    body.innerHTML = `<div class="alert alert-danger">Error loading details: ${error}</div>`;
                });
        }
    </script>
</body>
</html>
