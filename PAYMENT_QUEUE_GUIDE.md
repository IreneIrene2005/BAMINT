# Payment Queue System - User Guide

## Overview

The BAMINT system now features a **Pending Payment Queue** that allows admins to review and approve tenant payments, and tenants to track their payment status in real-time.

---

## For Admins: Payment Verification Queue

### Accessing the Queue

1. **Navigate to**: `Bills` → Scroll to top
2. **Look for**: **"Pending Payment Verification"** alert section
3. Shows count of payments awaiting verification

### Payment Queue Features

#### 1. Pending Payments Display
- **Color-coded alerts**: Yellow/Warning for pending approvals
- **Quick view cards** showing:
  - Tenant name
  - Room number
  - Billing month
  - Payment amount
  - Payment method (GCash, Bank Transfer, PayMaya, Check)

#### 2. Review Payment Details
- Click **"View"** button on any pending payment card
- Opens detailed modal showing:
  - **Tenant Information**: Name, email, room
  - **Payment Details**: Amount, method, date/time submitted
  - **Billing Information**: Which bill is being paid, amount due, amount already paid
  - **Proof of Payment**: 
    - Images (JPG, PNG): Display inline
    - PDFs: Clickable download link
  - **Tenant Notes**: Any notes added with payment submission

#### 3. Approve/Reject Payment

##### To Approve:
1. Click **"View"** on the payment card
2. Review all details and proof
3. Click **green "Approve"** button
4. Payment status changes to **"Verified"**
5. Bill status automatically updates

##### To Reject:
1. Click **"View"** on the payment card
2. Review details and proof
3. If proof is invalid, click **red "Reject"** button
4. Tenant will see status as **"Rejected"**
5. Tenant can resubmit with corrected proof

### What Happens After Approval

✅ **Immediately**:
- Payment status: `pending` → `verified`
- Bill amount_paid updates automatically
- If bill is fully paid: Status becomes `paid`
- If partial: Status becomes `partial`

✅ **Tenant Sees**:
- Payment moves from "Awaiting Review" to "Verified"
- Bill status updates in their Bills page
- Payment appears in their Payments history

---

## For Tenants: Payment Status Tracking

### Viewing Pending Payments

1. **Go to**: `My Bills` page
2. **Look for**: **"⏳ Pending Payment Status"** section (yellow alert)
3. Shows all submitted payments under review

### Payment Status Types

#### 🟡 Awaiting Review
- Status: `PENDING`
- Meaning: Admin has received your payment but hasn't verified it yet
- Timeline: Usually verified same day or next business day
- Action: Wait for admin review

#### 🟢 Verified
- Status: `VERIFIED` or `APPROVED`
- Meaning: Admin has approved your payment
- What happens: Your bill is updated automatically
- Timeline: Immediate once approved

#### ❌ Rejected
- Status: `REJECTED`
- Meaning: Your proof of payment wasn't valid
- Reason: Could be unclear image, wrong file type, or suspicious
- Action: Go to Payments → Submit again with better proof
- Tip: Take clearer screenshot or better quality scan

### Payment Status Card Information

Each card shows:
- 📅 **Billing Month**: Which bill this payment is for
- 💰 **Amount**: How much you paid
- 💳 **Method**: Payment method used (GCash, Bank Transfer, etc.)
- ⏰ **Date**: When you submitted the payment
- ✅ **Status**: Current approval status

### Example Flow

```
1. You submit ₱1,500 for June 2024 bill via GCash
   ↓ Shows: "⏳ Awaiting Review"
   
2. Admin reviews your GCash screenshot
   ↓ Admin clicks "Approve"
   
3. System automatically:
   - Updates payment status to "Verified"
   - Updates bill amount_paid to include ₱1,500
   - If bill is fully paid, marks bill as "paid"
   
4. You see in Bills page:
   - Bill status changed (e.g., pending → paid)
   - June 2024 bill now shows as PAID ✓
   - Your balance decreased by ₱1,500
```

---

## Payment Approval Process - Detailed Flow

### Step 1: Tenant Submits Payment
```
Tenant fills form:
├─ Selects bill
├─ Enters amount
├─ Chooses payment method
├─ Uploads proof file
└─ Clicks Submit

Result: Payment created with status = "pending"
```

### Step 2: Payment Appears in Admin Queue
```
Admin's Bills page shows:
├─ Pending Payments alert section
├─ Payment card with:
│  ├─ Tenant name
│  ├─ Room number
│  ├─ Billing month
│  ├─ Amount
│  └─ "View" button
```

### Step 3: Admin Reviews Payment
```
Admin clicks "View":
├─ Reviews tenant info
├─ Checks billing details
├─ Sees uploaded proof image/PDF
├─ Reads tenant notes
└─ Decides: Approve or Reject
```

### Step 4: Admin Takes Action
```
If Valid (Approve):
├─ Clicks "Approve" button
├─ Status → "verified"
├─ Bill updates automatically
└─ Tenant sees status change

If Invalid (Reject):
├─ Clicks "Reject" button
├─ Status → "rejected"
├─ Tenant notified in Bills page
└─ Tenant can resubmit
```

### Step 5: Tenant Sees Update
```
Tenant's Bills page:
├─ Pending payment status changes
├─ Bill amount_paid updates
├─ Bill status updates
└─ Payment appears in history
```

---

## Common Scenarios

### Scenario 1: Full Payment Submitted
```
Bill: June 2024 - ₱5,000 due

Tenant submits: ₱5,000

Admin approves:
✓ Bill status: pending → paid
✓ Amount paid: ₱0 → ₱5,000
✓ Balance: ₱5,000 → ₱0
✓ Payment status: pending → verified
```

