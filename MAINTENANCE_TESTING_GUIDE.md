# Maintenance Queue System - Testing Guide

## System Overview
The Maintenance Queue system is now fully implemented with:
- ✅ Admin maintenance queue interface
- ✅ Status workflow (Pending → Ongoing → Resolved)
- ✅ Request assignment to staff
- ✅ Estimated completion date setting
- ✅ Admin notes functionality
- ✅ Real-time tenant status updates
- ✅ Consistent status display across all pages

## Pre-Testing Checklist

### 1. Database Verification
Before testing, verify the maintenance_requests table has these fields:
```sql
SELECT * FROM maintenance_requests LIMIT 1;
```

Required columns:
- id
- tenant_id
- room_id
- category
- description
- priority (enum: high, normal, low)
- status (enum: pending, in_progress, completed, cancelled)
- assigned_to (can be NULL)
- submitted_date
- start_date (can be NULL)
- completion_date (can be NULL)
- cost (can be NULL)
- notes
- created_at
- updated_at

### 2. Admin Access Verification
```sql
SELECT id, username, email, role FROM admins LIMIT 3;
```

You need at least 2 admin users (one to be assigned as staff).

### 3. Sample Data Check
```sql
SELECT COUNT(*) as total_requests FROM maintenance_requests;
```

If you need test data, use:
```sql
INSERT INTO maintenance_requests 
(tenant_id, room_id, category, description, priority, status, submitted_date)
VALUES (1, 1, 'Plumbing', 'Leaky faucet in bathroom', 'high', 'pending', NOW());
```

## Testing Steps

### Test 1: Access Admin Maintenance Queue

**Step 1.1**: Login as Admin
- Go to: `http://localhost/BAMINT/index.php`
- Login with admin credentials
- Expected: Dashboard loads

**Step 1.2**: Navigate to Maintenance Queue
- Click: Sidebar → "Maintenance Queue"
- Expected: Queue page loads with card-based layout
- Expected URL: `http://localhost/BAMINT/admin_maintenance_queue.php`

**Step 1.3**: Verify Queue Display
- Look for: Summary statistics (Total, Pending, In Progress, etc.)
- Look for: Card-based request display
- Look for: Color-coded priority borders
- Look for: Action buttons (Assign, Start Work, Complete, Reject)

**Verification**:
```
✓ Page loads without errors
✓ Summary statistics display
✓ Pending requests visible
✓ All buttons present
```

---

### Test 2: Assign Request to Staff

**Step 2.1**: Open Assign Modal
- Find a pending request card
- Click: "Assign" button
- Expected: Modal dialog opens with:
  - Staff dropdown
  - Estimated Completion Date field
  - Notes textarea
  - Assign button

**Step 2.2**: Assign Request
- Select: A staff member from dropdown
- Enter: Future date/time for estimated completion
- Enter: "Initial assessment scheduled" in notes
- Click: "Assign" button
- Expected: Page redirects to queue
- Expected: Success message appears

**Step 2.3**: Verify Assignment
- Check: Request shows assigned staff name
- Check: Notes display in request card
- Check: Status still shows "⏳ Pending"
- Database verification:
```sql
SELECT id, assigned_to, notes, completion_date 
FROM maintenance_requests 
WHERE id = {request_id};
```
Expected: `assigned_to` is not NULL, `notes` contains assignment text

**Verification**:
```
✓ Modal opens correctly
✓ Form submits without error
✓ Page redirects after submit
✓ Request shows assigned staff
✓ Notes appear in UI
✓ Database updated correctly
```

---

### Test 3: Start Work on Request

**Step 3.1**: Open Queue and Find Assigned Request
- Go to: Admin Maintenance Queue
- Find: A request with assigned staff
- Expected: Shows "⏳ Pending" status

**Step 3.2**: Start Work
- Click: "Start Work" button on request card
- Expected: No modal opens (direct action)
- Expected: Page redirects immediately
- Expected: Success message appears

**Step 3.3**: Verify Status Changed
- Check: Request now shows "▶ Ongoing" status
- Check: Status badge color changed to blue
- Check: Request still in queue (not removed)
- Database verification:
```sql
SELECT id, status, start_date 
FROM maintenance_requests 
WHERE id = {request_id};
```
Expected: `status` = 'in_progress', `start_date` = current timestamp

**Step 3.4**: Verify Tenant Sees Change
- Logout from admin
- Login as tenant who submitted request
- Go to: Tenant Dashboard or Maintenance page
- Look for: Request showing "▶ Ongoing" status
- Expected: Status matches admin view

**Verification**:
```
✓ Status changes to in_progress
✓ Start date is set
✓ Status badge updates
✓ Tenant sees updated status
✓ In-progress requests stay in queue
```

---

### Test 4: Complete Request

**Step 4.1**: Complete Request
- Login as Admin
- Go to: Admin Maintenance Queue
- Find: In-progress request
- Click: "Complete" button
- Expected: Modal opens with "Completion Notes" textarea

