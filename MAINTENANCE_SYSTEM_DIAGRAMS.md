# Maintenance Queue System - Architecture & Flow Diagrams

## System Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    BAMINT SYSTEM OVERVIEW                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ADMIN SIDE                              TENANT SIDE             │
│  ──────────                              ───────────             │
│                                                                   │
│  ┌────────────────────┐                ┌────────────────────┐   │
│  │ Admin Dashboard    │                │ Tenant Dashboard   │   │
│  └────────┬───────────┘                └────────┬───────────┘   │
│           │                                     │                │
│           ▼                                     ▼                │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │    Admin Maintenance Queue                                 │ │
│  │    - View Pending/In Progress Requests                     │ │
│  │    - Assign Requests                                       │ │
│  │    - Start Work                                            │ │
│  │    - Complete Requests                                     │ │
│  │    - Reject Requests                                       │ │
│  │    - View Statistics                                       │ │
│  └─────────────────────┬──────────────────────────────────────┘ │
│                        │                                         │
│                        ▼                                         │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │         MAINTENANCE_REQUESTS Database Table               │ │
│  │  ┌──────────────────────────────────────────────────────┐ │ │
│  │  │ id, tenant_id, room_id, category, description       │ │ │
│  │  │ priority, status, assigned_to, submitted_date       │ │ │
│  │  │ start_date, completion_date, cost, notes            │ │ │
│  │  └──────────────────────────────────────────────────────┘ │ │
│  └─────────────────────┬──────────────────────────────────────┘ │
│                        │                                         │
│                        ▼                                         │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │  Tenant Maintenance Pages                                 │ │
│  │  - Maintenance Dashboard Section                          │ │
│  │  - Maintenance Request Page                               │ │
│  │  - Submit New Request                                     │ │
│  │  - View Status with Emoji Labels                          │ │
│  │  - See Assigned Staff                                     │ │
│  │  - View Completion Notes                                  │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

---

## Request Lifecycle Flowchart

```
START: Tenant Submits Request
           │
           ▼
    ┌────────────────────┐
    │  Status: PENDING   │
    │  ⏳ Pending         │
    │  (Yellow Badge)    │
    └────────┬───────────┘
             │
      [Admin Options]
             │
    ┌────────┴────────┐
    │                 │
    ▼                 ▼
┌─────────────┐  ┌──────────────┐
│   ASSIGN    │  │    REJECT    │
│   Request   │  │   Request    │
│   to Staff  │  │              │
└──────┬──────┘  └────────┬─────┘
       │                  │
       ▼                  ▼
    PENDING         ┌──────────────────┐
  (Assigned)        │ Status: CANCELLED│
       │            │ ✕ Cancelled      │
       │            │ (Gray Badge)     │
       │            └──────────────────┘
       │                    │
       │                    ▼
       │            [Notify Tenant]
       │            (Show rejection reason)
       │
       ▼
 ┌──────────────────┐
 │ Admin Clicks:    │
 │ "START WORK"     │
 │ Button           │
 └─────────┬────────┘
           │
           ▼
    ┌────────────────────┐
    │Status: IN_PROGRESS │
    │  ▶ Ongoing         │
    │  (Blue Badge)      │
    │  start_date = NOW()│
    └────────┬───────────┘
             │
      [Work Being Done]
             │
             ▼
    ┌─────────────────────┐
    │ Admin Clicks:       │
    │ "COMPLETE" Button   │
    │ Adds completion     │
    │ notes               │
    └─────────┬───────────┘
              │
              ▼
    ┌────────────────────┐
    │  Status: COMPLETED │
    │  ✓ Resolved        │
    │  (Green Badge)     │
    │completion_date=NOW()
    └────────┬───────────┘
             │
             ▼
    [Notify Tenant]
    (Show completion notes,
     assigned staff,
     completion date)
             │
             ▼
         [ARCHIVE]
```

---

## Admin Workflow Diagram