### Scenario 2: Partial Payment Submitted
```
Bill: June 2024 - ₱5,000 due

Tenant submits: ₱2,000 (partial)

Admin approves:
✓ Bill status: pending → partial
✓ Amount paid: ₱0 → ₱2,000
✓ Balance: ₱5,000 → ₱3,000
✓ Payment status: pending → verified
```

### Scenario 3: Multiple Payments for Same Bill
```
Bill: June 2024 - ₱5,000 due

Payment 1: Tenant submits ₱2,000
→ Admin approves
  Status: partial (₱2,000 of ₱5,000 paid)

Payment 2: Tenant submits ₱3,000
→ Admin approves
  Status: paid (₱5,000 of ₱5,000 paid)
```

### Scenario 4: Rejected Payment
```
Bill: June 2024 - ₱5,000 due

Payment 1: Tenant submits ₱5,000 with blurry screenshot
→ Admin rejects
  Tenant sees: Status "Rejected"
  
Tenant sees: Can resubmit
  Takes clearer screenshot
  Submits ₱5,000 again
  
Payment 2: Admin approves
  Status: paid ✓
```

---

## Admin Dashboard Improvements

### Bills Page Now Shows

**Top Alert Section (if payments pending)**:
```
⏳ Pending Payment Verification
You have X payment(s) awaiting your verification.

[Payment Card 1] [Payment Card 2] [Payment Card 3] ...
```

**Each Payment Card Shows**:
- Tenant name
- Room number
- Billing month
- Amount
- Payment method
- "View" button to review

**Modal (when "View" clicked)**:
- Full tenant details
- Complete payment information
- Proof of payment (image or PDF)
- Approve/Reject buttons

---

## Tenant Dashboard Improvements

### Bills Page Now Shows

**Pending Payments Alert (if any pending)**:
```
⏳ Pending Payment Status
You have X payment(s) under review by admin.

[Pending Payment Card 1] [Pending Payment Card 2] ...
```

**Each Payment Card Shows**:
- Billing month
- Payment amount
- Payment method
- Date submitted
- Status: "⏳ Awaiting Review" or "✓ Verified"
- What's happening: "Waiting for admin approval" or "Approved and recorded"

---

## Key Features

### For Admins
✅ See all pending payments in one place
✅ View proof of payment inline (images or PDFs)
✅ Approve or reject with one click
✅ Automatic bill updates on approval
✅ Payment history tracking

### For Tenants
✅ See status of submitted payments
✅ Know when admin is reviewing
✅ See when payment is approved
✅ Automatic bill updates when approved
✅ Can resubmit if rejected

### System Features
✅ Real-time status updates
✅ File upload support (JPG, PNG, PDF)
✅ Automatic calculations
✅ Audit trail (verified_by, verification_date)
✅ Bill status automation

---

## Database Tables Used

### payment_transactions
```
Fields:
- id: Payment ID
- bill_id: Which bill
- tenant_id: Tenant
- payment_amount: Amount paid
- payment_method: GCash, Bank Transfer, etc.
- payment_type: "online" or "cash"
- payment_status: pending, verified, rejected, approved
- proof_of_payment: Filename of uploaded proof
- payment_date: When submitted
- verified_by: Admin who verified (NULL until verified)
- verification_date: When verified (NULL until verified)
- notes: Tenant notes
```

### bills
```
Fields updated on payment approval:
- amount_paid: Auto-updated with sum of verified payments
- status: Automatically updated (pending → partial → paid)
```

---

## Troubleshooting

### Admin: Can't see pending payments?
- Check Bills page (not other pages)
- Scroll to top of page
- Payments might be for other admins to verify
- Refresh page (F5) to see new submissions

### Admin: Proof image not showing?
- Check file was uploaded as JPG, PNG, or PDF
- File might be in wrong location
- Check file permissions
- Try downloading PDF directly

### Tenant: Payment stuck in "Awaiting Review"?
- Admin might still be reviewing
- Check time (submitted same day = may not be approved yet)
- During off-hours, approval delayed to next business day
- Contact office if delayed more than 1 business day

### Tenant: Payment shows "Rejected"?
- Proof of payment was unclear or invalid
- File type might be wrong (use JPG, PNG, or PDF)
- Image might be too dark/blurry
- Submit again with clearer proof

---

## Best Practices

### For Admins
1. ✅ Check pending payments daily
2. ✅ Review proof carefully before approving
3. ✅ Reject unclear proofs immediately
4. ✅ Verify math (payment amount ≤ bill balance)
5. ✅ Keep payment processing swift

### For Tenants
1. ✅ Take clear screenshots of transactions
2. ✅ Include transaction amount in proof
3. ✅ Use accepted formats: JPG, PNG, or PDF
4. ✅ Keep file under 5MB
5. ✅ Add notes explaining payment if needed
6. ✅ Check status regularly
7. ✅ Resubmit if rejected

---

## Feature Summary

| Feature | Admin | Tenant |
|---------|-------|--------|
| See pending payments | ✅ | ✅ |
| Count of pending | ✅ | ✅ |
| View payment details | ✅ | ❌ |
| View proof of payment | ✅ | ❌ |
| Approve payment | ✅ | ❌ |
| Reject payment | ✅ | ❌ |
| See payment status | ✅ | ✅ |
| Automatic bill update | ✅ | ✅ |
| Payment history | ✅ | ✅ |

---

**System**: BAMINT Tenant Management
**Version**: 2.1 with Payment Queue
**Last Updated**: January 26, 2026
