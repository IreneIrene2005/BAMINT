# Payment System - Visual Workflow Guide

## System Overview Diagram

```
┌────────────────────────────────────────────────────────────────────┐
│                     BAMINT PAYMENT SYSTEM                          │
│                      Dual-Method Architecture                      │
└────────────────────────────────────────────────────────────────────┘

                    ┌─ ONLINE PAYMENT PATH ─┐
                    │  (Verification Flow)   │
                    └───────────────────────┘
                              │
                              ▼
                    ┌──────────────────────┐
                    │  Tenant Submits      │
                    │  Payment Proof       │
                    │  (Upload Image/PDF)  │
                    └──────────────────────┘
                              │
                              ▼
                    ┌──────────────────────┐
                    │  File Validation     │
                    │  - Type Check        │
                    │  - Size Check        │
                    │  - Store Filename    │
                    └──────────────────────┘
                              │
                              ▼
                    ┌──────────────────────┐
                    │  Status: PENDING     │
                    │  Await Verification  │
                    └──────────────────────┘
                              │
                              ▼
                    ┌──────────────────────┐
                    │  Admin Reviews       │
                    │  Payment Proof       │
                    │  in Dashboard        │
                    └──────────────────────┘
                              │
                    ┌─────────┴─────────┐
                    │                   │
                    ▼                   ▼
            ┌─────────────────┐  ┌──────────────┐
            │   APPROVED      │  │  REJECTED    │
            │ (Verified)      │  │ (Returns to  │
            │ • Set Status    │  │  Pending)    │
            │ • Update Bill   │  │ • Tenant     │
            │ • Record Admin  │  │ • Resubmit   │
            └─────────────────┘  └──────────────┘


                    ┌─ CASH PAYMENT PATH ─┐
                    │  (Direct Entry)      │
                    └───────────────────┘
                              │
                              ▼
                    ┌──────────────────────┐
                    │  Admin Selects       │
                    │  Tenant & Bill       │
                    │  in Cash Payment     │
                    │  Form                │
                    └──────────────────────┘
                              │
                              ▼
                    ┌──────────────────────┐
                    │  Admin Enters:       │
                    │  - Payment Amount    │
                    │  - Payment Method    │
                    │  - Optional Notes    │
                    └──────────────────────┘
                              │
                              ▼
                    ┌──────────────────────┐
                    │  Immediate Record    │
                    │  Status: APPROVED    │
                    │  No Verification     │
                    │  Required            │
                    └──────────────────────┘
                              │
                              ▼
                    ┌──────────────────────┐
                    │  Bill Updated        │
                    │  • Increase Paid     │
                    │  • Update Status     │
                    │  • Add Entry to DB   │
                    └──────────────────────┘
```

## Tenant Online Payment Workflow

```
┌────────────────────────────────────────────────────────────────┐
│                 TENANT ONLINE PAYMENT FLOW                      │
└────────────────────────────────────────────────────────────────┘

    1. LOGIN
       ↓
    [Tenant Dashboard]
       ↓
    2. NAVIGATE
       ↓
    [My Bills] → [Make a Payment Button]
       ↓
    3. SELECT PAYMENT METHOD
       ↓
    ┌─────────────────────────────────────────┐
    │     Payment Method Selection             │
    │  ┌─────────────────────────────────┐   │
    │  │  💳 Online Payment              │   │
    │  │  GCash, Bank Transfer, etc.     │   │
    │  │  Submit proof of payment        │   │
    │  └─────────────────────────────────┘   │
    │  ┌─────────────────────────────────┐   │
    │  │  💰 Walk-in / Cash Payment      │   │
    │  │  Pay at our office              │   │
    │  │  Admin will process immediately │   │
    │  └─────────────────────────────────┘   │
    └─────────────────────────────────────────┘
       ↓
    4. [CHOOSE ONLINE PAYMENT]
       ↓
    ┌─────────────────────────────────────────┐
    │     Payment Form                         │
    │  • Select Bill (dropdown)                │
    │    └─ Shows outstanding balance          │
    │  • Payment Amount (number input)         │
    │    └─ Default: full balance              │
    │  • Payment Method (dropdown)             │
    │    └─ GCash, Bank Transfer, PayMaya, etc│
    │  • Proof of Payment (file upload)        │
    │    └─ Accepted: JPG, PNG, PDF (max 5MB) │
    │  • Notes (optional textarea)             │
    │  [Submit Payment]                        │
    └─────────────────────────────────────────┘
       ↓
    5. FILE UPLOAD VALIDATION
       ├─ Check file type: JPG/PNG/PDF ✓
       ├─ Check file size: ≤ 5MB ✓
       └─ Generate secure filename ✓
       ↓
    6. DATABASE ENTRY
       ├─ Insert payment_transactions
       ├─ Set status: PENDING
       ├─ Store filename: proof_5_10_1704067200.jpg
       └─ Store date/time: NOW()
       ↓
    7. SUCCESS MESSAGE
       ↓
    ┌─────────────────────────────────────────┐
    │  ✓ Payment submitted!                    │
    │  Status: Awaiting Verification          │
    │                                          │
    │  Pending Verifications:                  │
    │  • February 2024 - ₱1,500.00            │
    │    [Awaiting Verification]              │
    └─────────────────────────────────────────┘
       ↓
    8. [ADMIN REVIEWS]
       └─ See section: Admin Verification
```

