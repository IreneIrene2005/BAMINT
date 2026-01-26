<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "tenant") {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";

$tenant_id = $_SESSION["tenant_id"];
$message = '';
$message_type = '';

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $bill_id = isset($_POST['bill_id']) ? intval($_POST['bill_id']) : 0;
    $payment_type = isset($_POST['payment_type']) ? $_POST['payment_type'] : '';
    $payment_amount = isset($_POST['payment_amount']) ? floatval($_POST['payment_amount']) : 0;
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    // Validate input
    $errors = [];
    if ($bill_id <= 0) $errors[] = "Please select a bill";
    if (!in_array($payment_type, ['online', 'cash'])) $errors[] = "Invalid payment type";
    if ($payment_amount <= 0) $errors[] = "Payment amount must be greater than 0";
    if (empty($payment_method)) $errors[] = "Please select a payment method";

    // Validate bill exists and belongs to tenant
    if ($bill_id > 0) {
        $bill_stmt = $conn->prepare("SELECT * FROM bills WHERE id = :id AND tenant_id = :tenant_id");
        $bill_stmt->execute(['id' => $bill_id, 'tenant_id' => $tenant_id]);
        $bill = $bill_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bill) {
            $errors[] = "Bill not found or does not belong to you";
        }
    }

    // Additional validation for online payments
    if ($payment_type === 'online' && empty($errors)) {
        if (!isset($_FILES['proof_of_payment']) || $_FILES['proof_of_payment']['error'] != UPLOAD_ERR_OK) {
            $error_code = $_FILES['proof_of_payment']['error'] ?? 0;
            if ($error_code == UPLOAD_ERR_NO_FILE) {
                $errors[] = "⚠️ No file selected. Please choose a payment proof file (JPG, PNG, or PDF)";
            } elseif ($error_code == UPLOAD_ERR_FORM_SIZE || $error_code == UPLOAD_ERR_INI_SIZE) {
                $errors[] = "⚠️ File is too large. Maximum file size is 5MB";
            } else {
                $errors[] = "⚠️ Failed to upload file. Error code: $error_code. Please try again";
            }
        } else {
            // Validate file
            $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
            $file_type = $_FILES['proof_of_payment']['type'];
            
            if (!in_array($file_type, $allowed_types)) {
                $errors[] = "Only JPG, PNG, and PDF files are allowed";
            }
            
            if ($_FILES['proof_of_payment']['size'] > 5 * 1024 * 1024) { // 5MB limit
                $errors[] = "File size must be less than 5MB";
            }
        }
    }

    if (!empty($errors)) {
        $message = implode("<br>", $errors);
        $message_type = "danger";
    } else {
        try {
            // Handle file upload for online payments
            $proof_filename = null;
            if ($payment_type === 'online' && isset($_FILES['proof_of_payment'])) {
                $upload_dir = __DIR__ . "/public/payment_proofs";
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_ext = pathinfo($_FILES['proof_of_payment']['name'], PATHINFO_EXTENSION);
                $proof_filename = "proof_" . $bill_id . "_" . $tenant_id . "_" . time() . "." . $file_ext;
                $upload_path = $upload_dir . "/" . $proof_filename;
                
                if (!move_uploaded_file($_FILES['proof_of_payment']['tmp_name'], $upload_path)) {
                    throw new Exception("Failed to upload proof of payment");
                }
            }

            // Insert payment transaction
            $status = ($payment_type === 'online') ? 'pending' : 'approved';
            
            $stmt = $conn->prepare("
                INSERT INTO payment_transactions 
                (bill_id, tenant_id, payment_amount, payment_method, payment_type, payment_status, 
                 proof_of_payment, payment_date, notes, created_at)
                VALUES 
                (:bill_id, :tenant_id, :payment_amount, :payment_method, :payment_type, :payment_status,
                 :proof_of_payment, CURDATE(), :notes, NOW())
            ");
            
            $stmt->execute([
                'bill_id' => $bill_id,
                'tenant_id' => $tenant_id,
                'payment_amount' => $payment_amount,
                'payment_method' => $payment_method,
                'payment_type' => $payment_type,
                'payment_status' => $status,
                'proof_of_payment' => $proof_filename,
                'notes' => $notes
            ]);

            // Update bill status if it's cash payment
            if ($payment_type === 'cash') {
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

                $message = "✓ Cash payment recorded successfully! Admin will verify it shortly.";
            } else {
                $message = "✓ Online payment submitted! We'll verify your proof of payment and update your account.";
            }
            
            $message_type = "success";
        } catch (Exception $e) {
            $message = "Error processing payment: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Fetch pending bills
try {
    $stmt = $conn->prepare("
        SELECT * FROM bills 
        WHERE tenant_id = :tenant_id AND status IN ('pending', 'partial')
        ORDER BY billing_month DESC
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $pending_bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = "Error loading bills: " . $e->getMessage();
    $message_type = "danger";
    $pending_bills = [];
}

// Fetch pending online payments for verification
try {
    $stmt = $conn->prepare("
        SELECT pt.*, b.billing_month 
        FROM payment_transactions pt
        JOIN bills b ON pt.bill_id = b.id
        WHERE pt.tenant_id = :tenant_id AND pt.payment_type = 'online' AND pt.payment_status = 'pending'
        ORDER BY pt.created_at DESC
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $pending_online = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_online = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make Payment - BAMINT</title>
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
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .payment-method-card {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 1rem;
        }
        .payment-method-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
            transform: translateY(-2px);
        }
        .payment-method-card.selected {
            border-color: #667eea;
            background-color: rgba(102, 126, 234, 0.05);
        }
        .payment-method-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
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
        .pending-badge {
            display: inline-block;
            background: #ffc107;
            color: #000;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .paid-badge {
            display: inline-block;
            background: #28a745;
            color: #fff;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
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
                            <a class="nav-link" href="tenant_maintenance.php">
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
                    <h1><i class="bi bi-credit-card"></i> Make a Payment</h1>
                    <p class="mb-0">Choose your payment method and submit your payment</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Pending Online Payments Verification Status -->
                <?php if (!empty($pending_online)): ?>
                    <div class="card mb-4 border-warning">
                        <div class="card-header bg-warning bg-opacity-10">
                            <h6 class="mb-0"><i class="bi bi-hourglass-split"></i> Pending Verification</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-3">Your online payments are awaiting admin verification:</p>
                            <div class="row">
                                <?php foreach ($pending_online as $payment): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <h6><?php echo date('F Y', strtotime($payment['billing_month'])); ?></h6>
                                                <p class="mb-2">
                                                    Amount: <strong>₱<?php echo number_format($payment['payment_amount'], 2); ?></strong>
                                                </p>
                                                <p class="mb-0">
                                                    <span class="pending-badge">
                                                        <i class="bi bi-clock"></i> Awaiting Verification
                                                    </span>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Payment Methods Selection -->
                <div class="row mb-4">
                    <div class="col-lg-6">
                        <h5 class="mb-3"><i class="bi bi-collection"></i> Payment Methods Available</h5>
                        
                        <div class="payment-method-card" data-method="online" onclick="selectMethod(this)">
                            <div class="payment-method-icon">
                                <i class="bi bi-credit-card text-primary"></i>
                            </div>
                            <h6>Online Payment</h6>
                            <p class="text-muted mb-0">GCash, Bank Transfer, etc.</p>
                            <small class="text-muted">Submit proof of payment for verification</small>
                        </div>

                        <div class="payment-method-card" data-method="cash" onclick="selectMethod(this)">
                            <div class="payment-method-icon">
                                <i class="bi bi-cash-coin text-success"></i>
                            </div>
                            <h6>Walk-in / Cash Payment</h6>
                            <p class="text-muted mb-0">Pay at our office</p>
                            <small class="text-muted">Admin will process your payment immediately</small>
                        </div>
                    </div>

                    <!-- Payment Form -->
                    <div class="col-lg-6">
                        <div class="card sticky-top" style="top: 1rem;">
                            <div class="card-header bg-primary bg-opacity-10">
                                <h6 class="mb-0"><i class="bi bi-form-check"></i> Payment Details</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="submit_payment">
                                    <input type="hidden" id="payment_type" name="payment_type" value="">

                                    <div class="mb-3">
                                        <label for="bill_id" class="form-label">Select Bill <span class="text-danger">*</span></label>
                                        <select class="form-control" id="bill_id" name="bill_id" required onchange="updateBillAmount()">
                                            <option value="">-- Choose a bill --</option>
                                            <?php foreach ($pending_bills as $bill): ?>
                                                <option value="<?php echo $bill['id']; ?>" data-amount="<?php echo $bill['amount_due'] - $bill['amount_paid']; ?>">
                                                    <?php echo date('F Y', strtotime($bill['billing_month'])); ?> - 
                                                    ₱<?php echo number_format($bill['amount_due'] - $bill['amount_paid'], 2); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Showing bills with pending balance</small>
                                    </div>

                                    <div class="mb-3">
                                        <label for="payment_amount" class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" class="form-control" id="payment_amount" name="payment_amount" 
                                                   placeholder="0.00" step="0.01" min="0" required>
                                        </div>
                                        <small class="text-muted" id="amount_hint"></small>
                                    </div>

                                    <div class="mb-3">
                                        <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                                        <select class="form-control" id="payment_method" name="payment_method" required>
                                            <option value="">-- Select method --</option>
                                            <option value="GCash">GCash</option>
                                            <option value="Bank Transfer">Bank Transfer</option>
                                            <option value="PayMaya">PayMaya</option>
                                            <option value="Check">Check</option>
                                            <option value="Cash">Cash</option>
                                        </select>
                                    </div>

                                    <!-- Online Payment File Upload -->
                                    <div class="mb-3" id="proof_upload_div" style="display: none;">
                                        <label for="proof_of_payment" class="form-label">Proof of Payment <span class="text-danger">*</span></label>
                                        <input type="file" class="form-control" id="proof_of_payment" name="proof_of_payment" 
                                               accept=".jpg,.jpeg,.png,.pdf">
                                        <small class="text-muted">JPG, PNG, or PDF (max 5MB)</small>
                                    </div>

                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Notes (Optional)</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="2" 
                                                  placeholder="Add any notes about this payment..."></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100" id="submit_btn" disabled>
                                        <i class="bi bi-check-circle"></i> Submit Payment
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectMethod(element) {
            // Remove previous selection
            document.querySelectorAll('.payment-method-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selection to clicked card
            element.classList.add('selected');
            
            // Set payment type
            const method = element.dataset.method;
            document.getElementById('payment_type').value = method;
            
            // Toggle file upload for online payments
            if (method === 'online') {
                document.getElementById('proof_upload_div').style.display = 'block';
                document.getElementById('proof_of_payment').required = true;
            } else {
                document.getElementById('proof_upload_div').style.display = 'none';
                document.getElementById('proof_of_payment').required = false;
            }
            
            enableSubmitButton();
        }

        function updateBillAmount() {
            const select = document.getElementById('bill_id');
            const selected = select.options[select.selectedIndex];
            const amount = selected.dataset.amount;
            
            if (amount) {
                document.getElementById('payment_amount').placeholder = amount;
                document.getElementById('amount_hint').textContent = 'Bill balance: ₱' + parseFloat(amount).toFixed(2);
            }
            
            enableSubmitButton();
        }

        function enableSubmitButton() {
            const paymentType = document.getElementById('payment_type').value;
            const billId = document.getElementById('bill_id').value;
            const amount = document.getElementById('payment_amount').value;
            const method = document.getElementById('payment_method').value;
            
            const canSubmit = paymentType && billId && amount > 0 && method;
            document.getElementById('submit_btn').disabled = !canSubmit;
        }

        // Form submission validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const paymentType = document.getElementById('payment_type').value;
            if (!paymentType) {
                e.preventDefault();
                alert('Please select a payment method');
                return false;
            }
        });
    </script>
</body>
</html>
