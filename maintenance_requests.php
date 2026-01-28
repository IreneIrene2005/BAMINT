<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

// Search and filter variables
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_priority = isset($_GET['priority']) ? $_GET['priority'] : '';
$filter_assigned = isset($_GET['assigned']) ? $_GET['assigned'] : '';
$filter_category = isset($_GET['category']) ? $_GET['category'] : '';

// Build the SQL query with search and filter
$sql = "SELECT maintenance_requests.*, tenants.name, rooms.room_number, admins.username 
        FROM maintenance_requests
        LEFT JOIN tenants ON maintenance_requests.tenant_id = tenants.id
        LEFT JOIN rooms ON maintenance_requests.room_id = rooms.id
        LEFT JOIN admins ON maintenance_requests.assigned_to = admins.id
        WHERE 1=1";

if ($search) {
    $sql .= " AND (tenants.name LIKE :search OR maintenance_requests.description LIKE :search OR rooms.room_number LIKE :search)";
}

if ($filter_status) {
    $sql .= " AND maintenance_requests.status = :status";
}

if ($filter_priority) {
    $sql .= " AND maintenance_requests.priority = :priority";
}

if ($filter_assigned) {
    if ($filter_assigned === 'unassigned') {
        $sql .= " AND maintenance_requests.assigned_to IS NULL";
    } else {
        $sql .= " AND maintenance_requests.assigned_to = :assigned_to";
    }
}

if ($filter_category) {
    $sql .= " AND maintenance_requests.category = :category";
}

$sql .= " ORDER BY maintenance_requests.priority DESC, maintenance_requests.submitted_date DESC";

$stmt = $conn->prepare($sql);

if ($search) {
    $search_param = "%$search%";
    $stmt->bindParam(':search', $search_param);
}

if ($filter_status) {
    $stmt->bindParam(':status', $filter_status);
}

if ($filter_priority) {
    $stmt->bindParam(':priority', $filter_priority);
}

if ($filter_assigned && $filter_assigned !== 'unassigned') {
    $stmt->bindParam(':assigned_to', $filter_assigned);
}

if ($filter_category) {
    $stmt->bindParam(':category', $filter_category);
}

$stmt->execute();
$requests = $stmt;

// Fetch all categories for filter
$sql_categories = "SELECT DISTINCT category FROM maintenance_requests WHERE category IS NOT NULL ORDER BY category ASC";
$all_categories = $conn->query($sql_categories);

// Fetch all staff/admins for assignment
$sql_staff = "SELECT id, username FROM admins ORDER BY username ASC";
$all_staff = $conn->query($sql_staff);

// Get summary statistics
$sql_summary = "SELECT 
    COUNT(*) as total_requests,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
    SUM(CASE WHEN priority = 'high' AND status != 'completed' THEN 1 ELSE 0 END) as urgent_count
    FROM maintenance_requests";