## Admin Verification Workflow

```
┌────────────────────────────────────────────────────────────────┐
│            ADMIN PAYMENT VERIFICATION FLOW                      │
└────────────────────────────────────────────────────────────────┘

    1. ADMIN LOGIN
       ↓
    [Admin Dashboard]
       ↓
    2. NAVIGATE
       ↓
    [Payment Verification]
       ↓
    3. VIEW STATISTICS
       ├─ Pending Verification: 5
       ├─ Verified (30 days): 28
       └─ Rejected (30 days): 2
       ↓
    4. PENDING PAYMENTS LIST
       ↓
    For Each Pending Payment:
    ┌──────────────────────────────────────────┐
    │  Tenant: John Doe                        │
    │  Email: john@example.com                 │
    │  Phone: 09123456789                      │
    │  ─────────────────────────────────────── │
    │  Bill: February 2024                     │
    │  Amount: ₱1,500.00                       │
    │  Method: GCash                           │
    │  Submitted: 2024-01-15 2:30 PM          │
    │  ─────────────────────────────────────── │
    │  Proof of Payment:                       │
    │  [Image/PDF Display]                     │
    │  ─────────────────────────────────────── │
    │  Verification Decision:                  │
    │  ┌──────────────────────────────────┐   │
    │  │ ○ Verify & Approve               │   │
    │  │ ○ Reject                         │   │
    │  └──────────────────────────────────┘   │
    │  ─────────────────────────────────────── │
    │  Verification Notes:                     │
    │  [Text area - optional]                  │
    │  ─────────────────────────────────────── │
    │  [Submit Verification]                   │
    └──────────────────────────────────────────┘
       ↓
    5. DECISION: APPROVE
       ├─ Update payment_status: VERIFIED
       ├─ Record verified_by: admin_id
       ├─ Set verification_date: NOW()
       ├─ Check total payments
       └─ Update bill status if fully paid
       ↓
    6. DATABASE UPDATE
       └─ SELECT total_paid FROM payment_transactions
          WHERE bill_id = X AND status IN ('verified', 'approved')
       ↓
    7. BILL STATUS LOGIC
       ├─ If total_paid >= amount_due
       │  └─ Set bill status: PAID
       └─ If total_paid < amount_due
          └─ Set bill status: PARTIAL
       ↓
    8. CONFIRMATION MESSAGE
       ↓
    ┌──────────────────────────────────────────┐
    │  ✓ Payment verified and recorded!        │
    │                                           │
    │  Recent Verifications:                   │
    │  ┌─────────────────────────────────────┐ │
    │  │ John Doe - Feb 2024 - ₱1,500.00     │ │
    │  │ [✓ Verified] Verified By: Admin     │ │
    │  │ Date: Jan 15, 2024                  │ │
    │  └─────────────────────────────────────┘ │
    └──────────────────────────────────────────┘
       ↓
    [Payment appears in history]
```

## Admin Cash Payment Recording Workflow

