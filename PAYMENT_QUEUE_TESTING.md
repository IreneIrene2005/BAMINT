# Payment Queue Testing Guide

## Quick Test Workflow

Follow these steps to test the complete pending payments queue system.

---

## Setup (Admin Only)

### 1. Ensure Database is Ready
```bash
# Check that payment_transactions table has these columns:
- id (INT PRIMARY KEY)
- bill_id (INT)
- tenant_id (INT)
- payment_amount (DECIMAL)
- payment_method (VARCHAR)
- payment_type (VARCHAR) - "online" or "cash"
- payment_status (VARCHAR) - "pending", "verified", "rejected", "approved"
- proof_of_payment (VARCHAR) - filename
- payment_date (TIMESTAMP)
- verified_by (INT) - admin who verified
- verification_date (TIMESTAMP)
- notes (TEXT)
- created_at (TIMESTAMP)
```

### 2. Ensure Bills Table Has
```bash
- amount_paid (DECIMAL)
- status (VARCHAR) - "pending", "partial", "paid", "overdue"
```

---

## Test Case 1: Tenant Submits Online Payment

### Tenant Steps:
1. **Login as Tenant**
   - Go to http://localhost/BAMINT/
   - Login with tenant credentials

2. **Navigate to Payments**
   - Click "Payments" or "Coin icon" in sidebar
   - See "Make a Payment" button

3. **Make Online Payment**
   - Click blue "Online Payment" card
   - Select a pending bill
   - Enter amount (e.g., ₱500)
   - Select payment method (e.g., "GCash")
   - Upload a test image/file
     - Can use any JPG, PNG, or PDF file
     - Keep under 5MB
   - Click "Submit Payment"

4. **Verify Success**
   - See success message: "Online payment submitted!"
   - Status shows: "Awaiting Verification"

### Expected Result ✅
- Payment created in database with status = "pending"
- Payment appears in Tenant's Bills page under "⏳ Pending Payment Status" section
- Payment shows status as "⏳ Awaiting Review"

---

## Test Case 2: Admin Reviews Pending Payment

### Admin Steps:
1. **Login as Admin**
   - Go to http://localhost/BAMINT/
   - Login with admin credentials

2. **Go to Bills Page**
   - Click "Billing" or "Receipt icon" in sidebar
   - At top, see "⏳ Pending Payment Verification" alert section
   - Shows count of pending payments

3. **Review Payment Details**
   - See payment card showing:
     - Tenant name
     - Room number
     - Billing month
     - Payment amount
     - Payment method
   - Click "View" button on the payment card

4. **Examine Proof of Payment**
   - Modal opens showing:
     - Tenant name and email
     - Payment amount, method, date
     - Which bill is being paid
     - **Uploaded proof image/PDF** (should display)
     - Any tenant notes
   - Verify proof matches payment amount

### Expected Result ✅
- Admin can see pending payment card with key info
- Admin can click "View" and see full details
- Proof of payment displays (image inline or PDF link)
- Approve/Reject buttons visible at bottom of modal

---

## Test Case 3: Admin Approves Payment

### Admin Steps (continuing from Test Case 2):
1. **Review Proof**
   - Examine the uploaded proof image
   - Verify payment amount is correct
   - Check transaction details

2. **Approve Payment**
   - Click green "✓ Approve" button
   - System processes approval
   - Redirects to Bills page

3. **Verify Status Change**
   - Payment is no longer in pending queue
   - Refresh page to confirm

### Expected Result ✅
- Payment status changes from "pending" to "verified"
- Bill's amount_paid updates automatically
- Bill status updates if fully paid
- Payment disappears from pending queue
- Admin sees success confirmation

---

## Test Case 4: Tenant Sees Approved Payment

### Tenant Steps:
1. **Login as Tenant** (same tenant from Test Case 1)
   - Refresh Bills page

2. **Check Pending Payments Section**
   - Look for "⏳ Pending Payment Status" section
   - Should now show payment with status "✓ Verified"

3. **Check Bill Status**
   - Review the bill that was paid
   - Amount paid should be updated
   - Status should be updated

### Expected Result ✅
- Tenant sees payment status changed to "✓ Verified"
- Bill shows updated amount_paid
- If fully paid, bill status = "paid"
- If partial, bill status = "partial"