```
        ADMIN MAINTENANCE QUEUE
              (Main Page)
                  │
         ┌────────┴────────────┬──────────────────┐
         │                     │                  │
         ▼                     ▼                  ▼
   ┌──────────────┐    ┌──────────────┐   ┌────────────────┐
   │  Summary      │    │  Request     │   │    Filter by   │
   │  Statistics   │    │  Cards       │   │   Status/      │
   │               │    │  (Pending &  │   │   Priority     │
   │ - Total       │    │   In Progress)   │                │
   │ - Pending     │    │              │   └────────────────┘
   │ - In Progress │    │ Color Coded: │
   │ - Completed   │    │ Red (High)   │
   │ - High Pri.   │    │ Yellow (Norm)│
   │ - Unassigned  │    │ Blue (Low)   │
   └──────────────┘    └──────┬───────┘
                               │
              ┌────────────────┼────────────────┐
              │                │                │
              ▼                ▼                ▼
       ┌────────────────┐ ┌──────────────┐ ┌────────────────┐
       │    ASSIGN      │ │ START WORK   │ │ COMPLETE/      │
       │   Button       │ │   Button     │ │ REJECT Button  │
       │                │ │              │ │                │
       ▼                ▼                ▼ ▼
    ┌──────────────────────────────────────────────────┐
    │         MODAL DIALOGS                            │
    │                                                   │
    │  ┌──────────────┐  ┌────────────┐  ┌─────────┐  │
    │  │ ASSIGN Modal │  │ COMPLETE   │  │ REJECT  │  │
    │  │              │  │ Modal      │  │ Modal   │  │
    │  │ - Select     │  │            │  │         │  │
    │  │   Staff      │  │ - Completion│ │ - Reason│  │
    │  │ - Est.       │  │   Notes    │  │   for   │  │
    │  │   Completion │  │ - Submit   │  │   Cancel│  │
    │  │   Date       │  └────────────┘  └─────────┘  │
    │  │ - Notes      │                                │
    │  │ - Submit     │                                │
    │  └──────────────┘                                │
    └──────────────────┬───────────────────────────────┘
                       │
                       ▼
            ┌─────────────────────────┐
            │  DATABASE UPDATED       │
            │                         │
            │ UPDATE maintenance_     │
            │ requests SET ...        │
            │ WHERE id = request_id   │
            └─────────────┬───────────┘
                          │
                          ▼
            ┌─────────────────────────┐
            │  PAGE REDIRECTS         │
            │  (Refresh Queue)        │
            │                         │
            │  Success Message Shown  │
            └─────────────────────────┘
```

---

## Data Flow Diagram

```
┌────────────────────────────────────┐
│   ADMIN ACTION                     │
│                                    │
│  1. Click Button (Assign/Start/    │
│     Complete/Reject)               │
│                                    │
│  2. Modal Opens (if needed)        │
│                                    │
│  3. Enter Data (staff, notes, etc) │
│                                    │
│  4. Click Submit                   │
└─────────────────┬──────────────────┘
                  │
                  ▼
    ┌─────────────────────────────┐
    │  POST REQUEST SENT           │
    │  admin_maintenance_queue.php │
    │                             │
    │  Parameters:                │
    │  - action (assign/start/    │
    │    complete/reject)         │
    │  - request_id               │
    │  - [other data]             │
    └─────────────┬───────────────┘
                  │
                  ▼
    ┌─────────────────────────────┐
    │  PHP PROCESSES REQUEST      │
    │                             │
    │  1. Validate input          │
    │  2. Check permissions       │
    │  3. Prepare SQL query       │
    └─────────────┬───────────────┘
                  │
                  ▼
    ┌─────────────────────────────┐
    │  DATABASE OPERATION         │
    │                             │
    │  UPDATE maintenance_requests│
    │  SET field = value          │
    │  WHERE id = request_id      │
    │                             │
    │  SET status, start_date,    │
    │  completion_date, notes,    │
    │  assigned_to, etc.          │
    └─────────────┬───────────────┘
                  │
                  ▼
    ┌─────────────────────────────┐
    │  SUCCESS MESSAGE            │
    │                             │
    │  Set session variable       │
    │  "Assign successful!"       │
    └─────────────┬───────────────┘
                  │
                  ▼
    ┌─────────────────────────────┐
    │  REDIRECT BROWSER           │
    │                             │
    │  header("location:          │
    │  admin_maintenance_queue.php")
    └─────────────┬───────────────┘
                  │
                  ▼
    ┌─────────────────────────────┐
    │  PAGE RELOADS               │
    │                             │
    │  1. Fetch updated requests  │
    │     from database           │
    │  2. Display success message │
    │  3. Show updated queue      │
    │  4. Display new status      │
    └─────────────┬───────────────┘
                  │
                  ▼
    ┌─────────────────────────────┐
    │  TENANT SEES UPDATE         │
    │  (Next time they load page) │
    │                             │
    │  Dashboard:                 │
    │  Shows updated status       │
    │                             │
    │  Maintenance Page:          │
    │  Shows assigned staff,      │
    │  updated status,            │
    │  completion date            │
    └─────────────────────────────┘
```

