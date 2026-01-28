# BAMINT System - Testing & Deployment Guide

## Pre-Deployment Checklist

### Files to Verify
- [ ] `tenant_dashboard.php` - No PHP errors
- [ ] `admin_payment_verification.php` - No PHP errors
- [ ] `tenant_messages.php` - No PHP errors (NEW FILE)
- [ ] `admin_send_message.php` - No PHP errors (NEW FILE)
- [ ] `maintenance_actions.php` - Updated correctly
- [ ] `db/notifications.php` - Has all 4 new functions
- [ ] All files have syntax: `php -l filename.php` should pass

### Database Verification
- [ ] `messages` table exists in database
- [ ] `bills` table has proper schema for tracking amounts
- [ ] `payment_transactions` table has `payment_status` column
- [ ] `maintenance_requests` table has `cost` column

---

## Complete Testing Workflow

### Test Group 1: Maintenance Pricing

#### Test 1.1: Cost Display in Tenant Form
1. Login as tenant
2. Go to Maintenance → Request Maintenance
3. **Expected:** Category dropdown shows prices (e.g., "Door/Lock – Broken lock, stuck door ₱150")
4. **Expected:** Selecting category updates "Estimated Cost" display
5. **Expected:** Cost should update when category changes

#### Test 1.2: Cost Display in Admin Queue
1. Login as admin
2. Go to Maintenance → Request Queue
3. **Expected:** Each request card shows cost (e.g., "₱150" for Door/Lock)
4. **Expected:** "Other" category shows "Determined by admin"
5. **Expected:** Cost display matches selected category

#### Test 1.3: Cost Display in Admin List
1. Login as admin
2. Go to Maintenance → All Requests
3. **Expected:** Cost column visible in table
4. **Expected:** All requests show their category's corresponding cost
5. **Expected:** "Other" requests show "Determined by admin"

#### Test 1.4: Cost Display in History
1. Login as admin or tenant
2. Go to Maintenance → History
3. **Expected:** Previous requests show their costs
4. **Expected:** Costs match the category pricing

---

### Test Group 2: Automatic Cost-to-Bill Integration

#### Test 2.1: Cost Added on Completion (Non-Other Category)
1. **Precondition:** Create a new maintenance request for "Door/Lock" (₱150)
2. **Action:** Admin marks request as "completed"
3. **Check Database:**
   ```sql
   SELECT * FROM bills WHERE tenant_id = [TENANT_ID] 
   ORDER BY billing_month DESC LIMIT 1;
   ```
4. **Expected:** 
   - Bill exists for next month (e.g., next month if current is January, then February)
   - `amount_due` includes the ₱150 cost
   - Status is "unpaid"

#### Test 2.2: Cost Added to Existing Bill
1. **Precondition:** Tenant has existing bill for next month (₱1000)
2. **Action:** Admin completes maintenance request for "Cleaning" (₱100)
3. **Check Database:**
   ```sql
   SELECT amount_due FROM bills WHERE tenant_id = [TENANT_ID] 
   AND billing_month = [NEXT_MONTH];
   ```
4. **Expected:** Bill amount increased from ₱1000 to ₱1100

#### Test 2.3: Multiple Costs Accumulate
1. **Precondition:** Tenant has new bill for next month
2. **Action:** Complete 3 maintenance requests:
   - Door/Lock (₱150)
   - Cleaning (₱100)
   - Light/Bulb (₱50)
3. **Check Database:** Total cost should be ₱300
4. **Expected:** Bill shows ₱300 added

#### Test 2.4: "Other" Category Doesn't Auto-Add
1. **Action:** Complete maintenance request with "Other" category (no cost specified by admin)
2. **Check Database:** Bill should NOT be automatically updated
3. **Expected:** Admin must manually add cost

---

### Test Group 3: Partial Payment Notifications

#### Test 3.1: Partial Payment Detection
1. **Setup:** Create bill of ₱1000 for tenant
2. **Action:** Tenant submits payment of ₱600
3. **Admin Action:** Go to Payment Verification, verify the ₱600 payment
4. **Check Database:**
   ```sql
   SELECT status FROM bills WHERE id = [BILL_ID];
   ```
5. **Expected:** Bill status changes to "partial" (NOT "paid")

#### Test 3.2: Admin Notification on Partial Payment
1. **Precondition:** Bill of ₱1000, tenant pays ₱600
2. **Action:** Admin verifies payment in admin_payment_verification.php
3. **Check:** Admin notification bell / notification inbox
4. **Expected:** Notification appears saying:
   - "Partial payment received: ₱600.00 of ₱1000.00"
   - "Remaining: ₱400.00"

#### Test 3.3: Tenant Notification on Partial Payment
1. **Precondition:** Bill of ₱1000, tenant pays ₱600
2. **Action:** Admin verifies payment
3. **Check:** Tenant dashboard or notification panel
4. **Expected:** Notification says:
   - "Payment received! You paid ₱600.00"
   - "Remaining balance: ₱400.00"