---

## Test Case 5: Admin Rejects Payment

### Admin Steps:
1. **Go to Bills page**
   - Have another tenant submit a payment first
   - Or use existing pending payment

2. **Review Payment**
   - Click "View" on a pending payment card
   - Examine the proof
   - Decide it's invalid (e.g., blurry, wrong amount, etc.)

3. **Reject Payment**
   - Click red "✓ Reject" button
   - Confirm action (optional popup)
   - System processes rejection
   - Redirects to Bills page

### Expected Result ✅
- Payment status changes to "rejected"
- Payment disappears from admin's pending queue
- Tenant sees payment status as "Rejected"
- Tenant can resubmit with better proof

---

## Test Case 6: Tenant Resubmits After Rejection

### Tenant Steps:
1. **Login as Tenant**
   - Go to Bills or Payments page
   - See rejected payment

2. **Go to Payments**
   - Click "Make a Payment"
   - Go through payment process again
   - Select same bill (or different one)
   - Upload better proof
   - Submit

3. **Admin Reviews New Payment**
   - Admin goes to Bills page
   - New payment appears in pending queue
   - Admin approves this time

### Expected Result ✅
- Old rejected payment still shows in history
- New pending payment appears in queue
- Can be approved without issue
- Payment succeeds on retry

---

## Test Case 7: Partial Payment

### Setup:
1. **Have a bill** for ₱5,000
2. **Tenant submits** first payment of ₱2,000
3. **Admin approves** first payment
4. **Check bill status**: Should be "partial"

### Expected Results ✅
- Bill status: "pending" → "partial"
- Amount paid: ₱0 → ₱2,000
- Balance: ₱5,000 → ₱3,000

### Continue:
1. **Tenant submits** second payment of ₱3,000
2. **Admin approves** second payment
3. **Check bill status**: Should be "paid"

### Expected Results ✅
- Bill status: "partial" → "paid"
- Amount paid: ₱2,000 → ₱5,000
- Balance: ₱3,000 → ₱0

---

## Test Case 8: Bill Status Automation

### Scenario A: Full Payment Submitted
```
Bill: ₱5,000 due
Tenant submits: ₱5,000
Admin approves:
✅ Bill status automatically = "paid"
✅ Amount paid automatically = ₱5,000
```

### Scenario B: Overpayment (shouldn't happen)
```
Bill: ₱5,000 due
Tenant submits: ₱5,500 (extra ₱500)
Admin approves:
✅ Bill status automatically = "paid"
✅ Amount paid automatically = ₱5,500
📝 Note: Should validate against bill balance
```

---

## Verification Checklist

### Admin Features
- [ ] Can see pending payments queue at top of Bills page
- [ ] Pending payment alert shows correct count
- [ ] Each payment card shows: Tenant, Room, Month, Amount, Method
- [ ] Can click "View" to see full details
- [ ] Can see proof of payment (image or PDF)
- [ ] Can see tenant info and billing details
- [ ] Can click "Approve" button
- [ ] Can click "Reject" button
- [ ] Page refreshes after action
- [ ] Payment disappears from queue after approval/rejection

### Tenant Features
- [ ] Can submit online payment with file upload
- [ ] Can see pending payment in Bills page
- [ ] Pending section shows correct count
- [ ] Payment card shows: Month, Amount, Method, Status
- [ ] Status shows "⏳ Awaiting Review" until approved
- [ ] Status changes to "✓ Verified" when approved
- [ ] Bill amount_paid updates automatically
- [ ] Bill status updates automatically

### Data Accuracy
- [ ] Payment amount is correct
- [ ] Billing month is correct
- [ ] Payment method is correct
- [ ] File uploads successfully
- [ ] Bill calculations are accurate
- [ ] Status transitions are correct

### Error Handling
- [ ] Approve button updates database correctly
- [ ] Reject button updates database correctly
- [ ] No errors in PHP error log
- [ ] Page redirects work correctly
- [ ] Form validation prevents invalid data

---

## Quick Test Checklist

Use this for rapid testing:

