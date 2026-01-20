# BAMINT System Testing Guide

## Pre-Deployment Testing Checklist

### 1. Database Setup
- [ ] Visit `http://localhost/BAMINT/db/migrate.php`
- [ ] Verify success message appears
- [ ] Check for any table creation errors
- [ ] Confirm all tables are created (6 total)

### 2. Authentication System
**Testing**: Registration and Login

#### Test 2.1: Register New Admin
- [ ] Visit `http://localhost/BAMINT/register.php`
- [ ] Enter username: "admin1"
- [ ] Enter password: "password123"
- [ ] Confirm password: "password123"
- [ ] Click "Register"
- [ ] Verify success and automatic login
- [ ] Redirects to dashboard
- [ ] Session is active

#### Test 2.2: Try Invalid Registration
- [ ] Attempt duplicate username
- [ ] Verify error message
- [ ] Try password mismatch
- [ ] Verify error message
- [ ] Try short password (< 6 chars)
- [ ] Verify error message

#### Test 2.3: Login Test
- [ ] Logout if needed (click logout button)
- [ ] Visit `http://localhost/BAMINT/index.php`
- [ ] Enter username and password
- [ ] Click "Login"
- [ ] Verify dashboard loads
- [ ] Session is maintained

### 3. Tenant Management
**Testing**: Add, View, Edit, Delete, Deactivate/Activate

#### Test 3.1: Add Tenant
- [ ] Click "Tenants" in sidebar
- [ ] Click "Add Tenant" button
- [ ] Fill form:
  - Name: "Juan Dela Cruz"
  - Email: "juan@example.com"
  - Phone: "09123456789"
  - ID Number: "123456789"
- [ ] Click "Add Tenant"
- [ ] Verify success message
- [ ] Tenant appears in list

#### Test 3.2: Edit Tenant
- [ ] Click edit button (pencil icon) on tenant
- [ ] Modify name or email
- [ ] Click "Save Changes"
- [ ] Verify changes in list

#### Test 3.3: Deactivate Tenant
- [ ] Click deactivate button on tenant
- [ ] Verify status changes to "Inactive"
- [ ] Verify badge color changes

#### Test 3.4: Activate Tenant
- [ ] Click activate button on inactive tenant
- [ ] Verify status changes to "Active"

#### Test 3.5: Delete Tenant
- [ ] Click delete button (trash icon)
- [ ] Confirm deletion
- [ ] Verify tenant removed from list

#### Test 3.6: Search & Filter
- [ ] Search by name
- [ ] Filter by status (Active/Inactive)
- [ ] Search by email
- [ ] Verify results update in real-time

### 4. Room Management
**Testing**: Add, View, Edit, Delete rooms

#### Test 4.1: Add Room
- [ ] Click "Rooms" in sidebar
- [ ] Click "Add Room" button
- [ ] Fill form:
  - Room Number: "101"
  - Room Type: "Standard"
  - Description: "Single bed, small room"
  - Rate: "5000"
- [ ] Click "Add Room"
- [ ] Verify success message
- [ ] Room appears in list

#### Test 4.2: Assign Tenant to Room
- [ ] Edit room
- [ ] This will show occupied status once tenant is assigned
- [ ] Go to tenants, edit tenant to assign room

#### Test 4.3: Edit Room
- [ ] Click edit button on room
- [ ] Modify rate or description
- [ ] Click "Save Changes"
- [ ] Verify changes displayed

#### Test 4.4: Delete Room
- [ ] Click delete button
- [ ] Confirm deletion
- [ ] Verify room removed

#### Test 4.5: Search & Filter
- [ ] Filter by room type
- [ ] Filter by status
- [ ] Search by room number

### 5. Billing Module
**Testing**: Generate, Edit, View Bills

#### Test 5.1: Generate Monthly Bills
- [ ] Click "Bills & Billing" in sidebar
- [ ] Click "Generate Monthly Bills" button
- [ ] Select month
- [ ] Click "Generate"
- [ ] Verify bills created for all active tenants
- [ ] Check amount_due = room rate

#### Test 5.2: View Bills
- [ ] Verify bills display in list
- [ ] Check status (Unpaid initially)
- [ ] Verify tenant name and room

