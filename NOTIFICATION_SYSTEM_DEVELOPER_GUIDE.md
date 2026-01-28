# How to Add Notifications to New Pages

## Quick Reference for Developers

This guide explains how to integrate notifications into new features or existing pages.

---

## Step 1: Include the Header with Notification Bell

Every admin and tenant page already has the notification bell because they include `templates/header.php`.

Make sure your page includes this:
```php
<?php include 'templates/header.php'; ?>
```

The header automatically:
- Shows the notification bell
- Displays unread count badge
- Provides the modal popup
- Handles JavaScript functionality

---

## Step 2: Create Notification Triggers

When a key action happens, create a notification. Here are the patterns:

### Pattern 1: Notify All Admins

```php
// After creating/updating something important
require_once "db/notifications.php";

// Your action code here...
$itemId = $conn->lastInsertId();

// Create notification for all admins
notifyAdminsNewRoom($conn, $itemId, 'Room 101');
```

### Pattern 2: Notify Specific Tenant

```php
// After an admin action affecting a tenant
require_once "db/notifications.php";

// Your admin action here...
notifyTenantPaymentVerification($conn, $tenantId, $paymentId, 'approved');
```

### Pattern 3: Custom Notification

```php
// For custom events not covered by helpers
require_once "db/notifications.php";

createNotification(
    $conn,
    'admin',                    // recipient_type
    $adminId,                   // recipient_id
    'custom_event',             // notification_type
    'Custom Event Title',       // title
    'Details about the event',  // message
    $relatedId,                 // related_id (optional)
    'custom_type',              // related_type (optional)
    'page_to_redirect.php'      // action_url (optional)
);
```

---

## Step 3: Choose Notification Type

Use these predefined types. Add to `notifyAdmins*` functions or `notifyTenant*` functions:

### Admin Notification Types
- `room_added` - New room created
- `payment_made` - Payment received from tenant
- `maintenance_request` - New maintenance request
- `room_request` - New co-tenant room request
- `custom_event` - Your custom event

### Tenant Notification Types
- `payment_verified` - Payment verified/rejected
- `maintenance_approved` - Maintenance status updated
- `room_request_approved` - Room request approved/rejected
- `custom_event` - Your custom event

---

## Step 4: Testing

After adding notifications:

```php
// 1. Trigger the action
// (Fill form and submit, etc.)

// 2. Check database
mysql> SELECT * FROM notifications ORDER BY created_at DESC LIMIT 1;

// 3. Login as recipient
// (Admin for admin notifications, tenant for tenant notifications)

// 4. Click bell icon
// Verify notification appears

// 5. Click notification
// Verify it marks as read and navigates correctly
```

---

## Common Implementation Examples

### Example 1: Notify Admins of New Complaint

```php
<?php
session_start();
require_once "db/database.php";
require_once "db/notifications.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenant_id = $_POST['tenant_id'];
    $subject = $_POST['subject'];
    $description = $_POST['description'];
    
    // Insert complaint
    $stmt = $conn->prepare("INSERT INTO complaints (tenant_id, subject, description) 
                           VALUES (:tenant_id, :subject, :description)");
    $stmt->execute([
        'tenant_id' => $tenant_id,
        'subject' => $subject,
        'description' => $description
    ]);
    
    $complaintId = $conn->lastInsertId();
    
    // Notify all admins
    $adminStmt = $conn->prepare("SELECT id FROM admins");
    $adminStmt->execute();
    $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($admins as $admin) {
        createNotification(
            $conn,
            'admin',
            $admin['id'],
            'new_complaint',
            'New Complaint Received',
            $subject,
            $complaintId,
            'complaint',
            'admin_complaints.php'
        );
    }
    
    header("location: complaints.php?success=1");
}
?>
```

### Example 2: Notify Tenant of Invoice

```php
<?php
// In admin_send_invoice.php
require_once "db/notifications.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenant_id = $_POST['tenant_id'];
    $amount = $_POST['amount'];
    
    // Create invoice
    $stmt = $conn->prepare("INSERT INTO invoices (tenant_id, amount, due_date) 
                           VALUES (:tenant_id, :amount, :due_date)");
    $stmt->execute([
        'tenant_id' => $tenant_id,
        'amount' => $amount,
        'due_date' => date('Y-m-d', strtotime('+7 days'))
    ]);
    
    $invoiceId = $conn->lastInsertId();
    
    // Notify tenant
    createNotification(
        $conn,
        'tenant',
        $tenant_id,
        'invoice_created',
        'New Invoice',
        'You have a new invoice of ₱' . number_format($amount, 2),
        $invoiceId,
        'invoice',
        'tenant_invoices.php'
    );
    
    header("location: admin_invoices.php?success=1");
}
?>
```

### Example 3: Auto-Notify on Schedule

