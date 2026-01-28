# ✅ Tenant Partial Payment Notification System

## How It Works (Complete Flow)

### **Step 1: Admin Approves Partial Payment**
```
Grace's Bill: ₱1,200
Grace Pays: ₱1,000
Remaining: ₱200

Admin reviews payment and clicks: "Verify & Approve"
Admin writes message about remaining balance
Admin clicks: "Submit Verification"
```

### **Step 2: System Processes Payment**
Backend automatically:
1. ✅ Marks payment as "verified"
2. ✅ Marks bill as "partial" (not "paid")
3. ✅ Creates notification for admin
4. ✅ **Creates notification for tenant** ← NEW/ENHANCED
5. ✅ Sends admin's message to tenant inbox

### **Step 3: Tenant Receives Notification**

#### **Notification Bell Shows Alert**
```
Tenant logs into their dashboard
├─ NOTIFICATION BELL appears in top-right
│  └─ Red badge showing "1" unread notification 🔔
│
└─ Clicks bell icon
   └─ Modal opens showing notifications
```

#### **Notification Message Details**
The tenant will see:
```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️ Partial Payment - February 2026 Billing
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Your payment of ₱1,000.00 was received.
Remaining balance: ₱200.00.
Your monthly billing will NOT be officially marked 
as PAID until you pay the full amount.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Received: Jan 28, 2026 • 22:35 PM
```

### **Step 4: Tenant Takes Action**

#### **Option A: Read the Message**
1. Click on notification
2. Notification marked as "read"
3. Message modal shows admin's details
4. Can navigate to bills page

#### **Option B: Check Messages**
1. Click "Messages" in sidebar
2. Read admin's full message about partial payment
3. Details about remaining balance
4. Reminder to pay remaining amount

#### **Option C: Check Remaining Balance**
1. View dashboard
2. Remaining Balance card shows: **₱200.00** (RED, unpaid)
3. Clear indication that billing is not complete

---

## Notification Content Breakdown

### **For Tenant (What They See):**

| Field | Value |
|-------|-------|
| **Title** | ⚠️ Partial Payment - February 2026 Billing |
| **Message** | Your payment of ₱1,000.00 was received. Remaining balance: ₱200.00. Your monthly billing will NOT be officially marked as PAID until you pay the full amount. |
| **Time** | Just now / Jan 28, 2026 22:35 PM |
| **Status** | Unread (blue dot) |
| **Action** | Click to view details |

### **For Admin (What They See):**

| Field | Value |
|-------|-------|
| **Title** | Partial Payment from Grace |
| **Message** | Payment received: ₱1,000.00 but ₱200.00 still due |
| **Related To** | Payment Verification page |
| **Time** | Just now |
| **Status** | Unread (blue dot) |

---

## Complete User Experience Timeline

```
TIME: Jan 28, 2026 22:30 PM
├─ Grace submits ₱1,000 payment
│  └─ Awaiting admin verification
│
TIME: Jan 28, 2026 22:31 PM
├─ Admin reviews payment
│  └─ Sees RED ALERT: Partial Payment Detected
│  └─ Bill: ₱1,200 | Paid: ₱1,000 | Due: ₱200
│  └─ Message field appears (REQUIRED)
│  └─ Admin writes: "Please pay the remaining ₱200..."
│  └─ Admin clicks "Verify & Approve"
│
TIME: Jan 28, 2026 22:32 PM (SYSTEM PROCESSES)
├─ Payment marked: "verified"
├─ Bill marked: "partial" (not paid)
├─ Admin notified: "Payment received: ₱1,000 of ₱1,200"
└─ TENANT NOTIFIED: ⚠️ PARTIAL PAYMENT
   └─ Message: "Your payment of ₱1,000 was received..."
   └─ Remaining: ₱200 DUE
   └─ Warning: "...will NOT be officially marked as PAID..."
│
TIME: Jan 28, 2026 22:33 PM (Grace Logs In)
├─ Dashboard loads
├─ NOTIFICATION BELL shows "1" 🔔
├─ Grace clicks bell
│  └─ Modal opens
│  └─ Shows: "⚠️ Partial Payment - February 2026 Billing"
│  └─ Message: Full details about ₱200 remaining
│  └─ Grace marks as read
│
TIME: Jan 28, 2026 22:35 PM
├─ Grace checks "Remaining Balance" card
│  └─ Shows: ₱200.00 (RED, unpaid)
│
TIME: Jan 28, 2026 22:40 PM
├─ Grace checks Messages
│  └─ Reads admin's detailed message
│  └─ Understands: Must pay ₱200 to complete billing
│
TIME: Jan 28, 2026 23:00 PM
├─ Grace prepares ₱200 payment
├─ Payment is processed the next day
│
TIME: Jan 29, 2026 10:00 AM
├─ Admin verifies ₱200 payment
├─ Bill now marked: "paid" ✅
└─ Grace gets new notification: ✅ PAYMENT COMPLETE
```

---

## Key Information Tenant Receives

