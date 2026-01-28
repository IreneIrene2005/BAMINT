# Code Implementation Reference Guide

## Overview of All Code Changes Made in This Session

This document provides a detailed reference of all code modifications and additions made to implement the maintenance pricing, automatic billing, and messaging system.

---

## 1. Tenant Dashboard Updates

### File: `tenant_dashboard.php`

#### Change 1: Added Remaining Balance Query (Line ~48)
```php
// Query remaining balance from unpaid bills
try {
    $remaining_query = $conn->prepare("
        SELECT COALESCE(SUM(amount_due - amount_paid), 0) as remaining_balance
        FROM bills
        WHERE tenant_id = :tenant_id AND status IN ('unpaid', 'partial')
    ");
    $remaining_query->execute(['tenant_id' => $tenant_id]);
    $remaining = $remaining_query->fetch(PDO::FETCH_ASSOC);
    $remaining_balance = $remaining['remaining_balance'] ?? 0;
} catch (Exception $e) {
    $remaining_balance = 0;
}
```

#### Change 2: Added Remaining Balance Metric Card (Line ~340)
```html
<div class="card metric-card <?php echo $remaining_balance > 0 ? 'border-danger' : 'border-success'; ?>">
    <div class="card-body text-center">
        <p class="metric-value <?php echo $remaining_balance > 0 ? 'text-danger' : 'text-success'; ?>">
            ₱<?php echo number_format($remaining_balance, 2); ?>
        </p>
        <small class="text-muted">
            <?php echo $remaining_balance > 0 ? 'Amount due' : 'All paid up!'; ?>
        </small>
    </div>
</div>
```

#### Change 3: Added Messages Navigation Link (Line ~226)
```html
<li class="nav-item">
    <a class="nav-link" href="tenant_messages.php">
        <i class="bi bi-envelope"></i> Messages
    </a>
</li>
```

---

## 2. Admin Payment Verification Updates

### File: `admin_payment_verification.php`

#### Change 1: Added Unpaid Bills Query (After pending payments query)
```php
// Fetch unpaid bills with outstanding balances
try {
    $stmt = $conn->prepare("
        SELECT b.*, t.name as tenant_name, t.email as tenant_email, t.id as tenant_id,
               COALESCE(SUM(pt.payment_amount), 0) as total_paid,
               (b.amount_due - COALESCE(SUM(pt.payment_amount), 0)) as remaining_balance
        FROM bills b
        JOIN tenants t ON b.tenant_id = t.id
        LEFT JOIN payment_transactions pt ON b.id = pt.bill_id AND pt.payment_status IN ('verified', 'approved')
        WHERE b.status IN ('partial', 'unpaid')
        GROUP BY b.id, t.name, t.email, t.id, b.amount_due
        ORDER BY b.billing_month DESC
    ");
    $stmt->execute();
    $unpaid_bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $unpaid_bills = [];
}
```

#### Change 2: Integrated Partial Payment Notification (Line ~57)
```php
// Notify if partial payment
if ($bill_status === 'partial') {
    $amount_due_remaining = $payment_info['amount_due'] - $total_paid;
    notifyPartialPayment($conn, $payment_info['tenant_id'], $payment_info['bill_id'], 
                         $payment_info['amount_due'], $total_paid, $payment_id);
}
```

#### Change 3: Added Outstanding Bills UI Section (Line ~407)
```html
<!-- Outstanding Bills Alert Section -->
<?php if (!empty($unpaid_bills)): ?>
    <div class="mb-5">
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-center gap-3">
                <div class="flex-shrink-0">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.5rem;"></i>
                </div>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-2">
                        <i class="bi bi-cash-coin"></i> Outstanding Bills
                    </h5>
                    <p class="mb-0">
                        There are <strong><?php echo count($unpaid_bills); ?></strong> bill(s) with outstanding balances. 
                        Contact tenants to collect remaining payment.
                    </p>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Tenant Name</th>
                        <th>Billing Month</th>
                        <th>Amount Due</th>
                        <th>Paid</th>
                        <th>Remaining Balance</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unpaid_bills as $bill): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($bill['tenant_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($bill['tenant_email']); ?></small>
                            </td>
                            <td><?php echo date('F Y', strtotime($bill['billing_month'])); ?></td>
                            <td>₱<?php echo number_format($bill['amount_due'], 2); ?></td>
                            <td>₱<?php echo number_format($bill['total_paid'], 2); ?></td>
                            <td>
                                <span class="badge bg-danger">₱<?php echo number_format($bill['remaining_balance'], 2); ?></span>
                            </td>
                            <td>
                                <a href="admin_send_message.php?tenant_id=<?php echo $bill['tenant_id']; ?>&bill_id=<?php echo $bill['id']; ?>" 
                                   class="btn btn-sm btn-outline-primary" title="Send payment reminder">
                                    <i class="bi bi-envelope"></i> Message
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
```

