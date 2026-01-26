# Payment System - Quick Start Guide

## Overview
The BAMINT system now supports a comprehensive dual-method payment system where tenants can make payments via two distinct pathways:

1. **Online Payment** - Tenants upload proof of payment for admin verification
2. **Walk-in/Cash Payment** - Admin directly records cash payments received in person

---

## For Tenants: Making Payments

### Step 1: Access Payment System
Navigate to: **My Bills** → **Make a Payment** button

Or directly visit: `tenant_make_payment.php`

### Step 2: Choose Payment Method

#### Online Payment Workflow
1. **Select a Bill**
   - Choose from pending/partial bills
   - System shows the outstanding balance

2. **Enter Payment Details**
   - Payment Amount (can be partial or full)
   - Payment Method (GCash, Bank Transfer, PayMaya, Check, etc.)

3. **Upload Proof of Payment**
   - Supported formats: JPG, PNG, PDF
   - Maximum file size: 5MB
   - This is required for online payments

4. **Optional: Add Notes**
   - Add any notes about the payment (reference number, etc.)

5. **Submit Payment**
   - Status: **Pending Verification**
   - Admin will review and verify

#### Cash/Walk-in Payment Workflow
1. Come to office during business hours
2. Inform staff which bill(s) you're paying for
3. Pay the amount
4. Admin will record the payment in the system immediately
5. Receipt will be issued

---

## For Admins: Processing Payments

### Dashboard Access
Admin navigation includes:
- **Payment Verification** - Review online payment proofs
- **Record Cash Payment** - Enter cash payments received

### Managing Online Payments

**Location**: Admin Dashboard → **Payment Verification**

1. **View Pending Payments**
   - List shows all online payments awaiting verification
   - Displays tenant info, proof of payment, and payment details

2. **Review Payment Proof**
   - View uploaded images or PDFs
   - Check payment method and amount

3. **Verification Decision**
   - Click **Verify & Approve** to confirm payment
   - Click **Reject** if proof is insufficient
   - Add verification notes (optional)

4. **System Updates**
   - Approved: Bill status updates automatically
   - Rejected: Tenant notified, can resubmit

### Recording Cash Payments

**Location**: Admin Dashboard → **Record Cash Payment**

1. **Select Tenant**
   - Search or scroll through tenant list
   - Shows outstanding balance

2. **Select Bill**
   - Click bill to select
   - System shows amount due and already paid

3. **Enter Payment Details**
   - Payment Amount
   - Payment Method (Cash, Check, Bank Transfer, etc.)
   - Optional notes

4. **Record Payment**
   - Recorded immediately with current date
   - Bill status updates automatically
   - Payment logged in system

---

## Database Schema

### New Columns in `payment_transactions` Table

| Column | Type | Purpose |
|--------|------|---------|
| `payment_type` | VARCHAR(50) | 'online' or 'cash' |
| `payment_status` | VARCHAR(50) | 'pending', 'verified', 'approved', 'rejected' |
| `proof_of_payment` | VARCHAR(255) | Filename of uploaded proof image/PDF |
| `verified_by` | INT (FK) | Admin ID who verified the payment |
| `verification_date` | DATETIME | When payment was verified |

### Status Workflow

**Online Payments:**
```
pending → verified → (bill updates) → approved
       ↓
    rejected
```

**Cash Payments:**
```
Direct entry as "approved" (no verification step)
```

---

## Key Features

### For Tenants
✓ Multiple payment methods supported (online and cash)
✓ Flexible payment amounts (partial or full)
✓ Receipt of payment history
✓ Track payment status
✓ Optional notes for reference numbers

### For Admins
✓ Image/PDF proof verification
✓ One-click approval/rejection
✓ Automatic bill status updates
✓ Payment history and reporting
✓ Verification audit trail

---

## Payment Methods Supported

### Online Payment Methods
- GCash
- Bank Transfer
- PayMaya
- Check (with proof)
- Custom methods

### Direct Entry Methods
- Cash
- Check
- Bank Transfer
- Other

---

## File Upload Requirements

### Proof of Payment Specifications
- **Directory**: `/public/payment_proofs/`
- **Allowed Formats**: JPG, PNG, PDF
- **Maximum Size**: 5MB
- **Naming**: `proof_[billId]_[tenantId]_[timestamp].[ext]`

### File Storage
- Automatically created if directory doesn't exist
- Secure filename generation to prevent conflicts
- All files encrypted and access-controlled

---

## Testing the Payment System

### Test Online Payment Flow
1. Login as tenant
2. Go to My Bills → Make a Payment
3. Select a bill
4. Choose "Online Payment" method
5. Upload a test proof image
6. Submit

### Test Cash Payment Flow
1. Login as admin
2. Go to Record Cash Payment
3. Select a tenant
4. Select their bill
5. Enter payment amount
6. Click Record Payment

### Verify in Database
```sql
-- Check payment transactions
SELECT * FROM payment_transactions WHERE payment_type = 'online';

-- Check verification status
SELECT * FROM payment_transactions WHERE payment_status = 'pending';
```

---

## Troubleshooting

### "Proof of payment is required" error
- Make sure you selected Online Payment method
- Upload a valid image or PDF file

### "Payment amount must be greater than 0" error
- Enter a payment amount in the amount field
- Amount must be greater than 0

### File upload fails
- Check file size (max 5MB)
- Ensure file is JPG, PNG, or PDF
- Verify `/public/payment_proofs/` directory exists

### Bill status doesn't update
- For online payments, must be verified first
- For cash payments, check if recording completed successfully

---

## Security Features

✓ Prepared statements to prevent SQL injection
✓ File type validation for uploads
✓ File size limits
✓ User role-based access control
✓ Audit trail with verified_by tracking
✓ Timestamp tracking for all payments
✓ Secure filename generation

---

## Related Documentation
- SYSTEM_STATUS.md - System overview
- IMPLEMENTATION_SUMMARY.md - Feature implementation details
- DATABASE_ERROR_RECOVERY.md - Error handling
