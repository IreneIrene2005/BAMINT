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
$filter_tenant = isset($_GET['tenant']) ? $_GET['tenant'] : '';
$filter_month = isset($_GET['month']) ? $_GET['month'] : '';

// Build the SQL query with search and filter
$sql = "SELECT bills.*, tenants.name, tenants.email, rooms.room_number FROM bills 
        LEFT JOIN tenants ON bills.tenant_id = tenants.id 
        LEFT JOIN rooms ON bills.room_id = rooms.id 
        WHERE 1=1";

if ($search) {
    $sql .= " AND (tenants.name LIKE :search OR tenants.email LIKE :search OR rooms.room_number LIKE :search)";
}

if ($filter_status) {
    $sql .= " AND bills.status = :status";
}

if ($filter_tenant) {
    $sql .= " AND bills.tenant_id = :tenant_id";
}

if ($filter_month) {
    $sql .= " AND DATE_FORMAT(bills.billing_month, '%Y-%m') = :month";
}

$sql .= " ORDER BY bills.billing_month DESC, tenants.name ASC";

$stmt = $conn->prepare($sql);

if ($search) {
    $search_param = "%$search%";
    $stmt->bindParam(':search', $search_param);
}

if ($filter_status) {
    $stmt->bindParam(':status', $filter_status);
}

if ($filter_tenant) {
    $stmt->bindParam(':tenant_id', $filter_tenant);
}

if ($filter_month) {
    $stmt->bindParam(':month', $filter_month);
}

$stmt->execute();
$bills = $stmt;

// Fetch all active tenants for filter dropdown
$sql_tenants = "SELECT id, name FROM tenants WHERE status = 'active' ORDER BY name ASC";
$all_tenants = $conn->query($sql_tenants);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Billing</title>
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
                <h1 class="h2">Monthly Billing</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary me-2" data-bs-toggle="modal" data-bs-target="#generateBillsModal">
                        <i class="bi bi-plus-circle"></i>
                        Generate Bills
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addBillModal">
                        <i class="bi bi-plus-circle"></i>
                        Add Manual Bill
                    </button>
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="row mb-3">
                <div class="col-md-12">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" placeholder="Tenant, email, room..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-control" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="partial" <?php echo $filter_status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="overdue" <?php echo $filter_status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
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
                            <label for="month" class="form-label">Month</label>
                            <input type="month" class="form-control" id="month" name="month" value="<?php echo htmlspecialchars($filter_month); ?>">
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100">Search</button>
                        </div>
                        <div class="col-md-1">
                            <a href="bills.php" class="btn btn-secondary w-100">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-sm">
                    <thead>
                        <tr>
                            <th>Billing Month</th>
                            <th>Tenant</th>
                            <th>Room</th>
                            <th>Amount Due (₱)</th>
                            <th>Discount (₱)</th>
                            <th>Amount Paid (₱)</th>
                            <th>Balance (₱)</th>
                            <th>Status</th>
                            <th>Due Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $bills->fetch(PDO::FETCH_ASSOC)) : 
                            $balance = $row['amount_due'] - $row['discount'] - $row['amount_paid'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date('F Y', strtotime($row['billing_month']))); ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['room_number']); ?></td>
                            <td><?php echo htmlspecialchars(number_format($row['amount_due'], 2)); ?></td>
                            <td><?php echo htmlspecialchars(number_format($row['discount'], 2)); ?></td>
                            <td><?php echo htmlspecialchars(number_format($row['amount_paid'], 2)); ?></td>
                            <td><strong><?php echo htmlspecialchars(number_format($balance, 2)); ?></strong></td>
                            <td>
                                <span class="badge bg-<?php 
                                    if ($row['status'] == 'paid') echo 'success';
                                    elseif ($row['status'] == 'partial') echo 'warning';
                                    elseif ($row['status'] == 'overdue') echo 'danger';
                                    else echo 'secondary';
                                ?>">
                                    <?php echo ucfirst($row['status']); ?>
                                </span>
                            </td>
                            <td><?php echo $row['due_date'] ? htmlspecialchars(date('M d, Y', strtotime($row['due_date']))) : '-'; ?></td>
                            <td>
                                <a href="bill_actions.php?action=edit&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil-square"></i></a>
                                <a href="bill_actions.php?action=view&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-info" title="View Details"><i class="bi bi-eye"></i></a>
                                <a href="bill_actions.php?action=delete&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure?');"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Generate Bills Modal -->
<div class="modal fade" id="generateBillsModal" tabindex="-1" aria-labelledby="generateBillsModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="generateBillsModalLabel">Generate Monthly Bills</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form action="bill_actions.php?action=generate" method="post">
          <div class="mb-3">
            <label for="billing_month" class="form-label">Billing Month</label>
            <input type="month" class="form-control" id="billing_month" name="billing_month" value="<?php echo date('Y-m'); ?>" required>
          </div>
          <div class="mb-3">
            <label for="due_date" class="form-label">Due Date</label>
            <input type="date" class="form-control" id="due_date" name="due_date" value="<?php echo date('Y-m-15'); ?>" required>
          </div>
          <p class="text-muted">This will create bills for all active tenants based on their room rates.</p>
          <button type="submit" class="btn btn-primary">Generate Bills</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Add Manual Bill Modal -->
<div class="modal fade" id="addBillModal" tabindex="-1" aria-labelledby="addBillModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addBillModalLabel">Add Manual Bill</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form action="bill_actions.php?action=add" method="post">
          <div class="mb-3">
            <label for="tenant_id" class="form-label">Tenant</label>
            <select class="form-control" id="tenant_id" name="tenant_id" required>
                <option value="">Select a tenant</option>
                <?php 
                $stmt2 = $conn->query("SELECT id, name FROM tenants WHERE status = 'active' ORDER BY name ASC");
                while($tenant = $stmt2->fetch(PDO::FETCH_ASSOC)): ?>
                    <option value="<?php echo $tenant['id']; ?>"><?php echo htmlspecialchars($tenant['name']); ?></option>
                <?php endwhile; ?>
            </select>
          </div>
          <div class="mb-3">
            <label for="billing_month_manual" class="form-label">Billing Month</label>
            <input type="month" class="form-control" id="billing_month_manual" name="billing_month" required>
          </div>
          <div class="mb-3">
            <label for="amount_due" class="form-label">Amount Due (₱)</label>
            <input type="number" step="0.01" class="form-control" id="amount_due" name="amount_due" required>
          </div>
          <div class="mb-3">
            <label for="due_date_manual" class="form-label">Due Date</label>
            <input type="date" class="form-control" id="due_date_manual" name="due_date">
          </div>
          <button type="submit" class="btn btn-primary">Save Bill</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
