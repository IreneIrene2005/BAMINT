# Payment System - Testing & Deployment Guide

## Pre-Deployment Checklist

### Database Setup
- [ ] Migration script executed: `db/migrate_payment_system.php`
- [ ] All 5 new columns added to `payment_transactions` table
- [ ] `/public/payment_proofs/` directory created
- [ ] Database indexes created
- [ ] Foreign key constraint verified

### File Structure
- [ ] `tenant_make_payment.php` created and accessible
- [ ] `admin_payment_verification.php` created and accessible
- [ ] `admin_record_payment.php` created and accessible
- [ ] `/public/payment_proofs/` directory writable (permissions 755)
- [ ] `tenant_bills.php` updated with payment button

### Documentation
- [ ] PAYMENT_SYSTEM_QUICK_START.md created
- [ ] PAYMENT_SYSTEM_TECHNICAL.md created
- [ ] PAYMENT_SYSTEM_IMPLEMENTATION.md created
- [ ] PAYMENT_SYSTEM_VISUAL_GUIDE.md created

### Configuration
- [ ] Database connection verified in `db/database.php`
- [ ] Session management working
- [ ] File upload max size set appropriately
- [ ] MIME types properly configured

---

## Test Scenario 1: Online Payment Submission

### Preconditions
- Tenant user account exists
- Tenant has pending or partial bills
- Test image file available (JPG, PNG, or PDF)

### Test Steps

#### 1.1 Login as Tenant
```
Action: Navigate to index.php
Input: Tenant credentials
Expected: Dashboard accessible
Verify: Session created, role = 'tenant'
```

#### 1.2 Access Payment Page
```
Action: Click "Make a Payment" button on bills page
OR: Navigate directly to tenant_make_payment.php
Expected: Payment form loads
Verify: Both payment method cards visible
```

#### 1.3 Select Online Payment Method
```
Action: Click "Online Payment" card
Expected: Card highlighted with border/shadow
        Form updates to show proof upload field
        Submit button enabled
Verify: payment_type = 'online' in hidden input
        proof_of_payment field required = true
        Form focused on payment details
```

#### 1.4 Select Bill
```
Action: Click on bill dropdown
Expected: List of pending/partial bills displayed
        Each shows: Month, amount due, balance
Input: Select a bill (e.g., "February 2024 - ₱500.00")
Expected: Fields update:
        - Amount due displayed
        - Amount hint shows: "Bill balance: ₱500.00"
Verify: bill_id correctly set in hidden input
```

#### 1.5 Enter Payment Amount
```
Action: Click payment amount field
Input: Enter amount (e.g., 500.00 for full payment or 250.00 for partial)
Expected: Amount accepted
        Form validation passes
Verify: Amount > 0
        Submit button remains enabled
```

#### 1.6 Select Payment Method
```
Action: Click payment method dropdown
Expected: List visible:
        - GCash
        - Bank Transfer
        - PayMaya
        - Check
        - Cash
Input: Select "GCash"
Expected: Selection displayed
Verify: payment_method = 'GCash' in form
```

#### 1.7 Upload Proof of Payment
```
Action: Click "Choose File" in proof upload field
Expected: File browser dialog opens
Input: Select test image (payment_proof.jpg, max 5MB)
Expected: File selected, filename displayed
Verify: File shown in upload field
        File meets requirements:
        - Type: JPG, PNG, or PDF
        - Size: ≤ 5MB
```

#### 1.8 Add Optional Notes
```
Action: Click notes textarea
Input: Enter "GCash reference #123456789"
Expected: Text accepted
Verify: Notes displayed in field
```

#### 1.9 Submit Payment
```
Action: Click "Submit Payment" button
Expected: Form submits
        Processing indicator visible
        Page reloads or updates
Verify: No JavaScript errors in console
```

#### 1.10 Verify Submission Success
```
Expected Result: Success message displayed
        "✓ Online payment submitted!"
        Bill remains in list (not removed)
        Pending online payment visible in status list
```