```php
<?php
// In cron job or scheduled task
require_once "db/database.php";
require_once "db/notifications.php";

// Get all tenants with overdue rent
$stmt = $conn->prepare("SELECT DISTINCT tenant_id FROM bills 
                       WHERE status = 'overdue' AND notified = 0");
$stmt->execute();
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($tenants as $tenant) {
    createNotification(
        $conn,
        'tenant',
        $tenant['tenant_id'],
        'payment_overdue',
        'Payment Overdue',
        'Your payment is overdue. Please pay immediately.',
        null,
        null,
        'tenant_bills.php'
    );
    
    // Mark as notified
    $updateStmt = $conn->prepare("UPDATE bills SET notified = 1 WHERE tenant_id = :id");
    $updateStmt->execute(['id' => $tenant['tenant_id']]);
}
?>
```

---

## Helper Function Reference

### Notify All Admins Functions

```php
notifyAdminsNewRoom($conn, $roomId, $roomNumber);
notifyAdminsNewPayment($conn, $billId, $tenantId, $amount);
notifyAdminsNewMaintenance($conn, $maintenanceId, $tenantId, $category);
notifyAdminsNewRoomRequest($conn, $roomRequestId, $tenantId, $tenantCount);
```

### Notify Single Tenant Functions

```php
notifyTenantPaymentVerification($conn, $tenantId, $paymentId, $status);
notifyTenantMaintenanceStatus($conn, $tenantId, $maintenanceId, $status);
notifyTenantRoomRequestStatus($conn, $tenantId, $roomRequestId, $status);
```

### Generic Function

```php
createNotification($conn, 
    $recipientType,  // 'admin' or 'tenant'
    $recipientId,    // admin_id or tenant_id
    $type,           // 'notification_type'
    $title,          // Display title
    $message,        // Notification message
    $relatedId,      // Optional related ID
    $relatedType,    // Optional related type
    $actionUrl       // Optional redirect URL
);
```

### Retrieve Functions

```php
// Get unread count
$count = getUnreadNotificationsCount($conn, 'admin', $adminId);

// Get notifications list
$notifications = getNotifications($conn, 'tenant', $tenantId, 10, 0);

// Get single notification
$notif = getNotificationById($conn, $notificationId);
```

### Management Functions

```php
// Mark as read
markNotificationAsRead($conn, $notificationId);

// Mark all as read
markAllNotificationsAsRead($conn, 'admin', $adminId);

// Delete notification
deleteNotification($conn, $notificationId);

// Delete all for user
deleteAllNotifications($conn, 'tenant', $tenantId);
```

---

## Best Practices

### ✅ DO:
1. Use the helper functions provided
2. Always include recipient IDs
3. Provide meaningful titles
4. Include action URLs when possible
5. Use consistent notification types
6. Test notifications before deployment
7. Consider the recipient (admin or tenant)

### ❌ DON'T:
1. Don't use raw SQL INSERT - use helper functions
2. Don't forget to include notifications.php
3. Don't use empty messages
4. Don't create notifications for non-important events (to avoid spam)
5. Don't hardcode admin IDs - query from database
6. Don't skip testing

---

## Performance Tips

1. **Batch Notifications**: If notifying all admins, query admin list once
   ```php
   $admins = $conn->query("SELECT id FROM admins")->fetchAll();
   foreach ($admins as $admin) {
       createNotification(...);  // Reuse connection
   }
   ```

2. **Defer Non-Critical**: Move non-critical notifications to background job
   ```php
   // Immediate: Payment verification
   notifyTenantPaymentVerification(...);
   
   // Deferred: Daily digest notification
   // Schedule via cron job
   ```

3. **Index Queries**: Use indexed columns in WHERE clauses
   ```php
   // Good - uses recipient_type_id index
   SELECT * FROM notifications 
   WHERE recipient_type='admin' AND recipient_id=5
   
   // Slow - doesn't use indexes
   SELECT * FROM notifications 
   WHERE CONCAT(recipient_type, recipient_id) = 'admin5'
   ```

---

## Common Issues

| Problem | Solution |
|---------|----------|
| Notifications not showing | Verify `require_once "db/notifications.php"` in action file |
| Wrong recipient getting notified | Check recipient_type ('admin' vs 'tenant') and recipient_id |
| Can't find notification function | Check spelling, function is in db/notifications.php |
| Database error | Verify notifications table exists: `SHOW TABLES;` |
| JavaScript errors | Open console (F12) and check for missing files |

---

## Summary

To add notifications to any new feature:

1. **Include notifications.php**
   ```php
   require_once "db/notifications.php";
   ```

2. **Call helper function**
   ```php
   notifyAdminsNewRoom($conn, $id, $name);
   // or
   notifyTenantPaymentVerification($conn, $tenantId, $paymentId, 'approved');
   ```

3. **Test it works**
   - Trigger the action
   - Check database: `SELECT * FROM notifications`
   - Login as recipient and check bell icon

That's it! The header and API handle the rest automatically.

---

## Questions?

Refer to:
- [NOTIFICATION_SYSTEM_GUIDE.md](NOTIFICATION_SYSTEM_GUIDE.md) - Complete technical guide
- [NOTIFICATION_SYSTEM_QUICK_START.md](NOTIFICATION_SYSTEM_QUICK_START.md) - User guide
- [db/notifications.php](db/notifications.php) - Source code and comments

---

Happy notifying! 🔔
