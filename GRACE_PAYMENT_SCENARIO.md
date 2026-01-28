# 📊 Grace's Partial Payment Scenario - Complete Walkthrough

**Scenario:** Grace pays ₱1,000 but owes ₱1,200  
**System:** BAMINT Payment Verification & Messaging  
**Date:** January 28, 2026  

---

## 🎯 The Situation

```
GRACE'S BILL (February 2026)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Amount Due:     ₱1,200.00
Amount Paid:    ₱1,000.00
Remaining:      ₱200.00 ⚠️

Payment Method: GCash
Payment Date:   Jan 28, 2026 22:29 PM
Status:         Pending Verification
```

---

## 👨‍💼 ADMIN'S EXPERIENCE

### Step 1: Admin Opens Payment Verification Page
```
Admin clicks: Payments → Review Payments
Sees Grace's payment in queue
Opens her payment to review
```

### Step 2: Admin Sees the Alert ⚠️
```
════════════════════════════════════════════════════════════
┌─────────────────────────────────────────────────────────┐
│  ⚠️  PARTIAL PAYMENT DETECTED                            │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  Grace has paid ₱1,000.00 but owes ₱1,200.00            │
│                                                          │
│  Remaining Balance: ₱200.00                             │
│                                                          │
│  ⚠️ Note: The billing for this month will NOT be        │
│     officially marked as PAID until the full amount is  │
│     received. If you approve this partial payment,      │
│     you MUST send a message to the tenant notifying     │
│     them about the remaining balance.                   │
│                                                          │
└─────────────────────────────────────────────────────────┘
════════════════════════════════════════════════════════════
```

**Color:** Orange/Yellow (Warning)  
**Icon:** Exclamation triangle  
**Position:** Prominent, above form  
**Cannot be dismissed:** No X button (important!)

### Step 3: Admin Reviews Payment Details
```
Tenant Information
├─ Name: grace
├─ Email: grace@gmail.com
└─ Phone: xxxxxxxxxx

Payment Details
├─ Amount: ₱1,000.00 (in blue)
├─ Method: GCash
├─ Submitted: Jan 28, 2026 • 22:29 PM
└─ Status: Pending [clock icon]

Billing Information
├─ Billing Month: February 2026
├─ Amount Due: ₱1,200.00 ← FULL AMOUNT
├─ Already Paid: ₱0.00
└─ Balance: ₱1,200.00

Proof of Payment: [GCash receipt image visible]
```

### Step 4: Admin Decision - Two Options
```
┌────────────────────────────────────────────────────┐
│         VERIFICATION DECISION                      │
├────────────────────────────────────────────────────┤
│                                                    │
│  [✓ Verify & Approve]    [✗ Reject]               │
│                                                    │
└────────────────────────────────────────────────────┘

Option A: REJECT
→ Payment stays pending
→ Can request more documentation
→ Tenant can resubmit

Option B: VERIFY & APPROVE (what Grace's admin does)
→ Must write message to tenant
→ Message field appears
→ Message becomes REQUIRED
```

### Step 5: Admin Clicks "Verify & Approve"
```
Admin selects the "Verify & Approve" radio button
═══════════════════════════════════════════════════════

✨ MAGIC HAPPENS ✨

═══════════════════════════════════════════════════════

A NEW FIELD APPEARS BELOW:
```

### Step 6: Message Field Appears (Was Hidden)
```
✨ MESSAGE FIELD NOW VISIBLE ✨

┌─────────────────────────────────────────────────────┐
│  📝 MESSAGE TO TENANT (REQUIRED)                     │
├─────────────────────────────────────────────────────┤
│                                                     │
│  ⚠️ Required: You must send a message to the        │
│     tenant about the remaining balance               │
│     (₱200.00) before approving.                      │
│                                                     │
│  ┌──────────────────────────────────────────────┐  │
│  │ Dear grace,                                  │  │
│  │                                              │  │
│  │ Thank you for your payment of ₱1,000.00.    │  │
│  │                                              │  │
│  │ Please note: Your billing for February 2026 │  │
│  │ is NOT YET officially paid.                 │  │
│  │                                              │  │
│  │ You still have a remaining balance of       │  │
│  │ ₱200.00 that needs to be paid to complete  │  │
│  │ your monthly billing.                       │  │
│  │                                              │  │
│  │ Please settle the remaining amount as soon  │  │
│  │ as possible.                                │  │
│  │                                              │  │
│  │ Thank you for your cooperation.             │  │
│  └──────────────────────────────────────────────┘  │
│                                                     │
│  💡 Admin can edit the template above              │
│                                                     │
│  🔔 This message will be sent to the tenant via    │
│     notification and they will receive it in       │
│     their message inbox.                           │
│                                                     │
└─────────────────────────────────────────────────────┘
```

**Key Features:**
- ✅ Shows when "Verify & Approve" selected
- ✅ Hidden when "Reject" selected
- ✅ Pre-filled with helpful template
- ✅ Admin can edit freely
- ✅ Shows where tenant will see it (bell + inbox)
- ✅ Clear that it's REQUIRED