```
┌────────────────────────────────────────────────────────────────┐
│          ADMIN CASH PAYMENT RECORDING FLOW                      │
└────────────────────────────────────────────────────────────────┘

    1. ADMIN RECEIVES CASH PAYMENT
       └─ Tenant comes to office with payment
       ↓
    2. ADMIN LOGIN
       ↓
    [Admin Dashboard]
       ↓
    3. NAVIGATE
       ↓
    [Record Cash Payment]
       ↓
    4. TENANT SELECTION
       ├─ Search bar for quick lookup
       └─ Tenant list with:
          ├─ Name
          ├─ Email
          ├─ Bill Count
          └─ Outstanding Balance
       ↓
    ┌────────────────────────────────┐
    │ Click Tenant Card to Select    │
    │ • John Doe                     │
    │ • john@example.com             │
    │ • Bills: 3 | Balance: ₱4,500   │
    └────────────────────────────────┘
       ↓
    5. BILL SELECTION
       ├─ Bills container loads
       ├─ Shows all tenant bills
       └─ Click to select bill
       ↓
    ┌────────────────────────────────┐
    │ Select Bill                    │
    │ ┌──────────────────────────┐   │
    │ │ February 2024            │   │
    │ │ Due: ₱1,500.00           │   │
    │ │ Paid: ₱1,000.00          │   │
    │ │ [Partial]                │   │
    │ └──────────────────────────┘   │
    │ ┌──────────────────────────┐   │
    │ │ January 2024             │   │
    │ │ Due: ₱1,500.00           │   │
    │ │ Paid: ₱0.00              │   │
    │ │ [Unpaid]                 │   │
    │ └──────────────────────────┘   │
    └────────────────────────────────┘
       ↓
    6. PAYMENT FORM APPEARS
       ├─ Selected Bill: February 2024
       ├─ Amount Due: ₱1,500.00
       ├─ Already Paid: ₱1,000.00
       ├─ Outstanding: ₱500.00
       ↓
    7. ADMIN ENTERS PAYMENT DETAILS
       ├─ Payment Amount: [text input]
       │  └─ Placeholder: ₱500.00
       ├─ Payment Method: [dropdown]
       │  └─ Options: Cash, Check, Bank Transfer, GCash, PayMaya
       └─ Notes: [textarea - optional]
          └─ E.g., "Walk-in payment, cash received"
       ↓
    8. SUBMIT PAYMENT
       ├─ Validate amount > 0
       ├─ Validate method selected
       ├─ Validate bill exists
       └─ [Record Payment] button click
       ↓
    9. IMMEDIATE DATABASE UPDATE
       ├─ INSERT INTO payment_transactions
       │  ├─ payment_type: 'cash'
       │  ├─ payment_status: 'approved'
       │  ├─ recorded_by: admin_id
       │  ├─ payment_date: TODAY
       │  └─ NO verification needed
       ├─ UPDATE bills
       │  ├─ amount_paid += ₱500.00
       │  └─ status: 'paid' (if fully paid)
       └─ Entry immediately recorded
       ↓
    10. SUCCESS CONFIRMATION
        ├─ ✓ Cash payment recorded!
        ├─ Bill status updated
        └─ Admin can record more payments
        ↓
    11. TRANSACTION COMPLETE
        └─ No further action needed
           (Unlike online - no verification step)
```

## Database State Changes

### Online Payment Status Progression

```
INITIAL STATE (After Submission):
┌─────────────────────────────────┐
│ payment_transactions record      │
├─────────────────────────────────┤
│ id: 1                           │
│ bill_id: 5                      │
│ tenant_id: 10                   │
│ payment_amount: 1500.00         │
│ payment_type: 'online'          │
│ payment_status: 'pending' ◄────┤ WAITING
│ proof_of_payment: 'proof_...'   │
│ verified_by: NULL               │
│ verification_date: NULL         │
│ created_at: 2024-01-15 14:30    │
└─────────────────────────────────┘

AFTER APPROVAL:
┌─────────────────────────────────┐
│ payment_transactions record      │
├─────────────────────────────────┤
│ id: 1                           │
│ bill_id: 5                      │
│ tenant_id: 10                   │
│ payment_amount: 1500.00         │
│ payment_type: 'online'          │
│ payment_status: 'verified' ◄───┤ APPROVED
│ proof_of_payment: 'proof_...'   │
│ verified_by: 3 (admin_id)       │
│ verification_date: 2024-01-15   │
│ created_at: 2024-01-15 14:30    │
└─────────────────────────────────┘
        │
        │ TRIGGERS
        ▼
┌─────────────────────────────────┐
│ bills record                    │
├─────────────────────────────────┤
│ id: 5                           │
│ amount_due: 1500.00             │
│ amount_paid: 1500.00 ◄─────────┤ UPDATED
│ status: 'paid' ◄───────────────┤ UPDATED
│ updated_at: 2024-01-15         │
└─────────────────────────────────┘
```

### Cash Payment Status Progression

```
IMMEDIATE STATE (Direct Entry):
┌─────────────────────────────────┐
│ payment_transactions record      │
├─────────────────────────────────┤
│ id: 2                           │
│ bill_id: 6                      │
│ tenant_id: 10                   │
│ payment_amount: 500.00          │
│ payment_type: 'cash'            │
│ payment_status: 'approved' ◄───┤ IMMEDIATE
│ proof_of_payment: NULL          │
│ recorded_by: 3 (admin_id)       │
│ verified_by: NULL (N/A)         │
│ verification_date: NULL (N/A)   │
│ payment_date: 2024-01-15        │
│ created_at: 2024-01-15 15:45    │
└─────────────────────────────────┘
        │
        │ IMMEDIATE EFFECT
        ▼
┌─────────────────────────────────┐
│ bills record                    │
├─────────────────────────────────┤
│ id: 6                           │
│ amount_due: 1500.00             │
│ amount_paid: 1500.00 ◄─────────┤ UPDATED
│ status: 'paid' ◄───────────────┤ UPDATED
│ updated_at: 2024-01-15 15:45    │
└─────────────────────────────────┘

NO ADDITIONAL STEPS NEEDED
(Payment is complete)
```