#### Test 3.4: Full Payment Works Correctly
1. **Setup:** Bill of ₱1000
2. **Action:** Tenant submits ₱1000 payment
3. **Admin Action:** Verify the payment
4. **Check Database:** Bill status should be "paid" (NOT "partial")
5. **Expected:** Different notifications (no remaining balance)

---

### Test Group 4: Messaging System

#### Test 4.1: Admin Sends Message
1. **Action:** Login as admin
2. **Go to:** Admin Dashboard → Find a messaging interface or admin_send_message.php
3. **Action:**
   - Select a tenant from dropdown
   - Choose template: "Balance Reminder"
   - See auto-filled subject with balance amount
   - Send message
4. **Expected:**
   - Message sent successfully
   - Admin sees confirmation

#### Test 4.2: Tenant Receives Message
1. **Precondition:** Admin sent message to tenant (Test 4.1)
2. **Login as:** That tenant
3. **Check Dashboard:** Should see Messages navigation link
4. **Go to:** Messages link (tenant_messages.php)
5. **Expected:**
   - Message visible in inbox
   - Shows admin as sender
   - Shows message subject
   - Shows message date/time

#### Test 4.3: Tenant Reads Message
1. **Action:** In tenant_messages.php, click on a message to expand
2. **Expected:**
   - Full message content visible
   - Message marked as read
   - Visual indication of read status (styling change)
   - Timestamp shows when read

#### Test 4.4: Multiple Messages
1. **Admin Action:** Send multiple messages to same tenant
   - Message 1: "Balance Reminder"
   - Message 2: "Payment Confirmation"
   - Message 3: Custom message
2. **Tenant Action:** View tenant_messages.php
3. **Expected:**
   - All 3 messages visible
   - Ordered by date (newest first)
   - Each can be expanded/read independently

#### Test 4.5: Message Links
1. **Admin Action:** Send message related to a specific bill
   - Use admin_send_message.php
   - Select bill in "Related Record" field
2. **Check Database:**
   ```sql
   SELECT * FROM messages WHERE related_type = 'bill';
   ```
3. **Expected:** Message has related_type and related_id populated

---

### Test Group 5: Outstanding Bills Admin Display

#### Test 5.1: Outstanding Bills Section Appears
1. **Setup:** Create bill with partial payment (₱100 of ₱1000 paid)
2. **Login as:** Admin
3. **Go to:** Payment Verification page
4. **Check:** Top of page
5. **Expected:**
   - Yellow/warning alert about outstanding bills
   - Shows count: "1 bill(s) with outstanding balances"
   - Table with unpaid bills visible

#### Test 5.2: Outstanding Bills Table Content
1. **Precondition:** Same as 5.1
2. **Check Table for:**
   - Tenant name
   - Billing month
   - Amount due (₱1000)
   - Amount paid (₱100)
   - Remaining balance badge showing ₱900
3. **Expected:** All fields correct and formatted

#### Test 5.3: Message Button on Outstanding Bills
1. **Precondition:** Bill with outstanding balance
2. **Action:** Click "Message" button in Outstanding Bills table
3. **Expected:**
   - Redirects to admin_send_message.php
   - Tenant pre-selected
   - Bill pre-selected in related record

#### Test 5.4: Multiple Outstanding Bills
1. **Setup:** Create 3 bills with partial payments for different tenants
2. **Login as:** Admin
3. **Go to:** Payment Verification
4. **Check Outstanding Bills section:**
5. **Expected:**
   - All 3 bills visible in table
   - Count shows "3 bill(s)"
   - Can message each tenant individually

#### Test 5.5: No Outstanding Bills Message
1. **Setup:** All bills are either unpaid (0% paid) or fully paid (100%)
2. **Login as:** Admin
3. **Go to:** Payment Verification
4. **Expected:**
   - Outstanding Bills section doesn't appear (hidden)
   - Only shows when partial payments exist

---

### Test Group 6: Tenant Dashboard Display

#### Test 6.1: Remaining Balance Display
1. **Setup:** Create unpaid bill of ₱1000
2. **Login as:** Tenant
3. **Go to:** Tenant Dashboard
4. **Check:** Remaining Balance metric card
5. **Expected:**
   - Shows ₱1000.00
   - Red border around card
   - Text color is red/danger
   - Shows "Amount due" label

#### Test 6.2: Remaining Balance When Fully Paid
1. **Setup:** All bills paid (no unpaid/partial bills)
2. **Login as:** Tenant
3. **Go to:** Tenant Dashboard
4. **Check:** Remaining Balance card
5. **Expected:**
   - Shows ₱0.00
   - Green border around card
   - Text color is green/success
   - Shows "All paid up!" label

#### Test 6.3: Remaining Balance with Mixed Bills
1. **Setup:**
   - Bill 1: ₱1000 unpaid
   - Bill 2: ₱500 partial (₱200 paid, ₱300 remaining)
   - Bill 3: ₱800 fully paid
2. **Expected:** Remaining Balance shows ₱1300 (1000 + 300)

#### Test 6.4: Messages Navigation
1. **Login as:** Tenant
2. **Check Sidebar:** Should see "Messages" link with envelope icon
3. **Action:** Click Messages link
4. **Expected:** Redirects to tenant_messages.php with message list

