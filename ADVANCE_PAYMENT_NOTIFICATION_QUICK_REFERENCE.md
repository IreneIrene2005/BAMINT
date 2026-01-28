# Advance Payment Notification - Quick Reference

## What Was Changed

### Modified File
- **db/notifications.php** - Updated `notifyTenantPaymentVerification()` function

### What It Does Now
✅ **Automatically detects** if a payment is for an advance payment (move-in)  
✅ **Generates custom notification** with amount and approval message  
✅ **Records timestamp** automatically when admin approves  
✅ **Sends to tenant** via notification bell system in real-time  

## User Flow

```
Admin approves advance payment
        ↓
Notification auto-generated with:
  • Title: ✅ Advance Payment Approved!
  • Message: Your advance payment of ₱[amount] has been 
    verified and approved by admin. You can now move in!
  • Timestamp: Auto-recorded
        ↓
Tenant's notification bell updates (within 30 seconds)
  • Badge shows notification count
  • Bell icon appears
        ↓
Tenant clicks notification bell
  • Modal opens
  • Shows full message with timestamp
  • One-click link to payment_history.php
```

## Example Notification

```
┌─────────────────────────────────────────┐
│ ✅ ADVANCE PAYMENT APPROVED!            │
├─────────────────────────────────────────┤
│ Your advance payment of ₱15,000.00 has │
│ been verified and approved by admin.    │
│ You can now move in!                    │
│                                         │
│ Approved on Jan 28, 2026 at 2:30 PM    │
│                                         │
│ [View Details]                          │
└─────────────────────────────────────────┘
```

## Implementation Details

### How It Detects Advance Payments
```php
// Checks if bill notes contain "ADVANCE PAYMENT" text
$isAdvancePayment = strpos($payment_details['notes'], 'ADVANCE PAYMENT') !== false;
```

### How Timestamp Works
```php
// Database inserts current time automatically
INSERT INTO notifications (..., created_at) VALUES (..., NOW())
```

### Where It's Triggered
**File**: admin_payment_verification.php  
**Line**: 87  
**Action**: When admin clicks "Verify" on a payment

## Features
| Feature | Details |
|---------|---------|
| Auto-Detection | Identifies advance payments automatically |
| Custom Message | Different for advance vs regular payments |
| Amount Display | Shows exact approved amount |
| Timestamp | Records exact approval date/time |
| Real-Time | Updates within 30 seconds |
| Persistent | Stored in database permanently |
| Navigation | One-click link to payment history |

## Testing

1. **Admin approves advance payment** in admin_payment_verification.php
2. **Tenant views notification bell** (within 30 seconds)
3. **Verify message shows**:
   - ✅ Advance Payment Approved! title
   - Approved amount (₱15,000.00 format)
   - "Can now move in" message
   - Exact timestamp

## Database Query to Check
```sql
SELECT * FROM notifications 
WHERE recipient_id = [tenant_id] 
  AND notification_type = 'payment_verified'
ORDER BY created_at DESC;
```

## No Additional Configuration Needed
✅ Already integrated into existing system  
✅ Automatic polling every 30 seconds  
✅ No manual setup required  
✅ Works with current notification bell  

## Files Updated
- ✅ db/notifications.php (notifyTenantPaymentVerification function)
- ✅ ADVANCE_PAYMENT_NOTIFICATION_GUIDE.md (created)

## Ready to Use
The system is **live and active**. When an admin approves an advance payment, the tenant will automatically receive a notification with the current timestamp within 30 seconds.