### Step 7: Admin Reviews/Customizes Message
```
Admin options:
1. Use template as-is ← Most common
2. Customize message
3. Add more details about payment plan
4. Refer to company policy
```

**Example Customization:**
```
Dear Grace,

Thank you for your payment of ₱1,000.00 for February 2026.

Your remaining balance is ₱200.00. Please settle this 
as soon as possible to avoid late fees.

You can pay through:
- GCash: [number]
- Bank transfer: [account]
- In-person: [address]

Contact me if you have any questions.

Best regards,
Admin Team
```

### Step 8: Admin Submits Form
```
Admin clicks: [✓ Submit Verification] button

System checks:
1. Is "Verify & Approve" selected? ✅ YES
2. Is message field filled? ✅ MUST BE
3. Is message not empty? ✅ REQUIRED

If message is empty:
┌─────────────────────────────────────┐
│  ❌ ERROR (JavaScript validation)    │
├─────────────────────────────────────┤
│  Please write a message to the      │
│  tenant about the remaining balance │
│  before approving the partial       │
│  payment.                           │
└─────────────────────────────────────┘
```

### Step 9: System Processes Approval
```
BACKEND PROCESSING:
═══════════════════════════════════════════════════════

1. ✅ Verify payment in database
   UPDATE payment_transactions SET status = 'verified'

2. ✅ Mark bill as PARTIAL (not paid)
   UPDATE bills SET status = 'partial'
   (Because: ₱1,000 < ₱1,200)

3. ✅ Create system notification
   Partial payment notification auto-generated
   "Grace paid ₱1,000... remaining ₱200"

4. ✅ Send admin's message
   INSERT into messages table
   FROM: admin (verified_by)
   TO: grace (tenant)
   Subject: "Partial Payment Notice - Remaining Balance"
   Content: [The message admin wrote]

5. ✅ Create message notification
   Notify Grace that she has new message
   Show notification bell 🔔

6. ✅ Return success message
   Display: "✓ Payment verified and recorded successfully!"
```

### Step 10: Admin Sees Confirmation
```
═══════════════════════════════════════════════════════
✓ Payment verified and recorded successfully!
═══════════════════════════════════════════════════════

Grace's payment:
✅ Status: Verified
✅ Amount: ₱1,000.00 (approved)
✅ Bill Status: Partial (₱200 remaining)
✅ Message Sent: YES
✅ Grace Notified: YES
```

---

## 👩‍💼 GRACE'S EXPERIENCE (TENANT)

### Step 1: Grace Checks Her Notifications 🔔
```
Grace opens BAMINT app or website
Sees notification bell in top right: 🔔 (NEW)

Clicks bell → Notification dropdown opens:
```

### Step 2: Grace Sees Notification Alert
```
╔════════════════════════════════════════════╗
║  NOTIFICATIONS                        🔔   ║
╠════════════════════════════════════════════╣
║                                            ║
║  ◆ NEW MESSAGE from Admin                  ║
║    "Partial Payment Notice - Remaining..." ║
║    Jan 28, 2026 • 22:45 PM                 ║
║                                            ║
║  ◆ PARTIAL PAYMENT RECEIVED                ║
║    "Your payment of ₱1,000 was received"   ║
║    Remaining balance: ₱200.00              ║
║    Jan 28, 2026 • 22:45 PM                 ║
║                                            ║
╚════════════════════════════════════════════╝

Grace sees TWO notifications:
1. System notification (automatic)
2. Admin's custom message (from admin)
```

### Step 3: Grace Clicks Message Notification
```
Grace clicks: "Partial Payment Notice - Remaining..."

Browser opens: Messages page (tenant_messages.php)

Message displayed:
╔════════════════════════════════════════════════════╗
║  MESSAGE FROM ADMIN                               ║
╠════════════════════════════════════════════════════╣
║                                                    ║
║  From: Admin                                       ║
║  Subject: Partial Payment Notice - Remaining Bal. ║
║  Date: Jan 28, 2026 • 22:45 PM                    ║
║  Status: Unread → Read ✓                          ║
║                                                    ║
║  ┌──────────────────────────────────────────────┐ ║
║  │ Dear grace,                                  │ ║
║  │                                              │ ║
║  │ Thank you for your payment of ₱1,000.00.    │ ║
║  │                                              │ ║
║  │ Please note: Your billing for February 2026 │ ║
║  │ is NOT YET officially paid.                 │ ║
║  │                                              │ ║
║  │ You still have a remaining balance of       │ ║
║  │ ₱200.00 that needs to be paid to complete  │ ║
║  │ your monthly billing.                       │ ║
║  │                                              │ ║
║  │ Please settle the remaining amount as soon  │ ║
║  │ as possible.                                │ ║
║  │                                              │ ║
║  │ Thank you for your cooperation.             │ ║
║  └──────────────────────────────────────────────┘ ║
║                                                    ║
╚════════════════════════════════════════════════════╝
```

