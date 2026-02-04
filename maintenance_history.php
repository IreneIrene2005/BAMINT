<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

// Search and filter variables
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_month = isset($_GET['month']) ? $_GET['month'] : '';
$filter_category = isset($_GET['category']) ? $_GET['category'] : '';
$filter_tenant = isset($_GET['tenant']) ? $_GET['tenant'] : '';

// Build the SQL query
$sql = "SELECT maintenance_requests.*, tenants.name as tenant_name, rooms.room_number,
       (SELECT a.username FROM maintenance_history mh JOIN admins a ON mh.completed_by = a.id WHERE mh.maintenance_request_id = maintenance_requests.id ORDER BY mh.moved_to_history_at DESC LIMIT 1) AS completed_by
        FROM maintenance_requests
        LEFT JOIN tenants ON maintenance_requests.tenant_id = tenants.id
        LEFT JOIN rooms ON maintenance_requests.room_id = rooms.id
        WHERE maintenance_requests.status IN ('completed', 'cancelled')";

if ($search) {
    $sql .= " AND (tenants.name LIKE :search OR maintenance_requests.description LIKE :search OR rooms.room_number LIKE :search)";
}

if ($filter_month) {
    $sql .= " AND DATE_FORMAT(maintenance_requests.completion_date, '%Y-%m') = :month";
}

if ($filter_category) {
    $sql .= " AND maintenance_requests.category = :category";
}

if ($filter_tenant) {
    $sql .= " AND maintenance_requests.tenant_id = :tenant";
}

$sql .= " ORDER BY maintenance_requests.completion_date DESC";

$stmt = $conn->prepare($sql);

$params = [];
if ($search) $params['search'] = "%$search%";
if ($filter_month) $params['month'] = $filter_month;
if ($filter_category) $params['category'] = $filter_category;
if ($filter_tenant) $params['tenant'] = $filter_tenant;

$stmt->execute($params);
$requests = $stmt;

// Get unique categories for filter
$sql_categories = "SELECT DISTINCT category FROM maintenance_requests ORDER BY category ASC";
$all_categories = $conn->query($sql_categories);

// Get tenants for filter
$sql_tenants = "SELECT DISTINCT tenants.id, tenants.name 
                FROM maintenance_requests 
                JOIN tenants ON maintenance_requests.tenant_id = tenants.id 
                ORDER BY tenants.name ASC";
$all_tenants = $conn->query($sql_tenants);

// Calculate statistics
$stats_sql = "SELECT 
    COUNT(*) as total_completed,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
    ROUND(AVG(CASE WHEN status = 'completed' AND completion_date IS NOT NULL THEN TIMESTAMPDIFF(HOUR, submitted_date, completion_date) ELSE NULL END), 1) as avg_resolution_hours,
    COALESCE(SUM(CASE WHEN status = 'completed' THEN cost ELSE 0 END), 0) as total_cost,
    COALESCE(SUM(CASE WHEN status = 'completed' THEN cost ELSE 0 END) / NULLIF(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0), 0) as avg_cost
FROM maintenance_requests";

if ($search) {
    $stats_sql .= " WHERE (tenants.name LIKE :search OR maintenance_requests.description LIKE :search OR rooms.room_number LIKE :search)";
}

