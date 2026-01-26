# Payment System - Technical Documentation

## Architecture Overview

The payment system is built with a two-tier architecture supporting distinct workflows:

```
┌─────────────────────────────────────────────────────────────────┐
│                    PAYMENT SYSTEM ARCHITECTURE                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  TENANT LAYER                    ADMIN LAYER                     │
│  ──────────────────────────────  ──────────────────────────────  │
│                                                                   │
│  tenant_make_payment.php    ←→  admin_payment_verification.php   │
│  │                              │                                │
│  ├─ Bill Selection             ├─ Online Payment Review         │
│  ├─ Online Payment Path         ├─ Proof Verification           │
│  ├─ Cash Payment Request        ├─ Approval/Rejection           │
│  └─ Proof Upload               └─ Bill Status Update            │
│                                                                   │
│      ↓                               ↓                           │
│  ┌─────────────────────────────────────────────────┐            │
│  │  admin_record_payment.php                       │            │
│  │  └─ Cash Payment Entry & Recording              │            │
│  └─────────────────────────────────────────────────┘            │
│                      ↓                                           │
│  ┌─────────────────────────────────────────────────┐            │
│  │  DATABASE (payment_transactions table)          │            │
│  │  └─ Store all payment records with metadata     │            │
│  └─────────────────────────────────────────────────┘            │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

## File Structure

### New Files Created

#### 1. `tenant_make_payment.php`
**Purpose**: Tenant-facing payment submission interface

**Key Features**:
- Displays available payment methods (Online/Cash)
- Bill selection with outstanding balance calculation
- Payment form with amount and method selection
- File upload for online payment proofs
- Optional payment notes
- Status tracking for pending online payments

**Database Operations**:
```php
// Fetch pending bills for tenant
SELECT * FROM bills WHERE tenant_id = ? AND status IN ('pending', 'partial')

// Insert payment transaction
INSERT INTO payment_transactions 
(bill_id, tenant_id, payment_amount, payment_method, payment_type, 
 payment_status, proof_of_payment, payment_date, notes)

// Check for pending online payments
SELECT * FROM payment_transactions 
WHERE tenant_id = ? AND payment_type = 'online' AND payment_status = 'pending'

// Update bill for cash payments
UPDATE bills SET amount_paid = amount_paid + ?, status = ? WHERE id = ?
```

**File Upload Handling**:
```php
// Upload directory: /public/payment_proofs/
// File naming: proof_[billId]_[tenantId]_[timestamp].[ext]
// Allowed types: JPG, PNG, PDF
// Max size: 5MB
```

#### 2. `admin_payment_verification.php`
**Purpose**: Admin dashboard for verifying online payment proofs

**Key Features**:
- List of pending online payment submissions
- Display of proof images/PDFs
- Verification decision buttons (Approve/Reject)
- Optional verification notes
- Recent verification history (last 30 days)
- Statistics dashboard (pending, verified, rejected counts)

**Database Operations**:
```php
// Fetch pending online payments
SELECT pt.*, t.name, t.email, b.billing_month, b.amount_due, b.amount_paid
FROM payment_transactions pt
JOIN tenants t ON pt.tenant_id = t.id
JOIN bills b ON pt.bill_id = b.id
WHERE pt.payment_type = 'online' AND pt.payment_status = 'pending'

// Verify payment
UPDATE payment_transactions 
SET payment_status = 'verified', verified_by = ?, verification_date = NOW()
WHERE id = ? AND payment_status = 'pending'

// Check if bill is fully paid
SELECT SUM(payment_amount) FROM payment_transactions 
WHERE bill_id = ? AND payment_status IN ('verified', 'approved')

// Update bill status
UPDATE bills SET status = ? WHERE id = ?

// Fetch recent verifications
SELECT * FROM payment_transactions
WHERE payment_type = 'online' AND payment_status IN ('verified', 'rejected')
AND verification_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
```

#### 3. `admin_record_payment.php`
**Purpose**: Admin interface to record cash/walk-in payments

**Key Features**:
- Tenant selection with search functionality
- Dynamic bill loading for selected tenant
- Bill balance information display
- Payment form with amount and method selection
- Optional notes
- Immediate bill status update

**Database Operations**:
```php
// Fetch all tenants with balance info
SELECT t.id, t.name, t.email,
       COUNT(DISTINCT b.id) as bill_count,
       SUM(b.amount_due - b.amount_paid) as total_balance
FROM tenants t
LEFT JOIN bills b ON t.id = b.tenant_id
GROUP BY t.id

// Fetch tenant bills
SELECT * FROM bills WHERE tenant_id = ? ORDER BY billing_month DESC

// Insert cash payment
INSERT INTO payment_transactions 
(bill_id, tenant_id, payment_amount, payment_method, payment_type, 
 payment_status, recorded_by, payment_date, notes)
VALUES (?, ?, ?, ?, 'cash', 'approved', ?, CURDATE(), ?)