---

## 3. Database Functions Additions

### File: `db/notifications.php`

#### Function 1: getCategoryCost()
```php
function getCategoryCost($category) {
    $costs = [
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
    
    return $costs[$category] ?? null;
}
```

#### Function 2: addMaintenanceCostToBill()
```php
function addMaintenanceCostToBill($conn, $tenantId, $cost) {
    if (!$cost) return;
    
    try {
        // Calculate next month's billing month
        $nextMonth = date('Y-m-01', strtotime('+1 month'));
        
        // Check if bill exists for next month
        $checkStmt = $conn->prepare("
            SELECT id, amount_due FROM bills 
            WHERE tenant_id = :tenant_id AND billing_month = :month
        ");
        $checkStmt->execute(['tenant_id' => $tenantId, 'month' => $nextMonth]);
        $existing_bill = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_bill) {
            // Update existing bill
            $updateStmt = $conn->prepare("
                UPDATE bills 
                SET amount_due = amount_due + :cost
                WHERE id = :id
            ");
            $updateStmt->execute(['cost' => $cost, 'id' => $existing_bill['id']]);
        } else {
            // Create new bill
            $insertStmt = $conn->prepare("
                INSERT INTO bills (tenant_id, billing_month, amount_due, status, created_at)
                VALUES (:tenant_id, :month, :cost, 'unpaid', NOW())
            ");
            $insertStmt->execute([
                'tenant_id' => $tenantId,
                'month' => $nextMonth,
                'cost' => $cost
            ]);
        }
    } catch (Exception $e) {
        error_log("Error adding maintenance cost to bill: " . $e->getMessage());
    }
}
```

#### Function 3: sendMessage()
```php
function sendMessage($conn, $senderType, $senderId, $recipientType, $recipientId, 
                     $subject, $text, $relatedType = null, $relatedId = null) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO messages 
            (sender_type, sender_id, recipient_type, recipient_id, subject, message, related_type, related_id, created_at)
            VALUES (:sender_type, :sender_id, :recipient_type, :recipient_id, :subject, :message, :related_type, :related_id, NOW())
        ");
        
        return $stmt->execute([
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'subject' => $subject,
            'message' => $text,
            'related_type' => $relatedType,
            'related_id' => $relatedId
        ]);
    } catch (Exception $e) {
        error_log("Error sending message: " . $e->getMessage());
        return false;
    }
}
```

#### Function 4: notifyPartialPayment()
```php
function notifyPartialPayment($conn, $tenantId, $billId, $amountDue, $amountPaid, $paymentTransactionId) {
    try {
        $remainingBalance = $amountDue - $amountPaid;
        
        // Notification for admin
        $adminMessage = "Partial payment received: ₱" . number_format($amountPaid, 2) . 
                       " of ₱" . number_format($amountDue, 2) . 
                       ". Remaining: ₱" . number_format($remainingBalance, 2);
        
        notifyAdmin(
            $conn,
            "Partial Payment Received",
            $adminMessage,
            "partial_payment",
            $paymentTransactionId,
            $tenantId
        );
        
        // Notification for tenant
        $tenantMessage = "Payment received! You paid ₱" . number_format($amountPaid, 2) . 
                        ". Remaining balance: ₱" . number_format($remainingBalance, 2);
        
        notifyTenant(
            $conn,
            $tenantId,
            "Payment Received",
            $tenantMessage,
            "partial_payment",
            $paymentTransactionId
        );
        
    } catch (Exception $e) {
        error_log("Error notifying partial payment: " . $e->getMessage());
    }
}
```

---

## 4. New File: tenant_messages.php

### Key Features:
- Displays all messages from admin to tenant
- Auto-marks messages as read
- Expandable message view
- Shows sender, subject, preview, date/time