$stats_stmt = $conn->prepare($stats_sql);
if ($search) {
    $stats_stmt->execute(['search' => "%$search%"]);
} else {
    $stats_stmt->execute();
}
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Maintenance History</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Maintenance History</h1>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">Total Completed</h6>
                            <p class="card-text display-6"><?php echo htmlspecialchars($stats['completed_count']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-danger">
                        <div class="card-body">
                            <h6 class="card-title text-danger">Cancelled</h6>
                            <p class="card-text display-6 text-danger"><?php echo htmlspecialchars($stats['cancelled_count']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">Avg Resolution</h6>
                            <p class="card-text display-6"><?php echo htmlspecialchars($stats['avg_resolution_hours']); ?><small>h</small></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">Total Cost</h6>
                            <p class="card-text display-6">₱<?php echo number_format($stats['total_cost'], 0); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">Avg Cost</h6>
                            <p class="card-text display-6">₱<?php echo number_format($stats['avg_cost'], 0); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">Total Records</h6>
                            <p class="card-text display-6"><?php echo htmlspecialchars($stats['total_completed']); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="row mb-3">
                <div class="col-md-12">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" placeholder="Tenant, description..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="month" class="form-label">Month</label>
                            <input type="month" class="form-control" id="month" name="month" value="<?php echo htmlspecialchars($filter_month); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-control" id="category" name="category">
                                <option value="">All Categories</option>
                                <option value="Door/Lock" <?php echo $filter_category === 'Door/Lock' ? 'selected' : ''; ?>>Door/Lock – Broken lock, stuck door ₱150</option>
                                <option value="Walls/Paint" <?php echo $filter_category === 'Walls/Paint' ? 'selected' : ''; ?>>Walls/Paint – Scratches, peeling paint ₱200</option>
                                <option value="Furniture" <?php echo $filter_category === 'Furniture' ? 'selected' : ''; ?>>Furniture – Bedframe/furniture repair ₱200</option>
                                <option value="Cleaning" <?php echo $filter_category === 'Cleaning' ? 'selected' : ''; ?>>Cleaning – Deep cleaning, carpet/fan cleaning ₱100</option>
                                <option value="Light/Bulb" <?php echo $filter_category === 'Light/Bulb' ? 'selected' : ''; ?>>Light/Bulb – Bulb replacement, fixture issues ₱50</option>
                                <option value="Leak/Water" <?php echo $filter_category === 'Leak/Water' ? 'selected' : ''; ?>>Leak/Water – Faucet drips, small pipe leak ₱150</option>
                                <option value="Pest/Bedbugs" <?php echo $filter_category === 'Pest/Bedbugs' ? 'selected' : ''; ?>>Pest/Bedbugs – Cockroaches, ants, bedbugs ₱100</option>
                                <option value="Appliances" <?php echo $filter_category === 'Appliances' ? 'selected' : ''; ?>>Appliances – Fan, fridge, microwave repair ₱200</option>
                                <option value="Other" <?php echo $filter_category === 'Other' ? 'selected' : ''; ?>>Other – Describe your issue (Cost determined by admin)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="tenant" class="form-label">Tenant</label>
                            <select class="form-control" id="tenant" name="tenant">
                                <option value="">All Tenants</option>
                                <?php 
                                while($tenant = $all_tenants->fetch(PDO::FETCH_ASSOC)): ?>
                                    <option value="<?php echo $tenant['id']; ?>" <?php echo $filter_tenant == $tenant['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tenant['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Search</button>
                        </div>
                        <div class="col-md-2">
                            <a href="maintenance_history.php" class="btn btn-secondary w-100">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- History Table -->
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Tenant</th>
                            <th>Room</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Cost</th>
                            <th>Submitted</th>
                            <th>Completed</th>
                            <th>Assigned To</th>
                            <th>Completed By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $count = 0;
                        while($row = $requests->fetch(PDO::FETCH_ASSOC)): 
                            $count++;
                        ?>
                        <tr>
                            <td><strong>#<?php echo str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['tenant_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['room_number']); ?></td>
                            <td>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($row['category']); ?></span>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars(substr($row['description'], 0, 40)); ?><?php echo strlen($row['description']) > 40 ? '...' : ''; ?></small>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $row['status'] === 'completed' ? 'success' : 'secondary';
                                ?>">
                                    <?php echo ucfirst($row['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['cost']): ?>
                                    <strong>₱<?php echo number_format($row['cost'], 2); ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo htmlspecialchars(date('M d, Y', strtotime($row['submitted_date']))); ?></small></td>
                            <td>
                                <?php if ($row['completion_date']): ?>
                                    <small><?php echo htmlspecialchars(date('M d, Y', strtotime($row['completion_date']))); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['username']): ?>
                                    <small><?php echo htmlspecialchars($row['username']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted"><small>Unassigned</small></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['completed_by'] ?? ''); ?></td>
                            <td>
                                <a href="maintenance_actions.php?action=view&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($count === 0): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">No maintenance history records found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