#### 1.11 Verify Database Entry
```
SQL Query:
SELECT * FROM payment_transactions 
WHERE tenant_id = ? AND bill_id = ? 
AND payment_type = 'online'
ORDER BY created_at DESC LIMIT 1;

Expected Results:
- id: [auto]
- bill_id: [selected bill]
- tenant_id: [logged in tenant]
- payment_amount: [entered amount]
- payment_method: 'GCash'
- payment_type: 'online'
- payment_status: 'pending' ← KEY
- proof_of_payment: 'proof_[billid]_[tenantid]_[timestamp].jpg'
- verified_by: NULL
- verification_date: NULL
- payment_date: [today]
- created_at: [now]
```

#### 1.12 Verify File Storage
```
Location: /public/payment_proofs/

Expected:
- File exists with secure name: proof_[billId]_[tenantId]_[timestamp].jpg
- File readable by web server
- File permissions: -rw-r--r-- or similar

Verification:
- Open file in browser: http://localhost/BAMINT/public/payment_proofs/proof_X_Y_Z.jpg
- Image displays correctly
```

### Test Case 1 Result
✓ **PASS** if:
- Payment submitted successfully
- Success message displayed
- Database record created with 'pending' status
- File uploaded to correct location
- All fields correctly saved

---

## Test Scenario 2: Admin Payment Verification

### Preconditions
- Pending online payment exists (from Test Scenario 1)
- Admin user account exists
- Proof image accessible

### Test Steps

#### 2.1 Login as Admin
```
Action: Navigate to index.php
Input: Admin credentials
Expected: Admin dashboard accessible
Verify: Session created, role = 'admin'
```

#### 2.2 Navigate to Payment Verification
```
Action: Click "Payment Verification" in admin navigation
OR: Navigate to admin_payment_verification.php
Expected: Verification dashboard loads
Verify: Statistics displayed at top
```

#### 2.3 View Statistics
```
Expected Display:
- Pending Verification: [count]
- Verified (30 days): [count]
- Rejected (30 days): [count]

Verify: Counts are accurate based on database
```

#### 2.4 View Pending Payments
```
Expected Display:
For Each Pending Payment:
- Tenant Name: "John Doe"
- Email: john@example.com
- Phone: 09123456789
- Billing Month: "February 2024"
- Amount: ₱500.00
- Payment Method: GCash
- Submitted: [date/time]
- Status Badge: [Yellow "Pending"]
```

#### 2.5 Review Proof of Payment
```
Expected Display:
- Proof image/PDF displayed inline
- If JPG/PNG: Image visible in browser
- If PDF: Download link with icon

Action: If image, visually inspect
        Verify it shows valid payment proof
Expected: Image shows GCash transaction confirmation
         Or equivalent proof of payment
```

#### 2.6 Approve Payment
```
Action: Select "Verify & Approve" radio button
Input: Add verification note (optional): "GCash reference verified"
Action: Click "Submit Verification"

Expected:
- Form submits
- Page updates or reloads
- Payment moves to "Recent Verifications"
- Status changes from "Pending" to "Verified"
```

#### 2.7 Verify Database Update
```
SQL Query:
SELECT * FROM payment_transactions 
WHERE id = [payment_id];

Expected Changes:
- payment_status: 'pending' → 'verified' ✓
- verified_by: NULL → [admin_id] ✓
- verification_date: NULL → NOW() ✓
- All other fields: UNCHANGED
```

#### 2.8 Verify Bill Status Update
```
SQL Query:
SELECT * FROM bills WHERE id = [bill_id];

Expected:
If Full Payment:
- amount_paid: [previous] → [previous + payment_amount]
- status: 'pending'/'partial' → 'paid'

If Partial Payment:
- amount_paid: [previous] → [previous + payment_amount]
- status: 'pending' → 'partial' (if balance remains)
```

