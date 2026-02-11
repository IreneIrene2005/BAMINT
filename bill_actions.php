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
        $billing_month_input = $_POST['billing_month'];
        // If input is YYYY-MM, convert to YYYY-MM-01; if already YYYY-MM-DD, use as is
        $billing_month = (strlen($billing_month_input) === 7) ? $billing_month_input . '-01' : $billing_month_input;
        $due_date = $_POST['due_date'];
        
        try {
            $conn->beginTransaction();
            
            // Get all active tenants with their room rates and check-in dates
            $sql = "SELECT tenants.id, tenants.name, tenants.room_id, rooms.id as room_id, rooms.rate,
                           MAX(rr.checkin_date) as checkin_date, MAX(rr.checkout_date) as checkout_date
                    FROM tenants 
                    JOIN rooms ON tenants.room_id = rooms.id 
                    LEFT JOIN room_requests rr ON tenants.id = rr.tenant_id AND tenants.room_id = rr.room_id
                    WHERE tenants.status = 'active'
                    GROUP BY tenants.id";
            
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
                    $insert_sql = "INSERT INTO bills (tenant_id, room_id, billing_month, amount_due, due_date, status, checkin_date, checkout_date) 
                                  VALUES (:tenant_id, :room_id, :billing_month, :amount_due, :due_date, 'pending', :checkin_date, :checkout_date)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->execute([
                        'tenant_id' => $tenant['id'],
                        'room_id' => $tenant['room_id'],
                        'billing_month' => $billing_month,
                        'amount_due' => $tenant['rate'],
                        'due_date' => $due_date,
                        'checkin_date' => $tenant['checkin_date'] ?: null,
                        'checkout_date' => $tenant['checkout_date'] ?: null
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
        $billing_month_input = $_POST['billing_month'];
        // If input is YYYY-MM, convert to YYYY-MM-01; if already YYYY-MM-DD, use as is
        $billing_month = (strlen($billing_month_input) === 7) ? $billing_month_input . '-01' : $billing_month_input;
        $amount_due = $_POST['amount_due'];
        $due_date = $_POST['due_date'] ?? null;
        
        try {
            // Get the tenant's room_id and check-in date from room_requests
            $sql = "SELECT t.room_id, MAX(rr.checkin_date) as checkin_date, MAX(rr.checkout_date) as checkout_date 
                    FROM tenants t 
                    LEFT JOIN room_requests rr ON t.id = rr.tenant_id AND t.room_id = rr.room_id
                    WHERE t.id = :id
                    GROUP BY t.id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['id' => $tenant_id]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tenant) {
                $insert_sql = "INSERT INTO bills (tenant_id, room_id, billing_month, amount_due, due_date, status, checkin_date, checkout_date) 
                              VALUES (:tenant_id, :room_id, :billing_month, :amount_due, :due_date, 'pending', :checkin_date, :checkout_date)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->execute([
                    'tenant_id' => $tenant_id,
                    'room_id' => $tenant['room_id'],
                    'billing_month' => $billing_month,
                    'amount_due' => $amount_due,
                    'due_date' => $due_date,
                    'checkin_date' => $tenant['checkin_date'] ?: null,
                    'checkout_date' => $tenant['checkout_date'] ?: null
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
                    checkin_date,
                    checkout_date,
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
                    :checkin_date,
                    :checkout_date,
                    NOW(),
                    NOW()
                )
            ";
            
            // Use actual checkin_date as billing_month, fallback to current date if not available
            $billing_month = $booking['checkin_date'] ? (new DateTime($booking['checkin_date']))->format('Y-m-d') : date('Y-m-d');
            $due_date = $booking['checkin_date'] ? (new DateTime($booking['checkin_date']))->format('Y-m-d') : date('Y-m-d');
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
                'notes' => $notes,
                'checkin_date' => $booking['checkin_date'] ?: null,
                'checkout_date' => $booking['checkout_date'] ?: null
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

                // After successful payment: only reserve the room and mark booking approved.
                // Do NOT activate tenant or mark room occupied — admin must approve check-in in admin_checkin_checkout.php
                $updateReq = $conn->prepare("UPDATE room_requests SET status = 'approved' WHERE id = :booking_id");
                $updateReq->execute(['booking_id' => $booking_room]);
                
                // Reserve room as 'booked' (not yet occupied)
                $updateRoom = $conn->prepare("UPDATE rooms SET status = 'booked' WHERE id = :room_id");
                $updateRoom->execute(['room_id' => $room_id]);
                
                // Assign room to tenant but keep status 'inactive' until admin approves check-in
                $updateTenant = $conn->prepare("UPDATE tenants SET room_id = :room_id, start_date = :start_date WHERE id = :tenant_id");
                $updateTenant->execute([
                    'room_id' => $room_id,
                    'start_date' => $booking['checkin_date'] ?: date('Y-m-d'),
                    'tenant_id' => $tenant_id
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
            
            // If bill is now fully paid, mark the room as occupied (was booked)
            if ($status === 'paid') {
                $bill_detail_sql = "SELECT room_id FROM bills WHERE id = :id";
                $bill_detail_stmt = $conn->prepare($bill_detail_sql);
                $bill_detail_stmt->execute(['id' => $id]);
                $bill_detail = $bill_detail_stmt->fetch(PDO::FETCH_ASSOC);
                if ($bill_detail && $bill_detail['room_id']) {
                    $room_update = $conn->prepare("UPDATE rooms SET status = 'occupied' WHERE id = :room_id AND status = 'booked'");
                    $room_update->execute(['room_id' => $bill_detail['room_id']]);
                }
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
        $checkin_time = $_POST['checkin_time'] ?? null;
        $checkout_time = $_POST['checkout_time'] ?? null;
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

            // Bill should always show FULL cost, regardless of payment option
            $amount_due = round($total_cost, 2);
            
            // Payment transaction will record what was actually paid
            if ($payment_option === 'downpayment') {
                $amount_paid_now = round($total_cost * 0.5, 2);
                $payment_notes = "Downpayment (50%) - Balance due: ₱" . number_format($total_cost * 0.5, 2);
            } else {
                $amount_paid_now = round($total_cost, 2);
                $payment_notes = "Full payment";
            }

            // Create or update bill for this tenant and room (pending)
            $billing_month = $checkin ? (new DateTime($checkin))->format('Y-m-d') : date('Y-m-d');
            $due_date = $checkin ? (new DateTime($checkin))->format('Y-m-d') : date('Y-m-d');

            $notes = ($payment_option === 'downpayment') ? "ADVANCE PAYMENT (Downpayment) - {$nights} night(s), ₱" . number_format($rate,2) . "/night" : "ADVANCE PAYMENT - Full payment (" . $nights . " night(s))";

            // Insert bill
            $insert = $conn->prepare("INSERT INTO bills (tenant_id, room_id, billing_month, amount_due, due_date, status, notes, checkin_date, checkout_date, checkin_time, checkout_time, created_at, updated_at) VALUES (:tenant_id, :room_id, :billing_month, :amount_due, :due_date, 'pending', :notes, :checkin_date, :checkout_date, :checkin_time, :checkout_time, NOW(), NOW())");
            $insert->execute([
                'tenant_id' => $tenant_id,
                'room_id' => $room_id,
                'billing_month' => $billing_month,
                'amount_due' => $amount_due,
                'due_date' => $due_date,
                'notes' => $notes,
                'checkin_date' => $checkin ?: null,
                'checkout_date' => $checkout ?: null,
                'checkin_time' => $checkin_time ?: null,
                'checkout_time' => $checkout_time ?: null
            ]);
            $bill_id = $conn->lastInsertId();

            // Record the payment transaction (downpayment or full) and update bill
            $payment_status = ($payment_option === 'downpayment') ? 'partial' : 'paid';
            $update_bill = $conn->prepare("UPDATE bills SET amount_paid = :amount_paid, status = :status WHERE id = :bill_id");
            $update_bill->execute([
                'amount_paid' => $amount_paid_now,
                'status' => $payment_status,
                'bill_id' => $bill_id
            ]);
            
            // Create payment transaction
            $payment_insert = $conn->prepare("INSERT INTO payment_transactions (bill_id, tenant_id, payment_amount, payment_method, payment_type, payment_status, payment_date, notes, recorded_by, created_at) VALUES (:bill_id, :tenant_id, :payment_amount, :payment_method, :payment_type, :payment_status, NOW(), :notes, :recorded_by, NOW())");
            $payment_insert->execute([
                'bill_id' => $bill_id,
                'tenant_id' => $tenant_id,
                'payment_amount' => $amount_paid_now,
                'payment_method' => 'cash',
                'payment_type' => ($payment_option === 'downpayment') ? 'downpayment' : 'full',
                'payment_status' => 'approved',
                'notes' => $payment_notes,
                'recorded_by' => $_SESSION['admin_id'] ?? null
            ]);

            // Mark room occupied so it's reserved
            if ($room_id) {
                $upd = $conn->prepare("UPDATE rooms SET status = 'occupied' WHERE id = :id");
                $upd->execute(['id' => $room_id]);
            }

            // Create or update room_request for walk-in customer
            $check_rr = $conn->prepare("SELECT id FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id");
            $check_rr->execute(['tenant_id' => $tenant_id, 'room_id' => $room_id]);
            
            if ($check_rr->rowCount() > 0) {
                // Update existing room_request with times and status
                $rr_stmt = $conn->prepare("UPDATE room_requests SET status = 'pending_payment', checkin_date = :checkin_date, checkout_date = :checkout_date, checkin_time = :checkin_time, checkout_time = :checkout_time WHERE tenant_id = :tenant_id AND room_id = :room_id AND status != 'approved'");
                $rr_stmt->execute([
                    'tenant_id' => $tenant_id, 
                    'room_id' => $room_id,
                    'checkin_date' => $checkin ?: null,
                    'checkout_date' => $checkout ?: null,
                    'checkin_time' => $checkin_time ?: null,
                    'checkout_time' => $checkout_time ?: null
                ]);
            } else {
                // Create new room_request for walk-in customer
                $insert_rr = $conn->prepare("INSERT INTO room_requests (tenant_id, room_id, checkin_date, checkout_date, checkin_time, checkout_time, status, created_at) 
                                            VALUES (:tenant_id, :room_id, :checkin_date, :checkout_date, :checkin_time, :checkout_time, 'pending_payment', NOW())");
                $insert_rr->execute([
                    'tenant_id' => $tenant_id, 
                    'room_id' => $room_id,
                    'checkin_date' => $checkin ?: null,
                    'checkout_date' => $checkout ?: null,
                    'checkin_time' => $checkin_time ?: null,
                    'checkout_time' => $checkout_time ?: null
                ]);
            }

            $conn->commit();
            $_SESSION['message'] = 'Invoice generated. Bill ID #' . $bill_id;
            header('location: bills.php'); exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = 'Error processing walk-in: ' . $e->getMessage();
            header('location: bills.php'); exit;
        }
    
    } elseif ($action === 'checkout') {
        // Process customer checkout: record final payment, mark tenant inactive, free room
        $tenant_id = intval($_POST['tenant_id'] ?? 0);
        $final_amount = floatval($_POST['final_amount'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $checkout_notes = $_POST['checkout_notes'] ?? '';
        
        if (!$tenant_id || $final_amount < 0) {
            $_SESSION['error'] = 'Invalid checkout data.';
            header('location: bills.php');
            exit;
        }
        
        try {
            $conn->beginTransaction();
            
            // Get tenant and room details
            $tenant_stmt = $conn->prepare("SELECT id, room_id, name FROM tenants WHERE id = :id");
            $tenant_stmt->execute(['id' => $tenant_id]);
            $tenant = $tenant_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tenant) {
                throw new Exception('Tenant not found.');
            }
            
            $room_id = $tenant['room_id'];
            
            // Get unpaid bills to record payment against
            $bills_stmt = $conn->prepare("
                SELECT id, amount_due, amount_paid 
                FROM bills 
                WHERE tenant_id = :tenant_id 
                AND status IN ('pending', 'partial', 'unpaid', 'overdue')
                ORDER BY billing_month ASC
            ");
            $bills_stmt->execute(['tenant_id' => $tenant_id]);
            $unpaid_bills = $bills_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Apply payment to bills (FIFO - oldest first)
            $remaining_payment = $final_amount;
            foreach ($unpaid_bills as $bill) {
                if ($remaining_payment <= 0) break;
                
                $bill_balance = $bill['amount_due'] - $bill['amount_paid'];
                $payment_on_bill = min($bill_balance, $remaining_payment);
                
                $new_amount_paid = $bill['amount_paid'] + $payment_on_bill;
                $new_status = ($new_amount_paid >= $bill['amount_due']) ? 'paid' : 'partial';
                
                $update_bill = $conn->prepare("
                    UPDATE bills 
                    SET amount_paid = :amount_paid, status = :status, paid_date = :paid_date
                    WHERE id = :id
                ");
                $update_bill->execute([
                    'amount_paid' => $new_amount_paid,
                    'status' => $new_status,
                    'paid_date' => ($new_status === 'paid') ? date('Y-m-d') : null,
                    'id' => $bill['id']
                ]);
                
                // Record payment transaction for this bill
                $trans_sql = "INSERT INTO payment_transactions 
                             (bill_id, tenant_id, payment_amount, payment_method, payment_status, payment_date, notes, recorded_by) 
                             VALUES (:bill_id, :tenant_id, :payment_amount, :payment_method, :payment_status, :payment_date, :notes, :recorded_by)";
                $trans_stmt = $conn->prepare($trans_sql);
                $trans_stmt->execute([
                    'bill_id' => $bill['id'],
                    'tenant_id' => $tenant_id,
                    'payment_amount' => $payment_on_bill,
                    'payment_method' => $payment_method,
                    'payment_status' => 'approved',
                    'payment_date' => date('Y-m-d'),
                    'notes' => 'Checkout payment. ' . $checkout_notes,
                    'recorded_by' => $_SESSION['id']
                ]);
                
                $remaining_payment -= $payment_on_bill;
            }
            
            // Mark tenant as inactive with end_date
            $update_tenant = $conn->prepare("
                UPDATE tenants 
                SET status = 'inactive', end_date = :end_date
                WHERE id = :id
            ");
            $update_tenant->execute([
                // backdate end_date so tenant immediately appears in archive view
                'end_date' => date('Y-m-d', strtotime('-8 days')),
                'id' => $tenant_id
            ]);
            
            // Set room back to available
            if ($room_id) {
                $update_room = $conn->prepare("
                    UPDATE rooms 
                    SET status = 'available' 
                    WHERE id = :id
                ");
                $update_room->execute(['id' => $room_id]);
                
                // Update room_requests to mark checkout
                $update_requests = $conn->prepare("
                    UPDATE room_requests 
                    SET status = 'completed', checkout_date = :checkout_date
                    WHERE tenant_id = :tenant_id AND room_id = :room_id AND status != 'completed'
                ");
                $update_requests->execute([
                    'checkout_date' => date('Y-m-d'),
                    'tenant_id' => $tenant_id,
                    'room_id' => $room_id
                ]);
            }
            
            $conn->commit();
            $_SESSION['message'] = "Customer {$tenant['name']} checked out successfully! Payment of ₱" . number_format($final_amount, 2) . " recorded.";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error processing checkout: " . $e->getMessage();
        }
        
        header("location: bills.php");
        exit;
    } elseif ($action === 'cancel_walk_in') {
        // Cancel walk-in customer registration
        $tenant_id = intval($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
        
        if (!$tenant_id) {
            $_SESSION['error'] = 'Invalid customer ID.';
            header("location: bills.php");
            exit;
        }
        
        try {
            $conn->beginTransaction();
            
            // Get tenant info
            $tenant_stmt = $conn->prepare("SELECT id, name, room_id FROM tenants WHERE id = :id");
            $tenant_stmt->execute(['id' => $tenant_id]);
            $tenant = $tenant_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tenant) {
                throw new Exception('Customer not found');
            }
            
            // Delete any bills associated with this tenant
            $delete_bills = $conn->prepare("DELETE FROM bills WHERE tenant_id = :tenant_id");
            $delete_bills->execute(['tenant_id' => $tenant_id]);
            
            // Delete any payment transactions associated with this tenant's bills
            $delete_payments = $conn->prepare("
                DELETE pt FROM payment_transactions pt
                INNER JOIN bills b ON pt.bill_id = b.id
                WHERE b.tenant_id = :tenant_id
            ");
            $delete_payments->execute(['tenant_id' => $tenant_id]);
            
            // Delete any room requests
            $delete_requests = $conn->prepare("DELETE FROM room_requests WHERE tenant_id = :tenant_id");
            $delete_requests->execute(['tenant_id' => $tenant_id]);
            
            // Delete the tenant
            $delete_tenant = $conn->prepare("DELETE FROM tenants WHERE id = :id");
            $delete_tenant->execute(['id' => $tenant_id]);
            
            $conn->commit();
            $_SESSION['message'] = "Walk-in customer registration cancelled successfully.";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error cancelling walk-in customer: " . $e->getMessage();
        }
        
        header("location: bills.php");
        exit;
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
    
    } elseif ($action === 'archive') {
        // Archive a bill by setting updated_at to 8 days ago
        $id = $_GET['id'];
        
        try {
            // Update updated_at to 8 days ago so it meets archive criteria (paid + 7 days old)
            $sql = "UPDATE bills SET updated_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            $_SESSION['message'] = "Bill archived successfully!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Error archiving bill: " . $e->getMessage();
        }
        
        header("location: bills.php");
        exit;
        
    } elseif ($action === 'edit') {

    } elseif ($action === 'restore') {
        // Restore a bill by setting updated_at to now so it no longer meets the archive criteria
        $id = $_GET['id'];

        try {
            $sql = "UPDATE bills SET updated_at = NOW() WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['id' => $id]);

            $_SESSION['message'] = "Bill restored to Payment Transactions.";
        } catch (Exception $e) {
            $_SESSION['error'] = "Error restoring bill: " . $e->getMessage();
        }

        header("location: bills.php");
        exit;

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
                                    <td><strong>Total Amount Paid</strong></td>
                                    <td class="text-end">
                                        <?php
                                        // Calculate total paid from all verified/approved payments
                                        $total_paid_stmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount),0) as total_paid FROM payment_transactions WHERE bill_id = :bill_id AND payment_status IN ('verified', 'approved')");
                                        $total_paid_stmt->execute(['bill_id' => $bill['id']]);
                                        $total_paid = floatval($total_paid_stmt->fetchColumn());
                                        echo '<strong>₱' . number_format($total_paid, 2) . '</strong>';
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Room Balance</strong></td>
                                    <td class="text-end">
                                        <?php
                                        $room_balance = ($total_cost !== null ? $total_cost : 0) - $total_paid;
                                        echo '<strong>₱' . number_format(max(0, $room_balance), 2) . '</strong>';
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
                                        // Grand Total Due = Remaining Room Balance (if any) + Additional Charges
                                        // If room is fully paid, Grand Total Due = Additional Charges only
                                        $grand_total_due = max(0, $room_balance) + $total_charges;
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