### Step 4: Grace Checks Dashboard
```
Grace clicks: Dashboard

Sees metric card:
┌─────────────────────────────────┐
│  REMAINING BALANCE              │ ← Red border
│                                 │
│        ₱200.00                  │ ← Big red text
│                                 │
│    Amount due                   │ ← Label
└─────────────────────────────────┘

Grace understands:
✅ "I paid ₱1,000"
✅ "I still owe ₱200"
✅ "My bill is NOT officially paid yet"
✅ "I need to pay the remaining amount"
```

### Step 5: Grace Pays Remaining ₱200
```
Grace goes to: Payments → Make Payment

Submits payment: ₱200.00

System processes:
1. Payment created: ₱200
2. Admin verifies
3. Now total paid: ₱1,000 + ₱200 = ₱1,200 ✅
4. Bill marked as: PAID ✓
5. Grace notified
6. Dashboard shows: ₱0.00 (all paid up!)
```

---

## 📊 WHAT CHANGED IN THE SYSTEM

### Bill Status Flow
```
BEFORE (Without this feature):
Payment ₱1,000 submitted
  ↓
Admin approves without message
  ↓
Bill marked as PAID (wrong! Should be partial)
  ↓
Grace thinks she's paid in full (confusion!)
  ↓
No communication about remaining ₱200

AFTER (With this feature):
Payment ₱1,000 submitted
  ↓
Admin sees alert: "Partial payment!"
  ↓
Admin MUST write message to Grace
  ↓
Bill marked as PARTIAL (correct!)
  ↓
Grace gets message: "You still owe ₱200"
  ↓
Grace dashboard shows: ₱200 remaining
  ↓
Clear communication, no confusion
```

### Data in System
```
BILLS TABLE
╔════╦════════════╦═══════════╦═══════════╦════════╗
║ ID ║ tenant_id  ║ month     ║ amount_due║ status ║
╠════╬════════════╬═══════════╬═══════════╬════════╣
║ 5  ║ grace      ║ 2026-02-01║ 1200.00   ║ partial║ ← Was unpaid, now partial
╚════╩════════════╩═══════════╩═══════════╩════════╝

PAYMENT_TRANSACTIONS TABLE
╔════╦════════════╦════════════════╦════════════════╗
║ ID ║ tenant_id  ║ payment_amount ║ payment_status ║
╠════╬════════════╬════════════════╬════════════════╣
║ 5  ║ grace      ║ 1000.00        ║ verified       ║ ← Added
╚════╩════════════╩════════════════╩════════════════╝

MESSAGES TABLE
╔════╦════════╦════════════╦════════════╦═════════════════════╗
║ ID ║ from   ║ to         ║ subject    ║ message             ║
╠════╬════════╬════════════╬════════════╬═════════════════════╣
║ 12 ║ admin  ║ grace      ║ Partial... ║ Dear grace, Thank.. ║ ← Added
╚════╩════════╩════════════╩════════════╩═════════════════════╝

NOTIFICATIONS TABLE
╔════╦════════════╦═════════════════════╦═══════════════╗
║ ID ║ tenant_id  ║ type                ║ is_read       ║
╠════╬════════════╬═════════════════════╬═══════════════╣
║ 5  ║ grace      ║ partial_payment     ║ false         ║ ← Auto-generated
║ 6  ║ grace      ║ message_received    ║ false         ║ ← Auto-generated
╚════╩════════════╩═════════════════════╩═══════════════╝
```

---

## ✅ SUCCESS METRICS

### What Gets Accomplished:

✅ **Transparency**
- Admin knows exactly: "Grace paid ₱1,000, owes ₱200"
- Grace knows exactly: "I paid ₱1,000, owe ₱200, bill not official yet"
- No confusion about billing status

✅ **Accountability**
- Admin must communicate remaining balance
- Grace receives communication
- Paper trail of messages (auditable)

✅ **Communication**
- Two-way message system in place
- Grace can respond if needed
- Admin can send follow-ups

✅ **Accuracy**
- Bill correctly marked as "partial" (not "paid")
- Remaining balance correctly calculated
- Shows in Outstanding Bills section

✅ **User Experience**
- Clear visual alerts (orange warning)
- Easy to understand what's happening
- Mobile responsive
- Notification bell alert

✅ **Compliance**
- System enforces communication
- Can't approve partial without message
- Creates permanent record
- Prevents accidental errors

---

## 📞 SUPPORT

**Document:** See [PARTIAL_PAYMENT_ALERT_FEATURE.md](PARTIAL_PAYMENT_ALERT_FEATURE.md)  
**Quick Guide:** See [QUICK_PARTIAL_PAYMENT_GUIDE.md](QUICK_PARTIAL_PAYMENT_GUIDE.md)  
**Files Modified:** `admin_payment_verification.php` (syntax verified ✅)  

---

**Implementation Status:** ✅ **COMPLETE & LIVE**

All scenarios tested and working as expected!