**Step 4.2**: Submit Completion
- Enter: "Work completed. Faucet replaced." in notes
- Click: "Complete" button
- Expected: Page redirects
- Expected: Success message appears

**Step 4.3**: Verify Completion
- Check: Request now shows "✓ Resolved" status
- Check: Status badge color changed to green
- Check: Request removed from main queue (might go to history)
- Database verification:
```sql
SELECT id, status, completion_date, notes 
FROM maintenance_requests 
WHERE id = {request_id};
```
Expected: `status` = 'completed', `completion_date` = current timestamp, notes include completion message

**Step 4.4**: Verify Tenant Notification
- Logout from admin
- Login as tenant
- Check: Dashboard shows request as "✓ Resolved"
- Check: Completion notes visible to tenant
- Check: Different badge color (green)

**Verification**:
```
✓ Modal opens for completion notes
✓ Status changes to completed
✓ Completion date is set
✓ Notes are appended/stored
✓ Status badge turns green
✓ Tenant sees resolved status
✓ Request moves from pending queue
```

---

### Test 5: Reject Request

**Step 5.1**: Reject Request
- Go to: Admin Maintenance Queue
- Find: Pending request (assign one if needed)
- Click: "Reject" button
- Expected: Modal opens with "Rejection Reason" textarea

**Step 5.2**: Submit Rejection
- Enter: "Request outside scope of maintenance" in reason
- Click: "Reject" button
- Expected: Page redirects
- Expected: Success message appears

**Step 5.3**: Verify Rejection
- Check: Request now shows "✕ Cancelled" status
- Check: Status badge color changed to gray
- Check: Request removed from active queue
- Database verification:
```sql
SELECT id, status, notes 
FROM maintenance_requests 
WHERE id = {request_id};
```
Expected: `status` = 'cancelled', notes include rejection reason

**Step 5.4**: Verify Tenant Sees Cancellation
- Logout from admin
- Login as tenant
- Check: Dashboard shows request as "✕ Cancelled"
- Check: Gray badge indicating cancelled status
- Check: Can see rejection reason if visible in notes

**Verification**:
```
✓ Rejection modal opens
✓ Reason is captured
✓ Status changes to cancelled
✓ Notes store rejection reason
✓ Status badge turns gray
✓ Tenant sees cancelled status
✓ Request removed from active queue
```

---

### Test 6: Complete Workflow (End-to-End)

**Scenario**: New maintenance request from start to completion

**Step 6.1**: Tenant Submits Request
- Login as Tenant
- Go to: Maintenance → Submit New Request
- Fill: Category, Priority, Description
- Submit: Request
- Expected: "Request submitted successfully"
- Note: Request ID for later reference

**Step 6.2**: Admin Receives Request
- Login as Admin
- Go to: Maintenance Queue
- Find: New request with submitted tenant
- Verify: Shows "⏳ Pending" with highest priority sorted top

**Step 6.3**: Admin Assigns Request
- Click: "Assign" on new request
- Assign to: Any staff member
- Set: Estimated completion date (e.g., tomorrow)
- Add: "Will schedule inspection" in notes
- Submit: Assignment
- Verify: Request shows assigned staff

**Step 6.4**: Admin Starts Work
- Click: "Start Work" on assigned request
- Verify: Status changes to "▶ Ongoing"
- Verify: Start date is set

**Step 6.5**: Tenant Sees Progress
- Logout from admin
- Login as tenant
- Go to: Dashboard or Maintenance
- Verify: Request shows "▶ Ongoing"
- Verify: Assigned staff name visible
- Verify: Estimated completion date visible

**Step 6.6**: Admin Completes Request
- Login as Admin
- Go to: Maintenance Queue
- Find: Ongoing request (should find it)
- Click: "Complete"
- Add: "Work completed successfully" in notes
- Submit: Completion
- Verify: Status changes to "✓ Resolved"

**Step 6.7**: Tenant Sees Completion
- Logout from admin
- Login as tenant
- Check: Dashboard shows "✓ Resolved"
- Check: Completion notes visible
- Check: Badge is green

**Verification**:
```
✓ Complete workflow from pending to resolved
✓ Status updates at each stage
✓ Tenant sees all updates in real-time
✓ All data properly stored in database
✓ Status emoji indicators work correctly
```

---

## Status Display Testing

### Tenant Dashboard Status Display

**Location**: `http://localhost/BAMINT/tenant_dashboard.php`

**Test Cases**:
1. Request with status 'pending'
   - Expected label: "⏳ Pending"
   - Expected badge class: warning (yellow)

2. Request with status 'in_progress'
   - Expected label: "▶ Ongoing"
   - Expected badge class: primary (blue)

3. Request with status 'completed'
   - Expected label: "✓ Resolved"
   - Expected badge class: success (green)

4. Request with status 'cancelled'
   - Expected label: "✕ Cancelled"
   - Expected badge class: secondary (gray)

**Verification**:
```
✓ All status labels display correctly
✓ Badge colors match status
✓ Emoji indicators show properly
```