#### Test 5.3: Edit Payment
- [ ] Click edit button on bill
- [ ] Enter payment amount
- [ ] Select payment method
- [ ] Enter payment date
- [ ] Click "Save"
- [ ] Verify status updates based on payment

#### Test 5.4: Generate Invoice
- [ ] Click invoice button
- [ ] Verify professional invoice displays
- [ ] Test print functionality (Ctrl+P)

#### Test 5.5: Search & Filter
- [ ] Filter by month
- [ ] Filter by tenant
- [ ] Filter by status
- [ ] Search functionality

### 6. Payment Tracking
**Testing**: View Payment History

#### Test 6.1: View Payment History
- [ ] Click "Payment History" in sidebar
- [ ] Verify all payment transactions display
- [ ] Check payment method badges
- [ ] Verify dates and amounts

#### Test 6.2: Statistics
- [ ] View summary cards:
  - Total payments
  - Total amount
  - Average payment
- [ ] Verify calculations are correct

#### Test 6.3: Search & Filter
- [ ] Filter by tenant
- [ ] Filter by payment method
- [ ] Filter by date range

### 7. Overdue Management
**Testing**: Overdue Bills & Reminders

#### Test 7.1: Create Overdue Situation
- [ ] Generate bills for current month
- [ ] Create past-due bill manually
- [ ] Set due date to yesterday or earlier

#### Test 7.2: View Overdue Bills
- [ ] Click "Overdue Reminders" in sidebar
- [ ] Verify overdue bills display with days overdue
- [ ] Verify calculation is correct

#### Test 7.3: Upcoming Bills
- [ ] Verify bills due within 7 days display
- [ ] Check due dates

#### Test 7.4: Statistics
- [ ] View summary cards
- [ ] Verify total overdue amount
- [ ] Verify account count

### 8. Maintenance Requests
**Testing**: Submit, Manage, View Requests

#### Test 8.1: Submit Maintenance Request
- [ ] Click "Maintenance Requests" in sidebar
- [ ] Click "Submit Request" button
- [ ] Select tenant (room auto-populates)
- [ ] Select category: "Plumbing"
- [ ] Select priority: "High"
- [ ] Enter description: "Bathroom sink leak"
- [ ] Click "Submit Request"
- [ ] Verify success message
- [ ] Request appears in list with status "Pending"

#### Test 8.2: View Summary Cards
- [ ] Verify Total Requests count increases
- [ ] Verify Pending count shows 1
- [ ] Verify Urgent count increases (high priority)

#### Test 8.3: Edit Request
- [ ] Click edit button (pencil icon)
- [ ] Assign to staff member
- [ ] Change status to "In Progress"
- [ ] Set start date
- [ ] Click "Save Changes"
- [ ] Verify changes in request list

#### Test 8.4: Update to Completed
- [ ] Click edit button again
- [ ] Change status to "Completed"
- [ ] Set completion date
- [ ] Enter cost: "500"
- [ ] Add note: "Replaced washers"
- [ ] Click "Save Changes"
- [ ] Verify status badge changes

#### Test 8.5: View Request Details
- [ ] Click view button (eye icon)
- [ ] Verify all information displays correctly
- [ ] Check tenant, room, dates, cost

#### Test 8.6: Delete Request
- [ ] Click delete button on pending request
- [ ] Confirm deletion
- [ ] Verify removed from list

#### Test 8.7: Search & Filter
- [ ] Filter by status
- [ ] Filter by priority
- [ ] Filter by category
- [ ] Filter by assigned staff
- [ ] Text search

### 9. Maintenance History
**Testing**: View Completed Maintenance Records

#### Test 9.1: View History
- [ ] Click "Maintenance History" in sidebar
- [ ] Verify completed requests display
- [ ] Verify only completed/cancelled show

#### Test 9.2: Statistics
- [ ] Verify summary cards show:
  - Total Completed: should match
  - Avg Resolution: should calculate hours
  - Total Cost: should sum all costs
  - Avg Cost: should calculate average

#### Test 9.3: Search & Filter History
- [ ] Filter by month
- [ ] Filter by category
- [ ] Filter by tenant
- [ ] Text search