// Update bill immediately
UPDATE bills SET amount_paid = amount_paid + ?, status = ? WHERE id = ?
```

## Database Schema Changes

### Migration Script: `db/migrate_payment_system.php`

**New Columns Added to `payment_transactions`**:

```sql
ALTER TABLE payment_transactions 
ADD COLUMN payment_type VARCHAR(50) DEFAULT 'cash' 
COMMENT 'Payment type: online or cash';

ALTER TABLE payment_transactions 
ADD COLUMN payment_status VARCHAR(50) DEFAULT 'pending' 
COMMENT 'Status: pending, verified, approved, rejected';

ALTER TABLE payment_transactions 
ADD COLUMN proof_of_payment VARCHAR(255) NULL 
COMMENT 'Filename of uploaded proof image/PDF';

ALTER TABLE payment_transactions 
ADD COLUMN verified_by INT NULL 
COMMENT 'Admin ID who verified the payment';

ALTER TABLE payment_transactions 
ADD FOREIGN KEY (verified_by) REFERENCES admins(id);

ALTER TABLE payment_transactions 
ADD COLUMN verification_date DATETIME NULL 
COMMENT 'When payment was verified/rejected';
```

### Updated Schema in `db/init.sql`

```sql
CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_id INT NOT NULL,
    tenant_id INT NOT NULL,
    payment_amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    payment_type VARCHAR(50) DEFAULT 'cash',
    payment_status VARCHAR(50) DEFAULT 'pending',
    proof_of_payment VARCHAR(255),
    recorded_by INT,
    verified_by INT,
    verification_date DATETIME,
    payment_date DATE NOT NULL,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bill_id) REFERENCES bills(id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (recorded_by) REFERENCES admins(id),
    FOREIGN KEY (verified_by) REFERENCES admins(id),
    INDEX idx_payment_type (payment_type),
    INDEX idx_payment_status (payment_status),
    INDEX idx_tenant_id (tenant_id),
    INDEX idx_bill_id (bill_id)
);
```

## Payment Status Workflow

### Online Payment Workflow

```
Tenant Submits Payment
        ↓
[payment_type = 'online', payment_status = 'pending']
        ↓
Stored with proof_of_payment file
        ↓
Admin Reviews Proof
        ├─→ APPROVED: payment_status = 'verified'
        │                verified_by = admin_id
        │                verification_date = NOW()
        │                Bill status updates based on total paid
        │
        └─→ REJECTED: payment_status = 'rejected'
                       verified_by = admin_id
                       verification_date = NOW()
                       Tenant notified (optional)
```

### Cash Payment Workflow

```
Admin Receives Cash Payment
        ↓
Admin Opens Record Cash Payment Form
        ↓
Selects Tenant & Bill
        ↓
Enters Payment Amount & Method
        ↓
[Immediately recorded as 'approved']
[payment_type = 'cash', payment_status = 'approved']
[recorded_by = admin_id, payment_date = TODAY]
        ↓
Bill amount_paid updated immediately
Bill status updated (pending/partial/paid)
```

## File Upload Mechanism

### Directory Structure

```
/public/
  ├── payment_proofs/          (Created automatically)
  │   ├── proof_1_5_1704067200.jpg
  │   ├── proof_2_5_1704067300.png
  │   └── proof_3_8_1704067400.pdf
  ├── css/
  │   └── style.css
  └── ...
```

### Upload Process

```php
// Step 1: Validate file
- Check MIME type (image/jpeg, image/png, application/pdf)
- Check file size (max 5MB)
- Validate against $_FILES array

// Step 2: Create directory if needed
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Step 3: Generate secure filename
$file_ext = pathinfo($_FILES['proof_of_payment']['name'], PATHINFO_EXTENSION);
$proof_filename = "proof_" . $bill_id . "_" . $tenant_id . "_" . time() . "." . $file_ext;
$upload_path = $upload_dir . "/" . $proof_filename;

// Step 4: Move uploaded file
move_uploaded_file($_FILES['proof_of_payment']['tmp_name'], $upload_path);

// Step 5: Store filename in database
INSERT INTO payment_transactions (proof_of_payment) VALUES ($proof_filename);
```

### File Access Security

- Files stored outside web root (would be better practice)
- Filename validation prevents directory traversal
- File type validation on upload
- Timestamp in filename prevents collisions

## API Endpoints / Form Submissions

### Tenant Payment Submission

**URL**: `tenant_make_payment.php`  
**Method**: POST

```php
POST Parameters:
- action: 'submit_payment'
- bill_id: Integer (bill to pay)
- payment_type: 'online' or 'cash'
- payment_amount: Decimal (amount)
- payment_method: String (GCash, Bank Transfer, etc.)
- notes: String (optional)
- proof_of_payment: File (for online only)

Response:
- Success: Message displayed, pending_online list updated
- Error: Alert with specific error message
```

### Admin Payment Verification

**URL**: `admin_payment_verification.php`  
**Method**: POST

```php
POST Parameters:
- action: 'verify' or 'reject'
- payment_id: Integer (payment transaction ID)
- verification_notes: String (optional)