---

## Status State Machine

```
                    ┌─────────────────────────────────┐
                    │        NEW REQUEST              │
                    │   (Submitted by Tenant)         │
                    └────────────────┬────────────────┘
                                     │
                                     ▼
                    ┌─────────────────────────────────┐
                    │   STATUS: PENDING               │
                    │   Badge: ⏳ Pending (Yellow)   │
                    │                                 │
                    │   User Can:                     │
                    │   - View Request                │
                    │                                 │
                    │   Admin Can:                    │
                    │   - Assign to Staff             │
                    │   - Set Est. Completion         │
                    │   - Add Notes                   │
                    │   - Reject Request              │
                    └────────┬──────────────────┬─────┘
                             │                  │
                    ┌────────▼────────┐  ┌─────▼──────────┐
                    │  ASSIGN CLICKED │  │ REJECT CLICKED │
                    └────────┬────────┘  └─────┬──────────┘
                             │                  │
                             ▼                  ▼
              ┌──────────────────────┐ ┌─────────────────────┐
              │  STATUS: PENDING     │ │STATUS: CANCELLED    │
              │  (With assignment)   │ │Badge: ✕ Cancelled  │
              │  Badge: ⏳ Pending  │ │ (Gray)              │
              │                      │ │                     │
              │  Admin Can:          │ │ [FINAL STATE]       │
              │  - START WORK        │ │                     │
              │  - REJECT            │ │ Notify Tenant:      │
              │                      │ │ "Request Rejected"  │
              │  Tenant Sees:        │ │                     │
              │  - Assigned Staff    │ │ Reason shown in     │
              │  - Est. Completion   │ │ notes               │
              │  - Admin Notes       │ │                     │
              └──────────┬───────────┘ └─────────────────────┘
                         │
                         ▼
           ┌─────────────────────────────────┐
           │ "START WORK" CLICKED            │
           └─────────────┬───────────────────┘
                         │
                         ▼
           ┌─────────────────────────────────┐
           │ STATUS: IN_PROGRESS             │
           │ Badge: ▶ Ongoing (Blue)        │
           │ start_date = NOW()              │
           │                                 │
           │ Tenant Sees:                    │
           │ - Work has started              │
           │ - Staff working on it           │
           │ - Started on [date/time]        │
           │                                 │
           │ Admin Can:                      │
           │ - COMPLETE REQUEST              │
           │ - Update Notes                  │
           └──────────┬──────────────────────┘
                      │
                      ▼
          ┌───────────────────────────────┐
          │ "COMPLETE" CLICKED            │
          └───────────┬─────────────────────┘
                      │
                      ▼
          ┌───────────────────────────────┐
          │ STATUS: COMPLETED             │
          │ Badge: ✓ Resolved (Green)    │
          │ completion_date = NOW()       │
          │                               │
          │ [FINAL STATE]                 │
          │                               │
          │ Tenant Sees:                  │
          │ - Work completed              │
          │ - Completion date/time        │
          │ - Completion notes            │
          │ - Green badge                 │
          │                               │
          │ Request moves to Archive      │
          └───────────────────────────────┘
```

---

## Database Table Relationships

