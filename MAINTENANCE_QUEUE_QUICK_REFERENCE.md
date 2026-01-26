# Maintenance Queue System - Quick Reference

## Overview
The maintenance request management system allows admins to efficiently manage maintenance requests from tenants through a queue-based interface with status tracking.

## Workflow Status States

| Status | Description | Transition From | Transition To |
|--------|-------------|-----------------|---------------|
| **⏳ Pending** | Request submitted, awaiting assignment | Initial state | In Progress / Cancelled |
| **▶ Ongoing** | Work in progress by assigned staff | Pending | Completed |
| **✓ Resolved** | Work completed | Ongoing | N/A |
| **✕ Cancelled** | Request rejected or cancelled | Pending | N/A |

## Admin Features

### 1. Main Queue Page
- **Location**: `/admin_maintenance_queue.php`
- **Access**: Sidebar → Maintenance Queue
- **Display**: Card-based queue with pending and in-progress requests

### 2. Queue Summary Statistics
Displays real-time counts:
- Total Requests
- Pending Requests  
- In Progress Requests
- Completed Requests
- Urgent (High Priority) Requests
- Unassigned Requests

### 3. Request Card Display
Each request shows:
- Request ID and submission date
- Tenant name and room number
- Category and description
- Priority level (color-coded: High=Red, Normal=Yellow, Low=Blue)
- Current status with emoji indicator
- Assigned staff (if any)
- Action buttons

### 4. Available Actions

#### **Assign Request**
- Opens modal dialog
- Select maintenance staff from dropdown
- Set estimated completion date
- Add notes (optional)
- Updates `assigned_to`, `completion_date`, and `notes` fields
- Status remains `pending`

#### **Start Work**
- Marks request as `in_progress`
- Sets `start_date` to current timestamp
- Only available for pending requests
- Shows when work begins

#### **Complete Request**
- Marks request as `completed`
- Sets `completion_date` to current timestamp
- Allows adding completion notes
- Appends notes to existing notes field
- Final state before archive

#### **Reject Request**
- Marks request as `cancelled`
- Stores rejection reason in notes
- Used when request cannot be completed
- Notifies tenant of cancellation

### 5. Priority Color Coding
- **High (Red)**: `border-left: 4px solid #dc3545`
- **Normal (Yellow)**: `border-left: 4px solid #ffc107`
- **Low (Blue)**: `border-left: 4px solid #0d6efd`

## Tenant View Updates

### Tenant Dashboard (`tenant_dashboard.php`)
- Shows recent maintenance requests
- Displays current status with emoji labels:
  - ✓ Resolved (green badge)
  - ▶ Ongoing (blue badge)
  - ⏳ Pending (yellow badge)
  - ✕ Cancelled (gray badge)

### Tenant Maintenance Page (`tenant_maintenance.php`)
- Shows all submitted requests
- Displays status with emoji indicators
- Shows assigned staff member (if assigned)
- Shows estimated and actual completion dates
- Shows admin notes

## Database Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | INT PK | Request identifier |
| `tenant_id` | INT FK | Reference to tenant |
| `room_id` | INT FK | Reference to room |
| `category` | VARCHAR | Type of maintenance needed |
| `description` | TEXT | Detailed description |
| `priority` | ENUM | high, normal, low |
| `status` | ENUM | pending, in_progress, completed, cancelled |
| `assigned_to` | INT FK | Admin/staff assigned (nullable) |
| `submitted_date` | TIMESTAMP | When request was submitted |
| `start_date` | DATETIME | When work started |
| `completion_date` | DATETIME | Estimated or actual completion |
| `cost` | DECIMAL | Cost of maintenance (optional) |
| `notes` | TEXT | Admin and completion notes |
| `created_at` | TIMESTAMP | Record creation time |
| `updated_at` | TIMESTAMP | Record last update |

## Navigation

### Admin Sidebar
- **Maintenance Queue**: Main queue interface (`admin_maintenance_queue.php`)
- **All Maintenance Requests**: Full request list (`maintenance_requests.php`)

### Tenant Access
- **Dashboard**: View recent requests with status
- **Maintenance**: Submit new requests and view all requests

## Modal Dialogs