### Tenant Maintenance Page Status Display

**Location**: `http://localhost/BAMINT/tenant_maintenance.php`

Same status display expectations as dashboard.

**Verification**:
```
✓ All request cards show correct status
✓ Status badges consistent with dashboard
✓ Emoji labels present
```

---

## Database Verification Tests

### Query 1: Verify Request Assignment
```sql
SELECT mr.id, mr.status, a.username as assigned_to
FROM maintenance_requests mr
LEFT JOIN admins a ON mr.assigned_to = a.id
WHERE mr.id = {request_id};
```

### Query 2: Verify Status Tracking
```sql
SELECT id, status, submitted_date, start_date, completion_date
FROM maintenance_requests
WHERE id = {request_id};
```

### Query 3: Verify Notes Storage
```sql
SELECT id, notes FROM maintenance_requests WHERE id = {request_id};
```

### Query 4: Get Queue Summary
```sql
SELECT 
  COUNT(*) as total,
  SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
  SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
  SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
  SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_priority,
  SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned
FROM maintenance_requests;
```

---

## Error Handling Tests

### Test 1: Invalid Request ID
- Edit URL manually: `admin_maintenance_queue.php?id=99999`
- Expected: Error or no action
- Verify: No database corruption

### Test 2: Missing Required Fields
- Modify form to remove required attribute
- Try submitting with empty staff selection
- Expected: Error message or handling

### Test 3: Database Connection Failure
- Stop MySQL service
- Try accessing queue page
- Expected: Graceful error message (not blank page)
- Restart MySQL after test

### Test 4: Session Timeout
- Login, wait 30+ minutes
- Try submitting form
- Expected: Redirect to login
- Verify: No orphaned database records

---

## Performance Testing

### Load Test 1: Large Queue
- Create 100+ maintenance requests
- Load admin queue page
- Expected: Page loads in < 3 seconds
- Verify: All records load
- Check: No timeout errors

### Load Test 2: Pagination
- If queue has pagination, test page navigation
- Expected: Smooth navigation between pages
- Verify: All records accessible

---

## Browser Compatibility Testing

Test on:
- [ ] Chrome/Chromium (Latest)
- [ ] Firefox (Latest)
- [ ] Safari (if on Mac)
- [ ] Edge (Windows)

Expected behavior:
- [ ] All buttons functional
- [ ] Modals display properly
- [ ] Status badges render correctly
- [ ] Forms submit without issues
- [ ] Date/time inputs work
- [ ] No console errors

---

## Mobile Responsiveness Testing

### Test on Mobile Viewport (375px width)
- Go to: Admin Maintenance Queue
- Expected: Layout adapts to mobile
- Check: Buttons still clickable
- Check: Cards remain readable
- Check: Modals display properly
- Check: Sidebar collapses appropriately

---

## Accessibility Testing

### Keyboard Navigation
- Tab through page without mouse
- Expected: All buttons accessible
- Check: Modals keyboard-navigable
- Check: Form inputs focusable

### Screen Reader Testing
- Use screen reader
- Expected: All elements properly labeled
- Check: Status labels announced
- Check: Button purposes clear

---

## Final Checklist

### Core Functionality
- [ ] Admin can view maintenance queue
- [ ] Admin can assign requests
- [ ] Admin can start work
- [ ] Admin can complete requests
- [ ] Admin can reject requests
- [ ] Tenant can see status updates
- [ ] All modals function correctly
- [ ] Form submissions work

### Data Integrity
- [ ] Database records created correctly
- [ ] All fields populated accurately
- [ ] Status values correct in database
- [ ] Timestamps set properly
- [ ] Notes stored with formatting

### User Experience
- [ ] Success messages display
- [ ] Error messages are clear
- [ ] Page redirects work
- [ ] No broken links
- [ ] Status badges visible
- [ ] Emoji labels render

### System Stability
- [ ] No PHP errors in logs
- [ ] No database errors
- [ ] No JavaScript console errors
- [ ] Page loads consistently
- [ ] Forms submit reliably

---

## Issue Reporting Template

When reporting an issue, include:

```
Issue: [Brief title]
Steps to Reproduce:
1. [Step 1]
2. [Step 2]
3. [Step 3]

Expected Result:
[What should happen]

Actual Result:
[What actually happened]

Browser: [Chrome, Firefox, etc.]
OS: [Windows, Mac, Linux]
Environment: [Local, Staging, Production]

Database Query Results:
[Relevant SELECT queries and output]

Error Messages:
[Any error messages from console or logs]

Screenshots:
[Attach if available]
```

---

## Test Sign-Off

When all tests pass:

```
Date: ________________
Tester: ________________
Environment: ________________
Build Version: 1.0
Status: ☐ PASS ☐ FAIL
```

If PASS: System ready for production
If FAIL: Document issues and retry

---

**Ready to Test!** 🚀

Start with Test 1 and progress through all test cases. Use this guide to verify the maintenance queue system is working correctly before deployment.