```
┌────────────────────┐
│    TENANTS         │
├────────────────────┤
│ id (PK)            │
│ name               │
│ email              │
│ phone              │
│ ...                │
└────────┬───────────┘
         │ 1
         │
         │ N
         │
┌────────▼──────────────────┐
│MAINTENANCE_REQUESTS       │
├───────────────────────────┤
│ id (PK)                   │
│ tenant_id (FK)            │◄────────┐
│ room_id (FK)              │         │
│ category                  │         │
│ description               │         │
│ priority                  │         │
│ status                    │         │ 1
│ assigned_to (FK)          │◄──┐     │
│ submitted_date            │   │ N   │
│ start_date                │   │     │
│ completion_date           │   │     │
│ cost                      │   │     │
│ notes                     │   │     │
│ created_at                │   │     │
│ updated_at                │   │     │
└───────────┬───────────────┘   │     │
            │ 1                 │     │
            │                   │     │
            │ N                 │     │
            │                   │     │
     ┌──────▼──────┐      ┌─────┴──────┐
     │    ROOMS    │      │   ADMINS   │
     ├─────────────┤      ├────────────┤
     │ id (PK)     │      │ id (PK)    │
     │ number      │      │ username   │
     │ ...         │      │ email      │
     └─────────────┘      │ role       │
                          │ ...        │
                          └────────────┘
```

**Relationships**:
- Tenant (1) ─── Has Many (N) ─── Maintenance Requests
- Room (1) ─── Associated With (N) ─── Maintenance Requests
- Admin (1) ─── Can Manage (N) ─── Maintenance Requests

---

## Page Navigation Flow

```
┌──────────────────────────────────────────────────────────────┐
│                       INDEX.PHP                              │
│                    (Login Page)                              │
└────────────────────┬─────────────────────────────────────────┘
                     │
          ┌──────────┴──────────┐
          │                     │
    Admin Login            Tenant Login
          │                     │
          ▼                     ▼
   ┌────────────────┐   ┌──────────────────┐
   │  DASHBOARD     │   │ TENANT_DASHBOARD │
   │  (Admin)       │   │ (Tenant)         │
   └────────┬───────┘   └────────┬─────────┘
            │                    │
            │ Click              │ Click
            │ "Maintenance Queue"│ "Maintenance"
            │                    │
            ▼                    ▼
    ┌──────────────────────┐ ┌──────────────────┐
    │ ADMIN_MAINTENANCE_   │ │ TENANT_          │
    │ QUEUE.PHP            │ │ MAINTENANCE.PHP  │
    │ (Queue Dashboard)    │ │ (Request List)   │
    │                      │ │                  │
    │ - View Queue         │ │ - View Requests  │
    │ - Assign Requests    │ │ - Status Display │
    │ - Start Work         │ │ - See Status     │
    │ - Complete Requests  │ │ - Submit New     │
    │ - Reject Requests    │ │                  │
    │ - View Statistics    │ │ Submit New Req:  │
    │                      │ │      ▼           │
    │ Click Actions:       │ │ MAINTENANCE_FORM │
    │     ▼                │ │ (modal or page)  │
    │ ┌──────────────┐     │ │                  │
    │ │ MODALS       │     │ │ Database Save:   │
    │ │ ┌──────────┐ │     │ │     ▼            │
    │ │ │ ASSIGN   │ │     │ │ SUCCESS          │
    │ │ │ COMPLETE │ │     │ │ (Redirect)       │
    │ │ │ REJECT   │ │     │ │                  │
    │ │ └──────────┘ │     │ │                  │
    │ └──────┬───────┘     │ │                  │
    │        │             │ │                  │
    │        ▼             │ │                  │
    │ Submit Form (POST)   │ │                  │
    │        │             │ │                  │
    │        ▼             │ │                  │
    │ Process Action       │ │                  │
    │ Update Database      │ │                  │
    │ Show Success Message │ │                  │
    │        │             │ │                  │
    │        ▼             │ │                  │
    │ Redirect to Queue    │ │                  │
    │ Display Updated      │ │                  │
    │ Status               │ │                  │
    └──────────────────────┘ └──────────────────┘
            │                         │
            │ Both               Real-Time
            │ Connected to       Update from
            │ Database Database   DB
            │                     │
            └──────────┬──────────┘
                       │
                       ▼
             ┌──────────────────────┐
             │ MAINTENANCE_REQUESTS │
             │     DATABASE         │
             └──────────────────────┘
```

---

## Request Lifecycle Timeline