## Key Differences: Online vs Cash

```
┌──────────────────┬──────────────────┬──────────────────┐
│                  │   ONLINE         │   CASH           │
├──────────────────┼──────────────────┼──────────────────┤
│ Entry Method     │ Tenant Upload    │ Admin Records    │
│ Proof Required   │ Yes (image/PDF)  │ No               │
│ Verification     │ Admin Reviews    │ Not Needed       │
│ Status Pending   │ Until Verified   │ Approved Instant │
│ Bill Update      │ After Approval   │ Immediate        │
│ Reversible       │ Can Reject       │ Direct Recording │
│ Time to Complete │ Depends on Admin │ Real-time        │
│ Use Case         │ GCash, Transfer  │ Walk-in, Cash    │
│ Audit Trail      │ verified_by      │ recorded_by      │
└──────────────────┴──────────────────┴──────────────────┘
```

## UI Component Interactions

### Payment Method Selection Card

```
┌─────────────────────────────────────────────┐
│  Payment Method Card                        │
│  ┌───────────────────────────────────────┐  │
│  │ 💳 Online Payment                     │  │
│  │ GCash, Bank Transfer, etc.            │  │
│  │ Submit proof of payment for verify    │  │
│  │                                       │  │
│  │ [Hover: Highlight border & shadow]   │  │
│  │ [Click: Select & Change Form]        │  │
│  └───────────────────────────────────────┘  │
└─────────────────────────────────────────────┘
        │
        │ Click/Select
        ▼
┌─────────────────────────────────────────────┐
│  Form Updates                               │
│  ├─ Show: Proof Upload Field                │
│  ├─ Set: Required = true on upload         │
│  └─ Update: Submit Button State            │
└─────────────────────────────────────────────┘
```

### Bill Selection with Amount Update

```
Select Bill Dropdown:
┌─────────────────────────────────────┐
│ ▼ Select Bill                       │
├─────────────────────────────────────┤
│ February 2024 - ₱500.00             │
│ January 2024 - ₱1,500.00            │
│ December 2023 - ₱1,500.00 [PAID]    │
└─────────────────────────────────────┘
        │
        │ Select Bill
        ▼
        onchange() triggers:
        ├─ Get bill data
        ├─ Calculate outstanding = amount_due - amount_paid
        ├─ Set payment_amount placeholder
        └─ Update amount_hint display
        
Result:
"Bill balance: ₱500.00"
[_________] ← Payment Amount field
```

## Status Indicators

```
ONLINE PAYMENT STATUSES:

⏳ pending
   └─ Yellow badge with clock icon
   └─ "Awaiting Verification"
   └─ Shown in pending list

✓ verified
   └─ Green badge with checkmark
   └─ "Payment Approved"
   └─ Shows in recent list
   └─ Bill updated

✗ rejected
   └─ Red badge with X
   └─ "Payment Rejected"
   └─ Shown in recent list
   └─ Tenant can resubmit


BILL STATUSES (After Payment):

pending → partial → paid
   │
   └─ Tracked in bills.status column
   └─ Updated after each payment
   └─ Displayed in bill cards
```

## File Upload Flow

```
User Selects File
        │
        ▼
Browser File Dialog
        │
        ├─ Select proof_image.jpg
        │
        ▼
JavaScript Validation (Frontend)
        ├─ File selected: ✓
        │
        ▼
Form Submission
        │
        ├─ POST to tenant_make_payment.php
        ├─ $_FILES['proof_of_payment'] = file
        │
        ▼
PHP Server-Side Validation
        ├─ Check MIME type
        │  └─ Must be: image/jpeg, image/png, application/pdf
        ├─ Check file size
        │  └─ Must be: ≤ 5MB
        ├─ Validate $_FILES array
        │  └─ error = UPLOAD_ERR_OK
        │
        ▼
Generate Secure Filename
        ├─ proof_5_10_1704067200.jpg
        │  └─ bill_id_tenant_id_timestamp.ext
        │
        ▼
Move File
        ├─ from: $_FILES[tmp_name]
        ├─ to: /public/payment_proofs/proof_5_10_1704067200.jpg
        │
        ▼
Store in Database
        ├─ INSERT payment_transactions
        ├─ proof_of_payment = 'proof_5_10_1704067200.jpg'
        │
        ▼
Display in Admin Dashboard
        ├─ Retrieve filename from DB
        ├─ Build path: /public/payment_proofs/{filename}
        ├─ Display as <img> or <a> to PDF
        │
        ▼
Admin Reviews & Approves
        └─ Updates payment_status = 'verified'
```

---

This visual guide helps understand the complete flow of both payment methods, from user interaction through database updates.
