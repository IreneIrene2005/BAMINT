# Maintenance Request System - Quick Reference Guide

## Overview
The Maintenance Request System is a comprehensive module for tracking, managing, and resolving facility and room maintenance issues. It provides a complete workflow from initial request submission through completion tracking.

## Features

### 1. Submit Maintenance Request
**File**: `maintenance_requests.php` (Modal Form)

Submit a new maintenance request with:
- **Tenant Selection**: Auto-populate tenant and room
- **Category**: Choose from 8 categories
  - Plumbing
  - Electrical
  - HVAC/Cooling
  - Furniture
  - Cleaning
  - Security
  - Internet/WiFi
  - Other
- **Priority**: Low, Normal, or High
- **Description**: Detailed problem description

**Process**: 
1. Click "Submit Request" button
2. Select tenant (room auto-populates)
3. Choose category and priority
4. Describe the issue
5. Click "Submit Request"

### 2. View & Manage Requests
**File**: `maintenance_requests.php`

#### Summary Cards
- **Total Requests**: All submitted requests
- **Pending**: Requests awaiting staff assignment or start
- **In Progress**: Currently being worked on
- **Completed**: Successfully resolved
- **Urgent**: High-priority unresolved requests

#### Request Table Columns
| Column | Purpose |
|--------|---------|
| ID | Request reference number |
| Tenant | Tenant reporting the issue |
| Room | Affected room location |
| Category | Type of maintenance needed |
| Description | Issue details (preview) |
| Priority | Urgency level (High/Normal/Low) |
| Status | Current workflow status |
| Assigned To | Staff member handling it |
| Submitted | Date request was created |
| Actions | View/Edit/Delete buttons |

#### Search & Filter
- **Search**: Tenant name, description, or room number
- **Status**: Filter by Pending, In Progress, Completed, Cancelled
- **Priority**: Filter by High, Normal, Low
- **Category**: Filter by maintenance type
- **Assigned**: Filter by assigned staff or unassigned

### 3. Edit & Update Requests
**File**: `maintenance_actions.php?action=edit&id={id}`

Update request information:
- **Request Details**
  - Category
  - Priority level

- **Status & Assignment**
  - Status: Pending → In Progress → Completed → Cancelled
  - Assign to staff member

- **Timeline & Cost**
  - Start Date: When work begins
  - Completion Date: When work finishes
  - Cost: Labor/parts cost (₱)
  - Notes: Admin notes and comments

**Workflow Status**:
- **Pending**: Request received, awaiting action
- **In Progress**: Staff has begun work
- **Completed**: Work finished successfully
- **Cancelled**: Request cancelled

### 4. View Request Details
**File**: `maintenance_actions.php?action=view&id={id}`

Displays complete request information:
- Request category and description
- Tenant contact information
- Current status and priority
- Assigned staff member
- Complete timeline
- Total cost if applicable
- Admin notes

### 5. Delete Requests
**File**: `maintenance_actions.php?action=delete&id={id}`

Remove maintenance requests with confirmation.

### 6. Maintenance History
**File**: `maintenance_history.php`

#### Statistics
- **Total Completed**: Number of finished requests
- **Cancelled**: Requests that were cancelled
- **Avg Resolution Time**: Average hours to complete (from submission to completion)
- **Total Cost**: Sum of all maintenance costs
- **Avg Cost**: Average cost per maintenance job
- **Total Records**: Count of all historical records

#### Historical Records
View all completed and cancelled maintenance requests with:
- Request ID
- Tenant and room information
- Maintenance category
- Status (Completed/Cancelled)
- Total cost
- Submission date
- Completion date
- Assigned staff
- Quick view button

#### Filter Historical Records
- **Search**: Tenant, description
- **Month**: By completion month
- **Category**: By maintenance type
- **Tenant**: By specific tenant

## API Endpoints

### GET Endpoints
- `maintenance_actions.php?action=view&id={id}` - View request details
- `maintenance_actions.php?action=edit&id={id}` - Edit request form
- `maintenance_actions.php?action=delete&id={id}` - Delete request
- `maintenance_actions.php?action=get_room&id={room_id}` - AJAX room details

### POST Endpoints
- `maintenance_actions.php?action=add` - Submit new request
- `maintenance_actions.php?action=edit` - Update request

## Database Fields

**maintenance_requests Table**:
```sql
- id: Request ID (Auto-increment)
- tenant_id: Tenant reporting issue (FK to tenants)
- room_id: Room with issue (FK to rooms)
- category: Maintenance category (varchar)
- description: Issue description (text)
- priority: Urgency (low, normal, high)
- status: Workflow status (pending, in_progress, completed, cancelled)
- assigned_to: Staff member (FK to admins)
- submitted_date: When reported (timestamp)
- start_date: When work began (datetime, nullable)
- completion_date: When work finished (datetime, nullable)
- cost: Maintenance cost in ₱ (decimal, nullable)
- notes: Staff notes (text, nullable)
- created_at: Record creation (timestamp)
- updated_at: Last modified (timestamp)
```

## Workflow Example

### Example: Plumbing Issue
1. **Tenant Reports Issue**
   - Submits request: "Bathroom sink leak in Room 205"
   - Priority: High
   - Category: Plumbing

2. **Admin Reviews**
   - Sees pending request in maintenance_requests.php
   - Clicks Edit button

3. **Admin Assigns Staff**
   - Changes status from "Pending" to "In Progress"
   - Assigns to "Juan (Plumber)"
   - Sets start date

4. **Work Completion**
   - Admin updates status to "Completed"
   - Adds completion date
   - Records cost: ₱500.00
   - Adds note: "Replaced washers and gasket"

5. **Historical Record**
   - Request appears in maintenance_history.php
   - Shows cost, resolution time, completion date
   - Can be filtered and searched

## Best Practices

1. **Assign Requests Promptly**: Don't leave requests unassigned
2. **Update Status**: Keep status updated as work progresses
3. **Record Costs**: Track all parts and labor costs
4. **Add Notes**: Document work performed and materials used
5. **Set Dates**: Record start and completion times
6. **Use Priority**: Mark urgent issues as "High" priority
7. **Complete Records**: Fill all relevant fields for better tracking

## Troubleshooting

**Request not appearing?**
- Verify tenant status is "Active"
- Check room assignment for tenant

**Filters not working?**
- Ensure search uses exact values
- Try clearing filters and searching again

**Missing assigned staff?**
- Verify staff/admin accounts exist in the system
- Check admin status is active

## Tips

- Use the summary cards to identify workflow bottlenecks
- Filter by "Unassigned" to see requests needing attention
- Check "Urgent" count regularly
- Monitor average resolution time to improve efficiency
- Review completed maintenance for cost trends
- Use categories to identify recurring issues

---
**Maintenance System v1.0**  
Part of BAMINT - Boarding House Management System