```
Timeline of a Maintenance Request
═════════════════════════════════════════════════════════════

Time    Action                      Status        Visible To
─────   ──────────────────────────  ──────────    ────────────────
[00:00] Tenant submits request      ⏳ PENDING    Tenant, Admin
        from maintenance form

[00:05] Admin receives notification ⏳ PENDING    Admin sees in queue
        Request appears in queue

[00:10] Admin clicks ASSIGN         ⏳ PENDING    Tenant: Staff assigned
        Assigns to John             (Assigned)   Tenant: Est. Date shown
        Sets est. completion        start_date=  Tenant: Notes visible
        Adds: "Assessment needed"   NULL

[00:15] Tenant refreshes dashboard  ⏳ PENDING    Tenant: "Assigned to John"
        Sees assignment             (Assigned)   "Est. Completion: [date]"

[14:00] Admin clicks START WORK     ▶ ONGOING    Tenant: Status changed
        John has arrived            start_date=  to blue badge
                                    NOW()        "▶ Ongoing"

[14:30] Tenant checks dashboard     ▶ ONGOING    Tenant: Still showing
        Sees work is in progress                 ongoing with start time

[16:30] Admin clicks COMPLETE       ✓ RESOLVED   Tenant: Status changed
        Adds: "Faucet replaced"     completion_  to green badge
        Work finished               date=NOW()   "✓ Resolved"
                                    completion_
                                    notes
                                    added

[16:35] Tenant refreshes page       ✓ RESOLVED   Tenant: Green badge
        Sees work completed                      "Faucet replaced"
        Reads completion notes                   Shows completion date
                                                 Shows assigned staff
```

---

## Communication Flow Between Admin & Tenant

```
ADMIN                               DATABASE                TENANT
────────────────────────────────────────────────────────────────

1. Admin Views Queue
   ├─→ Query pending requests
   ├─→ Sort by priority
   └─→ Display on page

2. Admin Clicks ASSIGN
   ├─→ Open Modal
   ├─→ Select staff: John
   ├─→ Set date: Tomorrow 2 PM
   ├─→ Notes: "Assessment needed"
   └─→ Click Submit

3. POST /admin_maintenance_queue.php
   ├─→ action=assign
   ├─→ request_id=42
   ├─→ assigned_to=3 (John's ID)
   ├─→ completion_date=2024-01-15 14:00:00
   └─→ notes=Assessment needed

4. PHP Processes Request
   ├─→ Validate input
   ├─→ Check permissions
   └─→ Prepare query

5. UPDATE maintenance_requests      ◄──────────→  Tenant's browser
   SET assigned_to=3                               detects change
   SET completion_date=...
   SET notes=...
   WHERE id=42
   
6. Query Executes
   Database updated
   
7. PHP Redirects
   to admin queue
   
8. Page Reloads
   Shows success message
   Request now shows
   "Assigned to John"

                                                 9. Tenant loads
                                                    tenant_dashboard.php
                                                    ├─→ Query their requests
                                                    └─→ Display dashboard

                                                 10. Maintenance section shows:
                                                     ├─ Assigned to: John
                                                     ├─ Est. Completion: Tomorrow 2 PM
                                                     ├─ Admin Notes: Assessment needed
                                                     └─ Status: ⏳ Pending
```

---

## System Component Diagram