#### 2.9 View Recent Verifications
```
Expected Display:
Table showing recent verifications (last 30 days):
- Tenant Name: John Doe
- Billing Month: February 2024
- Amount: ₱500.00
- Status: ✓ Verified
- Verified By: [Admin Name]
- Date: [verification date]

Verify: Recently verified payment appears in list
```

### Test Scenario 2B: Reject Payment

#### 2B.1 Select Different Pending Payment
```
Action: Find another pending payment
Expected: Payment details displayed
```

#### 2B.2 Reject Payment
```
Action: Select "Reject" radio button
Input: Add notes: "Proof unclear - please resubmit"
Action: Click "Submit Verification"

Expected:
- Payment updated to 'rejected'
- Appears in recent verifications as rejected
- Tenant notified (if implemented)
```

#### 2B.3 Verify Rejection in Database
```
SQL Query:
SELECT * FROM payment_transactions WHERE id = [payment_id];

Expected:
- payment_status: 'rejected'
- verified_by: [admin_id]
- verification_date: NOW()
- Amount NOT added to bill (bill unchanged)
```

### Test Case 2 Result
✓ **PASS** if:
- Pending payments display correctly
- Proof image/PDF displays
- Approval updates status to 'verified'
- verified_by and verification_date recorded
- Bill status updated appropriately
- Rejection marks payment as 'rejected'
- Recent verifications list updated

---

## Test Scenario 3: Cash Payment Recording

### Preconditions
- Admin user logged in
- Tenant with unpaid or partial bills exists

### Test Steps

#### 3.1 Navigate to Cash Payment Form
```
Action: Click "Record Cash Payment" in admin navigation
OR: Navigate to admin_record_payment.php
Expected: Form loads with two sections:
         - Tenant selection (left)
         - Payment form (right, initially hidden)
```

#### 3.2 Search for Tenant
```
Action: Type in tenant search field: "John"
Expected: Tenant list filtered
        Only tenants with "John" in name shown
        
If Exact Match:
- John Doe visible
- Shows: Name, Email, Bill Count, Balance
```

#### 3.3 Select Tenant
```
Action: Click on tenant card for "John Doe"
Expected:
- Card highlighted with blue border/background
- Bills container appears
- Bills load dynamically
- Payment form becomes visible
```

#### 3.4 View Tenant Bills
```
Expected Display:
Bill items showing:
- Month (e.g., "February 2024")
- Amount due
- Amount paid
- Status badge (Pending/Partial/Paid)

Example:
┌──────────────────────────┐
│ February 2024            │
│ Due: ₱1,500 | Paid: ₱0   │
│ [Pending]                │
└──────────────────────────┘

Verify: Multiple bills visible if tenant has them
```

#### 3.5 Select Bill
```
Action: Click on a bill card
Expected:
- Bill card highlighted
- Payment form updates:
  - Selected Bill: "February 2024"
  - Amount Due: ₱1,500.00
  - Already Paid: ₱0.00
  - Payment form becomes enabled
```

#### 3.6 Enter Payment Amount
```
Action: Click payment amount field
Input: Enter amount (e.g., 1500.00 for full or 750.00 for partial)
Expected: Amount accepted
         Form validation ready
```

#### 3.7 Select Payment Method
```
Action: Click payment method dropdown
Expected: Options visible:
         - Cash
         - Check
         - Bank Transfer
         - GCash
         - PayMaya
         - Other
Input: Select "Cash"
Expected: Cash selected in dropdown
```

#### 3.8 Add Notes
```
Action: Click notes textarea
Input: "Walk-in payment received, tenant present"
Expected: Notes displayed
```

#### 3.9 Submit Payment
```
Action: Click "Record Payment" button
Expected:
- Form submits
- Success message displays
- "✓ Cash payment recorded successfully!"
- Bills container clears (resets form)
```

