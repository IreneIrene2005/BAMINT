# ✅ Partial Payment Alert System - ALL TENANTS

## Implementation Status: COMPLETE & SYSTEM-WIDE

This system now applies to **ALL TENANTS** who make partial payments.

---

## How It Works for Grace (and Every Other Tenant)

### Step 1: Payment Submission
```
Grace submits payment: ₱1,000
Bill amount: ₱1,200
Remaining: ₱200
```

### Step 2: Admin Reviews Payment
Admin opens Payment Verification page and sees Grace's payment.

**Automatic Detection:**
- System checks: Is ₱1,000 < ₱1,200? **YES**
- Status: **PARTIAL PAYMENT DETECTED** ⚠️

### Step 3: Visual Alert Appears
Admin sees RED WARNING BANNER:
```
🔴 PARTIAL PAYMENT DETECTED

Grace has paid ₱1,000.00 but owes ₱1,200.00

Remaining Balance: ₱200.00

⚠️ Note: The billing for this month will NOT be officially 
marked as PAID until the full amount is received. If you 
approve this partial payment, you MUST send a message to 
the tenant notifying them about the remaining balance.
```

### Step 4: Admin Chooses Action

**Option A: Admin clicks "Verify & Approve"**
- Message field appears (required)
- Admin sees pre-filled message template:
  ```
  Dear Grace,
  
  Thank you for your payment of ₱1,000.00
  
  Please note: Your billing for February 2026 is NOT YET officially paid.
  
  You still have a remaining balance of ₱200.00 that needs 
  to be paid to complete your monthly billing.
  
  Please settle the remaining amount as soon as possible.
  
  Thank you for your cooperation.
  ```
- Admin can edit or customize message
- System shows: "This message will be sent to the tenant via notification"

**Option B: Admin clicks "Reject"**
- Payment rejected
- Message field hidden
- Bill not updated

### Step 5: Admin Confirms Approval
- Admin clicks "Submit Verification"
- System processes:
  1. ✅ Payment marked as "verified"
  2. ✅ Bill marked as "partial" (NOT "paid")
  3. ✅ Notification created: "Partial Payment Received"
  4. ✅ Message sent to Grace's inbox
  5. ✅ Notification bell rings for Grace
  6. ✅ Grace receives message with her remaining balance

### Step 6: Tenant (Grace) Sees Status

**In Tenant Dashboard:**
- Remaining balance card shows: ₱200.00 (RED, unpaid)
- Can't mark bill as fully paid

**In Tenant Messages (Inbox):**
- New message from Admin about partial payment
- Shows remaining balance owed
- Notification bell shows new message

**Monthly Billing Status:**
- ❌ NOT officially "PAID"
- ⚠️ PARTIAL - Awaiting ₱200.00 more

---

## For ALL Tenants - Complete Examples

### Tenant A: Maria
- Bill: ₱2,000
- Pays: ₱1,500
- Remaining: ₱500
- ✅ Gets partial payment alert
- ✅ Admin sends message
- ✅ Bill marked "partial"
- ✅ Maria sees notification

### Tenant B: Juan  
- Bill: ₱1,500
- Pays: ₱1,000
- Remaining: ₱500
- ✅ Gets partial payment alert
- ✅ Admin sends message
- ✅ Bill marked "partial"
- ✅ Juan sees notification

### Tenant C: Grace
- Bill: ₱1,200
- Pays: ₱1,000
- Remaining: ₱200
- ✅ Gets partial payment alert
- ✅ Admin sends message
- ✅ Bill marked "partial"
- ✅ Grace sees notification

### Tenant D: Anna (Pays Full)
- Bill: ₱1,000
- Pays: ₱1,000
- Remaining: ₱0
- ❌ NO alert (payment is complete)
- ❌ NO message required
- ✅ Bill marked "paid"
- ✅ Anna doesn't see partial warning

---

## Technical Implementation

### Backend Processing (admin_payment_verification.php, Lines 50-66)