---

### Test Group 7: Admin Panel Integration

#### Test 7.1: Admin Can View All Outstanding Bills
1. **Setup:** Multiple tenants with partial payments
2. **Login as:** Admin
3. **Go to:** Payment Verification page
4. **Expected:** All outstanding bills visible across all tenants

#### Test 7.2: Admin Can Send Quick Messages
1. **From:** Outstanding Bills table, click Message button
2. **Expected:**
   - Quick navigation to admin_send_message.php
   - Tenant/bill pre-selected
   - Can quickly compose and send reminder

#### Test 7.3: Admin Messaging Dashboard
1. **Login as:** Admin
2. **Check:** Admin dashboard or sidebar
3. **Expected:** Can see link to send messages to tenants

---

## Performance Testing

### Test P1: Cost Calculation Speed
- **Action:** Complete 100 maintenance requests simultaneously
- **Expected:** Bills updated within reasonable time (< 5 seconds)
- **Check:** Database logs for errors

### Test P2: Query Performance
- **Action:** Load admin_payment_verification.php with 1000 unpaid bills
- **Expected:** Page loads within 3 seconds
- **Check:** Outstanding bills table displays correctly

### Test P3: Message List Performance
- **Action:** View tenant_messages.php with 500 messages
- **Expected:** Page loads within 2 seconds
- **Check:** Pagination or lazy loading works if implemented

---

## Bug Testing Scenarios

### Test B1: Edge Case - Maintenance on Year Boundary
1. **Setup:** Complete maintenance on December 31st
2. **Expected:** Cost added to January (next year) bill, not December

### Test B2: Edge Case - No Bill Exists Yet
1. **Setup:** New tenant, no bills created
2. **Action:** Complete maintenance request
3. **Expected:** New bill created automatically with cost

### Test B3: Edge Case - Same Bill Completed Multiple Times
1. **Setup:** Complete 2 maintenance requests in same day
2. **Expected:** Both costs added to same next month bill

### Test B4: Message to Non-Existent Tenant
1. **Action:** Try to send message to deleted tenant
2. **Expected:** Error handling prevents orphaned messages

### Test B5: Partial Payment Calculation Accuracy
1. **Setup:** Bill of ₱1234.56
2. **Action:** Pay ₱555.55
3. **Expected:** Remaining = ₱679.01 (exact calculation)

---

## SQL Validation Queries

Run these queries before and after testing:

```sql
-- Check all maintenance costs recorded
SELECT COUNT(*), AVG(cost) as avg_cost FROM maintenance_requests WHERE cost IS NOT NULL;

-- Check all partial payment bills
SELECT COUNT(*) FROM bills WHERE status = 'partial';

-- Check all messages sent
SELECT COUNT(*) FROM messages;

-- Check total cost added via auto-billing
SELECT SUM(amount_due) FROM bills WHERE source = 'maintenance' OR related_type = 'maintenance';

-- Verify bill status distribution
SELECT status, COUNT(*) FROM bills GROUP BY status;

-- Check message read status
SELECT is_read, COUNT(*) FROM messages GROUP BY is_read;
```

---

## Rollback Procedure (If Issues Found)

### Rollback Steps:
1. **Restore from backup:** Database and PHP files
2. **Remove new files:**
   - Delete `tenant_messages.php`
   - Delete `admin_send_message.php`
3. **Revert modified files:**
   - Restore `tenant_dashboard.php` from backup
   - Restore `admin_payment_verification.php` from backup
4. **Verify system:** Test basic functionality works

### Backup Before Testing:
```sql
-- Backup database
mysqldump -u root -p BAMINT > BAMINT_backup_$(date +%Y%m%d).sql

-- Or in phpMyAdmin: Export database
```

---

## Sign-Off Checklist

- [ ] All PHP files pass syntax check (`php -l`)
- [ ] Database has `messages` table
- [ ] Test Group 1 (Maintenance Pricing) - All pass
- [ ] Test Group 2 (Auto-Billing) - All pass
- [ ] Test Group 3 (Partial Payments) - All pass
- [ ] Test Group 4 (Messaging) - All pass
- [ ] Test Group 5 (Outstanding Bills) - All pass
- [ ] Test Group 6 (Dashboard) - All pass
- [ ] Test Group 7 (Admin Integration) - All pass
- [ ] No critical errors in logs
- [ ] Performance tests acceptable
- [ ] Rollback procedure tested and working

---

## Going Live Checklist

Before deploying to production:

- [ ] Database backup taken
- [ ] All files uploaded to server
- [ ] File permissions set correctly (755 for PHP)
- [ ] Database connection verified
- [ ] Session handling verified
- [ ] All navigation links working
- [ ] Admin users tested
- [ ] Tenant users tested
- [ ] Email notifications tested (if configured)
- [ ] Redirect links verified
- [ ] Error logging configured
- [ ] No debug output visible to users

---

**Test Completed By:** _________________
**Date:** _________________
**Result:** ☐ PASS ☐ FAIL

**Notes:** _________________________________________________________________________