#### 3.10 Verify Database Entry
```
SQL Query:
SELECT * FROM payment_transactions 
WHERE bill_id = [selected_bill_id]
AND payment_type = 'cash'
ORDER BY created_at DESC LIMIT 1;

Expected:
- payment_type: 'cash'
- payment_status: 'approved' ← Immediately approved
- recorded_by: [admin_id]
- verified_by: NULL (N/A for cash)
- verification_date: NULL (N/A for cash)
- proof_of_payment: NULL (not needed)
- payment_date: [today]
- payment_amount: [entered amount]
```

#### 3.11 Verify Bill Update
```
SQL Query:
SELECT * FROM bills WHERE id = [selected_bill_id];

Expected:
- amount_paid: [previous] → [previous + payment_amount]
- status: [previous] → 'paid' (if fully paid)
          [previous] → 'partial' (if balance remains)
- updated_at: NOW()

Example:
Before: amount_due=1500, amount_paid=0, status='pending'
After:  amount_due=1500, amount_paid=1500, status='paid'
```

#### 3.12 Record Multiple Payments
```
Action: Repeat steps 3.2-3.9 for different tenant/bill
Expected: Each payment recorded independently
         Multiple cash payments appear in database
```

### Test Case 3 Result
✓ **PASS** if:
- Tenant selection works with search
- Bills load dynamically
- Bill selection updates form
- Payment amount accepted
- Payment recorded immediately as 'approved'
- recorded_by captured correctly
- Bill amount_paid and status updated
- No verification step required

---

## Test Scenario 4: Validation & Error Handling

### 4.1 Online Payment Validation

#### Test Missing Bill Selection
```
Action: Leave bill dropdown empty
Action: Click Submit Payment
Expected Error: "Please select a bill"
Result: Form not submitted, error displayed
```

#### Test Invalid Amount
```
Action: Select bill
Input: Payment amount = 0 (or negative)
Action: Click Submit Payment
Expected Error: "Payment amount must be greater than 0"
Result: Form not submitted
```

#### Test Missing Proof File
```
Action: Select "Online Payment" method
Action: Leave proof upload empty
Action: Try to submit
Expected Error: "Proof of payment is required"
Result: Form not submitted, file field highlighted
```

#### Test Invalid File Type
```
Action: Try to upload .txt or .exe file
Expected Error: "Only JPG, PNG, and PDF files are allowed"
Result: File rejected, error message shown
```

#### Test File Too Large
```
Action: Try to upload file > 5MB
Expected Error: "File size must be less than 5MB"
Result: File rejected, error message shown
```

#### Test Missing Payment Method
```
Action: Select bill and amount
Action: Leave payment method empty
Action: Try to submit
Expected Error: "Please select a payment method"
Result: Form not submitted
```

### 4.2 Cash Payment Validation

#### Test Missing Tenant Selection
```
Action: Try to submit without selecting tenant
Expected: Payment form disabled or shows error
Result: Cannot proceed without tenant
```

#### Test Missing Bill Selection
```
Action: Select tenant
Action: Try to submit without selecting bill
Expected Error: "Please select a bill"
Result: Form not submitted
```

#### Test Invalid Payment Amount
```
Action: Select tenant and bill
Input: Amount = 0 or negative
Action: Click Submit
Expected Error: "Payment amount must be greater than 0"
Result: Form validation prevents submission
```

#### Test Missing Payment Method
```
Action: Select tenant, bill, and amount
Action: Leave payment method empty
Action: Click Submit
Expected Error: "Please select a payment method"
Result: Form not submitted
```

### Test Case 4 Result
✓ **PASS** if:
- All validation errors caught
- User-friendly error messages displayed
- Forms prevent invalid submission
- No database errors or exceptions visible
- Error messages guide user to correct action

---

## Test Scenario 5: File Upload Security

### 5.1 File Type Validation
```
Test Files:
- payment.jpg ✓ ACCEPT
- payment.jpeg ✓ ACCEPT
- payment.png ✓ ACCEPT
- payment.pdf ✓ ACCEPT
- payment.gif ✗ REJECT
- payment.bmp ✗ REJECT
- payment.txt ✗ REJECT
- payment.doc ✗ REJECT

Expected: Only JPG, PNG, PDF accepted
```

