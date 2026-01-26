# Payment Queue Quick Reference

## System Overview

The BAMINT system now has a **Pending Payment Queue** that:
1. Admins use to review and approve tenant payments
2. Tenants use to track their submitted payments
3. Automatically updates bills when payments are approved

---

## For Admins

### What's New
✅ **Bills page** now shows pending payments at the top
✅ **View payment details** including uploaded proof
✅ **Approve or Reject** payments with one click
✅ **Bill automatically updates** when you approve

### Location
- **Page**: Bills (Billing in sidebar)
- **Section**: "⏳ Pending Payment Verification" (top of page)
- **Shows**: All payments waiting for your approval

### How to Use

**1. See Pending Payments**
   - Go to Bills page
   - Look for yellow/warning alert at top
   - Shows count of pending payments

**2. Review a Payment**
   - Click "View" button on payment card
   - Modal opens with full details:
     - Tenant info (name, email)
     - Payment info (amount, method, date)
     - Billing info (which bill, amount due)
     - **Proof image/PDF** (the tenant's payment proof)
     - Tenant's notes (if any)

**3. Approve or Reject**
   - **Approve** (green button):
     - Confirms payment is valid
     - Updates bill automatically
     - Payment status → "verified"
   - **Reject** (red button):
     - Marks proof as invalid
     - Tenant can resubmit
     - Payment status → "rejected"

### Data Visible
- Tenant name
- Tenant email
- Room number
- Billing month
- Payment amount
- Payment method (GCash, Bank Transfer, PayMaya, Check, Cash)
- Payment date/time
- **Proof of payment** (JPG, PNG, or PDF image)
- Tenant notes
- Bill details (amount due, amount paid)

### Quick Actions
| Action | Button | Result |
|--------|--------|--------|
| View details | "View" button | Opens modal |
| Approve | Green checkmark | Payment verified, bill updates |
| Reject | Red X | Payment rejected, tenant resubmits |
| Close modal | X or outside | Modal closes |

---

## For Tenants

### What's New
✅ **Bills page** shows your submitted payments
✅ **See payment status** immediately
✅ **Automatic bill updates** when approved
✅ **Can resubmit** if rejected

### Location
- **Page**: My Bills (Bills in sidebar)
- **Section**: "⏳ Pending Payment Status" (if you have pending payments)
- **Shows**: All your payments under review

### How to Use

**1. Check Pending Payments**
   - Go to Bills page
   - Look for "⏳ Pending Payment Status" section
   - Shows all your submitted payments

**2. Understand Status**
   - 🟡 **⏳ Awaiting Review**: Admin hasn't approved yet
   - 🟢 **✓ Verified**: Admin approved, payment recorded

**3. See Payment Details**
   - Each payment card shows:
     - Billing month (which bill)
     - Payment amount
     - Payment method
     - Date submitted
     - Current status

**4. What Happens Next**
   - **After you submit**: Payment shows "⏳ Awaiting Review"
   - **Admin approves**: Status changes to "✓ Verified"
   - **Bill updates**: Amount paid increases, status updates
   - **If rejected**: Status shows "Rejected", you can resubmit

### Payment Status Meanings

| Status | Icon | Meaning | Action |
|--------|------|---------|--------|
| Awaiting Review | ⏳ | Admin hasn't reviewed yet | Wait for approval |
| Verified | ✓ | Admin approved | Payment recorded ✅ |
| Rejected | ❌ | Proof was invalid | Resubmit with better proof |

### Resubmitting

If your payment is **rejected**:
1. Go to Payments page
2. Click "Make a Payment" 
3. Select "Online Payment"
4. Submit again with clearer proof
5. Wait for admin review

---

## Complete Payment Flow

```
STEP 1: TENANT SUBMITS
Tenant fills form:
├─ Select bill
├─ Enter amount
├─ Choose payment method
├─ Upload proof file
└─ Click Submit

Result: Payment status = "pending" ⏳

STEP 2: PAYMENT APPEARS IN ADMIN QUEUE
Admin's Bills page shows:
├─ Pending payment alert
├─ Payment card with tenant info
├─ "View" button

STEP 3: ADMIN REVIEWS
Admin clicks "View":
├─ See all payment details
├─ See uploaded proof image/PDF
├─ Decide: Approve or Reject

STEP 4A: ADMIN APPROVES ✅
├─ Click "Approve" button
├─ Payment status → "verified"
├─ Bill amount_paid updates automatically
├─ Bill status updates (partial or paid)
└─ Payment disappears from queue

STEP 4B: ADMIN REJECTS ❌
├─ Click "Reject" button
├─ Payment status → "rejected"
├─ Tenant sees rejection
└─ Tenant can resubmit

STEP 5: TENANT SEES UPDATE
├─ Refreshes Bills page
├─ Sees payment status changed
├─ Sees bill updated
└─ Payment recorded ✅
```

---

## Key Features

### Admin Benefits
✅ One-place review for all pending payments
✅ View proof image inline before approving
✅ Approve/Reject with one click
✅ Automatic bill calculation
✅ Payment audit trail (who verified, when)

### Tenant Benefits
✅ Track payment status in real-time
✅ Know when admin is reviewing
✅ See automatic bill updates
✅ Can resubmit if needed
✅ No surprises with payments

### System Benefits
✅ Secure file upload handling
✅ Automatic calculation
✅ Prevents duplicate approvals
✅ Audit trail for accounting
✅ Bill status accuracy

---

## File Locations in Code

### Admin Payment Queue
- **File**: `bills.php`
- **Section**: Lines with "Pending Payments Queue"
- **Database query**: `SELECT ... FROM payment_transactions WHERE payment_status = 'pending'`

### Tenant Pending Payments
- **File**: `tenant_bills.php`
- **Section**: "Pending Payments Section"
- **Database query**: `SELECT ... WHERE payment_status IN ('pending', 'verified')`

### File Storage
- **Uploaded files**: `/public/payment_proofs/`
- **Naming**: `proof_[billid]_[tenantid]_[timestamp].[ext]`
- **Example**: `proof_123_45_1674231456.jpg`

### Database Table
- **Table**: `payment_transactions`
- **Key fields**:
  - `id`: Payment ID
  - `payment_status`: pending, verified, rejected, approved
  - `proof_of_payment`: Filename of uploaded file
  - `verified_by`: Admin ID who verified
  - `verification_date`: When verified

---

## Common Scenarios

### Scenario 1: First-Time Payment
```
1. Tenant submits ₱1,500 for June bill
2. Status shows: "⏳ Awaiting Review"
3. Admin approves
4. Bill updated: amount_paid → ₱1,500, status → paid
5. Tenant sees: Status → "✓ Verified"
```

### Scenario 2: Rejected Payment
```
1. Tenant submits ₱2,000 with blurry screenshot
2. Status shows: "⏳ Awaiting Review"
3. Admin sees blurry image, clicks "Reject"
4. Tenant sees: Status → "Rejected"
5. Tenant takes clearer screenshot
6. Tenant resubmits ₱2,000 again
7. Admin approves
8. Bill updated successfully
```

### Scenario 3: Partial Payments
```
1. Bill: ₱5,000
2. Tenant submits ₱2,000, admin approves
   Bill: amount_paid = ₱2,000, status = "partial"
3. Tenant submits ₱3,000, admin approves
   Bill: amount_paid = ₱5,000, status = "paid"
```

### Scenario 4: Multiple Tenants
```
Admin's Bills page shows:
├─ Tenant A: ₱500 payment pending
├─ Tenant B: ₱1,500 payment pending
├─ Tenant C: ₱750 payment pending
└─ Each can be reviewed and approved independently
```

---

## Important Notes

### File Upload Rules
- **Formats**: JPG, PNG, PDF only
- **Max size**: 5MB
- **Content**: Must show proof of payment
  - Transaction amount
  - Payment method
  - Date (ideally)
  - Reference number (ideally)

### Approval Checklist
Before approving, verify:
- [ ] Proof image is clear and readable
- [ ] Amount matches what tenant submitted
- [ ] Amount matches bill balance (or less)
- [ ] Payment method matches proof
- [ ] Proof shows completed transaction
- [ ] No duplicate payments for same bill

### Status Transitions
```
Submitted → pending
           ↓
Admin reviews
           ↓
         ✅ Approve → verified → (bill updates)
         
         ❌ Reject → rejected → (tenant resubmits)
```

---

## Troubleshooting

### Admin: Pending payments not showing?
- Page: Make sure you're on Bills page
- Scroll: Check top of page for yellow alert
- Refresh: Try F5 to reload page
- Count: Check if any payments have pending status

### Admin: Can't see proof image?
- File: Check if file exists in `/public/payment_proofs/`
- Format: Should be JPG, PNG, or PDF
- Size: Check file size isn't corrupted
- Path: Verify filename matches database

### Tenant: Payment stuck in "Awaiting Review"?
- Time: May need 24 hours
- Weekend: Check if during off-hours
- Contact: Reach out to office if delayed

### Tenant: Payment shows "Rejected"?
- Proof: Image was likely unclear/invalid
- Resubmit: Can submit again with better proof
- Quality: Use clear screenshot or scan

---

## Quick Links

| Action | Path | Notes |
|--------|------|-------|
| Admin: See pending | Bills page (top) | Requires admin login |
| Admin: Review proof | Bills > View button | Opens modal |
| Admin: Approve | Modal > Approve button | Instant update |
| Tenant: See pending | Bills page | If payments submitted |
| Tenant: Submit payment | Payments > Make Payment | Go through form |
| Tenant: Track status | Bills > Pending section | Real-time updates |

---

## FAQ

**Q: How long does approval take?**
A: Usually same day or next business day. Weekend submissions may wait until Monday.

**Q: Can I edit payment after submission?**
A: No. If wrong, it will be rejected and you can resubmit with corrections.

**Q: Can I cancel a pending payment?**
A: No. Wait for rejection or contact office admin.

**Q: What if my proof is rejected?**
A: Resubmit with clearer proof (better screenshot, higher quality scan, etc.).

**Q: Are uploaded files private?**
A: Yes. Only office admin can view uploaded proofs.

**Q: What file types are accepted?**
A: JPG, PNG (for screenshots) or PDF (for scans). No Word, Excel, or other formats.

**Q: Can I pay multiple bills at once?**
A: No. Submit one payment per bill. You can submit multiple payments in sequence.

**Q: What happens if I pay more than owed?**
A: Bill status becomes "paid" (no overage storage in current system).

---

## System Status

✅ **Payment Queue**: Active
✅ **File Uploads**: Active
✅ **Admin Approval**: Active
✅ **Automatic Billing**: Active
✅ **Real-time Updates**: Active

---

**Version**: 2.1 with Payment Queue System
**Last Updated**: January 26, 2026
**Status**: ✅ Ready for Testing
