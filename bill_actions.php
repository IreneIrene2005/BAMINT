<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'generate') {
        // Generate monthly bills for all active tenants
        $billing_month = $_POST['billing_month'] . '-01';
        $due_date = $_POST['due_date'];
        
        try {
            $conn->beginTransaction();
            
            // Get all active tenants with their room rates
            $sql = "SELECT tenants.id, tenants.name, rooms.id as room_id, rooms.rate 
                    FROM tenants 
                    JOIN rooms ON tenants.room_id = rooms.id 
                    WHERE tenants.status = 'active'";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $count = 0;
            foreach ($tenants as $tenant) {
                // Check if bill already exists for this month
                $check_sql = "SELECT id FROM bills WHERE tenant_id = :tenant_id AND billing_month = :billing_month";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->execute([
                    'tenant_id' => $tenant['id'],
                    'billing_month' => $billing_month
                ]);
                
                if ($check_stmt->rowCount() == 0) {
                    // Create new bill
                    $insert_sql = "INSERT INTO bills (tenant_id, room_id, billing_month, amount_due, due_date, status) 
                                  VALUES (:tenant_id, :room_id, :billing_month, :amount_due, :due_date, 'pending')";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->execute([
                        'tenant_id' => $tenant['id'],
                        'room_id' => $tenant['room_id'],
                        'billing_month' => $billing_month,
                        'amount_due' => $tenant['rate'],
                        'due_date' => $due_date
                    ]);
                    
                    // Get the bill ID for the notification
                    $bill_id = $conn->lastInsertId();
                    
                    // Format the billing month for display
                    $month_display = date('F Y', strtotime($billing_month));
                    
                    // Notify tenant of new bill
                    createNotification(
                        $conn,
                        'tenant',
                        $tenant['id'],
                        'new_bill',
                        'New Bill Generated',
                        "A new bill for {$month_display} has been created. Amount due: ₱" . number_format($tenant['rate'], 2),
                        $bill_id,
                        'bill',
                        'bills.php'
                    );
                    
                    $count++;
                }
            }
            
            $conn->commit();
            $_SESSION['message'] = "Generated $count new bills successfully!";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error generating bills: " . $e->getMessage();
        }
        
        header("location: bills.php");
        exit;
        
    } elseif ($action === 'add') {
        // Add manual bill
        $tenant_id = $_POST['tenant_id'];
        $billing_month = $_POST['billing_month'] . '-01';
        $amount_due = $_POST['amount_due'];
        $due_date = $_POST['due_date'] ?? null;
        
        try {
            // Get the tenant's room_id
            $sql = "SELECT room_id FROM tenants WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['id' => $tenant_id]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tenant) {
                $insert_sql = "INSERT INTO bills (tenant_id, room_id, billing_month, amount_due, due_date, status) 
                              VALUES (:tenant_id, :room_id, :billing_month, :amount_due, :due_date, 'pending')";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->execute([
                    'tenant_id' => $tenant_id,
                    'room_id' => $tenant['room_id'],
                    'billing_month' => $billing_month,
                    'amount_due' => $amount_due,
                    'due_date' => $due_date
                ]);
                
                // Get the bill ID for the notification
                $bill_id = $conn->lastInsertId();
                
                // Format the billing month for display
                $month_display = date('F Y', strtotime($billing_month));
                
                // Notify tenant of new bill
                createNotification(
                    $conn,
                    'tenant',
                    $tenant_id,
                    'new_bill',
                    'New Bill Generated',
                    "A new bill for {$month_display} has been created. Amount due: ₱" . number_format($amount_due, 2),
                    $bill_id,
                    'bill',
                    'bills.php'
                );
                
                $_SESSION['message'] = "Bill added successfully!";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Error adding bill: " . $e->getMessage();
        }
        
        header("location: bills.php");
        exit;
        
    } elseif ($action === 'add_new_bill') {
        // Add new bill based on selected booking and payment details
        $booking_room = $_POST['booking_room'] ?? '';
        $payment_type = $_POST['payment_type'] ?? 'full';
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $amount_paid = floatval($_POST['amount_paid'] ?? 0);
        $remaining_balance = floatval($_POST['remaining_balance'] ?? 0);
        
        if (!$booking_room) {
            $_SESSION['error'] = "Please select a booking/room.";
            header("location: bills.php");
            exit;
        }
        
        try {
            $conn->beginTransaction();
            
            // Get booking and tenant details
            $booking_sql = "
                SELECT 
                    rr.id as booking_id,
                    rr.tenant_id,
                    rr.room_id,
                    r.rate as room_rate,
                    r.room_number,
                    rr.checkin_date,
                    rr.checkout_date
                FROM room_requests rr
                JOIN rooms r ON rr.room_id = r.id
                WHERE rr.id = :booking_id
            ";
            $booking_stmt = $conn->prepare($booking_sql);
            $booking_stmt->execute(['booking_id' => $booking_room]);
            $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                throw new Exception("Invalid booking selected.");
            }
            
            $tenant_id = $booking['tenant_id'];
            $room_id = $booking['room_id'];
            $room_rate = floatval($booking['room_rate']);
            
            // Calculate amount due based on payment type
            $amount_due = ($payment_type === 'downpayment') ? ($room_rate * 0.5) : $room_rate;
            
            // Determine bill status
            $status = 'pending';
            if ($amount_paid >= $amount_due) {
                $status = 'paid';
            } elseif ($amount_paid > 0) {
                $status = 'partial';
            }
            
            // Create bill
            $insert_bill_sql = "
                INSERT INTO bills (
                    tenant_id, 
                    room_id, 
                    billing_month, 
                    amount_due, 
                    amount_paid,
                    due_date, 
                    status, 
                    notes, 
                    created_at,
                    updated_at
                ) VALUES (
                    :tenant_id, 
                    :room_id, 
                    :billing_month, 
                    :amount_due, 
                    :amount_paid,
                    :due_date, 
                    :status, 
                    :notes,
                    NOW(),
                    NOW()
                )
            ";
            
            $billing_month = date('Y-m-01');
            $due_date = date('Y-m-15');
            $payment_type_note = ($payment_type === 'downpayment') ? 'Downpayment (50%)' : 'Full Payment';
            $notes = "Payment Type: {$payment_type_note} | Payment Method: " . ucfirst($payment_method);
            
            $insert_bill_stmt = $conn->prepare($insert_bill_sql);
            $insert_bill_stmt->execute([
                'tenant_id' => $tenant_id,
                'room_id' => $room_id,
                'billing_month' => $billing_month,
                'amount_due' => $amount_due,
                'amount_paid' => $amount_paid,
                'due_date' => $due_date,
                'status' => $status,
                'notes' => $notes
            ]);
            
            $bill_id = $conn->lastInsertId();
            
            // Record payment transaction if amount is paid
            if ($amount_paid > 0) {
                $payment_sql = "
                    INSERT INTO payment_transactions (
                        bill_id,
                        tenant_id,
                        payment_amount,
                        payment_method,
                        payment_date,
                        payment_status,
                        notes,
                        recorded_by,
                        created_at
                    ) VALUES (
                        :bill_id,
                        :tenant_id,
                        :payment_amount,
                        :payment_method,
                        :payment_date,
                        :payment_status,
                        :notes,
                        :recorded_by,
                        NOW()
                    )
                ";
                
                $payment_stmt = $conn->prepare($payment_sql);
                $payment_stmt->execute([
                    'bill_id' => $bill_id,
                    'tenant_id' => $tenant_id,
                    'payment_amount' => $amount_paid,
                    'payment_method' => $payment_method,
                    'payment_date' => date('Y-m-d'),
                    'payment_status' => 'verified',
                    'notes' => $notes,
                    'recorded_by' => $_SESSION['admin_id']
                ]);
            }
            
            $conn->commit();
            
            // Send notification to tenant
            $month_display = date('F Y');
            createNotification(
                $conn,
                'tenant',
                $tenant_id,
                'new_bill',
                'New Bill Created',
                "A new bill for {$month_display} has been created. Amount due: ₱" . number_format($amount_due, 2),
                $bill_id,
                'bill',
                'bills.php'
            );
            
            $_SESSION['message'] = "Bill created successfully! Amount paid: ₱" . number_format($amount_paid, 2) . ", Remaining: ₱" . number_format($remaining_balance, 2);
            
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error creating bill: " . $e->getMessage();
        }
        
        header("location: bills.php");
        exit;
        
    } elseif ($action === 'edit') {
        // Update bill payment
        $id = $_POST['id'];
        $amount_paid = $_POST['amount_paid'];
        $notes = $_POST['notes'] ?? '';
        $payment_method = $_POST['payment_method'] ?? '';
        
        try {
            // Get bill details
            $sql = "SELECT amount_due, amount_paid as prev_amount_paid, tenant_id FROM bills WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['id' => $id]);
            $bill = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate payment difference to record transaction
            $new_payment = $amount_paid - $bill['prev_amount_paid'];
            
            $balance = $bill['amount_due'] - $amount_paid;
            
            // Determine status
            if ($amount_paid >= $bill['amount_due']) {
                $status = 'paid';
                $paid_date = date('Y-m-d');
            } elseif ($amount_paid > 0) {
                $status = 'partial';
                $paid_date = null;
            } else {
                $status = 'pending';
                $paid_date = null;
            }
            
            $conn->beginTransaction();
            
            // Update bill
            $update_sql = "UPDATE bills SET amount_paid = :amount_paid, status = :status, notes = :notes, paid_date = :paid_date WHERE id = :id";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->execute([
                'amount_paid' => $amount_paid,
                'status' => $status,
                'notes' => $notes,
                'paid_date' => $paid_date,
                'id' => $id
            ]);
            
            // Record payment transaction if there's a new payment
            if ($new_payment > 0) {
                $trans_sql = "INSERT INTO payment_transactions (bill_id, tenant_id, payment_amount, payment_method, payment_date, notes, recorded_by) 
                             VALUES (:bill_id, :tenant_id, :payment_amount, :payment_method, :payment_date, :notes, :recorded_by)";
                $trans_stmt = $conn->prepare($trans_sql);
                $trans_stmt->execute([
                    'bill_id' => $id,
                    'tenant_id' => $bill['tenant_id'],
                    'payment_amount' => $new_payment,
                    'payment_method' => $payment_method,
                    'payment_date' => date('Y-m-d'),
                    'notes' => $notes,
                    'recorded_by' => $_SESSION['id']
                ]);
                
                $paymentId = $conn->lastInsertId();
                
                // Notify all admins about the new payment
                notifyAdminsNewPayment($conn, $id, $bill['tenant_id'], $new_payment);
            }
            
            $conn->commit();
            $_SESSION['message'] = "Bill updated successfully!";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error updating bill: " . $e->getMessage();
        }
        
        header("location: bills.php");
        exit;
    }
    elseif ($action === 'process_walk_in') {
        // Process walk-in payment setup: create invoice (downpayment or full) and mark room booked
        $tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : (isset($_POST['tenant_id']) ? intval($_POST['tenant_id']) : 0);
        $room_id = $_POST['room_id'] ?? null;
        $checkin = $_POST['checkin_date'] ?? null;
        $checkout = $_POST['checkout_date'] ?? null;
        $payment_option = $_POST['payment_option'] ?? 'full_payment';

        if (!$tenant_id) {
            $_SESSION['error'] = 'Missing tenant information.';
            header('location: bills.php'); exit;
        }

        try {
            $conn->beginTransaction();

            // Validate room availability if room selected
            if ($room_id) {
                $room_stmt = $conn->prepare("SELECT status, rate FROM rooms WHERE id = :id");
                $room_stmt->execute(['id' => $room_id]);
                $room = $room_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$room) throw new Exception('Selected room not found.');
                if ($room['status'] !== 'available' && $room['status'] !== 'vacant') {
                    // allow booking only if available
                    throw new Exception('Selected room is not available.');
                }
                $rate = floatval($room['rate']);
            } else {
                $rate = 0;
            }

            // compute nights
            $nights = 0;
            if ($checkin && $checkout) {
                try { $ci = new DateTime($checkin); $co = new DateTime($checkout); $nights = max(0, (int)$ci->diff($co)->days); } catch (Exception $e) { $nights = 0; }
            }
            $total_cost = $rate * max(1, $nights);

            if ($payment_option === 'downpayment') {
                $amount_due = round($total_cost * 0.5, 2);
            } else {
                $amount_due = round($total_cost, 2);
            }

            // Create or update bill for this tenant and room (pending)
            $billing_month = $checkin ? (new DateTime($checkin))->format('Y-m') : date('Y-m');
            $due_date = $checkin ?: date('Y-m-d');

            $notes = ($payment_option === 'downpayment') ? "ADVANCE PAYMENT (Downpayment) - {$nights} night(s), ₱" . number_format($rate,2) . "/night" : "ADVANCE PAYMENT - Full payment (" . $nights . " night(s))";

            // Insert bill
            $insert = $conn->prepare("INSERT INTO bills (tenant_id, room_id, billing_month, amount_due, due_date, status, notes, created_at, updated_at) VALUES (:tenant_id, :room_id, :billing_month, :amount_due, :due_date, 'pending', :notes, NOW(), NOW())");
            $insert->execute([
                'tenant_id' => $tenant_id,
                'room_id' => $room_id,
                'billing_month' => $billing_month,
                'amount_due' => $amount_due,
                'due_date' => $due_date,
                'notes' => $notes
            ]);
            $bill_id = $conn->lastInsertId();

            // Mark room booked so it's reserved
            if ($room_id) {
                $upd = $conn->prepare("UPDATE rooms SET status = 'booked' WHERE id = :id");
                $upd->execute(['id' => $room_id]);
            }

            // If there is a pending room_request, update its status to pending_payment (already) and link to bill via notes or leave as-is
            $rr_stmt = $conn->prepare("UPDATE room_requests SET status = 'pending_payment' WHERE tenant_id = :tenant_id AND room_id = :room_id AND status != 'approved'");
            $rr_stmt->execute(['tenant_id' => $tenant_id, 'room_id' => $room_id]);

            $conn->commit();
            $_SESSION['message'] = 'Invoice generated. Bill ID #' . $bill_id;
            header('location: bills.php'); exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = 'Error processing walk-in: ' . $e->getMessage();
            header('location: bills.php'); exit;
        }
    }
} else { // GET request
    if ($action === 'delete') {
        $id = $_GET['id'];
        
        try {
            $sql = "DELETE FROM bills WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            $_SESSION['message'] = "Bill deleted successfully!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Error deleting bill: " . $e->getMessage();
        }
        
        header("location: bills.php");
        exit;
        
    } elseif ($action === 'edit') {
        // Show edit form
        $id = $_GET['id'];
        $sql = "SELECT bills.*, tenants.name, rooms.room_number FROM bills 
                JOIN tenants ON bills.tenant_id = tenants.id 
                JOIN rooms ON bills.room_id = rooms.id 
                WHERE bills.id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['id' => $id]);
        $bill = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$bill) {
            header("location: bills.php");
            exit;
        }
        
        $balance = $bill['amount_due'] - $bill['amount_paid'];
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Edit Bill</title>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="public/css/style.css">
        </head>
        <body>
        <?php include 'templates/header.php'; ?>
        <div class="container-fluid">
            <div class="row">
                <?php include 'templates/sidebar.php'; ?>
                <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2">Edit Bill</h1>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">Bill Details</h5>
                                    <p class="card-text">
                                        <strong>Tenant:</strong> <?php echo htmlspecialchars($bill['name']); ?><br>
                                        <strong>Room:</strong> <?php echo htmlspecialchars($bill['room_number']); ?><br>
                                        <strong>Billing Month:</strong> <?php echo date('F Y', strtotime($bill['billing_month'])); ?><br>
                                        <strong>Amount Due:</strong> ₱<?php echo number_format($bill['amount_due'], 2); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-info">
                                <div class="card-body">
                                    <h5 class="card-title">Current Balance</h5>
                                    <p class="card-text display-5 text-<?php echo $balance > 0 ? 'danger' : 'success'; ?>">
                                        ₱<?php echo number_format($balance, 2); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <form action="bill_actions.php?action=edit" method="post">
                        <input type="hidden" name="id" value="<?php echo $bill['id']; ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="amount_paid" class="form-label">Amount Paid (₱)</label>
                                    <input type="number" step="0.01" class="form-control" id="amount_paid" name="amount_paid" value="<?php echo htmlspecialchars($bill['amount_paid']); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select class="form-control" id="payment_method" name="payment_method">
                                <option value="">Select method (optional)</option>
                                <option value="cash">Cash</option>
                                <option value="check">Check</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="gcash">GCash</option>
                                <option value="paypal">PayPal</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($bill['notes']); ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Current Status</label>
                            <p class="form-control-plaintext">
                                <span class="badge bg-<?php 
                                    if ($bill['status'] == 'paid') echo 'success';
                                    elseif ($bill['status'] == 'partial') echo 'warning';
                                    else echo 'secondary';
                                ?>">
                                    <?php echo ucfirst($bill['status']); ?>
                                </span>
                            </p>
                            <small class="text-muted">Status will be automatically updated based on payment information.</small>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="bills.php" class="btn btn-secondary">Cancel</a>
                    </form>
                </main>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
        </body>
        </html>
        <?php
        
    } elseif ($action === 'view') {
        // Show bill details view
        $id = $_GET['id'];
        $sql = "SELECT bills.*, tenants.name, tenants.email, tenants.phone, rooms.room_number FROM bills 
                JOIN tenants ON bills.tenant_id = tenants.id 
                JOIN rooms ON bills.room_id = rooms.id 
                WHERE bills.id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['id' => $id]);
        $bill = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$bill) {
            header("location: bills.php");
            exit;
        }
        
        $balance = $bill['amount_due'] - $bill['amount_paid'];
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>View Bill</title>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="public/css/style.css">
            <style>
                .invoice { max-width: 800px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; }
                .invoice-header { border-bottom: 2px solid #333; margin-bottom: 20px; padding-bottom: 10px; }
                .invoice-footer { border-top: 1px solid #ddd; margin-top: 20px; padding-top: 10px; }
            </style>
        </head>
        <body>
        <?php include 'templates/header.php'; ?>
        <div class="container-fluid">
            <div class="row">
                <?php include 'templates/sidebar.php'; ?>
                <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2">Bill Invoice</h1>
                        <a href="bills.php" class="btn btn-secondary">Back to Bills</a>
                    </div>
                    
                    <div class="invoice">
                        <div class="invoice-header">
                            <h2>BOARDING HOUSE INVOICE</h2>
                            <p class="text-muted">Invoice #<?php echo str_pad($bill['id'], 5, '0', STR_PAD_LEFT); ?></p>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6>Bill To:</h6>
                                <p>
                                    <strong><?php echo htmlspecialchars($bill['name']); ?></strong><br>
                                    Room <?php echo htmlspecialchars($bill['room_number']); ?><br>
                                    Email: <?php echo htmlspecialchars($bill['email']); ?><br>
                                    Phone: <?php echo htmlspecialchars($bill['phone']); ?>
                                </p>
                            </div>
                            <div class="col-md-6 text-end">
                                <p>
                                    <strong>Billing Period:</strong> <?php echo date('F Y', strtotime($bill['billing_month'])); ?><br>
                                    <strong>Invoice Date:</strong> <?php echo date('M d, Y'); ?><br>
                                    <strong>Stay Duration:</strong> <?php
                                        // Fetch stay duration, room rate, and calculate total cost
                                        $stay_stmt = $conn->prepare("SELECT checkin_date, checkout_date FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                        $stay_stmt->execute(['tenant_id' => $bill['tenant_id'], 'room_id' => $bill['room_id']]);
                                        $stay = $stay_stmt->fetch(PDO::FETCH_ASSOC);
                                        $room_stmt = $conn->prepare("SELECT rate FROM rooms WHERE id = :room_id");
                                        $room_stmt->execute(['room_id' => $bill['room_id']]);
                                        $room = $room_stmt->fetch(PDO::FETCH_ASSOC);
                                        $room_rate = $room ? floatval($room['rate']) : 0;
                                        $total_cost = null;
                                        $nights = null;
                                        if ($stay && $stay['checkin_date'] && $stay['checkout_date']) {
                                            echo date('M d, Y', strtotime($stay['checkin_date'])) . ' - ' . date('M d, Y', strtotime($stay['checkout_date']));
                                            $checkin = new DateTime($stay['checkin_date']);
                                            $checkout = new DateTime($stay['checkout_date']);
                                            $nights = $checkin->diff($checkout)->days;
                                            $total_cost = $nights * $room_rate;
                                        } else {
                                            echo 'N/A';
                                        }
                                    ?><br>
                                    <strong>Status:</strong> <span class="badge bg-<?php 
                                        if ($bill['status'] == 'paid') echo 'success';
                                        elseif ($bill['status'] == 'partial') echo 'warning';
                                        else echo 'secondary';
                                    ?>"><?php echo ucfirst($bill['status']); ?></span>
                                </p>
                            </div>
                        </div>
                        
                        <table class="table">
                            <tbody>
                                <tr>
                                    <td><strong>Total Cost (Room Only)</strong></td>
                                    <td class="text-end">
                                        <?php
                                        if ($total_cost !== null) {
                                            echo '<strong>₱' . number_format($total_cost, 2) . '</strong>';
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Downpayment</strong></td>
                                    <td class="text-end">
                                        <?php
                                        // Fetch downpayment (first payment transaction for this bill)
                                        $dp_stmt = $conn->prepare("SELECT payment_amount FROM payment_transactions WHERE bill_id = :bill_id ORDER BY payment_date ASC, id ASC LIMIT 1");
                                        $dp_stmt->execute(['bill_id' => $bill['id']]);
                                        $downpayment = $dp_stmt->fetchColumn();
                                        $downpayment = $downpayment ? floatval($downpayment) : 0.0;
                                        echo '₱' . number_format($downpayment, 2);
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Room Balance After Downpayment</strong></td>
                                    <td class="text-end">
                                        <?php
                                        $room_balance = ($total_cost !== null ? $total_cost : 0) - $downpayment;
                                        echo '<strong>₱' . number_format($room_balance, 2) . '</strong>';
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Total Additional Charges</strong></td>
                                    <td class="text-end">
                                        <?php
                                        $charges_stmt = $conn->prepare("SELECT SUM(cost) as total_charges FROM maintenance_requests WHERE tenant_id = :tenant_id AND status = 'completed' AND cost > 0");
                                        $charges_stmt->execute(['tenant_id' => $bill['tenant_id']]);
                                        $total_charges = $charges_stmt->fetchColumn();
                                        $total_charges = $total_charges ? floatval($total_charges) : 0.0;
                                        echo '₱' . number_format($total_charges, 2);
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Grand Total Due</strong></td>
                                    <td class="text-end">
                                        <?php
                                        $grand_total_due = $room_balance + $total_charges;
                                        echo '<strong>₱' . number_format($grand_total_due, 2) . '</strong>';
                                        ?>
                                    </td>
                                </tr>
                                <tr class="table-active">
                                    <td><strong>Status</strong></td>
                                    <td class="text-end">
                                        <?php
                                        if ($bill['status'] === 'paid') {
                                            echo '<span class="badge bg-success">Paid</span> ₱' . number_format($grand_total_due, 2);
                                        } else {
                                            echo '<span class="badge bg-warning text-dark">Unpaid</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <?php if ($bill['notes']): ?>
                        <div class="invoice-footer">
                            <h6>Notes:</h6>
                            <p><?php echo nl2br(htmlspecialchars($bill['notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="invoice-footer text-muted" style="font-size: 0.9em;">
                            <p>Thank you for your business.</p>
                            <?php if ($bill['paid_date']): ?>
                            <p>Paid on: <?php echo date('M d, Y', strtotime($bill['paid_date'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <button class="btn btn-primary" onclick="window.print()">Print Invoice</button>
                        <a href="bill_actions.php?action=edit&id=<?php echo $bill['id']; ?>" class="btn btn-outline-primary">Edit Payment</a>
                        <a href="bills.php" class="btn btn-secondary">Back to Bills</a>
                    </div>
                </main>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
        </body>
        </html>
        <?php
    }
}
?>