### 5.2 File Size Validation
```
Test Cases:
- 1MB file ✓ ACCEPT
- 4.9MB file ✓ ACCEPT
- 5MB file ✓ ACCEPT
- 5.1MB file ✗ REJECT
- 10MB file ✗ REJECT

Expected: Max 5MB enforced
```

### 5.3 Filename Security
```
Expected Behavior:
- Original filename NOT used
- Secure filename generated: proof_[billid]_[tenantid]_[timestamp].jpg
- Prevents directory traversal
- Prevents filename collisions
- Each upload has unique name
```

### 5.4 Directory Permissions
```
Check:
- /public/payment_proofs/ exists
- Directory is writable by web server
- Correct permissions set (755 or similar)
- Files within are readable

Expected:
- Upload succeeds
- Files accessible via web
- Security maintained
```

### Test Case 5 Result
✓ **PASS** if:
- Valid file types accepted
- Invalid types rejected
- File size limits enforced
- Secure filenames generated
- Files stored in correct location
- No security vulnerabilities

---

## Test Scenario 6: Bill Status Transitions

### 6.1 Pending to Paid (Full Payment)

#### Setup
```
Bill Status: pending
Amount Due: ₱1,500.00
Amount Paid: ₱0.00
```

#### Action: Record Full Cash Payment
```
Payment Amount: ₱1,500.00
```

#### Expected
```
Before:  status = pending, amount_paid = 0
After:   status = paid, amount_paid = 1500
Verify:  ✓ Bill marked as paid
```

### 6.2 Pending to Partial (Partial Payment)

#### Setup
```
Bill Status: pending
Amount Due: ₱1,500.00
Amount Paid: ₱0.00
```

#### Action: Record Partial Cash Payment
```
Payment Amount: ₱750.00
```

#### Expected
```
Before:  status = pending, amount_paid = 0
After:   status = partial, amount_paid = 750
Verify:  ✓ Bill marked as partial
         ✓ Balance = 750 remaining
```

### 6.3 Partial to Paid (Final Payment)

#### Setup
```
Bill Status: partial
Amount Due: ₱1,500.00
Amount Paid: ₱750.00
```

#### Action: Record Final Cash Payment
```
Payment Amount: ₱750.00
```

#### Expected
```
Before:  status = partial, amount_paid = 750
After:   status = paid, amount_paid = 1500
Verify:  ✓ Bill marked as paid
         ✓ No balance remaining
```

### 6.4 Online Payment Bill Update

#### Setup
```
Bill Status: pending
Amount Due: ₱500.00
Amount Paid: ₱0.00
```

#### Action: Submit Online Payment
```
Payment Amount: ₱500.00
Status: pending (awaiting verification)
```

#### Verification (Admin Approves)
```
Expected After Admin Approval:
- Bill status: paid
- Amount paid: 500
- Bill updated automatically
```

### Test Case 6 Result
✓ **PASS** if:
- Bill status transitions are correct
- Amount paid updates accurately
- Multiple partial payments accumulate correctly
- Final payment marks bill as paid
- Online payment bill updates after approval

---

## Test Scenario 7: Role-Based Access Control

### 7.1 Tenant Access
```
Tenant Login:
- ✓ Can access tenant_make_payment.php
- ✗ Cannot access admin_payment_verification.php
  Expected: Redirect to index.php
- ✗ Cannot access admin_record_payment.php
  Expected: Redirect to index.php
- ✓ Can see "Make a Payment" button on bills page
```

### 7.2 Admin Access
```
Admin Login:
- ✗ Cannot access tenant_make_payment.php
  Expected: Redirect to index.php
- ✓ Can access admin_payment_verification.php
- ✓ Can access admin_record_payment.php
- ✗ Cannot see "Make a Payment" button (admin doesn't have bills)
```

### 7.3 No Authentication
```
No Login:
- Attempting to access any payment page redirects to index.php
- No data leaked
- Session validation enforced
```

