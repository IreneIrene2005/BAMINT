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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_payment') {
    $action = $_POST['action'];
    $bill_id = isset($_POST['bill_id']) ? intval($_POST['bill_id']) : 0;
    $payment_type = isset($_POST['payment_type']) ? $_POST['payment_type'] : '';
    $payment_amount = isset($_POST['payment_amount']) ? floatval($_POST['payment_amount']) : 0;
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';


    
    // Validate input
    $errors = [];
    if ($bill_id < 0) $errors[] = "Please select a bill";
    if (!in_array($payment_type, ['online', 'cash'])) $errors[] = "Invalid payment type";
    if ($payment_amount <= 0) $errors[] = "Payment amount must be greater than 0";
    if (empty($payment_method)) $errors[] = "Please select a payment method";

    // If bill_id is 0, allow payment for advance payment (room request not yet admin approved)
    if ($bill_id == 0 && isset($_GET['room_request_id'])) {
        // Will create bill on the fly below if payment is submitted
        $bill = null;
    } elseif ($bill_id > 0) {
        $bill_stmt = $conn->prepare("SELECT * FROM bills WHERE id = :id AND tenant_id = :tenant_id");
        $bill_stmt->execute(['id' => $bill_id, 'tenant_id' => $tenant_id]);
        $bill = $bill_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bill) {
            $errors[] = "Bill not found or does not belong to you";
        }
    } else {
        $errors[] = "Invalid bill selection.";
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
            // If bill_id is 0, create the bill first
            if ($bill_id == 0 && isset($_GET['room_request_id'])) {
                $room_request_id = intval($_GET['room_request_id']);
                $rr_stmt = $conn->prepare("SELECT * FROM room_requests WHERE id = :id AND tenant_id = :tenant_id");
                $rr_stmt->execute(['id' => $room_request_id, 'tenant_id' => $tenant_id]);
                $rr = $rr_stmt->fetch(PDO::FETCH_ASSOC);
                if ($rr) {
                    $room_id = $rr['room_id'];
                    $rate_stmt = $conn->prepare("SELECT rate FROM rooms WHERE id = :room_id");
                    $rate_stmt->execute(['room_id' => $room_id]);
                    $rate = $rate_stmt->fetchColumn();
                    $checkin = $rr['checkin_date'];
                    $checkout = $rr['checkout_date'];
                    $nights = 0;
                    if ($checkin && $checkout) {
                        $checkin_dt = new DateTime($checkin);
                        $checkout_dt = new DateTime($checkout);
                        $interval = $checkin_dt->diff($checkout_dt);
                        $nights = (int)$interval->days;
                    }
                    $total_cost = $rate * $nights;
                    // Insert bill
                    $bill_insert = $conn->prepare("INSERT INTO bills (tenant_id, room_id, amount_due, notes, status, amount_paid, created_at) VALUES (:tenant_id, :room_id, :amount_due, :notes, 'pending', 0, NOW())");
                    $bill_insert->execute([
                        'tenant_id' => $tenant_id,
                        'room_id' => $room_id,
                        'amount_due' => $total_cost,
                        'notes' => 'ADVANCE PAYMENT for Room Request #' . $room_request_id
                    ]);
                    $bill_id = $conn->lastInsertId();
                    // Fetch the new bill for later use
                    $bill_stmt = $conn->prepare("SELECT * FROM bills WHERE id = :id AND tenant_id = :tenant_id");
                    $bill_stmt->execute(['id' => $bill_id, 'tenant_id' => $tenant_id]);
                    $bill = $bill_stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    throw new Exception("Room request not found.");
                }
            }

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
            // All payments require admin approval first
            $status = 'pending';
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

            // Update bill status and amount_paid for all payment types
            $message = "Downpayment/Full payment submitted. Please wait for admin approval.";
            $message_type = "success";
            // Do not redirect after payment, just show the message
        } catch (Exception $e) {
            $message = "Error processing payment: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Fetch pending bills, or filter by room_request_id if provided
$pending_bills = [];
try {
    if (isset($_GET['room_request_id']) && is_numeric($_GET['room_request_id'])) {
        $room_request_id = intval($_GET['room_request_id']);
        // Find the advance payment bill for this room request
        $stmt = $conn->prepare("
            SELECT b.* FROM bills b
            JOIN room_requests rr ON b.room_id = rr.room_id AND b.tenant_id = rr.tenant_id
            WHERE rr.id = :room_request_id AND b.notes LIKE '%ADVANCE PAYMENT%' AND b.status IN ('pending','partial')
            LIMIT 1
        ");
        $stmt->execute(['room_request_id' => $room_request_id]);
        $pending_bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // If no bill yet, calculate the total cost for the stay and show as the bill to pay
        if (count($pending_bills) === 0) {
            $rr_stmt = $conn->prepare("SELECT * FROM room_requests WHERE id = :id");
            $rr_stmt->execute(['id' => $room_request_id]);
            $rr = $rr_stmt->fetch(PDO::FETCH_ASSOC);
            if ($rr) {
                $room_stmt = $conn->prepare("SELECT rate FROM rooms WHERE id = :room_id");
                $room_stmt->execute(['room_id' => $rr['room_id']]);
                $rate = $room_stmt->fetchColumn();
                $checkin = $rr['checkin_date'];
                $checkout = $rr['checkout_date'];
                $nights = 0;
                if ($checkin && $checkout) {
                    $checkin_dt = new DateTime($checkin);
                    $checkout_dt = new DateTime($checkout);
                    $interval = $checkin_dt->diff($checkout_dt);
                    $nights = (int)$interval->days;
                }
                $total_cost = $rate * $nights;
                $pending_bills = [[
                    'id' => 0,
                    'amount_due' => $total_cost,
                    'notes' => 'Advance Payment (not yet approved)',
                ]];
            }
        }
    } else {
        $stmt = $conn->prepare("
            SELECT * FROM bills 
            WHERE tenant_id = :tenant_id AND status IN ('pending', 'partial')
            ORDER BY billing_month DESC
        ");
        $stmt->execute(['tenant_id' => $tenant_id]);
        $pending_bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
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
    <?php include 'templates/header.php'; ?>
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

                <!-- Pending Online Payments Verification Status removed -->

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
                                        <label for="bill_id" class="form-label">Bill to Pay <span class="text-danger">*</span></label>
                                        <select class="form-control" id="bill_id" name="bill_id" required onchange="updateBillAmount()">
                                            <?php
                                            // Always show Grand Total Due (Room + Amenities) as a payment option
                                            $today = date('Y-m-d');
                                            $show_advance_payment = false;
                                            // Check for any pending/pending_payment room request for this tenant
                                                                                        // Remove advance payment option after downpayment/full payment is made
                                            // Always show Grand Total Due (Room + Amenities) as a payment option
                                            // 1. Get all unpaid/active bills (room only)
                                            $bills_stmt = $conn->prepare("SELECT * FROM bills WHERE tenant_id = :tenant_id AND status IN ('pending','partial','unpaid','overdue')");
                                            $bills_stmt->execute(['tenant_id' => $tenant_id]);
                                            $bills = $bills_stmt->fetchAll(PDO::FETCH_ASSOC);
                                            // Fetch all completed amenities for this tenant
                                            $amenities_stmt = $conn->prepare("SELECT id, category, cost, billed, billed_bill_id FROM maintenance_requests WHERE tenant_id = :tenant_id AND status = 'completed' AND cost > 0 ORDER BY submitted_date DESC");
                                            $amenities_stmt->execute(['tenant_id' => $tenant_id]);
                                            $amenities = $amenities_stmt->fetchAll(PDO::FETCH_ASSOC);
                                            // Prepare Additional Charges (amenities that were completed and billed)
                                            $additional_items = [];
                                            $additional_total_unpaid = 0.0;
                                            foreach ($amenities as $a) {
                                                $bill_id = $a['billed'] && $a['billed_bill_id'] ? $a['billed_bill_id'] : null;
                                                $billing_month = null;
                                                $bill_status = 'not_billed';
                                                $bill_remaining = null;
                                                if ($bill_id) {
                                                    $billLookupStmt = $conn->prepare("SELECT id, billing_month, amount_due, amount_paid, status FROM bills WHERE id = :bill_id LIMIT 1");
                                                    $billLookupStmt->execute(['bill_id' => $bill_id]);
                                                    $bill = $billLookupStmt->fetch(PDO::FETCH_ASSOC);
                                                    if ($bill) {
                                                        $billing_month = $bill['billing_month'];
                                                        $bill_status = $bill['status'];
                                                        $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified','approved')");
                                                        $sum_stmt->execute(['bill_id' => $bill['id']]);
                                                        $paid = floatval($sum_stmt->fetchColumn());
                                                        $bill_remaining = max(0, floatval($bill['amount_due']) - $paid);
                                                    }
                                                }
                                                $alloc = $bill_remaining !== null ? min(floatval($a['cost']), $bill_remaining) : 0;
                                                if ($alloc > 0) $additional_total_unpaid += $alloc;
                                                $additional_items[] = [
                                                    'request_id' => $a['id'],
                                                    'category' => $a['category'],
                                                    'cost' => floatval($a['cost']),
                                                    'bill_id' => $bill_id,
                                                    'billing_month' => $billing_month,
                                                    'bill_status' => $bill_status,
                                                    'bill_remaining' => $bill_remaining,
                                                    'unpaid_alloc' => $alloc
                                                ];
                                            }
                                            // Calculate remaining balance for the most recent unpaid bill (room only, no amenities)
                                            $recent_bill_balance = 0.0;
                                            $room_rate = null;
                                            $nights = 1;
                                            if (!empty($bills)) {
                                                usort($bills, function($a, $b) {
                                                    $a_time = strtotime($a['billing_month']);
                                                    $b_time = strtotime($b['billing_month']);
                                                    if ($b_time === $a_time) {
                                                        return intval($b['id']) - intval($a['id']);
                                                    }
                                                    return $b_time - $a_time;
                                                });
                                                foreach ($bills as $bill) {
                                                    if (!isset($room_rate)) {
                                                        $room_stmt = $conn->prepare("SELECT r.rate FROM rooms r JOIN tenants t ON t.room_id = r.id WHERE t.id = :tenant_id LIMIT 1");
                                                        $room_stmt->execute(['tenant_id' => $tenant_id]);
                                                        $room_row = $room_stmt->fetch(PDO::FETCH_ASSOC);
                                                        $room_rate = $room_row && isset($room_row['rate']) ? floatval($room_row['rate']) : 0.0;
                                                    }
                                                    $room_req_stmt = $conn->prepare("SELECT checkin_date, checkout_date FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                                    $room_req_stmt->execute(['tenant_id' => $bill['tenant_id'], 'room_id' => $bill['room_id']]);
                                                    $dates = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                                    if ($dates && $dates['checkin_date'] && $dates['checkout_date']) {
                                                        $dt1 = new DateTime($dates['checkin_date']);
                                                        $dt2 = new DateTime($dates['checkout_date']);
                                                        $interval = $dt1->diff($dt2);
                                                        $nights = max(1, (int)$interval->format('%a'));
                                                    }
                                                    $room_total = $room_rate * $nights;
                                                    $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified','approved')");
                                                    $sum_stmt->execute(['bill_id' => $bill['id']]);
                                                    $live_paid = floatval($sum_stmt->fetchColumn());
                                                    if ($live_paid > 0 && $live_paid < $room_total) {
                                                        $recent_bill_balance = $room_total - $live_paid;
                                                    } else {
                                                        $recent_bill_balance = $room_total;
                                                    }
                                                    if ($recent_bill_balance < 0) $recent_bill_balance = 0.0;
                                                    break;
                                                }
                                            }
                                            // Grand Total Due = recent bill balance + all additional charges
                                            $grand_total_due = $recent_bill_balance;
                                            foreach ($additional_items as $ai) {
                                                $grand_total_due += floatval($ai['cost']);
                                            }
                                            echo '<option value="grand_total_due" data-amount="' . $grand_total_due . '">';
                                            echo 'Grand Total Due (Room + Amenities) - ₱' . number_format($grand_total_due, 2);
                                            echo '</option>';
                                            ?>
                                        </select>
                                        <small class="text-muted">
                                            <?php
                                            if (isset($_GET['room_request_id']) && $show_advance_payment) {
                                                echo 'This is your advance payment for your stay duration. The Grand Total Due will appear on your checkout date.';
                                            } else {
                                                echo 'This is your Grand Total Due including all charges and amenities.';
                                            }
                                            // Fetch payment history for this tenant
                                            $payment_history = [];
                                            try {
                                                $stmt = $conn->prepare("
                                                    SELECT pt.*, b.notes, b.amount_due, b.room_id, b.status as bill_status
                                                    FROM payment_transactions pt
                                                    JOIN bills b ON pt.bill_id = b.id
                                                    WHERE pt.tenant_id = :tenant_id
                                                    ORDER BY pt.created_at DESC
                                                ");
                                                $stmt->execute(['tenant_id' => $tenant_id]);
                                                $payment_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            } catch (Exception $e) {
                                                $payment_history = [];
                                            }
                                            ?>
                                        </small>
                                    </div>

                                    <div class="mb-3">
                                        <label for="payment_option" class="form-label">Payment Option <span class="text-danger">*</span></label>
                                        <select class="form-control" id="payment_option" name="payment_option" required onchange="updatePaymentOption()">
                                            <?php
                                            // Set this variable to true when the customer is checking out
                                            $is_checkout = false; // <-- Set to true for checkout scenario
                                            if ($is_checkout) {
                                                echo '<option value="full" selected>Full Payment</option>';
                                            } else {
                                                echo '<option value="">-- Select option --</option>';
                                                echo '<option value="downpayment">Downpayment (e.g. 50%)</option>';
                                                echo '<option value="full">Full Payment</option>';
                                            }
                                            ?>
                                        </select>
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
                                        <select class="form-control" id="payment_method" name="payment_method" required onchange="showQRCode()">
                                            <option value="">-- Select method --</option>
                                            <option value="GCash">GCash</option>
                                            <option value="PayMaya">PayMaya</option>
                                        </select>
                                        <div id="qr_demo" style="margin-top:15px; display:none; text-align:center;">
                                            <label class="form-label">Scan to Pay (Demo QR)</label><br>
                                            <img id="qr_img" src="" alt="QR Code" style="max-width:180px;">
                                            <div id="qr_text" style="font-size:14px; margin-top:5px;"></div>
                                        </div>
                                        <script>
                                            function showQRCode() {
                                                var method = document.getElementById('payment_method').value;
                                                var qrDiv = document.getElementById('qr_demo');
                                                var qrImg = document.getElementById('qr_img');
                                                var qrText = document.getElementById('qr_text');
                                                if (method === 'GCash') {
                                                    qrDiv.style.display = 'block';
                                                    qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=GCASH-09171234567';
                                                    qrText.textContent = 'GCash Number: 0917-123-4567';
                                                } else if (method === 'PayMaya') {
                                                    qrDiv.style.display = 'block';
                                                    qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=PAYMAYA-09181234567';
                                                    qrText.textContent = 'PayMaya Number: 0918-123-4567';
                                                } else {
                                                    qrDiv.style.display = 'none';
                                                    qrImg.src = '';
                                                    qrText.textContent = '';
                                                }
                                            }
                                        </script>
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
            <!-- Payment History Section -->
            <div class="card mt-4">
                <div class="card-header bg-secondary bg-opacity-10">
                    <h6 class="mb-0"><i class="bi bi-clock-history"></i> Payment History</h6>
                </div>
                <div class="card-body">
                    <?php if (count($payment_history) === 0): ?>
                        <div class="text-muted">No payment history yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Bill Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payment_history as $ph): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($ph['created_at']))); ?></td>
                                            <td>₱<?php echo number_format($ph['payment_amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($ph['payment_method']); ?></td>
                                            <td>
                                                <?php
                                                    $status_map = [
                                                        'pending' => '<span class="badge bg-warning text-dark">Pending</span>',
                                                        'approved' => '<span class="badge bg-success">Approved</span>',
                                                        'verified' => '<span class="badge bg-success">Verified</span>',
                                                        'rejected' => '<span class="badge bg-danger">Rejected</span>',
                                                    ];
                                                    echo $status_map[$ph['payment_status']] ?? htmlspecialchars($ph['payment_status']);
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($ph['notes']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Make payment method cards always clickable and update payment_type
        function selectMethod(element) {
            document.querySelectorAll('.payment-method-card').forEach(card => {
                card.classList.remove('selected');
            });
            element.classList.add('selected');
            const method = element.dataset.method;
            document.getElementById('payment_type').value = method;
            if (method === 'online') {
                document.getElementById('proof_upload_div').style.display = 'block';
                document.getElementById('proof_of_payment').required = true;
            } else {
                document.getElementById('proof_upload_div').style.display = 'none';
                document.getElementById('proof_of_payment').required = false;
            }
            enableSubmitButton();
        }

        // Update payment amount when bill or payment option changes
        function updatePaymentOption() {
            const option = document.getElementById('payment_option').value;
            const billSelect = document.getElementById('bill_id');
            const selected = billSelect.options[billSelect.selectedIndex];
            const billAmount = parseFloat(selected.dataset.amount) || 0;
            const amountInput = document.getElementById('payment_amount');
            const amountHint = document.getElementById('amount_hint');
            if (option === 'downpayment') {
                const half = (billAmount / 2).toFixed(2);
                amountInput.value = half;
                amountHint.textContent = 'You are paying 50% of the total bill as downpayment.';
            } else if (option === 'full') {
                amountInput.value = billAmount.toFixed(2);
                amountHint.textContent = 'You are paying the full amount of the bill.';
            } else {
                amountInput.value = '';
                amountHint.textContent = '';
            }
            enableSubmitButton();
        }

        // Update payment amount if bill changes
        function updateBillAmount() {
            updatePaymentOption();
        }

        // Enable/disable submit button based on form state
        function enableSubmitButton() {
            const paymentType = document.getElementById('payment_type').value;
            const billId = document.getElementById('bill_id').value;
            const amount = parseFloat(document.getElementById('payment_amount').value) || 0;
            const method = document.getElementById('payment_method').value;
            const proofFile = document.getElementById('proof_of_payment').files.length;
            let canSubmit = false;
            if (paymentType === 'online') {
                canSubmit = paymentType && billId && amount > 0 && method && proofFile > 0;
            } else {
                canSubmit = paymentType && billId && amount > 0 && method;
            }
            document.getElementById('submit_btn').disabled = !canSubmit;
        }

        // Add event listeners
        document.getElementById('proof_of_payment').addEventListener('change', enableSubmitButton);
        document.getElementById('payment_amount').addEventListener('input', enableSubmitButton);
        document.getElementById('payment_option').addEventListener('change', updatePaymentOption);
        document.getElementById('bill_id').addEventListener('change', updateBillAmount);

        // Form submission validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const paymentType = document.getElementById('payment_type').value;
            const billId = document.getElementById('bill_id').value;
            const amount = parseFloat(document.getElementById('payment_amount').value) || 0;
            const method = document.getElementById('payment_method').value;
            const proofFile = document.getElementById('proof_of_payment').files.length;
            if (!paymentType) {
                e.preventDefault();
                alert('Please select a payment method (Online or Walk-in/Cash)');
                return false;
            }
            if (!billId) {
                e.preventDefault();
                alert('Please select a bill to pay');
                return false;
            }
            if (amount <= 0) {
                e.preventDefault();
                alert('Please enter a valid payment amount greater than 0');
                return false;
            }
            if (!method) {
                e.preventDefault();
                alert('Please select a payment method (e.g., GCash, Bank Transfer, etc.)');
                return false;
            }
            if (paymentType === 'online' && proofFile === 0) {
                e.preventDefault();
                alert('For online payments, please upload proof of payment');
                return false;
            }
        });
    </script>
</body>
</html>
