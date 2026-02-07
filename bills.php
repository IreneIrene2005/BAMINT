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

                    // CHECK: Is this an advance/move-in payment? Handle both partial (downpayment) and full payment
                    if (strpos($payment_info['notes'], 'ADVANCE PAYMENT') !== false) {
                        if ($bill_status === 'paid') {
                            // Full payment: assign tenant, mark active, set room occupied, approve request
                            $tenant_update = $conn->prepare("
                                UPDATE tenants 
                                SET status = 'active', start_date = NOW(), room_id = :room_id
                                WHERE id = :tenant_id
                            ");
                            $tenant_update->execute(['tenant_id' => $payment_info['tenant_id'], 'room_id' => $payment_info['room_id']]);

                            $room_update = $conn->prepare("
                                UPDATE rooms 
                                SET status = 'occupied'
                                WHERE id = :room_id
                            ");
                            $room_update->execute(['room_id' => $payment_info['room_id']]);

                            $request_update = $conn->prepare("
                                UPDATE room_requests 
                                SET status = 'approved'
                                WHERE tenant_id = :tenant_id AND room_id = :room_id AND status = 'pending_payment'
                            ");
                            $request_update->execute([
                                'tenant_id' => $payment_info['tenant_id'],
                                'room_id' => $payment_info['room_id']
                            ]);
                        } elseif ($bill_status === 'partial') {
                            // Partial/downpayment: assign room to tenant and mark room as 'booked' (not yet occupied)
                            $tenant_update = $conn->prepare("
                                UPDATE tenants 
                                SET room_id = :room_id
                                WHERE id = :tenant_id
                            ");
                            $tenant_update->execute(['tenant_id' => $payment_info['tenant_id'], 'room_id' => $payment_info['room_id']]);

                            $room_update = $conn->prepare("
                                UPDATE rooms 
                                SET status = 'booked'
                                WHERE id = :room_id
                            ");
                            $room_update->execute(['room_id' => $payment_info['room_id']]);
                        }
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
$view = isset($_GET['view']) ? $_GET['view'] : 'active'; // 'active' or 'archive'

// Build the SQL query with search and filter for ACTIVE bills
$sql = "SELECT bills.*, tenants.name, tenants.email, rooms.room_number FROM bills 
        LEFT JOIN tenants ON bills.tenant_id = tenants.id 
        LEFT JOIN rooms ON bills.room_id = rooms.id 
        WHERE 1=1";

// Simulation: force month display to October 2025 (presentation only)
$simulate_month = true;
$simulated_month_date = '2025-10-01';

// Filter: Exclude paid bills older than 7 days (those go to archive) AND exclude cancelled bills
$sql .= " AND NOT (bills.status = 'paid' AND DATE_ADD(bills.updated_at, INTERVAL 7 DAY) < NOW())";
$sql .= " AND bills.status != 'cancelled'";

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

$sql .= " GROUP BY bills.id ORDER BY bills.billing_month DESC, tenants.name ASC";

// Build archive query (paid bills older than 7 days OR cancelled bills)
$archive_sql = "SELECT bills.*, tenants.name, tenants.email, rooms.room_number FROM bills 
        LEFT JOIN tenants ON bills.tenant_id = tenants.id 
        LEFT JOIN rooms ON bills.room_id = rooms.id 
        WHERE (bills.status = 'paid' AND DATE_ADD(bills.updated_at, INTERVAL 7 DAY) < NOW()) OR bills.status = 'cancelled'";

if ($search) {
    $archive_sql .= " AND (tenants.name LIKE :search OR tenants.email LIKE :search OR rooms.room_number LIKE :search)";
}

if ($filter_tenant) {
    $archive_sql .= " AND bills.tenant_id = :tenant_id";
}

if ($filter_month) {
    $archive_sql .= " AND DATE_FORMAT(bills.billing_month, '%Y-%m') = :month";
}

$archive_sql .= " GROUP BY bills.id ORDER BY bills.billing_month DESC, tenants.name ASC";

$stmt = $conn->prepare($sql);
$archive_stmt = $conn->prepare($archive_sql);

if ($search) {
    $search_param = "%$search%";
    $stmt->bindParam(':search', $search_param);
    $archive_stmt->bindParam(':search', $search_param);
}

if ($filter_status) {
    $stmt->bindParam(':status', $filter_status);
}

if ($filter_tenant) {
    $stmt->bindParam(':tenant_id', $filter_tenant);
    $archive_stmt->bindParam(':tenant_id', $filter_tenant);
}

if ($filter_month) {
    $stmt->bindParam(':month', $filter_month);
    $archive_stmt->bindParam(':month', $filter_month);
}

$stmt->execute();
$archive_stmt->execute();
$bills = $stmt;
$archive_bills = $archive_stmt;

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
            pt.created_at,
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
        ORDER BY pt.created_at DESC
    ");
    $pending_payments_stmt->execute();
    $pending_payments = $pending_payments_stmt->fetchAll(PDO::FETCH_ASSOC);
    $pending_count = count($pending_payments);
} catch (Exception $e) {
    $pending_payments = [];
    $pending_count = 0;
}

// Get list of active tenants who need bills created for next month
// Only include tenants who have PAID their current month bill
$tenants_needing_bills = [];
try {
    $current_month = date('Y-m-01');
    $next_month = date('Y-m-01', strtotime('+1 month'));
    
    $stmt = $conn->prepare("
        SELECT t.id, t.name, t.room_id, r.rate, r.room_number
        FROM tenants t
        LEFT JOIN rooms r ON t.room_id = r.id
        WHERE t.status = 'active' AND t.room_id IS NOT NULL
        AND EXISTS (
            SELECT 1 FROM bills b
            WHERE b.tenant_id = t.id 
            AND b.billing_month = :current_month 
            AND (b.status = 'paid' OR b.status = 'partial')
        )
        AND NOT EXISTS (
            SELECT 1 FROM bills 
            WHERE tenant_id = t.id AND billing_month = :next_month
        )
        ORDER BY t.name ASC
    ");
    $stmt->execute([
        'current_month' => $current_month,
        'next_month' => $next_month
    ]);
    $tenants_needing_bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tenants_needing_bills = [];
}

// Get walk-in customers waiting for room selection & payment (based on pending room_requests)
$walk_in_customers = [];
try {
    $walk_in_stmt = $conn->prepare("
        SELECT 
            t.id,
            t.name,
            t.email,
            t.phone,
            t.status,
            rr.id as room_request_id,
            rr.room_id,
            rr.checkin_date,
            rr.checkout_date,
            b.id as bill_id,
            b.amount_due,
            b.amount_paid,
            b.notes,
            rr.request_date as requested_at,
            t.created_at
        FROM tenants t
        JOIN room_requests rr ON rr.tenant_id = t.id AND rr.status = 'pending_payment'
        LEFT JOIN bills b ON b.tenant_id = t.id AND b.room_id = rr.room_id AND b.status = 'pending'
        WHERE t.status = 'inactive'
        ORDER BY rr.request_date DESC
    ");
    $walk_in_stmt->execute();
    $walk_in_customers = $walk_in_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $walk_in_customers = [];
}

// Fetch active tenants for checkout
$checkout_tenants = [];
try {
    $stmt = $conn->prepare("
        SELECT t.id, t.name, t.email, t.phone, r.room_number, r.rate, t.start_date
        FROM tenants t
        LEFT JOIN rooms r ON t.room_id = r.id
        WHERE t.status = 'active' AND t.room_id IS NOT NULL
        ORDER BY t.name ASC
    ");
    $stmt->execute();
    $checkout_tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $checkout_tenants = [];
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
                <h1 class="h2">Payments</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-sm btn-danger me-2" data-bs-toggle="modal" data-bs-target="#checkoutModal">
                        <i class="bi bi-box-arrow-right"></i>
                        Check Out
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary me-2" data-bs-toggle="modal" data-bs-target="#generateBillsModal">
                        <i class="bi bi-plus-circle"></i>
                        Generate Bills
                    </button>
                    <button type="button" class="btn btn-sm btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addNewBillModal">
                        <i class="bi bi-plus-circle"></i>
                        Add New Bill
                    </button>
                </div>
            </div>

            <!-- Billing Summary Cards (Live Data) -->
            <div class="row mb-4">
                <?php
                // Cache table rows and sum Grand Total Due for summary card
                $table_rows = [];
                $grand_total_due_sum = 0.0;
                $grand_total_paid_sum = 0.0;

                $bills->execute();
                while($row = $bills->fetch(PDO::FETCH_ASSOC)) {
                    // If this is an advance payment bill with no verified/admin-approved payments yet,
                    // do not include it in the main table so the customer is not recorded before admin approval.
                    $is_advance = false;
                    if (!empty($row['notes']) && stripos($row['notes'], 'ADVANCE PAYMENT') !== false) {
                        $is_advance = true;
                        try {
                            $ver_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as verified_paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified','approved')");
                            $ver_stmt->execute(['bill_id' => $row['id']]);
                            $verified_paid = floatval($ver_stmt->fetchColumn());
                        } catch (Exception $e) {
                            $verified_paid = 0.0;
                        }
                        if ($verified_paid <= 0) {
                            // Skip this bill for now
                            continue;
                        }
                    }
                    $room_req_stmt = $conn->prepare("SELECT checkin_date, checkout_date FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                    $room_req_stmt->execute(['tenant_id' => $row['tenant_id'], 'room_id' => $row['room_id']]);
                    $dates = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                    $checkin = $dates ? $dates['checkin_date'] : null;
                    $checkout = $dates ? $dates['checkout_date'] : null;
                    // Calculate amount paid from verified/approved payment transactions (includes checkout payments)
                    $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified', 'approved')");
                    $sum_stmt->execute(['bill_id' => $row['id']]);
                    $amount_paid = floatval($sum_stmt->fetchColumn());
                    
                    // Fetch check-in payment method (first/earliest payment)
                    $pm_checkin_stmt = $conn->prepare("SELECT payment_method FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified', 'approved') ORDER BY created_at ASC, id ASC LIMIT 1");
                    $pm_checkin_stmt->execute(['bill_id' => $row['id']]);
                    $payment_method_checkin = $pm_checkin_stmt->fetchColumn();
                    
                    $charges_stmt = $conn->prepare("SELECT SUM(cost) as total_charges FROM maintenance_requests WHERE tenant_id = :tenant_id AND status = 'completed' AND cost > 0");
                    $charges_stmt->execute(['tenant_id' => $row['tenant_id']]);
                    $total_charges = $charges_stmt->fetchColumn();
                    $total_charges = $total_charges ? floatval($total_charges) : 0.0;
                    
                    // Check if tenant is inactive (checked out) to determine status
                    $tenant_status_stmt = $conn->prepare("SELECT status FROM tenants WHERE id = :tenant_id");
                    $tenant_status_stmt->execute(['tenant_id' => $row['tenant_id']]);
                    $tenant_status = $tenant_status_stmt->fetchColumn();
                    
                    // Fetch check-out payment method (only if tenant is inactive/checked out)
                    $payment_method_checkout = null;
                    if ($tenant_status === 'inactive') {
                        $pm_checkout_stmt = $conn->prepare("SELECT payment_method FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified', 'approved') ORDER BY created_at DESC, id DESC LIMIT 1");
                        $pm_checkout_stmt->execute(['bill_id' => $row['id']]);
                        $payment_method_checkout = $pm_checkout_stmt->fetchColumn();
                    }
                    
                    // Get bill status for display
                    $bill_status_from_db = $row['status'];
                    
                    // Total billable = Bill amount + Additional charges
                    $bill_amount = floatval($row['amount_due']);
                    $total_billable = $bill_amount + $total_charges;
                    
                    // Grand Total Due calculation:
                    // If bill is cancelled, Grand Total Due = 0 (cancellation recorded)
                    // If tenant is inactive (checked out), Grand Total Due = 0 (already paid)
                    // If tenant is active, Grand Total Due = Amount Paid + Total Additional Charges (total billable)
                    if ($bill_status_from_db === 'cancelled') {
                        // Bill has been cancelled - Grand Total Due = 0
                        $grand_total_due = 0;
                    } elseif ($tenant_status === 'inactive') {
                        // Customer has checked out - they paid, so Grand Total Due = 0
                        $grand_total_due = 0;
                    } else {
                        // Customer is still active - show total billable (amount paid + charges)
                        $grand_total_due = $amount_paid + $total_charges;
                    }
                    
                        // Always add grand_total_due for the active listing
                        $grand_total_due_sum += $grand_total_due;
                        // We'll compute total paid globally (independent of active/archive view)
                        $grand_total_paid_sum += $amount_paid; // temporary accumulation; will be replaced by global sum below
                    $table_rows[] = [
                        'checkin' => $checkin,
                        'checkout' => $checkout,
                        'name' => $row['name'],
                        'room_number' => $row['room_number'],
                        'amount_due' => $bill_amount,
                        'grand_total_due' => $grand_total_due,
                        'amount_paid' => $amount_paid,
                        'total_charges' => $total_charges,
                        'payment_method_checkin' => $payment_method_checkin,
                        'payment_method_checkout' => $payment_method_checkout,
                        'status' => $row['status'],
                        'tenant_status' => $tenant_status,
                        'bill_id' => $row['id'],
                        'tenant_id' => $row['tenant_id']
                    ];
                }
                ?>
                <!-- Room Count card removed -->
                <div class="col-md-6 col-sm-6 mb-3">
                    <div class="card metric-card bg-warning bg-opacity-10 h-100">
                        <div class="card-body text-center">
                            <div class="metric-label">Total Due</div>
                            <div class="metric-value text-warning">₱<?php echo number_format($grand_total_due_sum, 2); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-sm-6 mb-3">
                    <div class="card metric-card bg-info bg-opacity-10 h-100">
                        <div class="card-body text-center">
                            <div class="metric-label">Total Paid</div>
                            <?php
                            // Compute global paid amount from payment_transactions to keep Total Paid stable across views
                            try {
                                $global_paid_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as total_paid FROM payment_transactions WHERE payment_status IN ('verified','approved')");
                                $global_paid_stmt->execute();
                                $global_paid = floatval($global_paid_stmt->fetchColumn());
                            } catch (Exception $e) {
                                $global_paid = $grand_total_paid_sum; // fallback
                            }
                            ?>
                            <div class="metric-value text-info">₱<?php echo number_format($global_paid, 2); ?></div>
                        </div>
                    </div>
                </div>
                
            </div>

            <!-- Walk-in Customers Section -->
            <?php if (!empty($walk_in_customers)): ?>
            <div class="card border-info mb-4">
                <div class="card-header bg-info bg-opacity-10 border-info">
                    <h5 class="mb-0"><i class="bi bi-person-walking"></i> Walk-in Customers - Pending Payment & Room Selection</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Customer Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($walk_in_customers as $customer): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($customer['created_at'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#walkInModal<?php echo $customer['id']; ?>">
                                            <i class="bi bi-credit-card"></i> Process Payment & Room
                                        </button>
                                    </td>
                                </tr>

                                <!-- Walk-in Payment & Room Modal -->
                                <div class="modal fade" id="walkInModal<?php echo $customer['id']; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header bg-primary bg-opacity-10">
                                                <h5 class="modal-title">
                                                    <i class="bi bi-person-walking"></i> Process Walk-in Customer - <?php echo htmlspecialchars($customer['name']); ?>
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row mb-3">
                                                    <div class="col-md-6">
                                                        <h6>Customer Info</h6>
                                                        <p><strong><?php echo htmlspecialchars($customer['name']); ?></strong><br>
                                                        <?php echo htmlspecialchars($customer['email']); ?><br>
                                                        <?php echo htmlspecialchars($customer['phone']); ?></p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <h6>Next Steps</h6>
                                                        <p>1. Select a room and check-in/out dates<br>
                                                        2. Process payment (Full or Downpayment)<br>
                                                        3. Confirm booking</p>
                                                    </div>
                                                </div>

                                                <hr>

                                                <form method="POST" action="bill_actions.php?action=process_walk_in&tenant_id=<?php echo $customer['id']; ?>">
                                                    <div class="row mb-3">
                                                        <div class="col-md-6">
                                                            <label for="room_walk_in_<?php echo $customer['id']; ?>" class="form-label">Select Room <span class="text-danger">*</span></label>
                                                            <select class="form-control" name="room_id" id="room_walk_in_<?php echo $customer['id']; ?>" required>
                                                                <option value="">-- Choose a Room --</option>
                                                                <?php
                                                                $available_rooms_stmt = $conn->prepare("SELECT id, room_number, room_type, rate FROM rooms WHERE status = 'available' ORDER BY room_number ASC");
                                                                $available_rooms_stmt->execute();
                                                                while ($room = $available_rooms_stmt->fetch(PDO::FETCH_ASSOC)):
                                                                ?>
                                                                    <option value="<?php echo $room['id']; ?>">
                                                                        <?php echo htmlspecialchars($room['room_number']); ?> (<?php echo htmlspecialchars($room['room_type']); ?>) - ₱<?php echo number_format($room['rate'], 2); ?>/night
                                                                    </option>
                                                                <?php endwhile; ?>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="row mb-3">
                                                        <div class="col-md-6">
                                                            <label for="checkin_walk_in_<?php echo $customer['id']; ?>" class="form-label">Check-in Date <span class="text-danger">*</span></label>
                                                            <input type="date" class="form-control" name="checkin_date" id="checkin_walk_in_<?php echo $customer['id']; ?>" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label for="checkout_walk_in_<?php echo $customer['id']; ?>" class="form-label">Check-out Date <span class="text-danger">*</span></label>
                                                            <input type="date" class="form-control" name="checkout_date" id="checkout_walk_in_<?php echo $customer['id']; ?>" required>
                                                        </div>
                                                    </div>

                                                    <div class="row mb-3">
                                                        <div class="col-md-12">
                                                            <label class="form-label">Payment Option <span class="text-danger">*</span></label>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="payment_option" id="full_pay_<?php echo $customer['id']; ?>" value="full_payment" required>
                                                                <label class="form-check-label" for="full_pay_<?php echo $customer['id']; ?>">
                                                                    <strong>Full Payment</strong> - Pay entire stay amount upfront
                                                                </label>
                                                            </div>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="payment_option" id="down_pay_<?php echo $customer['id']; ?>" value="downpayment" required>
                                                                <label class="form-check-label" for="down_pay_<?php echo $customer['id']; ?>">
                                                                    <strong>Downpayment</strong> - Pay 50% now, balance on checkout
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="alert alert-info mb-3">
                                                        <small><i class="bi bi-info-circle"></i> <strong>Note:</strong> The customer will receive a payment link after you click "Generate Invoice". They can pay online.</small>
                                                    </div>
                                                </form>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <form method="POST" action="bill_actions.php?action=process_walk_in&tenant_id=<?php echo $customer['id']; ?>" style="display: inline;">
                                                    <input type="hidden" name="room_id" value="">
                                                    <input type="hidden" name="checkin_date" value="">
                                                    <input type="hidden" name="checkout_date" value="">
                                                    <input type="hidden" name="payment_option" value="">
                                                    <button type="submit" class="btn btn-primary" onclick="document.querySelector('#walkInModal<?php echo $customer['id']; ?> form').submit();">
                                                        <i class="bi bi-check-circle"></i> Generate Invoice & Payment Link
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $view === 'active' ? 'active' : ''; ?>" onclick="switchView('active')" type="button">
                        <i class="bi bi-receipt"></i> Payment Transaction
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $view === 'archive' ? 'active' : ''; ?>" onclick="switchView('archive')" type="button">
                        <i class="bi bi-archive"></i> Archive 
                        <?php 
                        // Count archived bills
                        $archive_count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM bills WHERE status = 'paid' AND DATE_ADD(updated_at, INTERVAL 7 DAY) < NOW()");
                        $archive_count_stmt->execute();
                        $archive_count = $archive_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
                        ?>
                        <span class="badge bg-secondary"><?php echo $archive_count; ?></span>
                    </button>
                </li>
            </ul>

            <!-- Notification: Bills to Create Reminder -->
            <!-- ...existing code... -->

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
                        // Recompute totals including submitted (pending) payments so admin sees real-time totals
                        $bill_id_for_calc = isset($payment['bill_id']) ? intval($payment['bill_id']) : 0;
                        $total_paid_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status != 'rejected'");
                        $total_paid_stmt->execute(['bill_id' => $bill_id_for_calc]);
                        $total_paid_now = floatval($total_paid_stmt->fetchColumn());
                        $balance = floatval($payment['amount_due']) - $total_paid_now;
                        // Determine safe billing month display
                        $billing_display = 'Not set';
                        if (!empty($payment['billing_month']) && strtotime($payment['billing_month']) !== false && strtotime($payment['billing_month']) > 0) {
                            $billing_display = date('M Y', strtotime($payment['billing_month']));
                        } else {
                            try {
                                // Try to derive from bill row (created_at) or room_request checkin_date
                                $brrow = null;
                                if (!empty($payment['bill_id'])) {
                                    $br = $conn->prepare("SELECT id, room_id, tenant_id, created_at FROM bills WHERE id = :id LIMIT 1");
                                    $br->execute(['id' => $payment['bill_id']]);
                                    $brrow = $br->fetch(PDO::FETCH_ASSOC);
                                }
                                if (!empty($brrow) && !empty($brrow['room_id']) && !empty($brrow['tenant_id'])) {
                                    $rq = $conn->prepare("SELECT checkin_date FROM room_requests WHERE tenant_id = :tid AND room_id = :rid ORDER BY id DESC LIMIT 1");
                                    $rq->execute(['tid' => $brrow['tenant_id'], 'rid' => $brrow['room_id']]);
                                    $rqrow = $rq->fetch(PDO::FETCH_ASSOC);
                                    if ($rqrow && !empty($rqrow['checkin_date']) && strtotime($rqrow['checkin_date']) > 0) {
                                        $billing_display = date('M Y', strtotime($rqrow['checkin_date']));
                                    } elseif (!empty($brrow['created_at']) && strtotime($brrow['created_at']) > 0) {
                                        $billing_display = date('M Y', strtotime($brrow['created_at']));
                                    }
                                }
                            } catch (Exception $e) {
                                // leave as Not set
                            }
                        }
                    ?>
                    <div class="col-md-6 mb-2">
                        <div class="card border-warning h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="card-title mb-1"><?php echo htmlspecialchars($payment['tenant_name']); ?></h6>
                                        <p class="card-text mb-1 text-muted small">
                                            <i class="bi bi-door"></i> Room: <?php echo htmlspecialchars($payment['room_number'] ?? 'N/A'); ?> |
                                            <i class="bi bi-calendar"></i> <?php echo htmlspecialchars($billing_display); ?>
                                        </p>
                                        <p class="card-text mb-1 text-muted small">
                                                        <i class="bi bi-credit-card"></i> <?php
                                                        $pm = $payment['payment_method'] ?? '';
                                                        $pm_norm = strtolower(trim((string)$pm));
                                                        if ($pm_norm === 'gcash') $pm_display = 'GCash';
                                                        elseif ($pm_norm === 'paymaya') $pm_display = 'PayMaya';
                                                        elseif ($pm_norm === 'cash') $pm_display = 'Cash';
                                                        else $pm_display = $pm ? ucfirst($pm) : '-';
                                                        echo htmlspecialchars($pm_display);
                                                        ?>
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
                                            <strong>Method:</strong> <?php
                                            $pm = $payment['payment_method'] ?? '';
                                            $pm_norm = strtolower(trim((string)$pm));
                                            if ($pm_norm === 'gcash') $pm_display = 'GCash';
                                            elseif ($pm_norm === 'paymaya') $pm_display = 'PayMaya';
                                            elseif ($pm_norm === 'cash') $pm_display = 'Cash';
                                            else $pm_display = $pm ? ucfirst($pm) : '-';
                                            echo htmlspecialchars($pm_display);
                                            ?><br>
                                            <strong>Submitted:</strong> <?php echo date('M d, Y • H:i A', strtotime($payment['created_at'])); ?></p>
                                        </div>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-12">
                                            <h6 class="text-muted">Billing Information</h6>
                                            <?php
                                            // Compute live billing info to ensure accuracy
                                            $bill_id = isset($payment['bill_id']) ? intval($payment['bill_id']) : 0;
                                            $bill_amount_due = isset($payment['amount_due']) ? floatval($payment['amount_due']) : 0.0;
                                            // Recompute already paid including submitted (pending) payments so admin sees real-time totals
                                            $paid_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status != 'rejected'");
                                            $paid_stmt->execute(['bill_id' => $bill_id]);
                                            $already_paid = floatval($paid_stmt->fetchColumn());
                                            $balance_now = $bill_amount_due - $already_paid;

                                            // Determine billing month display: prefer billing_month if valid, else derive from room request or bill created_at
                                            $billing_display = 'Not set';
                                            if (!empty($payment['billing_month']) && strtotime($payment['billing_month']) !== false && strtotime($payment['billing_month']) > 0) {
                                                $billing_display = date('F Y', strtotime($payment['billing_month']));
                                            } else {
                                                // Try to derive from room_requests (checkin date)
                                                try {
                                                    if (!empty($payment['bill_id'])) {
                                                        $br = $conn->prepare("SELECT b.room_id, b.tenant_id, b.created_at FROM bills b WHERE b.id = :id LIMIT 1");
                                                        $br->execute(['id' => $payment['bill_id']]);
                                                        $brrow = $br->fetch(PDO::FETCH_ASSOC);
                                                    } else {
                                                        $brrow = null;
                                                    }
                                                    if (!empty($brrow) && !empty($brrow['room_id']) && !empty($brrow['tenant_id'])) {
                                                        $rq = $conn->prepare("SELECT checkin_date FROM room_requests WHERE tenant_id = :tid AND room_id = :rid ORDER BY id DESC LIMIT 1");
                                                        $rq->execute(['tid' => $brrow['tenant_id'], 'rid' => $brrow['room_id']]);
                                                        $rqrow = $rq->fetch(PDO::FETCH_ASSOC);
                                                        if ($rqrow && !empty($rqrow['checkin_date']) && strtotime($rqrow['checkin_date']) > 0) {
                                                            $billing_display = date('F Y', strtotime($rqrow['checkin_date']));
                                                        } elseif (!empty($brrow['created_at']) && strtotime($brrow['created_at']) > 0) {
                                                            $billing_display = date('F Y', strtotime($brrow['created_at']));
                                                        }
                                                    }
                                                } catch (Exception $e) {
                                                    // fallback below
                                                }
                                            }
                                            ?>
                                            <p>
                                                <strong>Billing Month:</strong> <?php echo htmlspecialchars($billing_display); ?><br>
                                                <strong>Amount Due:</strong> ₱<?php echo number_format($bill_amount_due, 2); ?><br>
                                                <strong>Already Paid:</strong> ₱<?php echo number_format($already_paid, 2); ?><br>
                                                <strong>Balance:</strong> ₱<?php echo number_format($balance_now, 2); ?>
                                            </p>
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
            <div id="activeView" class="tab-view" style="display: <?php echo $view === 'active' ? 'block' : 'none'; ?>;">
                <div class="row mb-3">
                    <div class="col-md-12">
                        <form method="GET" class="row g-3 align-items-end">
                            <input type="hidden" name="view" value="active">
                            <div class="col-md-3">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" class="form-control" id="search" name="search" placeholder="Customer, email, room..." value="<?php echo htmlspecialchars($search); ?>">
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
                                <label for="tenant" class="form-label">Customer</label>
                                <select class="form-control" id="tenant" name="tenant">
                                    <option value="">All Customers</option>
                                    <?php 
                                    $all_tenants_reset = $conn->query("SELECT id, name FROM tenants WHERE status = 'active' ORDER BY name ASC");
                                    while($tenant = $all_tenants_reset->fetch(PDO::FETCH_ASSOC)): ?>
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
                                <a href="bills.php?view=active" class="btn btn-secondary w-100">Clear</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>Stay Dates</th>
                                <th>Customer</th>
                                <th>Room</th>
                                <th>Grand Total Due (₱)</th>
                                <th>Amount Paid (₱)</th>
                                <th>Payment Method Check-in</th>
                                <th>Payment Method Check-out</th>
                                <th>Total Additional Charges (₱)</th>
                                <th>Status</th>
                                <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($table_rows as $row) : ?>
                        <tr>
                            <td>
                                <?php if ($row['checkin'] && $row['checkout']): ?>
                                    <?php echo date('M d, Y', strtotime($row['checkin'])); ?> - <?php echo date('M d, Y', strtotime($row['checkout'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['room_number']); ?></td>
                            <td><?php echo htmlspecialchars(number_format($row['grand_total_due'], 2)); ?></td>
                            <td><?php echo htmlspecialchars(number_format($row['amount_paid'], 2)); ?></td>
                            <td><?php
                                    $pm_checkin = $row['payment_method_checkin'] ?? '';
                                    $pm_norm = strtolower(trim((string)$pm_checkin));
                                    if ($pm_norm === 'gcash') $pm_display = 'GCash';
                                    elseif ($pm_norm === 'paymaya') $pm_display = 'PayMaya';
                                    elseif ($pm_norm === 'cash') $pm_display = 'Cash';
                                    else $pm_display = $pm_checkin ? ucfirst($pm_checkin) : '-';
                                    echo htmlspecialchars($pm_display);
                                ?></td>
                            <td><?php
                                    $pm_checkout = $row['payment_method_checkout'] ?? '';
                                    $pm_norm = strtolower(trim((string)$pm_checkout));
                                    if ($pm_norm === 'gcash') $pm_display = 'GCash';
                                    elseif ($pm_norm === 'paymaya') $pm_display = 'PayMaya';
                                    elseif ($pm_norm === 'cash') $pm_display = 'Cash';
                                    else $pm_display = $pm_checkout ? ucfirst($pm_checkout) : '-';
                                    echo htmlspecialchars($pm_display);
                                ?></td>
                            <td><?php echo htmlspecialchars(number_format($row['total_charges'], 2)); ?></td>
                            <td>
                                <?php
                                // Status determination:
                                // "Cancelled" if bill status is cancelled
                                // "Paid" only if tenant is inactive (checked out)
                                // Otherwise based on payment status: "Partial" if payment exists, "Pending" if no payment
                                $status_label = '';
                                $status_class = '';
                                
                                if ($row['status'] === 'cancelled') {
                                    // Bill has been cancelled
                                    $status_label = 'Cancelled';
                                    $status_class = 'danger';
                                } elseif ($row['tenant_status'] === 'inactive') {
                                    // Customer has checked out - mark as Paid
                                    $status_label = 'Paid';
                                    $status_class = 'success';
                                } elseif ($row['amount_paid'] > 0) {
                                    // Has some payment but not checked out
                                    $status_label = 'Partial';
                                    $status_class = 'warning';
                                } else {
                                    // No payment yet
                                    $status_label = 'Pending';
                                    $status_class = 'secondary';
                                }
                                ?>
                                <span class="badge bg-<?php echo $status_class; ?>">
                                    <?php echo $status_label; ?>
                                </span>
                            </td>
                            <td>
                                <a href="bill_actions.php?action=edit&id=<?php echo $row['bill_id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil-square"></i></a>
                                <a href="bill_actions.php?action=view&id=<?php echo $row['bill_id']; ?>" class="btn btn-sm btn-outline-info" title="View Details"><i class="bi bi-eye"></i></a>
                                <a href="bill_actions.php?action=archive&id=<?php echo $row['bill_id']; ?>" class="btn btn-sm btn-outline-warning" title="Archive" onclick="return confirm('Archive this bill?');"><i class="bi bi-archive"></i></a>
                                <a href="bill_actions.php?action=delete&id=<?php echo $row['bill_id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure?');"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Archive View -->
            <div id="archiveView" class="tab-view" style="display: <?php echo $view === 'archive' ? 'block' : 'none'; ?>;">
                <div class="row mb-3">
                    <div class="col-md-12">
                        <form method="GET" class="row g-3 align-items-end">
                            <input type="hidden" name="view" value="archive">
                            <div class="col-md-3">
                                <label for="search_archive" class="form-label">Search</label>
                                <input type="text" class="form-control" id="search_archive" name="search" placeholder="Customer, email, room..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="tenant_archive" class="form-label">Customer</label>
                                <select class="form-control" id="tenant_archive" name="tenant">
                                    <option value="">All Customers</option>
                                    <?php 
                                    $all_tenants_reset2 = $conn->query("SELECT id, name FROM tenants WHERE status = 'active' ORDER BY name ASC");
                                    while($tenant = $all_tenants_reset2->fetch(PDO::FETCH_ASSOC)): ?>
                                        <option value="<?php echo $tenant['id']; ?>" <?php echo $filter_tenant == $tenant['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tenant['name']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="month_archive" class="form-label">Month</label>
                                <input type="month" class="form-control" id="month_archive" name="month" value="<?php echo htmlspecialchars($filter_month); ?>">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Search</button>
                                <a href="bills.php?view=archive" class="btn btn-secondary w-100 mt-2">Clear</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>Billing Month</th>
                                <th>Customer</th>
                                <th>Room</th>
                                <th>Amount Due (₱)</th>
                                <th>Amount Paid (₱)</th>
                                <th>Status</th>
                                <th>Paid Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $archive_bills->execute();
                            while($row = $archive_bills->fetch(PDO::FETCH_ASSOC)) : ?>
                            <tr>
                                <td><?php 
                                    // If simulation flag is set, force month display to October 2025
                                    if (!empty($simulate_month)) {
                                        echo 'October 2025';
                                    } else {
                                        // Safely format billing_month - handle various date formats
                                        if (!empty($row['billing_month'])) {
                                            $bm = strtotime($row['billing_month']);
                                            // If parsing failed OR the year looks invalid, fallback to paid_date/updated_at
                                            if ($bm !== false) {
                                                $year = (int)date('Y', $bm);
                                                if ($year > 1970) {
                                                    echo htmlspecialchars(date('F Y', $bm));
                                                } else {
                                                    // fallback
                                                    if (!empty($row['paid_date']) && $row['paid_date'] !== '0000-00-00 00:00:00') {
                                                        echo htmlspecialchars(date('F Y', strtotime($row['paid_date'])));
                                                    } else {
                                                        echo htmlspecialchars(date('F Y', strtotime($row['updated_at'])));
                                                    }
                                                }
                                            } else {
                                                // fallback when strtotime fails
                                                if (!empty($row['paid_date']) && $row['paid_date'] !== '0000-00-00 00:00:00') {
                                                    echo htmlspecialchars(date('F Y', strtotime($row['paid_date'])));
                                                } else {
                                                    echo htmlspecialchars(date('F Y', strtotime($row['updated_at'])));
                                                }
                                            }
                                        } else {
                                            // No billing_month - use paid_date/updated_at
                                            if (!empty($row['paid_date']) && $row['paid_date'] !== '0000-00-00 00:00:00') {
                                                echo htmlspecialchars(date('F Y', strtotime($row['paid_date'])));
                                            } else {
                                                echo htmlspecialchars(date('F Y', strtotime($row['updated_at'])));
                                            }
                                        }
                                    }
                                ?></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['room_number']); ?></td>
                                <td><?php echo htmlspecialchars(number_format($row['amount_due'], 2)); ?></td>
                                <td><?php echo htmlspecialchars(number_format($row['amount_paid'], 2)); ?></td>
                                <td>
                                    <?php
                                    // Show correct status for archive view
                                    if ($row['status'] === 'cancelled') {
                                        echo '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Cancelled</span>';
                                    } else {
                                        echo '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Paid</span>';
                                    }
                                    ?>
                                </td>
                                <td><?php 
                                    // If simulation is enabled, force paid date to the simulated date (October 2025)
                                    if (!empty($simulate_month) && !empty($simulated_month_date)) {
                                        echo htmlspecialchars(date('M d, Y', strtotime($simulated_month_date)));
                                    } else {
                                        // Use paid_date if available, otherwise use updated_at
                                        if (!empty($row['paid_date']) && $row['paid_date'] !== '0000-00-00 00:00:00') {
                                            echo htmlspecialchars(date('M d, Y', strtotime($row['paid_date'])));
                                        } else {
                                            echo htmlspecialchars(date('M d, Y', strtotime($row['updated_at'])));
                                        }
                                    }
                                ?></td>
                                <td>
                                    <a href="bill_actions.php?action=view&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-info" title="View Details"><i class="bi bi-eye"></i></a>
                                    <a href="bill_actions.php?action=restore&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-success" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <?php 
                $check_empty = $conn->prepare("SELECT COUNT(*) as count FROM bills WHERE status = 'paid' AND DATE_ADD(updated_at, INTERVAL 7 DAY) < NOW()");
                $check_empty->execute();
                $empty_count = $check_empty->fetch(PDO::FETCH_ASSOC)['count'];
                if ($empty_count == 0): ?>
                <div class="alert alert-secondary text-center">
                    <i class="bi bi-inbox"></i> No archived bills yet. Paid bills older than 7 days will appear here.
                </div>
                <?php endif; ?>
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
                        <label for="tenant_id" class="form-label">Select Customer</label>
                        <select class="form-control" id="tenant_id" name="tenant_id" required onchange="loadTenantDetails()">
                                <option value="">-- Choose a customer --</option>
                <?php 
                $stmt2 = $conn->query("SELECT t.id, t.name, t.start_date, r.room_type, r.rate, r.room_number FROM tenants t LEFT JOIN rooms r ON t.room_id = r.id WHERE t.status = 'active' ORDER BY t.name ASC");
                while($tenant = $stmt2->fetch(PDO::FETCH_ASSOC)): ?>
                    <option value="<?php echo $tenant['id']; ?>" data-room-type="<?php echo htmlspecialchars($tenant['room_type'] ?? 'N/A'); ?>" data-rate="<?php echo $tenant['rate'] ?? '0'; ?>" data-move-in="<?php echo $tenant['start_date'] ?? 'N/A'; ?>" data-room-number="<?php echo htmlspecialchars($tenant['room_number'] ?? 'N/A'); ?>">
                        <?php echo htmlspecialchars($tenant['name']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
          </div>

                    <!-- Customer Details Card -->
                    <div id="tenantDetailsCard" style="display: none; margin-bottom: 20px;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title mb-3">Customer Details</h6>
                <div class="row">
                  <div class="col-md-6">
                    <small class="text-muted d-block">Room Number</small>
                    <strong id="detailRoomNumber">-</strong>
                  </div>
                  <div class="col-md-6">
                    <small class="text-muted d-block">Room Type</small>
                    <strong id="detailRoomType">-</strong>
                  </div>
                </div>
                <hr style="margin: 10px 0;">
                <div class="row">
                  <div class="col-md-6">
                    <small class="text-muted d-block">Monthly Rate</small>
                    <strong id="detailRate" class="text-success">₱0.00</strong>
                  </div>
                  <div class="col-md-6">
                    <small class="text-muted d-block">Move-in Date</small>
                    <strong id="detailMoveIn">-</strong>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label for="billing_month_manual" class="form-label">Billing Month</label>
            <input type="month" class="form-control" id="billing_month_manual" name="billing_month" value="<?php echo date('Y-m'); ?>" required>
          </div>
          <div class="mb-3">
            <label for="amount_due" class="form-label">Amount Due (₱)</label>
            <input type="number" step="0.01" class="form-control" id="amount_due" name="amount_due" placeholder="Auto-filled from room rate" required>
            <small class="text-muted">Tip: Click the monthly rate above to auto-fill this field</small>
          </div>
          <div class="mb-3">
            <label for="due_date_manual" class="form-label">Due Date</label>
            <input type="date" class="form-control" id="due_date_manual" name="due_date" value="<?php echo date('Y-m-15'); ?>">
          </div>
          <button type="submit" class="btn btn-primary">Save Bill</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Add New Bill Modal -->
<div class="modal fade" id="addNewBillModal" tabindex="-1" aria-labelledby="addNewBillModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addNewBillModalLabel">Add New Bill</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="addNewBillForm" action="bill_actions.php?action=add_new_bill" method="post">
          
                    <!-- Note: Guest information is taken from selected booking -->

          <!-- Stay Details Section -->
          <div class="mb-4">
            <h6 class="text-primary border-bottom pb-2"><strong>Stay Details</strong></h6>
            
            <div class="mb-3">
              <label for="booking_room" class="form-label">Select Booked Room</label>
              <select class="form-control" id="booking_room" name="booking_room" onchange="loadBookingDetails()">
                <option value="">-- Select a booked room --</option>
              </select>
              <small class="text-muted">Shows rooms with confirmed bookings</small>
            </div>

            <!-- Booking Details Card -->
            <div id="bookingDetailsCard" style="display: none; margin-bottom: 10px;">
              <div class="card bg-light">
                <div class="card-body">
                                    <div class="row mb-2">
                                        <div class="col-md-6">
                                            <small class="text-muted d-block">Room Number</small>
                                            <strong id="bookingRoomNumber">-</strong>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="text-muted d-block">Customer Name</small>
                                            <strong id="bookingCustomerName">-</strong>
                                        </div>
                                    </div>
                                    <div class="row mb-2">
                                        <div class="col-md-6">
                                            <small class="text-muted d-block">Contact Number</small>
                                            <strong id="bookingCustomerPhone">-</strong>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="text-muted d-block">Email Address</small>
                                            <strong id="bookingCustomerEmail">-</strong>
                                        </div>
                                    </div>
                                    <div class="row mb-2">
                                        <div class="col-md-6">
                                            <small class="text-muted d-block">Check-in Date</small>
                                            <strong id="bookingCheckIn">-</strong>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="text-muted d-block">Check-out Date</small>
                                            <strong id="bookingCheckOut">-</strong>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <small class="text-muted d-block">Room Rate</small>
                                            <strong id="bookingRoomRate" class="text-success">₱0.00</strong>
                                        </div>
                                    </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Payment Details Section -->
          <div class="mb-4">
            <h6 class="text-primary border-bottom pb-2"><strong>Payment Details</strong></h6>
            
            <div class="mb-3">
              <label class="form-label">Payment Type <span class="text-danger">*</span></label>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="payment_type" id="payment_full" value="full" checked onchange="updatePaymentCalculation()">
                <label class="form-check-label" for="payment_full">
                  Full Payment
                </label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="payment_type" id="payment_downpayment" value="downpayment" onchange="updatePaymentCalculation()">
                <label class="form-check-label" for="payment_downpayment">
                  Downpayment (50%)
                </label>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method" id="method_cash" value="cash" checked>
                                <label class="form-check-label" for="method_cash">Cash</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method" id="method_gcash" value="gcash">
                                <label class="form-check-label" for="method_gcash">GCash</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method" id="method_paymaya" value="paymaya">
                                <label class="form-check-label" for="method_paymaya">PayMaya</label>
                            </div>
            </div>

            <div class="mb-3">
              <label for="amount_paid_new" class="form-label">Amount Paid (₱) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" class="form-control" id="amount_paid_new" name="amount_paid" placeholder="0.00" oninput="updatePaymentCalculation()" required>
            </div>

            <div class="mb-3">
              <label for="remaining_balance_new" class="form-label">Remaining Balance (₱)</label>
              <input type="number" step="0.01" class="form-control" id="remaining_balance_new" name="remaining_balance" placeholder="0.00" readonly style="background-color: #f8f9fa;">
            </div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save Bill</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Load tenant details when tenant is selected
function loadTenantDetails() {
    const selectElement = document.getElementById('tenant_id');
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const detailsCard = document.getElementById('tenantDetailsCard');
    const amountInput = document.getElementById('amount_due');
    
    if (selectElement.value === '') {
        detailsCard.style.display = 'none';
        amountInput.value = '';
        return;
    }
    
    // Get data from selected option
    const roomNumber = selectedOption.getAttribute('data-room-number') || 'N/A';
    const roomType = selectedOption.getAttribute('data-room-type') || 'N/A';
    const rate = parseFloat(selectedOption.getAttribute('data-rate')) || 0;
    const moveInDate = selectedOption.getAttribute('data-move-in') || 'N/A';
    
    // Format move-in date
    let formattedDate = moveInDate;
    if (moveInDate !== 'N/A') {
        const date = new Date(moveInDate);
        formattedDate = date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    }
    
    // Update details card
    document.getElementById('detailRoomNumber').textContent = roomNumber;
    document.getElementById('detailRoomType').textContent = roomType;
    document.getElementById('detailRate').textContent = '₱' + rate.toFixed(2);
    document.getElementById('detailMoveIn').textContent = formattedDate;
    
    // Auto-fill amount due with room rate
    amountInput.value = rate.toFixed(2);
    
    // Show details card
    detailsCard.style.display = 'block';
}

// Click handler to fill amount from rate
document.addEventListener('DOMContentLoaded', function() {
    const detailRateElement = document.getElementById('detailRate');
    if (detailRateElement) {
        detailRateElement.style.cursor = 'pointer';
        detailRateElement.style.textDecoration = 'underline';
        detailRateElement.title = 'Click to fill Amount Due';
        detailRateElement.addEventListener('click', function() {
            const rateText = this.textContent.replace('₱', '').trim();
            document.getElementById('amount_due').value = rateText;
        });
    }

    // Load booked rooms when Add New Bill Modal is shown
    const addNewBillModal = document.getElementById('addNewBillModal');
    if (addNewBillModal) {
        addNewBillModal.addEventListener('show.bs.modal', function() {
            loadBookedRooms();
        });
    }
    // Bind payment type radios to auto-fill amounts when changed
    const paymentTypeRadios = document.querySelectorAll('input[name="payment_type"]');
    paymentTypeRadios.forEach(r => r.addEventListener('change', function(){
        // autoFill amounts based on selected booking and type
        updatePaymentCalculation(true);
    }));

    const amountPaidEl = document.getElementById('amount_paid_new');
    if (amountPaidEl) {
        amountPaidEl.addEventListener('input', function(){ updatePaymentCalculation(false); });
    }
});

// Load booked rooms for the "Add New Bill" modal
function loadBookedRooms() {
    const bookingRoomSelect = document.getElementById('booking_room');
    
    // Make AJAX call to fetch booked rooms
    fetch('api_get_booked_rooms.php')
        .then(response => response.json())
        .then(data => {
            bookingRoomSelect.innerHTML = '<option value="">-- Select a booked room --</option>';
            
            if (data.success && data.bookings.length > 0) {
                data.bookings.forEach(booking => {
                    const option = document.createElement('option');
                    option.value = booking.booking_id;
                    option.setAttribute('data-room-number', booking.room_number);
                    option.setAttribute('data-customer-name', booking.tenant_name);
                    option.setAttribute('data-tenant-phone', booking.tenant_phone || '');
                    option.setAttribute('data-tenant-email', booking.tenant_email || '');
                    // use raw ISO dates for calculations
                    option.setAttribute('data-checkin-date', booking.raw_checkin || '');
                    option.setAttribute('data-checkout-date', booking.raw_checkout || '');
                    option.setAttribute('data-room-rate', booking.room_rate);
                    option.setAttribute('data-room-id', booking.room_id);
                    option.setAttribute('data-tenant-id', booking.tenant_id);
                    // human readable label
                    const displayCheckIn = booking.checkin_date ? booking.checkin_date : '';
                    option.textContent = `${booking.room_number} - ${booking.tenant_name} ${displayCheckIn ? '(' + displayCheckIn + ')' : ''}`;
                    bookingRoomSelect.appendChild(option);
                });
            } else {
                const option = document.createElement('option');
                option.textContent = '-- No booked rooms available --';
                option.disabled = true;
                bookingRoomSelect.appendChild(option);
            }
        })
        .catch(error => {
            console.error('Error loading booked rooms:', error);
            bookingRoomSelect.innerHTML = '<option value="">-- Error loading rooms --</option>';
        });
}

// Load booking details when a room is selected
function loadBookingDetails() {
    const bookingRoomSelect = document.getElementById('booking_room');
    const selectedOption = bookingRoomSelect.options[bookingRoomSelect.selectedIndex];
    const bookingDetailsCard = document.getElementById('bookingDetailsCard');

    if (!bookingRoomSelect.value) {
        bookingDetailsCard.style.display = 'none';
        document.getElementById('amount_paid_new').value = '';
        document.getElementById('remaining_balance_new').value = '';
        return;
    }

    // Get data from selected option (raw ISO dates expected)
    const roomNumber = selectedOption.getAttribute('data-room-number') || '-';
    const customerName = selectedOption.getAttribute('data-customer-name') || '-';
    const checkinDate = selectedOption.getAttribute('data-checkin-date') || '';
    const checkoutDate = selectedOption.getAttribute('data-checkout-date') || '';
    const roomRate = parseFloat(selectedOption.getAttribute('data-room-rate')) || 0;

    // Format dates for display
    let formattedCheckIn = checkinDate;
    let formattedCheckOut = checkoutDate;
    if (checkinDate) {
        const d = new Date(checkinDate + 'T00:00:00');
        formattedCheckIn = d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }
    if (checkoutDate) {
        const d = new Date(checkoutDate + 'T00:00:00');
        formattedCheckOut = d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    // Update booking details card
    document.getElementById('bookingRoomNumber').textContent = roomNumber;
    document.getElementById('bookingCustomerName').textContent = customerName;
    document.getElementById('bookingCheckIn').textContent = formattedCheckIn || '-';
    document.getElementById('bookingCheckOut').textContent = formattedCheckOut || '-';
    document.getElementById('bookingRoomRate').textContent = '₱' + roomRate.toFixed(2);
    document.getElementById('bookingCustomerPhone').textContent = selectedOption.getAttribute('data-tenant-phone') || '-';
    document.getElementById('bookingCustomerEmail').textContent = selectedOption.getAttribute('data-tenant-email') || '-';

    // Compute nights and total
    const nights = computeNights(checkinDate, checkoutDate);
    const totalAmount = parseFloat((roomRate * nights).toFixed(2));
    bookingRoomSelect.setAttribute('data-total-amount', totalAmount);

    bookingDetailsCard.style.display = 'block';

    // Auto-apply payment type calculation to set amount paid and remaining
    updatePaymentCalculation(true);
}

// Update payment calculation based on payment type and amount paid
function updatePaymentCalculation(autoFill) {
    const bookingRoomSelect = document.getElementById('booking_room');
    const selectedOption = bookingRoomSelect.options[bookingRoomSelect.selectedIndex] || {};
    const roomRate = parseFloat(selectedOption.getAttribute && selectedOption.getAttribute('data-room-rate')) || 0;

    const paymentType = (document.querySelector('input[name="payment_type"]:checked') || {}).value || 'full';
    const amountPaidInput = document.getElementById('amount_paid_new');
    const remainingBalanceInput = document.getElementById('remaining_balance_new');

    const totalFromAttr = parseFloat(bookingRoomSelect.getAttribute('data-total-amount')) || 0;
    const totalBillAmount = totalFromAttr || roomRate;

    // If autoFill requested (e.g., on booking selection or payment type change), set typical amounts
    if (autoFill) {
        if (paymentType === 'downpayment') {
            const down = parseFloat((totalBillAmount * 0.5).toFixed(2));
            amountPaidInput.value = down.toFixed(2);
            remainingBalanceInput.value = (totalBillAmount - down).toFixed(2);
        } else {
            // full
            amountPaidInput.value = totalBillAmount.toFixed(2);
            remainingBalanceInput.value = '0.00';
        }
        return;
    }

    // If admin edits amount_paid, recalc remaining
    const amountPaidRaw = amountPaidInput.value;
    const amountPaid = amountPaidRaw === '' ? 0 : parseFloat(amountPaidRaw) || 0;
    const remaining = Math.max(0, totalBillAmount - amountPaid);
    remainingBalanceInput.value = remaining.toFixed(2);
}

// compute nights between two ISO dates (checkout - checkin)
function computeNights(checkin, checkout) {
    if (!checkin || !checkout) return 1;
    const c = new Date(checkin + 'T00:00:00');
    const o = new Date(checkout + 'T00:00:00');
    const diff = Math.ceil((o - c) / (1000 * 60 * 60 * 24));
    return diff > 0 ? diff : 1;
}

// Function to switch between Active and Archive views
function switchView(view) {
    const activeView = document.getElementById('activeView');
    const archiveView = document.getElementById('archiveView');
    
    // Get all nav buttons
    const navButtons = document.querySelectorAll('.nav-link');
    
    if (view === 'active') {
        activeView.style.display = 'block';
        archiveView.style.display = 'none';
        navButtons.forEach(btn => btn.classList.remove('active'));
        if (navButtons[0]) navButtons[0].classList.add('active');
        // Update URL without reloading
        window.history.pushState({}, '', 'bills.php?view=active');
    } else if (view === 'archive') {
        activeView.style.display = 'none';
        archiveView.style.display = 'block';
        navButtons.forEach(btn => btn.classList.remove('active'));
        if (navButtons[1]) navButtons[1].classList.add('active');
        // Update URL without reloading
        window.history.pushState({}, '', 'bills.php?view=archive');
    }
}
</script>

<!-- Check Out Modal -->
<div class="modal fade" id="checkoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exit"></i> Customer Check Out</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="bill_actions.php?action=checkout">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="checkout_tenant" class="form-label">Select Customer <span class="text-danger">*</span></label>
                        <select class="form-select" id="checkout_tenant" name="tenant_id" required onchange="loadCheckoutDetails()">
                            <option value="">-- Choose a Customer --</option>
                            <?php foreach ($checkout_tenants as $tenant): ?>
                                <option value="<?php echo $tenant['id']; ?>">
                                    <?php echo htmlspecialchars($tenant['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Customer Details Card -->
                    <div id="checkoutDetailsCard" class="card mb-3" style="display: none;">
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <p class="mb-2"><strong><i class="bi bi-person"></i> Name:</strong> <span id="co_name" class="text-muted">-</span></p>
                                    <p class="mb-2"><strong><i class="bi bi-envelope"></i> Email:</strong> <span id="co_email" class="text-muted small">-</span></p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2"><strong><i class="bi bi-door-closed"></i> Room:</strong> <span id="co_room" class="badge bg-info">-</span></p>
                                    <p class="mb-2"><strong><i class="bi bi-telephone"></i> Phone:</strong> <span id="co_phone" class="text-muted">-</span></p>
                                </div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Amount Paid:</strong><br><span id="co_amount_paid" class="text-success" style="font-size: 1.2rem; font-weight: 600;">₱0.00</span></p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Total Additional Charges:</strong><br><span id="co_charges" class="text-info" style="font-size: 1.2rem; font-weight: 600;">₱0.00</span></p>
                                </div>
                            </div>
                            <div class="row mt-3 border-top pt-3">
                                <div class="col-12">
                                    <p class="mb-0"><strong>Grand Total Due:</strong><br><span id="co_grand_total_due" class="text-warning" style="font-size: 1.3rem; font-weight: 700;">₱0.00</span></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Charges Section -->
                    <div id="chargesSection" style="display: none;">
                        <h6 class="mb-3"><i class="bi bi-receipt"></i> Additional Charges</h6>
                        <div id="chargesList" class="mb-3">
                            <!-- Charges will be loaded here -->
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <label for="checkout_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-select" id="checkout_method" name="payment_method" required>
                                <option value="">-- Select Payment Method --</option>
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                                <option value="paymaya">PayMaya</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="checkout_amount" class="form-label">Final Amount to Collect <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="checkout_amount" name="final_amount" step="0.01" readonly placeholder="₱0.00">
                        </div>
                    </div>

                    <div class="mt-3">
                        <label for="checkout_notes" class="form-label">Checkout Notes (Optional)</label>
                        <textarea class="form-control" id="checkout_notes" name="checkout_notes" placeholder="Any notes about the checkout..." rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-check-circle"></i> Process Check Out
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Load checkout details when tenant is selected
async function loadCheckoutDetails() {
    const tenantId = document.getElementById('checkout_tenant').value;
    const detailsCard = document.getElementById('checkoutDetailsCard');
    const chargesSection = document.getElementById('chargesSection');
    const chargesList = document.getElementById('chargesList');
    const finalAmount = document.getElementById('checkout_amount');
    
    if (!tenantId) {
        detailsCard.style.display = 'none';
        chargesSection.style.display = 'none';
        finalAmount.value = '';
        return;
    }

    try {
        const response = await fetch('api_get_checkout_details.php?tenant_id=' + tenantId);
        const data = await response.json();
        
        if (data.success) {
            // Populate customer details
            document.getElementById('co_name').textContent = data.tenant_name;
            document.getElementById('co_email').textContent = data.email;
            document.getElementById('co_phone').textContent = data.phone;
            document.getElementById('co_room').textContent = data.room_number || 'N/A';
            document.getElementById('co_amount_paid').textContent = '₱' + parseFloat(data.amount_paid).toFixed(2);
            document.getElementById('co_charges').textContent = '₱' + parseFloat(data.charges_total).toFixed(2);
            document.getElementById('co_grand_total_due').textContent = '₱' + parseFloat(data.grand_total_due).toFixed(2);
            
            // Update final amount to collect (Grand Total Due)
            finalAmount.value = parseFloat(data.grand_total_due).toFixed(2);
            
            // Populate charges
            if (data.charges && data.charges.length > 0) {
                let chargesHTML = '<div class="table-responsive"><table class="table table-sm mb-0"><tbody>';
                let totalCharges = 0;
                data.charges.forEach(charge => {
                    chargesHTML += '<tr><td>' + charge.category + '</td><td class="text-end"><strong>₱' + parseFloat(charge.cost).toFixed(2) + '</strong></td></tr>';
                    totalCharges += parseFloat(charge.cost);
                });
                chargesHTML += '</tbody></table></div>';
                chargesList.innerHTML = chargesHTML;
                chargesSection.style.display = 'block';
            } else {
                chargesSection.style.display = 'none';
            }
            
            detailsCard.style.display = 'block';
        }
    } catch (error) {
        console.error('Error loading checkout details:', error);
        alert('Error loading checkout details. Please try again.');
    }
}
</script>

</body>
</html>