### Test Case 7 Result
✓ **PASS** if:
- Role-based access properly enforced
- Unauthorized access redirected
- Session validation working
- No data leakage between roles

---

## Performance Testing

### Load Testing
```
Test Case: Record 100 cash payments sequentially
Expected:
- All payments recorded successfully
- No timeout errors
- Database performance acceptable
- No deadlocks or conflicts
```

### File Upload Performance
```
Test Case: Upload 50 proof images
Expected:
- All files stored successfully
- No disk space issues
- Upload time < 30 seconds per file
- Proper indexing maintained
```

### Query Performance
```
Queries Tested:
- Fetch pending payments (should use indexes)
- Bill status update (should be atomic)
- Recent verification list (30-day window)

Expected:
- All queries complete in < 1 second
- Proper use of indexes
- No full table scans
```

---

## Deployment Verification Checklist

### Pre-Production
- [ ] All tests passed
- [ ] Documentation complete
- [ ] Database migrated
- [ ] File permissions correct
- [ ] Session security enabled
- [ ] HTTPS configured (if available)
- [ ] Backup created

### Production Deployment
- [ ] Migrate database on production server
- [ ] Deploy PHP files
- [ ] Set file permissions
- [ ] Test each workflow in production environment
- [ ] Verify file uploads work
- [ ] Check database connectivity
- [ ] Test role-based access

### Post-Deployment
- [ ] Monitor error logs
- [ ] Check payment submissions
- [ ] Verify file uploads
- [ ] Monitor database performance
- [ ] Test with real data
- [ ] Verify admin notifications

---

## Troubleshooting Guide

### Issue: "Column not found: payment_type"
**Solution**: Run migration script at `db/migrate_payment_system.php`

### Issue: File upload fails
**Solution**: 
- Check /public/payment_proofs/ exists and is writable
- Run migration to create directory
- Check file size limit in PHP (php.ini: upload_max_filesize)

### Issue: Payment status not updating
**Solution**:
- Check database foreign key relationships
- Verify admin_id in verified_by update
- Check bill_id references are correct

### Issue: "Access Denied" on payment pages
**Solution**:
- Check session is created properly
- Verify role = 'tenant' or 'admin'
- Clear browser cookies and re-login

### Issue: Proof image not displaying
**Solution**:
- Verify file uploaded to correct directory
- Check filename in database
- Test file access directly: /public/payment_proofs/proof_X_Y_Z.jpg

### Issue: Database errors on submit
**Solution**:
- Check database connection in db/database.php
- Verify all tables exist and have required columns
- Check for SQL syntax errors in logs
- Verify prepared statements are correct

---

## Test Results Log Template

```
Test Date: _______________
Tester: ___________________
Environment: Development / Production

Test Scenario 1: Online Payment .............. [ PASS / FAIL ]
Test Scenario 2: Admin Verification ......... [ PASS / FAIL ]
Test Scenario 3: Cash Payment Recording ..... [ PASS / FAIL ]
Test Scenario 4: Validation & Errors ........ [ PASS / FAIL ]
Test Scenario 5: File Upload Security ....... [ PASS / FAIL ]
Test Scenario 6: Bill Status Transitions .... [ PASS / FAIL ]
Test Scenario 7: Role-Based Access .......... [ PASS / FAIL ]

Issues Found:
1. _________________________________
2. _________________________________
3. _________________________________

Overall Status: READY FOR DEPLOYMENT / NEEDS FIXES

Sign-off: ___________________ Date: __________
```

---

## Automated Testing (Optional)

For future enhancement, consider implementing:

1. **Unit Tests** (PHPUnit)
   - File validation functions
   - Amount calculation
   - Status transition logic

2. **Integration Tests**
   - End-to-end payment flows
   - Database transactions
   - File handling

3. **E2E Tests** (Selenium/Cypress)
   - Complete user workflows
   - Form submissions
   - UI interactions

---

This comprehensive testing guide ensures the payment system is production-ready with thorough coverage of all features and edge cases.
