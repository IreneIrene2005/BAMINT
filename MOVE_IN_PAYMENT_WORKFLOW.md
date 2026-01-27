# Move-In Payment Workflow Guide

## Overview
This document describes the comprehensive move-in payment workflow implemented in the BAMINT boarding house management system. The system requires tenants to complete an advance payment before they can officially move in and occupy a room.

## Workflow Stages

### Stage 1: Tenant Submits Room Request
- **Actor**: Tenant
- **Action**: Submits a room request via the tenant portal
- **System Updates**: Creates a `room_requests` record with status = `'pending'`
- **Room Status**: Remains `'available'`
- **Tenant Status**: Not yet assigned to room

### Stage 2: Admin Reviews and Approves Request (Conditional)
- **Actor**: Admin/Manager
- **Location**: Room Requests Queue (`room_requests_queue.php`)
- **Action**: Reviews tenant application and clicks "Approve" button
- **System Actions**:
  1. Updates `room_requests` status → `'pending_payment'`
  2. Assigns `room_id` to tenant record (but status still `'inactive'`)
  3. **Automatically generates advance payment bill**:
     - Amount: 1 month rent (from room rate)
     - Description: "ADVANCE PAYMENT - Move-in fee (1 month rent)"
     - Status: `'unpaid'`
  4. Creates payment transaction record with status `'pending'`
- **Room Status**: Remains `'available'` (NOT occupied yet)
- **Tenant Status**: Remains `'inactive'` (NOT active yet)
- **Result**: Room request now shows "Awaiting Payment" status

### Stage 3: Tenant Makes Payment
- **Actor**: Tenant
- **Location**: Tenant portal (`tenant_make_payment.php` or similar)
- **Payment Options**: 
  - Online payment (card, e-wallet, etc.)
  - Walk-in payment (cash)
- **System**: Accepts payment and marks as `'pending'` verification
- **Important**: Payment is NOT yet confirmed

### Stage 4: Admin Verifies Payment
- **Actor**: Admin/Manager
- **Location**: Billing/Payments Management (`bills.php`)
- **Action**: Reviews submitted payment and clicks "Verify" button
- **System Actions** (Only for Move-in Advance Payments):
  1. Updates payment status → `'verified'`
  2. Updates bill status → `'paid'`
  3. **Automatically triggers final move-in approval**:
     - Updates tenant status → `'active'` with `start_date = NOW()`
     - Updates room status → `'occupied'`
     - Updates room request status → `'approved'` (final)
- **Result**: Tenant is now fully approved and moved in

## Status Reference Table

### Room Request Statuses
| Status | Description | Room Assignable | Payment Required |
|--------|-------------|-----------------|-----------------|
| `pending` | Awaiting admin review | No | No |
| `pending_payment` | Approved by admin, awaiting payment | Yes (temp) | Yes |
| `approved` | Payment verified, fully approved | Yes | No |
| `rejected` | Rejected by admin | No | No |

### Tenant Statuses
| Status | Meaning | Can Occupy Room |
|--------|---------|-----------------|
| `active` | Approved and moved in | Yes |
| `inactive` | Not yet approved or payment pending | No |

### Room Statuses
| Status | Meaning | Bookable |
|--------|---------|----------|
| `available` | Can accept new requests | Yes |
| `occupied` | Tenant is currently occupying | No |
| `unavailable` | Under maintenance | No |

### Payment Statuses
| Status | Meaning |
|--------|---------|
| `pending` | Awaiting verification |
| `verified` | Confirmed and valid |
| `approved` | Final approval |
| `rejected` | Not accepted |

## Validation Rules

### 1. No Room Occupancy Without Payment
- A tenant cannot be marked as `'active'` without confirmed payment
- Rooms remain `'available'` until payment is verified
- Payment verification automatically updates all statuses

### 2. Advance Payment Identification
- Move-in advance payments are identified by the bill `notes` field
- Must contain "ADVANCE PAYMENT" text
- System automatically detects these and completes the workflow

### 3. Payment Amount Validation
- Advance payment amount = Room monthly rent
- Must be fully paid to be marked as `'paid'`
- Partial payments keep bill status as `'partial'` (flow stalls)

### 4. Room Assignment Protection
- Admin cannot manually mark a room as `'occupied'` before payment verification
- System prevents premature occupancy updates
- Room assignment is locked in workflow chain

## Database Tables Involved