### **Via Notification Bell:**
✅ ⚠️ Partial Payment notification
✅ Amount received: ₱1,000
✅ Remaining balance: ₱200
✅ Critical message: "will NOT be officially marked as PAID"

### **Via Message Inbox:**
✅ Admin's detailed message about payment
✅ Specific remaining balance amount
✅ Request to settle the balance
✅ Deadline or urgency (if admin includes)

### **Via Dashboard:**
✅ Remaining Balance card (₱200 in RED)
✅ Shows as not fully paid
✅ Visual indicator that action needed

---

## Multiple Tenants - Same System

This works for **ALL tenants** with partial payments:

### **Grace:**
- Pays ₱1,000 of ₱1,200 → Gets notification, sees ₱200 remaining

### **Maria:**
- Pays ₱1,500 of ₱2,000 → Gets notification, sees ₱500 remaining

### **Juan:**
- Pays ₱1,000 of ₱1,500 → Gets notification, sees ₱500 remaining

### **Anna (Pays Full):**
- Pays ₱2,000 of ₱2,000 → NO partial payment notification (payment complete)

---

## Notification Details

### **Notification Bell Features:**
- **Real-time:** Notification appears immediately
- **Unread indicator:** Blue dot shows new message
- **Badge count:** Shows "1" unread
- **Auto-refresh:** Updates every 30 seconds
- **Click to navigate:** Marks as read when clicked

### **Notification Content:**
- **Title:** Includes billing month (e.g., "⚠️ Partial Payment - February 2026 Billing")
- **Message:** Details amount paid, remaining due, warning about billing status
- **Timestamp:** Shows when notification was created
- **Action link:** Can navigate to bills page

### **Notification Management:**
- **Mark as read:** Click notification or message
- **Delete:** Remove individual notifications
- **View all:** Click bell to see all notifications

---

## System Flow Diagram

```
Admin Verifies Payment (Partial)
    ↓
[Check: payment_amount < bill_amount? YES]
    ↓
[Bill status → "partial"]
[Payment status → "verified"]
    ↓
notifyPartialPayment() called
    ├─ Create Admin notification
    │  └─ "Partial Payment from Grace"
    │  └─ "Payment: ₱1,000 but ₱200 still due"
    │
    └─ Create Tenant notification
       └─ "⚠️ Partial Payment - February 2026 Billing"
       └─ "Your payment of ₱1,000.00 was received..."
       └─ "Remaining balance: ₱200.00"
       └─ "Your monthly billing will NOT be officially marked as PAID..."
    ↓
Tenant Logs In
    ↓
[Notification Bell shows "1" unread 🔔]
    ↓
Tenant clicks bell
    ↓
Modal opens showing notifications
    ├─ Title: ⚠️ Partial Payment - Feb 2026
    ├─ Message: Full details
    ├─ Time: Jan 28, 22:32 PM
    └─ Status: Unread (blue dot)
    ↓
Tenant clicks notification
    ↓
[Marked as read]
[Can navigate to bills page]
    ↓
Tenant checks:
├─ Remaining Balance card → ₱200 (RED)
├─ Messages inbox → Admin's detailed letter
└─ Bills page → Shows "partial" status
    ↓
Tenant understands: Must pay ₱200 more
```

---

## Enhanced Features (Just Added)

✅ **Tenant-specific message:** Includes name of billing month
✅ **Clear warning:** "will NOT be officially marked as PAID"
✅ **Amount details:** Shows exact remaining balance
✅ **Action reminder:** Encourages immediate payment
✅ **Title indicator:** ⚠️ Shows it's a partial payment warning

---

## Testing the System

### Test Case: Partial Payment Notification

**Setup:**
- Grace has bill: ₱1,200 for February 2026
- Grace submits payment: ₱1,000

**Admin Action:**
1. Open Payment Verification
2. See Grace's payment
3. See RED ALERT: "Partial Payment Detected"
4. Write message about remaining ₱200
5. Click "Verify & Approve"
6. System processes

**Expected Tenant Notification:**
```
Title: ⚠️ Partial Payment - February 2026 Billing
Message: Your payment of ₱1,000.00 was received. 
         Remaining balance: ₱200.00. 
         Your monthly billing will NOT be officially 
         marked as PAID until you pay the full amount.
Time: Just received
Status: Unread (blue dot)
```

**Tenant Actions:**
1. ✅ Sees notification bell with "1" badge
2. ✅ Clicks bell
3. ✅ Reads full notification
4. ✅ Understands remaining balance
5. ✅ Sees message from admin
6. ✅ Checks dashboard - remaining balance card shows ₱200 (RED)
7. ✅ Decides to pay remaining ₱200

---

## Summary

✅ **Partial payments trigger notifications** for tenants
✅ **Notification bell displays** red badge with count
✅ **Message is detailed** with amount and warning
✅ **Tenant receives clear warning:** billing won't be "official" until fully paid
✅ **Multiple notification methods:** Bell + Messages + Dashboard
✅ **Works for ALL tenants** with partial payments
✅ **System-wide implementation** - no exceptions

**Result:** Tenants are immediately and clearly informed about partial payments and what they need to do next.

