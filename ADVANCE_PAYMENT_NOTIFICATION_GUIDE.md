# Advance Payment Approval Notification System

## Overview
When an admin approves an advance payment (move-in payment) for a tenant, the system automatically generates a notification with timestamp. The tenant will see it in real-time through the notification bell system.

## How It Works

### 1. **Admin Approves Advance Payment**
- Admin logs into `admin_payment_verification.php`
- Views pending payments awaiting verification
- Clicks "Verify" button on an advance payment
- System updates payment status to 'verified'

### 2. **Automatic Notification Generation**
- Payment approval triggers `notifyTenantPaymentVerification()` function
- Function checks if payment is for an advance payment bill
- Identifies advance payments by "ADVANCE PAYMENT" text in bill notes
- Creates specific notification with:
  - **Title**: ✅ Advance Payment Approved!
  - **Message**: Your advance payment of ₱[amount] has been verified and approved by admin. You can now move in!
  - **Timestamp**: Automatically recorded (created_at field)
  - **Link**: payment_history.php

### 3. **Tenant Receives Notification**
- Notification stored in `notifications` table
- Tenant's browser polls API every 30 seconds
- Notification bell badge count increases
- Tenant clicks bell to view full notification
- Modal opens showing:
  - Notification title with checkmark icon
  - Amount approved
  - Approval message
  - Exact timestamp
  - Link to payment history

## Technical Implementation

