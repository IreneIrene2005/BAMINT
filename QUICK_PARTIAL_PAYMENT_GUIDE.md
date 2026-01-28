# 🚀 Quick Implementation - Partial Payment Alert System

**What Was Added:** Alert when tenant doesn't pay full amount, require admin to message tenant  
**Where It Works:** Admin Payment Verification page  
**Status:** ✅ Ready to use  

---

## What Admin Sees Now

### Before (Grace pays ₱1,000 of ₱1,200):
```
Simple form to approve/reject payment
```

### After (Grace pays ₱1,000 of ₱1,200):
```
⚠️ PARTIAL PAYMENT DETECTED
━━━━━━━━━━━━━━━━━━━━━━━━━━━
Grace has paid ₱1,000.00 but owes ₱1,200.00
Remaining Balance: ₱200.00

Note: The billing will NOT be officially marked as PAID 
until the full amount is received. If you approve this 
partial payment, you MUST send a message...

[Verify & Approve] [Reject] buttons

→ If admin clicks "Verify & Approve":

📝 MESSAGE TO TENANT (REQUIRED)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️ Required: You must send a message about remaining balance

Dear Grace,
Thank you for your payment of ₱1,000.00.
Please note: Your billing for February 2026 is NOT YET officially paid.
You still have a remaining balance of ₱200.00...
[TEXT AREA - admin can edit or use template]

This message will be sent to tenant via notification & inbox.
[Submit Verification button]
```

---

## How It Works

### Step 1: Payment Detection
```
System checks: payment_amount < amount_due?
YES → Show alert and conditional message field
NO → Show normal form (no alert)
```

### Step 2: Admin Approves Partial Payment
```
Admin clicks "Verify & Approve"
↓
Message field appears (was hidden)
↓
Must write/approve message
↓
Cannot submit without message (blocked by JavaScript)
```

### Step 3: Message Sent Automatically
```
Admin submits form with message
↓
PHP backend processes:
  - Mark payment as "verified"
  - Mark bill as "partial" (not "paid")
  - Send admin's message to tenant
  - Create notification
↓
Tenant receives notification 🔔
Tenant can read message 📬
```

---

## For Grace (The Tenant)

### What She Sees:
1. **Notification Bell** 🔔 → "New message from Admin"
2. **Click Bell** → Message preview appears
3. **Click Full Message** → Message opens:

```
Partial Payment Notice - Remaining Balance
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Dear Grace,

Thank you for your payment of ₱1,000.00.

Please note: Your billing for February 2026 is NOT YET officially paid.

You still have a remaining balance of ₱200.00 that needs to be paid 
to complete your monthly billing.

Please settle the remaining amount as soon as possible.

Thank you for your cooperation.
```

4. **Check Dashboard** → Remaining Balance shows: **₱200.00** (in RED)

---

## Files Changed

**Modified:** `admin_payment_verification.php`
- Added partial payment alert UI
- Added conditional message field
- Added JavaScript validation
- Updated PHP backend to send message

**No Database Changes Needed**
- Uses existing `messages` table
- Uses existing `notifications` table
- Uses existing `bills` table

---

## Key Points

✅ **Only shows for partial payments** (amount < due)  
✅ **Message is required** to approve (can't submit empty)  
✅ **Pre-filled template** admin can customize  
✅ **Auto-sends to tenant** when approved  
✅ **Notifies tenant** via bell and message inbox  
✅ **Bill stays partial** until fully paid  
✅ **Shows in Outstanding Bills** for tracking  

---

## Admin Workflow

```
1. Grace pays ₱1,000 on ₱1,200 bill
   ↓
2. Admin goes to Payment Verification
   ↓
3. Sees alert: "Grace paid ₱1,000... owes ₱1,200"
   ↓
4. Clicks "Verify & Approve"
   ↓
5. Message field appears with template
   ↓
6. Customizes or uses template
   ↓
7. Clicks "Submit Verification"
   ↓
8. ✅ Payment approved
   ✅ Bill marked as "partial"
   ✅ Message sent to Grace
   ✅ Grace notified
```

---

## Testing

**Try This:**
1. Login as admin
2. Go to Payment Verification
3. Find a payment where `amount_paid < amount_due`
4. See the orange alert appear
5. Click "Verify & Approve"
6. See message field appear
7. Try to submit without message → gets blocked
8. Write/edit message
9. Submit
10. Check tenant's message inbox → message appears
11. Check tenant's notification bell → shows new message

---

**Status:** ✅ Ready  
**Documentation:** See PARTIAL_PAYMENT_ALERT_FEATURE.md for details  

