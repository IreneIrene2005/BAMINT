<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "tenant") {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";

$tenant_id = $_SESSION["tenant_id"];
$success_msg = "";

// Handle new maintenance request submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_request'])) {
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = trim($_POST['priority'] ?? 'normal');

    if (!empty($category) && !empty($description)) {
        try {
            // Get tenant's room_id
            $stmt = $conn->prepare("SELECT room_id FROM tenants WHERE id = :tenant_id");
            $stmt->execute(['tenant_id' => $tenant_id]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            $room_id = $room['room_id'] ?? 0;

            $stmt = $conn->prepare("
                INSERT INTO maintenance_requests 
                (tenant_id, room_id, category, description, priority, status)
                VALUES (:tenant_id, :room_id, :category, :description, :priority, 'pending')
            ");
            
            $stmt->execute([
                'tenant_id' => $tenant_id,
                'room_id' => $room_id,
                'category' => $category,
                'description' => $description,
                'priority' => $priority
            ]);

            $success_msg = "Maintenance request submitted successfully!";
        } catch (Exception $e) {
            $error = "Error submitting request: " . $e->getMessage();
        }
    }
}

try {
    // Get maintenance requests
    $stmt = $conn->prepare("
        SELECT * FROM maintenance_requests 
        WHERE tenant_id = :tenant_id
        ORDER BY submitted_date DESC
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get pending count
    $result = $conn->query("SELECT COUNT(*) as count FROM maintenance_requests WHERE tenant_id = $tenant_id AND status = 'pending'");
    $pending_count = $result->fetch(PDO::FETCH_ASSOC)['count'];

    // Get completed count
    $result = $conn->query("SELECT COUNT(*) as count FROM maintenance_requests WHERE tenant_id = $tenant_id AND status = 'completed'");
    $completed_count = $result->fetch(PDO::FETCH_ASSOC)['count'];

} catch (Exception $e) {
    $error = "Error loading maintenance data: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Requests - BAMINT</title>
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
        .request-card {
            border-left: 4px solid #667eea;
            transition: all 0.3s;
        }
        .request-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .priority-high { border-left-color: #dc3545; }
        .priority-normal { border-left-color: #ffc107; }
        .priority-low { border-left-color: #0dcaf0; }
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
                            <a class="nav-link active" href="tenant_maintenance.php">
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
                    <h1><i class="bi bi-tools"></i> Maintenance Requests</h1>
                    <p class="mb-0">Submit and track maintenance issues in your room</p>
                </div>

                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Key Metrics -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-danger bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-exclamation-circle"></i> Pending</p>
                                <p class="metric-value text-danger"><?php echo $pending_count; ?></p>
                                <small class="text-muted">Awaiting attention</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-success bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-check-circle"></i> Completed</p>
                                <p class="metric-value text-success"><?php echo $completed_count; ?></p>
                                <small class="text-muted">All time</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-info bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-list-check"></i> Total</p>
                                <p class="metric-value text-info"><?php echo count($requests); ?></p>
                                <small class="text-muted">All requests</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-lg-3">
                        <div class="card metric-card bg-primary bg-opacity-10">
                            <div class="card-body">
                                <p class="text-muted mb-2"><i class="bi bi-percent"></i> Resolution Rate</p>
                                <p class="metric-value text-primary">
                                    <?php echo count($requests) > 0 ? round(($completed_count / count($requests)) * 100) : 0; ?>%
                                </p>
                                <small class="text-muted">Completed</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit New Request -->
                <div class="card mb-4">
                    <div class="card-header bg-success bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-plus-circle"></i> Submit New Request</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="category" class="form-label">Category</label>
                                    <select class="form-select" id="category" name="category" required>
                                        <option value="">Select category...</option>
                                        <option value="Plumbing">Plumbing</option>
                                        <option value="Electrical">Electrical</option>
                                        <option value="HVAC">HVAC/Cooling</option>
                                        <option value="Door/Lock">Door/Lock</option>
                                        <option value="Walls/Paint">Walls/Paint</option>
                                        <option value="Furniture">Furniture</option>
                                        <option value="Cleaning">Cleaning</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="priority" class="form-label">Priority Level</label>
                                    <select class="form-select" id="priority" name="priority" required>
                                        <option value="low">Low</option>
                                        <option value="normal" selected>Normal</option>
                                        <option value="high">High (Urgent)</option>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="4" placeholder="Describe the issue in detail..." required></textarea>
                                </div>

                                <div class="col-12">
                                    <button type="submit" name="submit_request" class="btn btn-success">
                                        <i class="bi bi-send"></i> Submit Request
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Requests List -->
                <h5 class="mb-3"><i class="bi bi-list"></i> Your Requests</h5>
                <div class="row g-4">
                    <?php if (!empty($requests)): ?>
                        <?php foreach ($requests as $request): ?>
                            <div class="col-md-6">
                                <div class="card request-card priority-<?php echo $request['priority']; ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h5 class="card-title mb-1">
                                                    <?php echo htmlspecialchars($request['category']); ?>
                                                </h5>
                                                <small class="text-muted">
                                                    Submitted: <?php echo date('M d, Y', strtotime($request['submitted_date'])); ?>
                                                </small>
                                            </div>
                                            <span class="badge bg-<?php 
                                                $status = $request['status'];
                                                echo $status === 'completed' ? 'success' : ($status === 'in-progress' ? 'info' : 'secondary');
                                            ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </div>

                                        <p class="card-text text-muted mb-3">
                                            <?php echo htmlspecialchars(substr($request['description'], 0, 100)) . (strlen($request['description']) > 100 ? '...' : ''); ?>
                                        </p>

                                        <div class="d-flex gap-2 mb-3">
                                            <span class="badge bg-<?php 
                                                $priority = $request['priority'];
                                                echo $priority === 'high' ? 'danger' : ($priority === 'normal' ? 'warning' : 'info');
                                            ?>">
                                                <i class="bi bi-exclamation-circle"></i> <?php echo ucfirst($priority); ?>
                                            </span>
                                            <?php if ($request['assigned_to']): ?>
                                                <span class="badge bg-primary">
                                                    <i class="bi bi-person"></i> Assigned
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($request['status'] === 'completed' && $request['completion_date']): ?>
                                            <small class="text-success">
                                                <i class="bi bi-check-circle"></i> Completed: <?php echo date('M d, Y', strtotime($request['completion_date'])); ?>
                                            </small>
                                        <?php endif; ?>

                                        <?php if (!empty($request['notes'])): ?>
                                            <div class="mt-3 p-2 bg-light rounded">
                                                <small class="text-muted"><strong>Notes:</strong> <?php echo htmlspecialchars($request['notes']); ?></small>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($request['cost'] > 0): ?>
                                            <div class="mt-2">
                                                <small class="text-muted"><strong>Cost:</strong> ₱<?php echo number_format($request['cost'], 2); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No maintenance requests yet.
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
