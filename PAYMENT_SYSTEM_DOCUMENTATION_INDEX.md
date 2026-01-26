# Payment System - Documentation Index

## Quick Navigation

### For Users (Tenants & Admins)
Start here if you want to understand how to use the payment system:
- **[PAYMENT_SYSTEM_QUICK_START.md](PAYMENT_SYSTEM_QUICK_START.md)** - Step-by-step user guide for both tenants and admins

### For Administrators  
If you're managing the system:
- **[PAYMENT_SYSTEM_IMPLEMENTATION.md](PAYMENT_SYSTEM_IMPLEMENTATION.md)** - What's new and how to deploy
- **[PAYMENT_SYSTEM_TESTING_GUIDE.md](PAYMENT_SYSTEM_TESTING_GUIDE.md)** - Complete testing procedures

### For Developers
If you need to understand the code:
- **[PAYMENT_SYSTEM_TECHNICAL.md](PAYMENT_SYSTEM_TECHNICAL.md)** - Architecture, database schema, code examples
- **[PAYMENT_SYSTEM_VISUAL_GUIDE.md](PAYMENT_SYSTEM_VISUAL_GUIDE.md)** - Workflows and state diagrams

---

## System Overview

The BAMINT payment system now supports a comprehensive dual-method payment approach:

### 1. Online Payment (Verification Flow)
- Tenants submit payment proof (image or PDF)
- Proof supports: GCash, Bank Transfer, PayMaya, etc.
- Admin reviews and verifies proof
- Payment marked as approved after verification
- Bill status updates automatically

### 2. Cash/Walk-in Payment (Direct Entry)
- Admin receives cash payment from tenant
- Admin directly records payment in system
- No verification step required
- Bill status updates immediately
- Real-time payment recording

---

## Key Features

### For Tenants
✓ Choose between online payment or walk-in/cash payment  
✓ Upload payment proof for online payments (JPG, PNG, PDF)  
✓ Track payment status (pending, verified, or approved)  
✓ Make partial or full payments  
✓ Add notes/reference numbers  
✓ View payment history  

### For Admins
✓ Review and verify online payment proofs  
✓ Record cash payments directly  
✓ View payment statistics and recent activity  
✓ Search and filter payments  
✓ Track verification history  
✓ Automatic bill status management  

---

## New Files

| File | Purpose | Type |
|------|---------|------|
| `tenant_make_payment.php` | Tenant payment submission interface | PHP |
| `admin_payment_verification.php` | Admin online payment review dashboard | PHP |
| `admin_record_payment.php` | Admin cash payment recording form | PHP |
| `db/migrate_payment_system.php` | Database schema migration | PHP Migration |
| `PAYMENT_SYSTEM_QUICK_START.md` | User workflow guide | Documentation |
| `PAYMENT_SYSTEM_TECHNICAL.md` | Technical architecture & code | Documentation |
| `PAYMENT_SYSTEM_IMPLEMENTATION.md` | Deployment & features summary | Documentation |
| `PAYMENT_SYSTEM_VISUAL_GUIDE.md` | Workflow diagrams | Documentation |
| `PAYMENT_SYSTEM_TESTING_GUIDE.md` | Complete testing procedures | Documentation |

---

## Database Changes

### New Columns in `payment_transactions` Table

```sql
payment_type VARCHAR(50)           -- 'online' or 'cash'
payment_status VARCHAR(50)         -- 'pending', 'verified', 'approved', 'rejected'
proof_of_payment VARCHAR(255)      -- Uploaded proof filename
verified_by INT                    -- Admin ID who verified payment
verification_date DATETIME         -- When payment was verified
```

### Migration
Execute migration at: `http://localhost/BAMINT/db/migrate_payment_system.php`

The migration will:
- Add 5 new columns to payment_transactions table
- Create necessary indexes
- Set up foreign key relationships
- Create /public/payment_proofs/ directory for file uploads

---

## Workflow Comparison

### Online Payment Workflow
```
1. Tenant logs in
2. Goes to My Bills → Make a Payment
3. Selects Online Payment method
4. Chooses bill and amount
5. Selects payment method (GCash, etc.)
6. Uploads proof image/PDF
7. Submits payment
   └─ Status: PENDING (awaiting verification)
8. Admin reviews proof
9. Admin approves or rejects
   └─ If approved: Status → VERIFIED, Bill updates
   └─ If rejected: Status → REJECTED, Tenant can resubmit
```

### Cash Payment Workflow
```
1. Tenant comes to office with payment
2. Admin logs in
3. Goes to Record Cash Payment
4. Selects tenant from list
5. Selects bill
6. Enters payment amount and method
7. Submits payment
   └─ Status: APPROVED (immediate)
   └─ Bill updates immediately
   └─ Payment complete, no further verification
```

---

## File Uploads

### Storage Location
`/public/payment_proofs/`

### File Specifications
- **Allowed Types**: JPG, PNG, PDF
- **Maximum Size**: 5MB per file
- **Naming Format**: `proof_[billId]_[tenantId]_[timestamp].[ext]`
- **Example**: `proof_5_10_1704067200.jpg`

### Security
- MIME type validation
- File size validation
- Secure filename generation (prevents conflicts)
- Web server write permissions required

---

## User Access

### Tenant Features
- **URL**: `tenant_make_payment.php`
- **Access**: Logged in as tenant
- **Features**:
  - Submit online payments with proof
  - View pending online payments
  - Select bill and payment amount
  - Add payment notes

### Admin Features
- **Verification URL**: `admin_payment_verification.php`
  - Review pending online payments
  - View payment proofs
  - Approve or reject payments
  - Track verification history

- **Cash Payment URL**: `admin_record_payment.php`
  - Record cash payments
  - Search and select tenant
  - Enter payment details
  - Immediate bill update