```
┌────────────────────────────────────────────────────────────────┐
│                  BAMINT MAINTENANCE SYSTEM                      │
├────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌────────────────────────────────────────────────────────┐   │
│  │              USER INTERFACE LAYER                      │   │
│  │                                                        │   │
│  │  Admin Interface:                 Tenant Interface:   │   │
│  │  - admin_maintenance_queue.php    - tenant_dash.php  │   │
│  │  - maintenance_requests.php       - tenant_maint.php │   │
│  │                                                        │   │
│  └────────────────────┬─────────────────────────────────┘   │
│                       │                                       │
│  ┌────────────────────▼─────────────────────────────────┐   │
│  │            BUSINESS LOGIC LAYER                      │   │
│  │                                                      │   │
│  │  admin_maintenance_queue.php:                      │   │
│  │  - POST handler for 4 actions (assign, start,     │   │
│  │    complete, reject)                               │   │
│  │  - Query building and execution                    │   │
│  │  - Status validation                               │   │
│  │                                                     │   │
│  │  maintenance_actions.php:                          │   │
│  │  - Additional action handlers                      │   │
│  │  - Form processing                                 │   │
│  │                                                     │   │
│  └────────────────────┬────────────────────────────────┘   │
│                       │                                      │
│  ┌────────────────────▼────────────────────────────────┐   │
│  │          DATA ACCESS LAYER                         │   │
│  │                                                    │   │
│  │  database.php:                                   │   │
│  │  - PDO connection                                │   │
│  │  - Connection pooling                            │   │
│  │  - Error handling                                │   │
│  │                                                    │   │
│  └────────────────────┬────────────────────────────────┘   │
│                       │                                      │
│  ┌────────────────────▼────────────────────────────────┐   │
│  │        DATABASE LAYER (MySQL)                      │   │
│  │                                                    │   │
│  │  - maintenance_requests table                    │   │
│  │  - tenants table                                 │   │
│  │  - admins table                                  │   │
│  │  - rooms table                                   │   │
│  │                                                    │   │
│  └────────────────────────────────────────────────────┘   │
│                                                              │
└────────────────────────────────────────────────────────────┘
```

---

## Session & Authentication Flow

```
┌─────────────────────────────────────────────────────────────┐
│          AUTHENTICATION & SESSION MANAGEMENT                 │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  1. USER VISITS INDEX.PHP
│     ├─→ Check: Is $_SESSION['loggedin'] set?
│     │   NO → Show login form
│     │   YES → Check role and redirect
│     │
│     └─→ User enters credentials
│         ├─→ Database query: SELECT * FROM admins WHERE...
│         └─→ Password verification
│
│  2. LOGIN SUCCESSFUL
│     ├─→ Set $_SESSION['loggedin'] = true
│     ├─→ Set $_SESSION['admin_id'] = {id}
│     ├─→ Set $_SESSION['role'] = 'admin'
│     └─→ Redirect to dashboard.php
│
│  3. ADMIN VISITS QUEUE PAGE
│     ├─→ admin_maintenance_queue.php
│     └─→ Check: if (!isset($_SESSION["loggedin"]) || ... ) exit;
│
│  4. QUEUE PAGE PROCESSES REQUEST
│     ├─→ Check: REQUEST_METHOD === 'POST'
│     ├─→ Check: $_POST['action'] is set
│     ├─→ Check: request_id is valid
│     └─→ Process action (assign/start/complete/reject)
│
│  5. DATABASE UPDATE
│     ├─→ Use admin_id from session for audit
│     └─→ Update maintenance_requests table
│
│  6. REDIRECT
│     ├─→ header("location: admin_maintenance_queue.php")
│     ├─→ Page reloads
│     └─→ Display updated queue with success message
│
│  7. SESSION EXPIRES
│     ├─→ Timeout: 24 minutes (default PHP)
│     ├─→ Next request: Redirect to login
│     └─→ User must login again
│
│  8. LOGOUT
│     ├─→ Click: Logout in sidebar
│     ├─→ logout.php executes:
│     │   ├─→ session_destroy()
│     │   └─→ Redirect to index.php
│     └─→ Login form displayed
│
└─────────────────────────────────────────────────────────────┘
```

---

## Error Handling Flow

```
Request → PHP Processing → Database Query → Result
    ↓            ↓                 ↓           ↓
  Valid?      Error?           Error?      Success?
    │            │                │           │
    ├─ NO  ───────────────────────┤       ┌───┤
    │                              │       │   │
    │  ┌──────────────────────┐    │       │   │
    │  │ Error Message Set    │    │       │   │
    │  │ $_SESSION['error']   │    │       │   │
    │  └──────────────────────┘    │       │   │
    │                              │       │   │
    │                              │       │   │
    └──────────────────────────────┼───────┼───┘
                                   │       │
                                   │   ┌───────────────────┐
                                   │   │ Success Message   │
                                   │   │ $_SESSION['msg']  │
                                   │   └───────────────────┘
                                   │
                                   └──────┬───────────┐
                                          │           │
                                     Try-Catch   Redirect to
                                     Block       Queue Page
                                          │
                                     Message
                                     Displayed
                                     to User
```

---

**All diagrams show the complete system architecture and data flow.**
**Use these to understand how the maintenance queue system works.**

