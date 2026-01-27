<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

// Search and filter variables
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_tenant = isset($_GET['tenant']) ? $_GET['tenant'] : '';
$filter_method = isset($_GET['method']) ? $_GET['method'] : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// Build the SQL query with search and filter
$sql = "SELECT payment_transactions.*, tenants.name, tenants.email, bills.billing_month FROM payment_transactions 
        LEFT JOIN tenants ON payment_transactions.tenant_id = tenants.id 
        LEFT JOIN bills ON payment_transactions.bill_id = bills.id 
        WHERE payment_transactions.payment_amount > 0";

if ($search) {
    $sql .= " AND (tenants.name LIKE :search OR tenants.email LIKE :search)";
}

if ($filter_tenant) {
    $sql .= " AND payment_transactions.tenant_id = :tenant_id";
}

if ($filter_method) {
    $sql .= " AND payment_transactions.payment_method = :method";
}

if ($from_date) {
    $sql .= " AND payment_transactions.payment_date >= :from_date";
}

if ($to_date) {
    $sql .= " AND payment_transactions.payment_date <= :to_date";
}

$sql .= " ORDER BY payment_transactions.payment_date DESC";

$stmt = $conn->prepare($sql);

if ($search) {
    $search_param = "%$search%";
    $stmt->bindParam(':search', $search_param);
}

if ($filter_tenant) {
    $stmt->bindParam(':tenant_id', $filter_tenant);
}

if ($filter_method) {
    $stmt->bindParam(':method', $filter_method);
}

if ($from_date) {
    $stmt->bindParam(':from_date', $from_date);
}

if ($to_date) {
    $stmt->bindParam(':to_date', $to_date);
}

$stmt->execute();
$transactions = $stmt;

// Fetch all active tenants for filter dropdown
$sql_tenants = "SELECT id, name FROM tenants WHERE status = 'active' ORDER BY name ASC";
$all_tenants = $conn->query($sql_tenants);

// Get payment summary statistics
$sql_summary = "SELECT 
    COUNT(*) as total_payments,
    SUM(payment_amount) as total_amount,
    AVG(payment_amount) as avg_amount
    FROM payment_transactions";
$summary = $conn->query($sql_summary)->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment History</title>
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
                <h1 class="h2">Payment History</h1>
            </div>

            <!-- Summary Cards -->
            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="card text-white bg-primary">
                        <div class="card-body">
                            <h5 class="card-title">Total Payments</h5>
                            <p class="card-text display-6"><?php echo htmlspecialchars($summary['total_payments']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-white bg-success">
                        <div class="card-body">
                            <h5 class="card-title">Total Amount Received</h5>
                            <p class="card-text display-6">₱<?php echo number_format($summary['total_amount'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-white bg-info">
                        <div class="card-body">
                            <h5 class="card-title">Average Payment</h5>
                            <p class="card-text display-6">₱<?php echo number_format($summary['avg_amount'] ?? 0, 2); ?></p>
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
                            <input type="text" class="form-control" id="search" name="search" placeholder="Tenant, email..." value="<?php echo htmlspecialchars($search); ?>">
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
                            <label for="method" class="form-label">Payment Method</label>
                            <select class="form-control" id="method" name="method">
                                <option value="">All Methods</option>
                                <option value="cash" <?php echo $filter_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="check" <?php echo $filter_method === 'check' ? 'selected' : ''; ?>>Check</option>
                                <option value="bank_transfer" <?php echo $filter_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="credit_card" <?php echo $filter_method === 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                                <option value="gcash" <?php echo $filter_method === 'gcash' ? 'selected' : ''; ?>>GCash</option>
                                <option value="paypal" <?php echo $filter_method === 'paypal' ? 'selected' : ''; ?>>PayPal</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label for="from_date" class="form-label">From</label>
                            <input type="date" class="form-control" id="from_date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>">
                        </div>
                        <div class="col-md-1">
                            <label for="to_date" class="form-label">To</label>
                            <input type="date" class="form-control" id="to_date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Search</button>
                            <a href="payment_history.php" class="btn btn-secondary w-100 mt-1">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Tenant</th>
                            <th>Billing Month</th>
                            <th>Payment Amount (₱)</th>
                            <th>Payment Method</th>
                            <th>Notes</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $transactions->fetch(PDO::FETCH_ASSOC)) : ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date('M d, Y', strtotime($row['payment_date']))); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($row['name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($row['email']); ?></small>
                            </td>
                            <td><?php echo $row['billing_month'] ? htmlspecialchars(date('F Y', strtotime($row['billing_month']))) : '-'; ?></td>
                            <td><strong>₱<?php echo htmlspecialchars(number_format($row['payment_amount'], 2)); ?></strong></td>
                            <td>
                                <?php if ($row['payment_method']): ?>
                                    <span class="badge bg-info"><?php echo ucfirst(str_replace('_', ' ', $row['payment_method'])); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $row['notes'] ? htmlspecialchars(substr($row['notes'], 0, 50)) . '...' : '-'; ?></td>
                            <td>
                                <?php 
                                // Get admin name from recorded_by
                                if ($row['recorded_by']) {
                                    $stmt2 = $conn->prepare("SELECT username FROM admins WHERE id = :id");
                                    $stmt2->execute(['id' => $row['recorded_by']]);
                                    $admin = $stmt2->fetch(PDO::FETCH_ASSOC);
                                    echo htmlspecialchars($admin['username'] ?? 'Unknown');
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