### Code Location
**File**: [db/notifications.php](db/notifications.php#L281)  
**Function**: `notifyTenantPaymentVerification()`

### Implementation Details

```php
function notifyTenantPaymentVerification($conn, $tenantId, $paymentId, $status) {
    // Get payment and bill details
    $payment_stmt = $conn->prepare("
        SELECT pt.bill_id, b.notes, b.amount_due, b.billing_month
        FROM payment_transactions pt
        JOIN bills b ON pt.bill_id = b.id
        WHERE pt.id = :id
    ");
    
    // Check if it's an advance payment
    $isAdvancePayment = strpos($payment_details['notes'], 'ADVANCE PAYMENT') !== false;
    
    // Create specific notification for advance payments
    if ($isAdvancePayment) {
        $title = '✅ Advance Payment Approved!';
        $message = 'Your advance payment of ₱' . 
                   number_format($payment_details['amount_due'], 2) . 
                   ' has been verified and approved by admin. You can now move in!';
    }
    
    // Insert notification with timestamp
    createNotification($conn, 'tenant', $tenantId, 'payment_verified', 
                      $title, $message, $paymentId, 'payment_transaction', 
                      'payment_history.php');
}
```

### Database Schema
**Table**: `notifications`

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| recipient_type | VARCHAR | 'admin' or 'tenant' |
| recipient_id | INT | Admin ID or Tenant ID |
| notification_type | VARCHAR | 'payment_verified' |
| title | VARCHAR | '✅ Advance Payment Approved!' |
| message | TEXT | Full message with amount |
| related_id | INT | Payment transaction ID |
| related_type | VARCHAR | 'payment_transaction' |
| action_url | VARCHAR | 'payment_history.php' |
| created_at | TIMESTAMP | Time of approval (auto-generated) |
| is_read | BOOLEAN | false by default |

### Trigger Points
1. **admin_payment_verification.php** (line 87)
   - When admin clicks "Verify" on payment
   - Calls `notifyTenantPaymentVerification($conn, $tenant_id, $payment_id, 'approved')`

### Auto-Polling System
- **Frequency**: Every 30 seconds
- **Endpoint**: api_notifications.php?action=get_count
- **Frontend**: templates/header.php (JavaScript)
- **Badge Updates**: Real-time without page reload

## Notification Timeline

### Example Flow:
```
1. 2:30 PM - Admin approves advance payment of ₱15,000
   └─ notifyTenantPaymentVerification() called
   └─ Notification inserted into database with timestamp 2:30 PM

2. 2:30:15 PM - Frontend polls for notifications
   └─ api_notifications.php returns updated count
   └─ Notification bell badge shows "1"

3. 2:30:30 PM - Tenant sees notification bell with badge
   └─ Clicks bell icon
   └─ Modal opens showing:
      ✅ ADVANCE PAYMENT APPROVED!
      Your advance payment of ₱15,000.00 has been verified 
      and approved by admin. You can now move in!
      [Approved at 2:30 PM on Jan 28, 2026]

4. Tenant clicks notification
   └─ Marked as read
   └─ Redirected to payment_history.php
   └─ Badge count decreases
```

## Tenant User Experience

### Before (Old System)
- ❌ No automatic notification
- ❌ Tenant doesn't know when payment approved
- ❌ Must refresh page to see status changes
- ❌ Confusion about next steps

### After (New System)
- ✅ Real-time notification with bell icon
- ✅ Clear message with exact amount
- ✅ Timestamp showing when approved
- ✅ Auto-updates every 30 seconds
- ✅ Tenant knows can now move in
- ✅ One-click access to payment history

## Features Enabled

### Automatic Features
1. **Smart Detection**
   - Identifies advance payments automatically
   - Distinguishes from regular payment approvals
   - Customizes message accordingly

2. **Timestamp Recording**
   - Automatic database timestamp via NOW()
   - Shown in notification modal
   - Tracks exact approval time

3. **Rich Message**
   - Includes approved amount
   - User-friendly formatting with ₱ symbol
   - Clear next action (move in)

4. **Real-time Delivery**
   - No manual sending required
   - Instant database insertion
   - Auto-refresh from frontend

5. **Persistent Record**
   - Stored in notifications table
   - Can be viewed later
   - Marked as read/unread

## Configuration Options

### Current Settings
```php
// Advance payment detection
$isAdvancePayment = strpos($payment_details['notes'], 'ADVANCE PAYMENT') !== false;

// Notification type
$type = 'payment_verified';

// Navigation link
$actionUrl = 'payment_history.php';

// Polling frequency
Auto-refresh: 30 seconds (in templates/header.php)
```

### Customization Points
To customize the notification message, edit [db/notifications.php](db/notifications.php#L293):

```php
// Change the title
$title = '✅ Your Advance Payment Approved!'; // Line 293

// Change the message
$message = 'Custom message here'; // Line 294-296
```

## Testing Checklist

- [ ] Admin logs in to admin_payment_verification.php
- [ ] Click "Verify" on an advance payment
- [ ] Check that notification bell badge appears (within 30 seconds)
- [ ] Click notification bell
- [ ] Verify notification shows:
  - [ ] ✅ Advance Payment Approved! title
  - [ ] Amount in message
  - [ ] Timestamp
  - [ ] Move-in message
- [ ] Click notification
- [ ] Verify redirects to payment_history.php
- [ ] Check database: SELECT * FROM notifications WHERE notification_type = 'payment_verified'
- [ ] Verify created_at timestamp matches approval time

## Troubleshooting

### Notification Not Appearing
1. Check if payment was actually updated to 'verified'
   ```sql
   SELECT * FROM payment_transactions WHERE id = [payment_id];
   ```

2. Verify notification was created
   ```sql
   SELECT * FROM notifications 
   WHERE recipient_id = [tenant_id] 
   AND notification_type = 'payment_verified' 
   ORDER BY created_at DESC;
   ```

3. Check browser console for JavaScript errors
   - Open DevTools (F12)
   - Check Console tab
   - Look for errors in api_notifications.php calls

### Incorrect Message
1. Verify advance payment bill has "ADVANCE PAYMENT" in notes
   ```sql
   SELECT notes FROM bills WHERE id = [bill_id];
   ```

2. Check if amount is formatting correctly
   - Verify amount_due is numeric
   - Check PHP number_format() function

### Timestamp Not Showing
1. Check if created_at field exists in notifications table
2. Verify database timezone settings
3. Check if notification modal is displaying created_at

## Related Files

- [admin_payment_verification.php](admin_payment_verification.php#L87) - Triggers notification
- [db/notifications.php](db/notifications.php#L281) - Notification function
- [api_notifications.php](api_notifications.php) - AJAX polling endpoint
- [templates/header.php](templates/header.php) - Notification bell UI
- [tenant_payments.php](tenant_payments.php) - Tenant payment page
- [tenant_dashboard.php](tenant_dashboard.php) - Tenant dashboard

## See Also
- [NOTIFICATION_SYSTEM_GUIDE.md](NOTIFICATION_SYSTEM_GUIDE.md) - Full notification system documentation
- [MOVE_IN_PAYMENT_WORKFLOW.md](MOVE_IN_PAYMENT_WORKFLOW.md) - Move-in payment workflow
- [NOTIFICATION_SYSTEM_DEVELOPER_GUIDE.md](NOTIFICATION_SYSTEM_DEVELOPER_GUIDE.md) - Developer reference