### room_requests
```
- id
- tenant_id
- room_id
- status: 'pending' | 'pending_payment' | 'approved' | 'rejected'
- tenant_info_name, email, phone, address
- request_date
- approved_date
```

### bills
```
- id
- tenant_id
- room_id
- billing_month
- amount_due
- amount_paid
- status: 'unpaid' | 'partial' | 'paid'
- notes (contains "ADVANCE PAYMENT" for move-in payments)
```

### payment_transactions
```
- id
- bill_id
- tenant_id
- payment_amount
- payment_type: 'online' | 'cash'
- payment_status: 'pending' | 'verified' | 'approved' | 'rejected'
- verified_by
- verification_date
- notes
```

### tenants
```
- id
- room_id (assigned during approval, not activation)
- status: 'active' | 'inactive'
- start_date (set during payment verification)
- name, email, phone
```

### rooms
```
- id
- room_number
- status: 'available' | 'occupied' | 'unavailable'
- rate (used for advance payment calculation)
```

## Admin Dashboard Indicators

### Room Requests Queue Statistics
- **Total Requests**: All room requests
- **Pending**: Awaiting admin review
- **Awaiting Payment**: ⭐ Critical - approved but unpaid (pending_payment)
- **Approved**: Fully approved and moved in
- **Rejected**: Rejected applications

### Filter Options
Admins can filter by:
- All Requests
- Pending (waiting review)
- **Awaiting Payment** ⭐ (priority view)
- Approved
- Rejected

## Common Scenarios

### Scenario 1: Tenant Approved, Payment Complete
```
Admin Approves → Status: pending_payment → Bill Created
Tenant Pays → Payment marked: pending
Admin Verifies → Payment marked: verified
Result: Tenant marked active, Room marked occupied, Request marked approved
```

### Scenario 2: Tenant Approved, Payment Incomplete
```
Admin Approves → Status: pending_payment → Bill Created
Tenant Doesn't Pay → Status remains: pending_payment
Result: Room remains available, tenant remains inactive
Admin can reject if payment takes too long
```

### Scenario 3: Tenant Requests, Admin Rejects
```
Tenant Requests → Status: pending
Admin Rejects → Status: rejected
Result: No bill created, room status unchanged
```

## Important Notes

1. **Automatic Bill Generation**: When admin approves a room request, a bill is automatically created. No manual bill creation needed for move-in payments.

2. **Payment Workflow Integration**: Payment verification automatically triggers all move-in completion steps. No manual status updates needed.

3. **Tenant Assignment Timing**: 
   - During approval: `room_id` assigned, but tenant remains `'inactive'`
   - During payment verification: `status` becomes `'active'` and `start_date` set

4. **Room Protection**: Rooms cannot be marked occupied without payment verification. This prevents data inconsistency.

5. **Audit Trail**: All payment verifications create records showing:
   - Who verified
   - When verified
   - What changed

6. **Transactions**: Database transactions ensure atomicity. If any step fails, the entire workflow is rolled back.

## Administrator Guide

### To Approve a Room Request:
1. Go to **Room Requests Queue**
2. Find the request with status **"Pending Review"**
3. Click **Approve**
4. System automatically:
   - Creates advance payment bill
   - Changes status to **"Awaiting Payment"**
   - Assigns room to tenant (inactive)

### To Monitor Pending Payments:
1. Click filter button **"Awaiting Payment"** on Room Requests Queue
2. See all requests that have been approved but payment not verified
3. Advise tenants to complete their payment

### To Verify Payment:
1. Go to **Billing**
2. Find bills with note "ADVANCE PAYMENT"
3. Find the payment record marked **"Pending"**
4. Click **Verify**
5. System automatically:
   - Marks payment verified
   - Activates tenant (`status = 'active'`)
   - Marks room occupied
   - Completes room request

## Testing Checklist

- [ ] Tenant can submit room request
- [ ] Admin can approve request
- [ ] Bill is automatically created with correct amount
- [ ] Room request shows "Awaiting Payment" status
- [ ] Room remains "available" after approval
- [ ] Tenant remains "inactive" after approval
- [ ] Tenant can make payment
- [ ] Admin can verify payment
- [ ] Tenant automatically marked "active"
- [ ] Room automatically marked "occupied"
- [ ] Room request marked "approved" (final)
- [ ] Filter shows correct counts for all statuses
- [ ] Payment rejection properly updates system
- [ ] Partial payments don't complete workflow
- [ ] Multiple room requests can be in different stages simultaneously