```php
// Detect if partial payment
$total_paid = 1000;  // Grace's payment
$amount_due = 1200;  // Bill amount
$bill_status = ($total_paid >= $amount_due) ? 'paid' : 'partial';

// If partial: true
if ($bill_status === 'partial') {
    // 1. Create notification about partial payment
    notifyPartialPayment($conn, $tenant_id, $bill_id, 1200, 1000, $payment_id);
    
    // 2. Send admin's message to tenant
    if (!empty($partial_payment_message)) {
        sendMessage(
            $conn, 'admin', $admin_id, 'tenant', $tenant_id,
            'Partial Payment Notice - Remaining Balance',
            $partial_payment_message,
            'bill', $bill_id
        );
    }
}
```

### Frontend Display (admin_payment_verification.php, Lines 569-647)

**For Each Pending Payment:**
1. Calculate if partial: `$is_partial = $payment_amount < $payment['amount_due']`
2. If partial → Show alert warning
3. If partial → Show message field when "Verify & Approve" selected
4. Message field required for approval

### Database Updates

| Field | Before | After |
|-------|--------|-------|
| bill.status | null/unpaid | "partial" |
| messages table | - | Message inserted |
| notifications | - | Partial payment notification created |

---

## Key Features

✅ **System-Wide**
- Applies to ALL tenants, not just Grace
- Works for every payment that's partial

✅ **Automatic Detection**
- No manual checking needed
- Triggered for every payment < bill amount

✅ **Admin Control**
- Admin can approve partial payments
- Admin MUST send message
- Message customizable

✅ **Tenant Notification**
- Notification bell alerts tenant
- Message shows in inbox
- Shows remaining balance
- Clear that billing is "partial"

✅ **Bill Tracking**
- Bill status = "partial" (not "paid")
- Remaining balance tracked
- Can pay multiple times (multiple partial payments allowed)

✅ **Clarity**
- Admin sees warning: billing won't be "official" until full paid
- Admin sees message is required
- Tenant sees they haven't fully paid
- Monthly billing won't close until 100% paid

---

## User Flow Diagram

```
ADMIN VIEW (Payment Verification)
    ↓
[See Grace's payment: ₱1,000 of ₱1,200]
    ↓
[System auto-detects: PARTIAL ⚠️]
    ↓
[RED ALERT appears with details]
    ↓
[Admin chooses "Verify & Approve"]
    ↓
[Message field appears - REQUIRED]
    ↓
[Admin writes/edits message]
    ↓
[Admin clicks Submit Verification]
    ↓
Processing:
├─ Payment → "verified"
├─ Bill → "partial"
├─ Notification → Partial payment alert
├─ Message → Sent to tenant
└─ Notification bell → Alert tenant

                    ↓

TENANT VIEW (Grace's Dashboard)
    ↓
[Notification bell shows NEW MESSAGE 🔔]
    ↓
[Clicks "Messages" in sidebar]
    ↓
[Reads admin's message about ₱200 remaining]
    ↓
[Dashboard shows Remaining Balance: ₱200 (RED)]
    ↓
[Knows: Billing is PARTIAL - must pay ₱200 more]
    ↓
Grace makes another payment of ₱200
    ↓
[Billing becomes FULLY PAID]
```

---

## Testing the System

### Test Scenario: All Tenants

Create test payments for multiple tenants:

1. **Maria: ₱1,500 of ₱2,000**
   - Verify & approve with message
   - Expected: Partial alert, message sent, bill = "partial"

2. **Juan: ₱1,000 of ₱1,500**
   - Verify & approve with message
   - Expected: Partial alert, message sent, bill = "partial"

3. **Grace: ₱1,000 of ₱1,200**
   - Verify & approve with message
   - Expected: Partial alert, message sent, bill = "partial"

4. **Anna: ₱2,000 of ₱2,000**
   - Verify & approve (NO message needed)
   - Expected: NO alert (full payment), bill = "paid"

---

## Summary

✅ **System Status: COMPLETE & ACTIVE FOR ALL TENANTS**

The partial payment alert and message system is:
- **Automatic** - Triggers for any payment < bill amount
- **System-wide** - Works for ALL tenants
- **Admin-controlled** - Admin approves and sends message
- **Tenant-visible** - Tenant sees notification and message
- **Bill-tracking** - Bill marked "partial" until fully paid

**No exclusions. No tenant skipped. Everyone gets the same system.**

