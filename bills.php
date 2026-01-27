<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

// Admin role check
if ($_SESSION['role'] !== 'admin') {
    header("location: index.php");
    exit;
}

// Handle payment verification/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;

    if (($action === 'verify' || $action === 'reject') && $payment_id > 0) {
        try {
            $conn->beginTransaction();
            
            if ($action === 'verify') {
                // Get payment and bill details first
                $payment_stmt = $conn->prepare("
                    SELECT pt.*, b.amount_due, b.notes, b.tenant_id, b.room_id 
                    FROM payment_transactions pt
                    JOIN bills b ON pt.bill_id = b.id
                    WHERE pt.id = :id
                ");
                $payment_stmt->execute(['id' => $payment_id]);
                $payment_info = $payment_stmt->fetch(PDO::FETCH_ASSOC);

                // Update payment to verified
                $stmt = $conn->prepare("
                    UPDATE payment_transactions 
                    SET payment_status = 'verified', verified_by = :admin_id, verification_date = NOW()
                    WHERE id = :id AND payment_status = 'pending'
                ");
                $stmt->execute(['id' => $payment_id, 'admin_id' => $_SESSION['admin_id']]);

                if ($payment_info) {
                    $bill_check = $conn->prepare("
                        SELECT COALESCE(SUM(payment_amount), 0) as total_paid FROM payment_transactions 
                        WHERE bill_id = :bill_id AND payment_status IN ('verified', 'approved')
                    ");
                    $bill_check->execute(['bill_id' => $payment_info['bill_id']]);
                    $paid_info = $bill_check->fetch(PDO::FETCH_ASSOC);

                    $total_paid = $paid_info['total_paid'];
                    $bill_status = ($total_paid >= $payment_info['amount_due']) ? 'paid' : 'partial';

                    $update_bill = $conn->prepare("
                        UPDATE bills SET status = :status, amount_paid = :amount_paid WHERE id = :id
                    ");
                    $update_bill->execute(['status' => $bill_status, 'amount_paid' => $total_paid, 'id' => $payment_info['bill_id']]);

                    // CHECK: Is this an advance/move-in payment?
                    if ($bill_status === 'paid' && strpos($payment_info['notes'], 'ADVANCE PAYMENT') !== false) {
                        // STEP 1: Update tenant status to 'active' with move-in date
                        $tenant_update = $conn->prepare("
                            UPDATE tenants 
                            SET status = 'active', start_date = NOW()
                            WHERE id = :tenant_id
                        ");
                        $tenant_update->execute(['tenant_id' => $payment_info['tenant_id']]);

                        // STEP 2: Update room status to 'occupied'
                        $room_update = $conn->prepare("
                            UPDATE rooms 
                            SET status = 'occupied'
                            WHERE id = :room_id
                        ");
                        $room_update->execute(['room_id' => $payment_info['room_id']]);

                        // STEP 3: Update room request status to 'approved' (final approval)
                        $request_update = $conn->prepare("
                            UPDATE room_requests 
                            SET status = 'approved'
                            WHERE tenant_id = :tenant_id AND room_id = :room_id AND status = 'pending_payment'
                        ");
                        $request_update->execute([
                            'tenant_id' => $payment_info['tenant_id'],
                            'room_id' => $payment_info['room_id']
                        ]);
                    }
                }

            } else {
                // Reject payment
                $stmt = $conn->prepare("
                    UPDATE payment_transactions 
                    SET payment_status = 'rejected', verified_by = :admin_id, verification_date = NOW()
                    WHERE id = :id AND payment_status = 'pending'
                ");
                $stmt->execute(['id' => $payment_id, 'admin_id' => $_SESSION['admin_id']]);
            }
            
            $conn->commit();
            
            // Redirect to refresh page
            header("location: bills.php");
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            // Error will be displayed on page
        }
    }
}

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

// Fetch pending payments (online payments awaiting verification)
try {
    $pending_payments_stmt = $conn->prepare("
        SELECT 
            pt.id,
            pt.bill_id,
            pt.tenant_id,
            pt.payment_amount,
            pt.payment_method,
            pt.payment_type,
            pt.payment_status,
            pt.proof_of_payment,
            pt.payment_date,
            pt.notes,
            t.name as tenant_name,
            t.email,
            b.billing_month,
            b.amount_due,
            b.amount_paid,
            r.room_number
        FROM payment_transactions pt
        JOIN tenants t ON pt.tenant_id = t.id
        JOIN bills b ON pt.bill_id = b.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE pt.payment_status = 'pending'
        ORDER BY pt.payment_date DESC
    ");
    $pending_payments_stmt->execute();
    $pending_payments = $pending_payments_stmt->fetchAll(PDO::FETCH_ASSOC);
    $pending_count = count($pending_payments);
} catch (Exception $e) {
    $pending_payments = [];
    $pending_count = 0;
}
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

            <!-- Pending Payments Queue Section -->
            <?php if ($pending_count > 0): ?>
            <div class="alert alert-info alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-clock-history me-2" style="font-size: 1.5rem;"></i>
                    <div>
                        <h5 class="alert-heading mb-1">⏳ Pending Payment Verification</h5>
                        <p class="mb-0">You have <strong><?php echo $pending_count; ?></strong> payment<?php echo $pending_count !== 1 ? 's' : ''; ?> awaiting your verification.</p>
                    </div>
                </div>
                <hr>
                <div class="row g-2">
                    <?php foreach ($pending_payments as $payment): 
                        $balance = $payment['amount_due'] - $payment['amount_paid'];
                    ?>
                    <div class="col-md-6 mb-2">
                        <div class="card border-warning h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="card-title mb-1"><?php echo htmlspecialchars($payment['tenant_name']); ?></h6>
                                        <p class="card-text mb-1 text-muted small">
                                            <i class="bi bi-door"></i> Room: <?php echo htmlspecialchars($payment['room_number'] ?? 'N/A'); ?> |
                                            <i class="bi bi-calendar"></i> <?php echo date('M Y', strtotime($payment['billing_month'])); ?>
                                        </p>
                                        <p class="card-text mb-2 text-muted small">
                                            <i class="bi bi-credit-card"></i> <?php echo htmlspecialchars($payment['payment_method']); ?>
                                        </p>
                                        <h5 class="text-primary">₱<?php echo number_format($payment['payment_amount'], 2); ?></h5>
                                    </div>
                                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#paymentModal<?php echo $payment['id']; ?>" title="Review Payment">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Review Modal -->
                    <div class="modal fade" id="paymentModal<?php echo $payment['id']; ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header bg-warning bg-opacity-10">
                                    <h5 class="modal-title">
                                        <i class="bi bi-search"></i> Review Payment - <?php echo htmlspecialchars($payment['tenant_name']); ?>
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <h6 class="text-muted">Tenant Information</h6>
                                            <p><strong><?php echo htmlspecialchars($payment['tenant_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($payment['email']); ?></small></p>
                                        </div>
                                        <div class="col-md-6">
                                            <h6 class="text-muted">Payment Details</h6>
                                            <p><strong>Amount:</strong> ₱<?php echo number_format($payment['payment_amount'], 2); ?><br>
                                            <strong>Method:</strong> <?php echo htmlspecialchars($payment['payment_method']); ?><br>
                                            <strong>Date:</strong> <?php echo date('M d, Y H:i', strtotime($payment['payment_date'])); ?></p>
                                        </div>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-12">
                                            <h6 class="text-muted">Billing Information</h6>
                                            <p><strong>Billing Month:</strong> <?php echo date('F Y', strtotime($payment['billing_month'])); ?><br>
                                            <strong>Amount Due:</strong> ₱<?php echo number_format($payment['amount_due'], 2); ?><br>
                                            <strong>Already Paid:</strong> ₱<?php echo number_format($payment['amount_paid'], 2); ?><br>
                                            <strong>Balance:</strong> ₱<?php echo number_format($balance, 2); ?></p>
                                        </div>
                                    </div>

                                    <?php if ($payment['proof_of_payment']): ?>
                                    <div class="row mb-3">
                                        <div class="col-md-12">
                                            <h6 class="text-muted">Proof of Payment</h6>
                                            <div class="card border-secondary">
                                                <div class="card-body text-center">
                                                    <?php 
                                                    $proof_path = "public/payment_proofs/" . htmlspecialchars($payment['proof_of_payment']);
                                                    $file_ext = pathinfo($payment['proof_of_payment'], PATHINFO_EXTENSION);
                                                    ?>
                                                    <?php if (in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                                        <img src="<?php echo $proof_path; ?>" alt="Payment Proof" style="max-width: 100%; max-height: 400px; border-radius: 8px;">
                                                    <?php elseif (strtolower($file_ext) === 'pdf'): ?>
                                                        <div class="text-center py-4">
                                                            <i class="bi bi-file-pdf" style="font-size: 4rem; color: #dc3545;"></i>
                                                            <p class="mt-2"><a href="<?php echo $proof_path; ?>" target="_blank" class="btn btn-sm btn-danger">
                                                                <i class="bi bi-download"></i> View PDF
                                                            </a></p>
                                                        </div>
                                                    <?php else: ?>
                                                        <p class="text-muted"><i class="bi bi-file"></i> File: <?php echo htmlspecialchars($payment['proof_of_payment']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($payment['notes']): ?>
                                    <div class="row mb-3">
                                        <div class="col-md-12">
                                            <h6 class="text-muted">Tenant Notes</h6>
                                            <p class="bg-light p-2 rounded"><?php echo htmlspecialchars($payment['notes']); ?></p>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                        <button type="submit" class="btn btn-danger" onclick="return confirm('Reject this payment?');">
                                            <i class="bi bi-x-circle"></i> Reject
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="verify">
                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-check-circle"></i> Approve
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

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
                            <th>Amount Paid (₱)</th>
                            <th>Balance (₱)</th>
                            <th>Status</th>
                            <th>Due Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $bills->fetch(PDO::FETCH_ASSOC)) : 
                            $balance = $row['amount_due'] - $row['amount_paid'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date('F Y', strtotime($row['billing_month']))); ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['room_number']); ?></td>
                            <td><?php echo htmlspecialchars(number_format($row['amount_due'], 2)); ?></td>
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