$summary = $conn->query($sql_summary)->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Maintenance Requests</title>
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
                <h1 class="h2">Maintenance Requests</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#submitRequestModal">
                        <i class="bi bi-plus-circle"></i>
                        Submit Request
                    </button>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">Total Requests</h6>
                            <p class="card-text display-6"><?php echo htmlspecialchars($summary['total_requests']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-warning">
                        <div class="card-body">
                            <h6 class="card-title text-warning">Pending</h6>
                            <p class="card-text display-6 text-warning"><?php echo htmlspecialchars($summary['pending_count']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-info">
                        <div class="card-body">
                            <h6 class="card-title text-info">In Progress</h6>
                            <p class="card-text display-6 text-info"><?php echo htmlspecialchars($summary['in_progress_count']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-success">
                        <div class="card-body">
                            <h6 class="card-title text-success">Completed</h6>
                            <p class="card-text display-6 text-success"><?php echo htmlspecialchars($summary['completed_count']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-danger">
                        <div class="card-body">
                            <h6 class="card-title text-danger">Urgent</h6>
                            <p class="card-text display-6 text-danger"><?php echo htmlspecialchars($summary['urgent_count']); ?></p>
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
                            <label for="status" class="form-label">Status</label>
                            <select class="form-control" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="priority" class="form-label">Priority</label>
                            <select class="form-control" id="priority" name="priority">
                                <option value="">All Priority</option>
                                <option value="high" <?php echo $filter_priority === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="normal" <?php echo $filter_priority === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                <option value="low" <?php echo $filter_priority === 'low' ? 'selected' : ''; ?>>Low</option>
                            </select>
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
                            <label for="assigned" class="form-label">Assigned</label>
                            <select class="form-control" id="assigned" name="assigned">
                                <option value="">All</option>
                                <option value="unassigned" <?php echo $filter_assigned === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                                <?php 
                                $all_staff->execute();
                                while($staff = $all_staff->fetch(PDO::FETCH_ASSOC)): ?>
                                    <option value="<?php echo $staff['id']; ?>" <?php echo $filter_assigned == $staff['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($staff['username']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100">Search</button>
                        </div>
                        <div class="col-md-1">
                            <a href="maintenance_requests.php" class="btn btn-secondary w-100">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Tenant</th>
                            <th>Room</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Cost</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $requests->fetch(PDO::FETCH_ASSOC)) : ?>
                        <tr>
                            <td><strong>#<?php echo str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['room_number']); ?></td>
                            <td>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($row['category']); ?></span>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars(substr($row['description'], 0, 50)); ?><?php echo strlen($row['description']) > 50 ? '...' : ''; ?></small>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $row['priority'] === 'high' ? 'danger' : ($row['priority'] === 'normal' ? 'warning' : 'info');
                                ?>">
                                    <?php echo ucfirst($row['priority']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $row['status'] === 'completed' ? 'success' : ($row['status'] === 'in_progress' ? 'primary' : 'warning');
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['username']): ?>
                                    <small><?php echo htmlspecialchars($row['username']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted"><small>Unassigned</small></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (isset($row['cost']) && $row['cost'] !== null && $row['cost'] !== ''): ?>
                                    <small>₱<?php echo number_format($row['cost'], 2); ?></small>
                                <?php else:
                                    $prices = [
                                        'Door/Lock' => 150,
                                        'Walls/Paint' => 200,
                                        'Furniture' => 200,
                                        'Cleaning' => 100,
                                        'Light/Bulb' => 50,
                                        'Leak/Water' => 150,
                                        'Pest/Bedbugs' => 100,
                                        'Appliances' => 200,
                                        'Other' => null
                                    ];
                                    if (!empty($row['category']) && array_key_exists($row['category'], $prices) && $prices[$row['category']] !== null): ?>
                                        <small>₱<?php echo number_format($prices[$row['category']], 2); ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">-</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo htmlspecialchars(date('M d, Y', strtotime($row['submitted_date']))); ?></small></td>
                            <td>
                                <a href="maintenance_actions.php?action=view&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                                <a href="maintenance_actions.php?action=edit&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-warning" title="Edit"><i class="bi bi-pencil-square"></i></a>
                                <a href="maintenance_actions.php?action=delete&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure?');"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Submit Request Modal -->
<div class="modal fade" id="submitRequestModal" tabindex="-1" aria-labelledby="submitRequestModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="submitRequestModalLabel">Submit Maintenance Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form action="maintenance_actions.php?action=add" method="post">
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label for="tenant_id" class="form-label">Tenant</label>
                <select class="form-control" id="tenant_id" name="tenant_id" required onchange="updateRoom()">
                    <option value="">Select a tenant</option>
                    <?php 
                    $stmt2 = $conn->query("SELECT id, name, room_id FROM tenants WHERE status = 'active' ORDER BY name ASC");
                    while($tenant = $stmt2->fetch(PDO::FETCH_ASSOC)): ?>
                        <option value="<?php echo $tenant['id']; ?>" data-room="<?php echo $tenant['room_id']; ?>"><?php echo htmlspecialchars($tenant['name']); ?></option>
                    <?php endwhile; ?>
                </select>
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label for="room_id" class="form-label">Room</label>
                <select class="form-control" id="room_id" name="room_id" required>
                    <option value="">Select room</option>
                </select>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6">
                            <div class="mb-3">
                                <label for="category" class="form-label">Category</label>
                                <select class="form-control" id="category" name="category" required onchange="updateCategoryCost()">
                    <option value="">Select category</option>
                    <option value="Door/Lock">Door/Lock – Broken lock, stuck door ₱150</option>
                    <option value="Walls/Paint">Walls/Paint – Scratches, peeling paint ₱200</option>
                    <option value="Furniture">Furniture – Bedframe/furniture repair ₱200</option>
                    <option value="Cleaning">Cleaning – Deep cleaning, carpet/fan cleaning ₱100</option>
                    <option value="Light/Bulb">Light/Bulb – Bulb replacement, fixture issues ₱50</option>
                    <option value="Leak/Water">Leak/Water – Faucet drips, small pipe leak ₱150</option>
                    <option value="Pest/Bedbugs">Pest/Bedbugs – Cockroaches, ants, bedbugs ₱100</option>
                    <option value="Appliances">Appliances – Fan, fridge, microwave repair ₱200</option>
                    <option value="Other">Other – Describe your issue (Cost determined by admin)</option>
                </select>
                                <div class="form-text mt-1">Estimated cost: <span id="category_cost_text">-</span></div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label for="priority" class="form-label">Priority</label>
                <select class="form-control" id="priority" name="priority" required>
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                    <option value="low">Low</option>
                </select>
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label for="description" class="form-label">Description</label>
            <textarea class="form-control" id="description" name="description" rows="4" placeholder="Describe the issue..." required></textarea>
          </div>
          <button type="submit" class="btn btn-primary">Submit Request</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateRoom() {
    const tenantSelect = document.getElementById('tenant_id');
    const roomSelect = document.getElementById('room_id');
    const selectedOption = tenantSelect.options[tenantSelect.selectedIndex];
    const roomId = selectedOption.getAttribute('data-room');
    
    if (roomId) {
        // Fetch room details
        fetch('maintenance_actions.php?action=get_room&id=' + roomId)
            .then(response => response.json())
            .then(data => {
                roomSelect.innerHTML = '<option value="' + data.id + '">' + data.room_number + '</option>';
                roomSelect.value = data.id;
            });
    }
}

const categoryPrices = {
    'Door/Lock': 150,
    'Walls/Paint': 200,
    'Furniture': 200,
    'Cleaning': 100,
    'Light/Bulb': 50,
    'Leak/Water': 150,
    'Pest/Bedbugs': 100,
    'Appliances': 200,
    'Other': null
};

function updateCategoryCost() {
    const sel = document.getElementById('category');
    const txt = document.getElementById('category_cost_text');
    const v = sel.value;
    if (v && categoryPrices[v] != null) {
        txt.textContent = '₱' + Number(categoryPrices[v]).toFixed(2);
    } else if (v) {
        txt.textContent = 'Determined by admin';
    } else {
        txt.textContent = '-';
    }
}
</script>
</body>
</html>