### Key SQL Query:
```php
$stmt = $conn->prepare("
    SELECT * FROM messages
    WHERE recipient_type = 'tenant' AND recipient_id = :tenant_id
    ORDER BY created_at DESC
");
$stmt->execute(['tenant_id' => $tenant_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### Key Features in Code:
```php
// Mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $message_id = intval($_POST['message_id']);
    $update_stmt = $conn->prepare("
        UPDATE messages 
        SET is_read = TRUE, read_at = NOW()
        WHERE id = :id AND recipient_id = :tenant_id
    ");
    $update_stmt->execute(['id' => $message_id, 'tenant_id' => $tenant_id]);
}
```

---

## 5. New File: admin_send_message.php

### Key Features:
- Tenant selector dropdown
- Message templates (Balance Reminder, Payment Confirmation, Custom)
- Auto-populate subject with balance
- Related record tracking (bill, payment, maintenance)

### Key SQL Query for Balance Display:
```php
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount_due - amount_paid), 0) as remaining_balance
    FROM bills
    WHERE tenant_id = :tenant_id AND status IN ('unpaid', 'partial')
");
```

### Key Features in Code:
```php
// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $tenant_id = intval($_POST['tenant_id']);
    $subject = trim($_POST['subject']);
    $message_text = trim($_POST['message']);
    
    sendMessage(
        $conn,
        'admin',
        $admin_id,
        'tenant',
        $tenant_id,
        $subject,
        $message_text,
        $_POST['related_type'] ?? null,
        $_POST['related_id'] ?? null
    );
}
```

---

## 6. Maintenance Request Processing

### File: `maintenance_actions.php`

#### Key Integration:
```php
if ($_POST['action'] === 'update_status') {
    $new_status = trim($_POST['new_status']);
    
    // When marked complete, add cost to bill
    if ($new_status === 'completed') {
        $cost = getCategoryCost($request['category']);
        if ($cost) {
            addMaintenanceCostToBill($conn, $request['tenant_id'], $cost);
        }
    }
    
    // Update status in database
    $stmt = $conn->prepare("
        UPDATE maintenance_requests 
        SET status = :status, updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute(['status' => $new_status, 'id' => $request_id]);
}
```

---

## 7. Frontend Cost Display

### File: `tenant_maintenance.php`

#### JavaScript Function:
```javascript
function updateCostDisplay() {
    const category = document.getElementById('category').value;
    const costMap = {
        'Door/Lock': 150,
        'Walls/Paint': 200,
        'Furniture': 200,
        'Cleaning': 100,
        'Light/Bulb': 50,
        'Leak/Water': 150,
        'Pest/Bedbugs': 100,
        'Appliances': 200
    };
    
    const cost = costMap[category] || 'TBD';
    document.getElementById('estimated_cost').innerHTML = 
        cost !== 'TBD' ? '₱' + cost : 'To be determined by admin';
}
```

#### Category Dropdown:
```html
<select id="category" name="category" class="form-control" onchange="updateCostDisplay()">
    <option value="">Select Category</option>
    <option value="Door/Lock">Door/Lock – Broken lock, stuck door ₱150</option>
    <option value="Walls/Paint">Walls/Paint – Cracks, holes, paint touch-up ₱200</option>
    <!-- ... other options ... -->
</select>
```

---

## Summary of Integration Points

| Process | Files Involved | Key Functions |
|---------|----------------|---------------|
| Cost Display | `tenant_maintenance.php`, `maintenance_requests.php`, `admin_maintenance_queue.php` | `getCategoryCost()` |
| Auto-Billing | `maintenance_actions.php`, `db/notifications.php` | `addMaintenanceCostToBill()` |
| Partial Payment Notifications | `admin_payment_verification.php`, `db/notifications.php` | `notifyPartialPayment()` |
| Messaging | `admin_send_message.php`, `tenant_messages.php`, `db/notifications.php` | `sendMessage()` |
| Dashboard Display | `tenant_dashboard.php` | SQL queries for balance calculation |

---

## Testing Code Snippets

### Test Maintenance Cost Flow:
```php
// In maintenance_actions.php, after marking complete:
$cost = getCategoryCost($request['category']);
echo "Cost for {$request['category']}: ₱{$cost}";

// Check if added to bill:
$bill_query = $conn->prepare("SELECT * FROM bills WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 1");
```

### Test Partial Payment Detection:
```php
// In admin_payment_verification.php:
$total_paid = 600;
$amount_due = 1000;
$bill_status = ($total_paid >= $amount_due) ? 'paid' : 'partial';
echo "Bill Status: {$bill_status}"; // Should output: partial
```

### Test Message Creation:
```php
// Test sendMessage function:
sendMessage($conn, 'admin', 1, 'tenant', 5, 'Test Subject', 'Test message body', 'bill', 123);

// Verify in database:
$check = $conn->prepare("SELECT * FROM messages WHERE subject = 'Test Subject'");
```

---

**All code changes have been tested and verified for syntax correctness.**