---

## Integration with Existing System

The payment system integrates seamlessly with:
- **Bills Management** - Updates bill status automatically
- **Tenant Management** - Links payments to tenant records
- **Admin Dashboard** - Adds payment verification tasks
- **Tenant Dashboard** - Shows payment status
- **Payment History** - Extends with new payment types and statuses

---

## Security Features

✓ **SQL Injection Prevention** - Prepared statements on all queries  
✓ **File Upload Security** - Type validation, size limits, secure naming  
✓ **Role-Based Access** - Tenant and admin access strictly controlled  
✓ **Session Security** - User authentication required on all pages  
✓ **Data Integrity** - Foreign key constraints, audit trails  
✓ **Audit Trail** - Tracking of verified_by and recorded_by  

---

## Status Values

### Payment Status
- **pending** - Waiting for admin verification (online only)
- **verified** - Approved by admin (online payments)
- **approved** - Payment recorded and approved (cash payments)
- **rejected** - Rejected by admin, can resubmit (online only)

### Bill Status
- **pending** - Bill issued, no payment
- **partial** - Some payment received
- **paid** - Bill fully paid
- **overdue** - Past due date and unpaid

---

## Getting Started

### Step 1: Run Migration
Navigate to: `http://localhost/BAMINT/db/migrate_payment_system.php`

This will set up the database schema and create necessary directories.

### Step 2: Test as Tenant
1. Login as tenant
2. Go to My Bills
3. Click "Make a Payment"
4. Follow online or cash payment workflow

### Step 3: Test as Admin
1. Login as admin
2. Go to "Payment Verification" (for online payments)
3. Or go to "Record Cash Payment" (for cash payments)
4. Follow admin workflows

### Step 4: Verify Integration
- Check that bills update status correctly
- Verify payment transactions appear in history
- Confirm file uploads work properly

---

## Documentation Map

```
Payment System Documentation
│
├── PAYMENT_SYSTEM_QUICK_START.md
│   └── For: Tenants & Admins
│   └── Content: How to use the system
│
├── PAYMENT_SYSTEM_TECHNICAL.md
│   └── For: Developers
│   └── Content: Architecture, database, code examples
│
├── PAYMENT_SYSTEM_IMPLEMENTATION.md
│   └── For: Administrators
│   └── Content: What's new, deployment, features
│
├── PAYMENT_SYSTEM_VISUAL_GUIDE.md
│   └── For: Visual learners
│   └── Content: Diagrams, workflows, state transitions
│
├── PAYMENT_SYSTEM_TESTING_GUIDE.md
│   └── For: QA & Testing
│   └── Content: Test scenarios, validation, checklist
│
└── PAYMENT_SYSTEM_DOCUMENTATION_INDEX.md (this file)
    └── For: Navigation & overview
    └── Content: Quick links and summary
```

---

## Quick Reference

### Tenant Payment URL
```
http://localhost/BAMINT/tenant_make_payment.php
```

### Admin Verification URL
```
http://localhost/BAMINT/admin_payment_verification.php
```

### Admin Cash Payment URL
```
http://localhost/BAMINT/admin_record_payment.php
```

### Migration URL
```
http://localhost/BAMINT/db/migrate_payment_system.php
```

### File Upload Location
```
/public/payment_proofs/
```

---

## Support & Help

### Common Issues

**Issue**: "Database column not found"  
**Solution**: Run migration script

**Issue**: "File upload fails"  
**Solution**: Check /public/payment_proofs/ directory permissions

**Issue**: "Can't access payment page"  
**Solution**: Verify login role (tenant vs admin)

For more troubleshooting, see **PAYMENT_SYSTEM_TESTING_GUIDE.md**

---

## System Statistics

| Metric | Count |
|--------|-------|
| New PHP Files | 3 |
| Modified PHP Files | 1 (tenant_bills.php) |
| New Documentation Files | 5 |
| Database Columns Added | 5 |
| New Database Indexes | 2 |
| Lines of Code | ~1,500 |
| Lines of Documentation | ~2,500 |

---

## Version Information

- **Version**: 1.0
- **Status**: ✓ Complete and Tested
- **Release Date**: 2024
- **Compatibility**: PHP 7+, MySQL 8, Bootstrap 5.3.2

---

## Related Documentation

- **[SYSTEM_STATUS.md](SYSTEM_STATUS.md)** - Overall system status
- **[IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)** - System features overview
- **[DATABASE_ERROR_RECOVERY.md](DATABASE_ERROR_RECOVERY.md)** - Error handling
- **[QUICK_START.md](QUICK_START.md)** - Getting started with BAMINT

---

## Support Contacts

For issues related to the payment system:
1. Check the relevant documentation above
2. Review PAYMENT_SYSTEM_TESTING_GUIDE.md for troubleshooting
3. Check application error logs
4. Review database logs for SQL errors

---

## Changelog

### Version 1.0 (Initial Release)
- ✓ Online payment submission with proof upload
- ✓ Admin payment verification dashboard
- ✓ Cash payment recording system
- ✓ Automatic bill status updates
- ✓ Payment history tracking
- ✓ File upload with validation
- ✓ Comprehensive documentation

---

## Next Steps

1. **Read**: Start with PAYMENT_SYSTEM_QUICK_START.md
2. **Deploy**: Execute migration script
3. **Test**: Follow PAYMENT_SYSTEM_TESTING_GUIDE.md
4. **Deploy to Production**: When ready
5. **Monitor**: Check payment submissions regularly

---

## License & Attribution

This payment system was implemented as part of the BAMINT Building Management System.
All code follows security best practices and includes comprehensive error handling.

---

**Last Updated**: 2024  
**Documentation Version**: 1.0  
**Status**: ✓ Complete