Response:
- Success: Payment status updated, recent_verifications list refreshed
- Error: Alert with specific error message
```

### Admin Cash Payment Recording

**URL**: `admin_record_payment.php`  
**Method**: POST

```php
POST Parameters:
- action: 'record_payment'
- bill_id: Integer
- tenant_id: Integer
- payment_amount: Decimal
- payment_method: String
- notes: String (optional)

Response:
- Success: Payment recorded, bills container cleared
- Error: Alert with specific error message
```

## Error Handling

### Validation Errors

```php
// Tenant-side validation
- Bill not selected
- Payment amount ≤ 0
- Payment method not selected
- Missing proof file for online payments
- Invalid file type (online payments)
- File too large (online payments)

// Admin verification-side
- No action specified
- Invalid payment ID
- Payment already processed

// Cash payment-side
- Bill not selected
- Payment amount ≤ 0
- Payment method not selected
```

### Database Error Handling

```php
try {
    // Database operations
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
} catch (Exception $e) {
    // Log error
    $message = "Error: " . $e->getMessage();
    $message_type = "danger";
}
```

## Security Implementations

### SQL Injection Prevention
- All queries use prepared statements with bound parameters
- No direct string concatenation in SQL

### File Upload Security
- MIME type validation
- File size limits (5MB)
- Secure filename generation with timestamp
- Directory permissions (0755)

### Session Security
- Role-based access control (tenant vs admin)
- Session validation on each page
- Logout functionality

### Data Integrity
- Foreign key constraints
- Transaction isolation
- Audit trail (recorded_by, verified_by, timestamps)

## Performance Optimization

### Database Indexes
```sql
INDEX idx_payment_type (payment_type)
INDEX idx_payment_status (payment_status)
INDEX idx_tenant_id (tenant_id)
INDEX idx_bill_id (bill_id)
```

### Query Optimization
- Filtered queries with WHERE clauses
- Limited result sets (30-day window for recent verifications)
- Aggregate functions for statistics

## Testing Considerations

### Unit Tests
- File upload validation
- Payment amount calculation
- Bill status update logic
- Role-based access control

### Integration Tests
- End-to-end online payment flow
- End-to-end cash payment flow
- Bill status transitions
- Verification workflow

### Security Tests
- SQL injection attempts
- File upload exploits
- Session hijacking
- Unauthorized access attempts

## Future Enhancements

1. **Email Notifications**
   - Tenant notified when payment verified
   - Admin notified of new pending payments

2. **Payment Reconciliation**
   - Bank statement matching
   - Automated receipt generation

3. **Multiple Currency Support**
   - Support different payment currencies
   - Exchange rate handling

4. **Webhook Integration**
   - Real-time payment gateway notifications
   - Automatic payment status updates

5. **Advanced Analytics**
   - Payment trends and patterns
   - Revenue forecasting
   - Delinquency reports

6. **Mobile App Integration**
   - Mobile-friendly payment interface
   - Mobile payment gateway support
   - Push notifications

## Code Examples

### Processing Online Payment in tenant_make_payment.php

```php
// Insert payment transaction
$stmt = $conn->prepare("
    INSERT INTO payment_transactions 
    (bill_id, tenant_id, payment_amount, payment_method, payment_type, 
     payment_status, proof_of_payment, payment_date, notes, created_at)
    VALUES 
    (:bill_id, :tenant_id, :payment_amount, :payment_method, :payment_type, 
     :payment_status, :proof_of_payment, CURDATE(), :notes, NOW())
");

$stmt->execute([
    'bill_id' => $bill_id,
    'tenant_id' => $tenant_id,
    'payment_amount' => $payment_amount,
    'payment_method' => $payment_method,
    'payment_type' => 'online',
    'payment_status' => 'pending',  // Awaiting verification
    'proof_of_payment' => $proof_filename,
    'notes' => $notes
]);

$message = "✓ Online payment submitted! We'll verify your proof and update your account.";
```

### Verifying Payment in admin_payment_verification.php

```php
// Update payment to verified
$stmt = $conn->prepare("
    UPDATE payment_transactions 
    SET payment_status = 'verified', verified_by = :admin_id, verification_date = NOW()
    WHERE id = :id AND payment_status = 'pending'
");
$stmt->execute(['id' => $payment_id, 'admin_id' => $admin_id]);

// Update bill status if fully paid
$bill_check = $conn->prepare("
    SELECT COALESCE(SUM(payment_amount), 0) as total_paid FROM payment_transactions 
    WHERE bill_id = :bill_id AND payment_status IN ('verified', 'approved')
");
$bill_check->execute(['bill_id' => $payment_info['bill_id']]);

$total_paid = $bill_check->fetch(PDO::FETCH_ASSOC)['total_paid'];
$bill_status = ($total_paid >= $payment_info['amount_due']) ? 'paid' : 'partial';

$update_bill = $conn->prepare("UPDATE bills SET status = :status WHERE id = :id");
$update_bill->execute(['status' => $bill_status, 'id' => $payment_info['bill_id']]);
```

---

## Related Files
- `db/migrate_payment_system.php` - Database migration script
- `db/init.sql` - Initial database schema
- `db/database.php` - Database connection
- `tenant_bills.php` - Updated with payment button
- `payment_history.php` - Existing payment history view
