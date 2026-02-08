<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}

require_once "db/database.php";

$admin_id = $_SESSION["id"];
$message = '';
$message_type = '';
$tenant_bills = [];

// Handle payment recording
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    $bill_id = isset($_POST['bill_id']) ? intval($_POST['bill_id']) : 0;
    $tenant_id = isset($_POST['tenant_id']) ? intval($_POST['tenant_id']) : 0;
    $payment_amount = isset($_POST['payment_amount']) ? floatval($_POST['payment_amount']) : 0;
    $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    // Validate input
    $errors = [];
    if ($bill_id <= 0) $errors[] = "Please select a bill";
    if ($payment_amount <= 0) $errors[] = "Payment amount must be greater than 0";
    if (empty($payment_method)) $errors[] = "Please select a payment method";

    // Validate bill exists
    if ($bill_id > 0) {
        $bill_stmt = $conn->prepare("SELECT * FROM bills WHERE id = :id");
        $bill_stmt->execute(['id' => $bill_id]);
        $bill = $bill_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bill) {
            $errors[] = "Bill not found";
        }
    }

    if (!empty($errors)) {
        $message = implode("<br>", $errors);
        $message_type = "danger";
    } else {
        try {
            // Insert payment transaction (cash payment - marked as approved immediately)
            $stmt = $conn->prepare("
                INSERT INTO payment_transactions 
                (bill_id, tenant_id, payment_amount, payment_method, payment_type, payment_status, 
                 recorded_by, payment_date, notes, created_at)
                VALUES 
                (:bill_id, :tenant_id, :payment_amount, :payment_method, 'cash', 'approved',
                 :recorded_by, CURDATE(), :notes, NOW())
            ");
            
            $stmt->execute([
                'bill_id' => $bill_id,
                'tenant_id' => $bill['tenant_id'],
                'payment_amount' => $payment_amount,
                'payment_method' => $payment_method,
                'recorded_by' => $admin_id,
                'notes' => $notes
            ]);

            // Update bill status
            $total_paid = $bill['amount_paid'] + $payment_amount;
            $bill_status = ($total_paid >= $bill['amount_due']) ? 'paid' : 'partial';
            
            $update_stmt = $conn->prepare("
                UPDATE bills 
                SET amount_paid = amount_paid + :amount, status = :status
                WHERE id = :id
            ");
            $update_stmt->execute([
                'amount' => $payment_amount,
                'id' => $bill_id,
                'status' => $bill_status
            ]);

            // If bill is now fully paid, mark the room as occupied (was booked)
            if ($bill_status === 'paid') {
                $room_id = $bill['room_id'] ?? null;
                if ($room_id) {
                    $room_update = $conn->prepare("UPDATE rooms SET status = 'occupied' WHERE id = :room_id AND status = 'booked'");
                    $room_update->execute(['room_id' => $room_id]);
                }
            }

            $message = "✓ Cash payment recorded successfully!";
            $message_type = "success";

            // Reset tenant_bills
            $tenant_bills = [];
        } catch (Exception $e) {
            $message = "Error recording payment: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Load tenant bills when tenant is selected
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['tenant_id'])) {
    $tenant_id = intval($_GET['tenant_id']);
    
    try {
        $stmt = $conn->prepare("
            SELECT b.*, t.name as tenant_name
            FROM bills b
            JOIN tenants t ON b.tenant_id = t.id
            WHERE b.tenant_id = :tenant_id
            ORDER BY b.billing_month DESC
        ");
        $stmt->execute(['tenant_id' => $tenant_id]);
        $tenant_bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $message = "Error loading bills: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Fetch all tenants
try {
    $stmt = $conn->prepare("
        SELECT t.id, t.name, t.email,
               COUNT(DISTINCT b.id) as bill_count,
               COALESCE(SUM(b.amount_due - b.amount_paid), 0) as total_balance
        FROM tenants t
        LEFT JOIN bills b ON t.id = b.tenant_id
        GROUP BY t.id, t.name, t.email
        ORDER BY t.name ASC
    ");
    $stmt->execute();
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = "Error loading tenants: " . $e->getMessage();
    $message_type = "danger";
    $tenants = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Cash Payment - BAMINT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .tenant-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        .tenant-card:hover {
            border-color: #667eea;
            background-color: rgba(102, 126, 234, 0.05);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.15);
        }
        .tenant-card.selected {
            border-color: #667eea;
            background-color: rgba(102, 126, 234, 0.1);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.2);
        }
        .tenant-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }
        .tenant-email {
            font-size: 0.85rem;
            color: #666;
        }
        .bills-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 0;
        }
        .bill-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }
        .bill-item:hover {
            background-color: #f8f9fa;
        }
        .bill-item.selected {
            background-color: rgba(102, 126, 234, 0.1);
            border-left: 3px solid #667eea;
        }
        .bill-item:last-child {
            border-bottom: none;
        }
        .form-card {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .stat-card h3 {
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        .stat-card p {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0;
        }
        .section-title {
            color: #333;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #667eea;
        }
    </style>
</head>
<body>
    <?php include 'templates/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'templates/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="header-banner">
                    <h1><i class="bi bi-cash-coin"></i> Record Cash Payment</h1>
                    <p class="mb-0">Record walk-in or cash payments directly into the system</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics -->
                <div class="stats-row">
                    <div class="stat-card">
                        <h3><?php echo count($tenants); ?></h3>
                        <p>Total Tenants</p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo count(array_filter($tenants, fn($t) => $t['total_balance'] > 0)); ?></h3>
                        <p>With Outstanding Balance</p>
                    </div>
                </div>

                <div class="row">
                    <!-- Tenant Selection -->
                    <div class="col-lg-5 mb-4 mb-lg-0">
                        <div class="form-card">
                            <h6 class="section-title"><i class="bi bi-people"></i> Select Tenant</h6>
                            
                            <div class="mb-3">
                                <input type="text" class="form-control" id="tenant_search" placeholder="Search tenant...">
                            </div>

                            <div class="tenant-list" id="tenant-list">
                                <?php foreach ($tenants as $tenant): ?>
                                    <div class="tenant-card" onclick="selectTenant(<?php echo $tenant['id']; ?>, this)">
                                        <div class="tenant-name"><?php echo htmlspecialchars($tenant['name']); ?></div>
                                        <div class="tenant-email"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($tenant['email']); ?></div>
                                        <div class="text-muted small mt-2">
                                            Bills: <?php echo $tenant['bill_count']; ?> | Balance: 
                                            <strong>₱<?php echo number_format($tenant['total_balance'], 2); ?></strong>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Bills and Payment Form -->
                    <div class="col-lg-7">
                        <div class="form-card">
                            <!-- Bills List -->
                            <h6 class="section-title mb-3" id="bills-section" style="display: none;">
                                <i class="bi bi-receipt"></i> Select Bill
                            </h6>
                            
                            <div class="bills-container" id="bills-container" style="display: none;">
                                <!-- Bills will be loaded here via AJAX -->
                            </div>

                            <!-- Payment Form -->
                            <form method="POST" id="payment-form" style="display: none;">
                                <input type="hidden" name="action" value="record_payment">
                                <input type="hidden" name="tenant_id" id="tenant_id" value="">
                                <input type="hidden" name="bill_id" id="bill_id" value="">

                                <h6 class="section-title mt-4"><i class="bi bi-file-earmark-check"></i> Payment Details</h6>

                                <div class="mb-3">
                                    <label for="selected_bill" class="form-label">Selected Bill</label>
                                    <input type="text" class="form-control" id="selected_bill" readonly>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="amount_due" class="form-label">Amount Due</label>
                                            <div class="input-group">
                                                <span class="input-group-text">₱</span>
                                                <input type="text" class="form-control" id="amount_due" readonly>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="amount_paid" class="form-label">Already Paid</label>
                                            <div class="input-group">
                                                <span class="input-group-text">₱</span>
                                                <input type="text" class="form-control" id="amount_paid" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="payment_amount" class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" id="payment_amount" name="payment_amount" 
                                               placeholder="0.00" step="0.01" min="0" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                                    <select class="form-control" id="payment_method" name="payment_method" required>
                                        <option value="">-- Select method --</option>
                                        <option value="Cash">Cash</option>
                                        <option value="Check">Check</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                        <option value="GCash">GCash</option>
                                        <option value="PayMaya">PayMaya</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes (Optional)</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2" 
                                              placeholder="Add any notes about this payment..."></textarea>
                                </div>

                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-check-circle"></i> Record Payment
                                </button>
                            </form>

                            <!-- No Tenant Selected Message -->
                            <div id="no-selection" class="alert alert-info">
                                <i class="bi bi-info-circle"></i> Select a tenant from the list to record a payment
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Tenant search functionality
        document.getElementById('tenant_search').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const tenantCards = document.querySelectorAll('.tenant-card');
            
            tenantCards.forEach(card => {
                const text = card.textContent.toLowerCase();
                card.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        function selectTenant(tenantId, element) {
            // Update selected state
            document.querySelectorAll('.tenant-card').forEach(card => {
                card.classList.remove('selected');
            });
            element.classList.add('selected');

            // Set tenant ID
            document.getElementById('tenant_id').value = tenantId;

            // Load bills via AJAX
            loadTenantBills(tenantId);

            // Show payment form
            document.getElementById('no-selection').style.display = 'none';
            document.getElementById('bills-section').style.display = 'block';
            document.getElementById('bills-container').style.display = 'block';
        }

        function loadTenantBills(tenantId) {
            fetch('?tenant_id=' + tenantId)
                .then(response => response.text())
                .then(html => {
                    // Parse the response to get bills data
                    // For now, we'll use a simpler approach with inline data
                    const billsContainer = document.getElementById('bills-container');
                    billsContainer.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
                    
                    // This would be replaced with actual AJAX loading
                    location.href = '?tenant_id=' + tenantId;
                });
        }

        function selectBill(billId, element, billMonth, amountDue, amountPaid) {
            // Update selected state
            document.querySelectorAll('.bill-item').forEach(item => {
                item.classList.remove('selected');
            });
            element.classList.add('selected');

            // Set form values
            document.getElementById('bill_id').value = billId;
            document.getElementById('selected_bill').value = 'Bill for ' + billMonth;
            document.getElementById('amount_due').value = parseFloat(amountDue).toFixed(2);
            document.getElementById('amount_paid').value = parseFloat(amountPaid).toFixed(2);
            document.getElementById('payment_amount').placeholder = (parseFloat(amountDue) - parseFloat(amountPaid)).toFixed(2);

            // Show payment form
            document.getElementById('payment-form').style.display = 'block';
        }

        // After page loads with bills
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($tenant_bills)): ?>
                document.getElementById('payment-form').style.display = 'none';
                document.getElementById('no-selection').style.display = 'none';
                document.getElementById('bills-section').style.display = 'block';
                document.getElementById('bills-container').style.display = 'block';

                // Render bills
                const billsContainer = document.getElementById('bills-container');
                billsContainer.innerHTML = '';
                
                <?php foreach ($tenant_bills as $bill): ?>
                    const billItem = document.createElement('div');
                    billItem.className = 'bill-item';
                    billItem.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong><?php echo date('F Y', strtotime($bill['billing_month'])); ?></strong><br>
                                <small class="text-muted">Due: ₱<?php echo number_format($bill['amount_due'], 2); ?> | Paid: ₱<?php echo number_format($bill['amount_paid'], 2); ?></small>
                            </div>
                            <span class="badge bg-<?php echo ($bill['status'] === 'paid') ? 'success' : (($bill['status'] === 'partial') ? 'warning' : 'danger'); ?>">
                                <?php echo ucfirst($bill['status']); ?>
                            </span>
                        </div>
                    `;
                    billItem.onclick = () => selectBill(
                        <?php echo $bill['id']; ?>, 
                        billItem,
                        '<?php echo date('F Y', strtotime($bill['billing_month'])); ?>',
                        <?php echo $bill['amount_due']; ?>,
                        <?php echo $bill['amount_paid']; ?>
                    );
                    billsContainer.appendChild(billItem);
                <?php endforeach; ?>
            <?php endif; ?>
        });
    </script>
</body>
</html>