```
TENANT SUBMISSION:
☐ Tenant logs in
☐ Tenant goes to Payments
☐ Tenant selects "Online Payment"
☐ Tenant fills form
☐ Tenant selects file (image/PDF under 5MB)
☐ Tenant clicks Submit
☐ Success message shows
☐ Tenant goes to Bills page
☐ Sees pending payment section
☐ Payment shows as "⏳ Awaiting Review"

ADMIN REVIEW:
☐ Admin logs in
☐ Admin goes to Bills page
☐ Admin sees pending payments alert
☐ Admin sees payment card
☐ Admin clicks "View"
☐ Admin sees full payment details
☐ Admin sees uploaded proof image
☐ Admin reviews all details
☐ Admin clicks "Approve"
☐ Page refreshes to Bills page
☐ Payment no longer in pending queue

TENANT VERIFICATION:
☐ Tenant refreshes Bills page
☐ Pending payment section still visible
☐ Payment status changed to "✓ Verified"
☐ Bill amount_paid updated
☐ Bill status updated (partial or paid)
☐ Balance recalculated

SUCCESS = All items checked ✅
```

---

## Common Issues & Fixes

### Issue: "Pending Payment Verification" section not showing

**Cause**: No payments with status = "pending"

**Fix**:
1. Have a tenant submit an online payment
2. Don't approve yet
3. Check Bills page again

---

### Issue: Proof of payment not displaying in modal

**Cause**: File not found or wrong path

**Debug**:
1. Check file exists: `/public/payment_proofs/proof_*.jpg/png/pdf`
2. Check file permissions are readable
3. Check filename in database matches actual file
4. Try uploading new payment with image file

---

### Issue: Bill amount_paid not updating after approval

**Cause**: Payment verification query not executing correctly

**Debug**:
1. Check `verified` and `approved` statuses in query
2. Verify SQL is calculating SUM correctly
3. Check bill ID matches payment bill_id
4. Check database for correct amount_paid value

---

### Issue: Admin can't see payment details

**Cause**: Modal not opening or permissions issue

**Fix**:
1. Check JavaScript console (F12) for errors
2. Verify payment ID is correct in modal target
3. Try different browser
4. Clear browser cache (Ctrl+Shift+Delete)

---

## Database Queries for Testing

### See All Pending Payments
```sql
SELECT 
    pt.id,
    t.name as tenant_name,
    pt.payment_amount,
    pt.payment_status,
    pt.payment_date,
    b.billing_month
FROM payment_transactions pt
JOIN tenants t ON pt.tenant_id = t.id
JOIN bills b ON pt.bill_id = b.id
WHERE pt.payment_status = 'pending'
ORDER BY pt.payment_date DESC;
```

### See All Verified Payments
```sql
SELECT 
    pt.id,
    t.name as tenant_name,
    pt.payment_amount,
    a.name as verified_by,
    pt.verification_date,
    b.billing_month
FROM payment_transactions pt
JOIN tenants t ON pt.tenant_id = t.id
LEFT JOIN admins a ON pt.verified_by = a.id
JOIN bills b ON pt.bill_id = b.id
WHERE pt.payment_status = 'verified'
ORDER BY pt.verification_date DESC;
```

### Check Bill Amount Paid
```sql
SELECT 
    b.id,
    b.billing_month,
    b.amount_due,
    b.amount_paid,
    b.status,
    SUM(pt.payment_amount) as calculated_total
FROM bills b
LEFT JOIN payment_transactions pt 
    ON pt.bill_id = b.id 
    AND pt.payment_status IN ('verified', 'approved')
WHERE b.tenant_id = [TENANT_ID]
GROUP BY b.id
ORDER BY b.billing_month DESC;
```

---

## Performance Notes

- Payment queue queries use proper JOINs for efficiency
- Modal uses unique IDs for each payment (no conflicts)
- File upload handling is secure
- Status updates are atomic (one transaction)

---

**Testing Completed**: 
[ ] Test Case 1: Tenant Submits ✅
[ ] Test Case 2: Admin Reviews ✅
[ ] Test Case 3: Admin Approves ✅
[ ] Test Case 4: Tenant Sees Update ✅
[ ] Test Case 5: Admin Rejects ✅
[ ] Test Case 6: Tenant Resubmits ✅
[ ] Test Case 7: Partial Payment ✅
[ ] Test Case 8: Automation ✅

**System Ready**: [ ] YES [ ] NO