### Assign Modal
```
Title: Assign Maintenance Request
Fields:
  - Select Staff (dropdown)
  - Estimated Completion Date (datetime)
  - Notes (textarea)
Actions:
  - Assign (Submit)
  - Cancel
```

### Complete Modal
```
Title: Complete Maintenance Request
Fields:
  - Completion Notes (textarea)
Actions:
  - Complete (Submit)
  - Cancel
```

### Reject Modal
```
Title: Reject Maintenance Request
Fields:
  - Rejection Reason (textarea)
Actions:
  - Reject (Submit)
  - Cancel
```

## Key Implementation Details

1. **Status Display Format**
   - Database uses underscore: `in_progress`, `pending`, etc.
   - Tenant views convert to emoji labels with descriptions

2. **Emoji Status Indicators**
   - ⏳ Pending - Yellow badge
   - ▶ Ongoing - Blue badge
   - ✓ Resolved - Green badge
   - ✕ Cancelled - Gray badge

3. **Form Handling**
   - All actions use POST requests with `action` parameter
   - Hidden `request_id` field for identification
   - Auto-redirect after successful action

4. **Authentication**
   - Requires admin role for queue page
   - Tenants can only view their own requests
   - Admin can see all requests

## Testing Checklist

- [ ] Admin can view maintenance queue
- [ ] Admin can assign request to staff
- [ ] Admin can set estimated completion date
- [ ] Admin can add notes
- [ ] Admin can mark request as in progress
- [ ] Admin can mark request as completed
- [ ] Admin can reject requests
- [ ] Tenant sees updated status on dashboard
- [ ] Tenant sees updated status on maintenance page
- [ ] Status changes reflect in real-time
- [ ] Email notifications sent (if configured)
- [ ] All emoji status labels display correctly

## Common Issues & Solutions

### Issue: "Status not updating"
- **Solution**: Check that database connection is active and user has UPDATE permissions

### Issue: "Modals not appearing"
- **Solution**: Ensure Bootstrap 5.3.2 JS is loaded
- Check browser console for JS errors

### Issue: "Staff dropdown empty"
- **Solution**: Verify `admins` table has records
- Check user has admin role

### Issue: "Tenant not seeing status update"
- **Solution**: Ensure `status` value in database matches enum values (pending, in_progress, completed, cancelled)
- Check database query in tenant_maintenance.php

## Files Involved

### Main System Files
- `admin_maintenance_queue.php` - Admin queue interface (NEW)
- `maintenance_requests.php` - Full maintenance request list (existing)
- `maintenance_actions.php` - Action handlers (existing)
- `tenant_maintenance.php` - Tenant request view (updated)
- `tenant_dashboard.php` - Tenant dashboard (updated)

### Template Files
- `templates/sidebar.php` - Navigation menu (updated)
- `templates/header.php` - Page header

### Database
- `db/database.php` - Database connection
- `db/init.sql` - Schema (existing with maintenance_requests table)

## API Endpoints

### Queue Status Update
```
POST /admin_maintenance_queue.php
Parameters:
  - action: assign|start|complete|reject
  - request_id: {id}
  - [assigned_to]: {admin_id} (for assign)
  - [estimated_completion]: {datetime} (for assign)
  - [notes]: {text} (for assign/update)
  - [completion_notes]: {text} (for complete)
  - [rejection_reason]: {text} (for reject)
```

## User Roles

| Role | Permissions |
|------|-------------|
| **Admin** | View queue, assign requests, update status, add notes |
| **Tenant** | Submit requests, view own requests and status |
| **Staff** | View assigned requests (if implemented) |

## Future Enhancements

- [ ] Email notifications to tenants when status changes
- [ ] Staff dashboard for assigned requests
- [ ] Photo upload for before/after completion
- [ ] Maintenance cost tracking and invoicing
- [ ] Recurring maintenance schedules
- [ ] SLA (Service Level Agreement) tracking
- [ ] Tenant ratings/feedback system
- [ ] Mobile app for staff

## Support & Troubleshooting

For issues or questions:
1. Check database for correct data
2. Verify user authentication and role
3. Check browser console for JavaScript errors
4. Review server logs for PHP errors
5. Verify all required fields are populated

---

**Last Updated**: 2024
**System Version**: 1.0
**Status**: Production Ready