#### Test 9.4: View Historical Details
- [ ] Click view button on completed request
- [ ] Verify all historical data displays

### 10. Navigation & UI
**Testing**: Navigation and User Interface

#### Test 10.1: Sidebar Navigation
- [ ] Verify all menu items appear:
  - Dashboard
  - Rooms
  - Tenants
  - Bills & Billing
  - Payment History
  - Overdue Reminders
  - Maintenance Requests
  - Maintenance History
- [ ] Click each menu item
- [ ] Verify correct page loads

#### Test 10.2: Header Navigation
- [ ] Verify site name/title displays
- [ ] Verify logout button works
- [ ] Click logout
- [ ] Verify redirects to login

#### Test 10.3: Responsive Design
- [ ] Test on desktop (1920x1080)
- [ ] Test on tablet (768x1024)
- [ ] Test on mobile (375x667)
- [ ] Verify layout adapts properly
- [ ] Verify tables are readable

### 11. Data Validation
**Testing**: Form Validation

#### Test 11.1: Required Fields
- [ ] Try submitting forms with empty fields
- [ ] Verify error messages appear
- [ ] Verify form doesn't submit

#### Test 11.2: Data Format
- [ ] Try invalid email
- [ ] Try invalid phone (non-numeric)
- [ ] Try non-numeric rates/amounts
- [ ] Verify validation messages

#### Test 11.3: Duplicate Prevention
- [ ] Try duplicate tenant email
- [ ] Verify error handling

### 12. Session & Security
**Testing**: Session Management

#### Test 12.1: Session Timeout
- [ ] Login successfully
- [ ] Close browser without logout
- [ ] Open new browser window
- [ ] Try accessing dashboard
- [ ] Verify redirects to login

#### Test 12.2: Direct Access Prevention
- [ ] Logout
- [ ] Try direct URL to dashboard.php
- [ ] Verify redirects to login

#### Test 12.3: CSRF Protection
- [ ] Verify forms work correctly
- [ ] Check session tokens in forms

### 13. Data Persistence
**Testing**: Database Operations

#### Test 13.1: Create Test Data
- [ ] Create 5 tenants
- [ ] Create 10 rooms
- [ ] Generate monthly bills
- [ ] Create maintenance requests

#### Test 13.2: Data Integrity
- [ ] Delete tenant
- [ ] Verify room assignment cleared (or cascade)
- [ ] Verify no orphaned records

#### Test 13.3: Relationships
- [ ] Verify tenant assignments to rooms work
- [ ] Verify bills linked to tenants
- [ ] Verify maintenance requests linked to rooms

---

## Performance Testing

### Load Testing
- [ ] Load page with 100+ records
- [ ] Verify page loads within 3 seconds
- [ ] Test search performance
- [ ] Verify filters work smoothly

### Database Indexes
- [ ] Verify indexes created on:
  - status, priority, tenant_id, room_id
- [ ] Verify query performance with indexes

---

## Error Handling Testing

### Test Error Scenarios
- [ ] Delete item that doesn't exist
- [ ] Edit item with invalid ID
- [ ] Database connection failure
- [ ] Invalid form submissions
- [ ] File upload errors

---

## Reporting Testing

### Invoice Generation
- [ ] Verify invoice format
- [ ] Test print functionality
- [ ] Check PDF export (if available)
- [ ] Verify date formatting

---

## Cross-Browser Testing

### Test in Multiple Browsers
- [ ] Chrome
- [ ] Firefox
- [ ] Edge
- [ ] Safari
- [ ] Mobile browsers

---

## Final Verification Checklist

- [ ] All modules working correctly
- [ ] No PHP errors in logs
- [ ] No database errors
- [ ] All forms validate properly
- [ ] All CRUD operations working
- [ ] Search and filter functional
- [ ] Navigation complete
- [ ] Responsive design verified
- [ ] Session management working
- [ ] Data integrity maintained
- [ ] Reports generate correctly

---

## Sign-Off

**Testing Date**: _____________
**Tested By**: _____________
**Status**: ☐ PASS ☐ FAIL

**Notes**: ________________________________________________________________

---

**End of Testing Guide**
