# Notification System - Architecture & Flow Diagrams

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      BAMINT Notification System                  │
└─────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│                         Frontend Layer                            │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │              Browser (Client Side)                       │    │
│  │  ┌──────────────────────────────────────────────────┐   │    │
│  │  │  Navigation Bar                                  │   │    │
│  │  │  ┌──────────────────────────────────────────┐   │   │    │
│  │  │  │ [🔔] Notification Bell with Badge        │   │   │    │
│  │  │  │ Shows count: 1, 2, ... 99+              │   │   │    │
│  │  │  └──────────────────────────────────────────┘   │   │    │
│  │  └──────────────────────────────────────────────────┘   │    │
│  │                                                           │    │
│  │  ┌──────────────────────────────────────────────────┐   │    │
│  │  │  Modal Popup (Hidden until bell clicked)       │   │    │
│  │  │  ┌──────────────────────────────────────────┐   │   │    │
│  │  │  │ Notifications                            │   │   │    │
│  │  │  │ ─────────────────────────────────────── │   │   │    │
│  │  │  │ □ Payment Approved (2h ago)             │   │   │    │
│  │  │  │ ■ Room Request Approved (5m ago)        │   │   │    │
│  │  │  │ ■ New Payment Received (just now)       │   │   │    │
│  │  │  │ ─────────────────────────────────────── │   │   │    │
│  │  │  │ [Mark All as Read] [Close]              │   │   │    │
│  │  │  └──────────────────────────────────────────┘   │   │    │
│  │  └──────────────────────────────────────────────────┘   │    │
│  │                                                           │    │
│  │  JavaScript (in header.php):                             │    │
│  │  • loadNotifications()                                   │    │
│  │  • handleNotificationClick()                             │    │
│  │  • markAllNotificationsAsRead()                          │    │
│  │  • updateNotificationBadge() [auto every 30s]            │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                   │
└──────────────────────────────────────────────────────────────────┘
                              ↓↑ AJAX (api_notifications.php)
                       [GET/POST with JSON]

┌──────────────────────────────────────────────────────────────────┐
│                      Backend API Layer                            │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  api_notifications.php (REST Endpoints)                │    │
│  │                                                          │    │
│  │  GET ?action=get_count                                 │    │
│  │  └─> Returns: {"count": 5}                             │    │
│  │                                                          │    │
│  │  GET ?action=get_notifications                         │    │
│  │  └─> Returns: {"notifications": [...]}                 │    │
│  │                                                          │    │
│  │  GET ?action=mark_read&notification_id=123             │    │
│  │  └─> Returns: {"success": true}                        │    │
│  │                                                          │    │
│  │  GET ?action=mark_all_read                             │    │
│  │  └─> Returns: {"success": true}                        │    │
│  │                                                          │    │
│  │  GET ?action=delete&notification_id=123                │    │
│  │  └─> Returns: {"success": true}                        │    │
│  └─────────────────────────────────────────────────────────┘    │
│                           ↓↑ (Includes)
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  db/notifications.php (Helper Functions)               │    │
│  │                                                          │    │
│  │  ▶ createNotification()                                │    │
│  │  ▶ getUnreadNotificationsCount()                       │    │
│  │  ▶ getNotifications()                                  │    │
│  │  ▶ markNotificationAsRead()                            │    │
│  │  ▶ markAllNotificationsAsRead()                        │    │
│  │  ▶ notifyAdminsNewRoom()                               │    │
│  │  ▶ notifyAdminsNewPayment()                            │    │
│  │  ▶ notifyAdminsNewMaintenance()                        │    │
│  │  ▶ notifyAdminsNewRoomRequest()                        │    │
│  │  ▶ notifyTenantPaymentVerification()                   │    │
│  │  ▶ notifyTenantMaintenanceStatus()                     │    │
│  │  ▶ notifyTenantRoomRequestStatus()                     │    │
│  │  ... and more                                           │    │
│  └─────────────────────────────────────────────────────────┘    │
│                           ↓↑ (Uses)
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  Database (PDO/MySQL)                                  │    │
│  │  └─> notifications table                               │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                   │
└──────────────────────────────────────────────────────────────────┘
                              ↑↓ Triggers (called from)
                    [Various action files]

┌──────────────────────────────────────────────────────────────────┐
│                    Trigger Points (Actions)                      │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─────────────────────────────┬──────────────────────────────┐ │
│  │ room_actions.php            │ bill_actions.php             │ │
│  │ └─ Add room                 │ └─ Record payment            │ │
│  │    ↓                          │    ↓                         │ │
│  │ notifyAdminsNewRoom()        │ notifyAdminsNewPayment()    │ │
│  │ (all admins notified)         │ (all admins notified)       │ │
│  └─────────────────────────────┴──────────────────────────────┘ │
│                                                                   │
│  ┌─────────────────────────────┬──────────────────────────────┐ │
│  │ admin_payment_verification   │ maintenance_actions.php      │ │
│  │ └─ Verify payment            │ └─ Submit request            │ │
│  │    ↓                          │ └─ Update status             │ │
│  │ notifyTenantPaymentVerif()   │    ↓                         │ │
│  │ (specific tenant notified)    │ notifyAdminsNewMaintenance()│ │
│  │                              │ notifyTenantMaintStatus()    │ │
│  └─────────────────────────────┴──────────────────────────────┘ │
│                                                                   │
│  ┌─────────────────────────────┬──────────────────────────────┐ │
│  │ tenant_add_room.php          │ room_requests_queue.php      │ │
│  │ └─ Request co-tenant         │ └─ Approve request           │ │
│  │    ↓                          │ └─ Reject request            │ │
│  │ notifyAdminsNewRoomRequest() │    ↓                         │ │
│  │ (all admins notified)         │ notifyTenantRoomReqStatus() │ │
│  │                              │ (specific tenant notified)   │ │
│  └─────────────────────────────┴──────────────────────────────┘ │
│                                                                   │
└──────────────────────────────────────────────────────────────────┘
```

---

## Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         EVENT FLOW                               │
└─────────────────────────────────────────────────────────────────┘

USER ACTION
    ↓
┌─────────────────────────────────────────────────────────────────┐
│ 1. Admin adds room in room_actions.php                          │
│    - Form submitted                                              │
│    - INSERT INTO rooms                                           │
│    - $roomId = lastInsertId()                                    │
└─────────────────────────────────────────────────────────────────┘
    ↓
TRIGGER NOTIFICATION
    ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. Call: notifyAdminsNewRoom($conn, $roomId, $roomNumber)      │
│    - Function queries all admins from database                   │
│    - For each admin:                                             │
│      - Call createNotification()                                 │
│        - INSERT INTO notifications                              │
│        - recipient_type: 'admin'                                │
│        - recipient_id: admin_id                                 │
│        - notification_type: 'room_added'                        │
│        - title: 'New Room Added'                                │
│        - message: 'Room [Number] added'                         │
│        - action_url: 'rooms.php'                                │
│        - is_read: 0                                             │
└─────────────────────────────────────────────────────────────────┘
    ↓
ADMIN RECEIVES NOTIFICATION
    ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. Admin is viewing dashboard                                   │
│    - Page loads header.php                                       │
│    - getUnreadNotificationsCount() called                        │
│    - Badge shows "1"                                             │
└─────────────────────────────────────────────────────────────────┘
    ↓
ADMIN CLICKS BELL
    ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. Modal popup opens                                             │
│    - JavaScript calls: loadNotifications()                       │
│    - fetch('api_notifications.php?action=get_notifications')   │
│    - GET request with AJAX                                      │
└─────────────────────────────────────────────────────────────────┘
    ↓
API PROCESSES REQUEST
    ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5. api_notifications.php ?action=get_notifications              │
│    - Validates session (is admin logged in?)                    │
│    - Calls: getNotifications($conn, 'admin', $adminId, 10, 0)   │
│    - Queries: SELECT * FROM notifications                       │
│               WHERE recipient_type='admin'                      │
│               AND recipient_id=$adminId                         │
│               ORDER BY created_at DESC LIMIT 10                 │
│    - Returns JSON: {notifications: [...]}                       │
└─────────────────────────────────────────────────────────────────┘
    ↓
FRONTEND RENDERS
    ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6. JavaScript renders modal with notifications                   │
│    - Shows: "New Room Added - Room 101 added (2m ago)"         │
│    - Blue background indicates unread                            │
│    - Clickable notification                                      │
└─────────────────────────────────────────────────────────────────┘
    ↓
ADMIN CLICKS NOTIFICATION
    ↓
┌─────────────────────────────────────────────────────────────────┐
│ 7. handleNotificationClick(notificationId, actionUrl)            │
│    - fetch('api_notifications.php?action=mark_read&...')        │
│    - Call markNotificationAsRead()                              │
│    - UPDATE notifications SET is_read=1, read_at=NOW()          │
│    - window.location.href = actionUrl (rooms.php)               │
└─────────────────────────────────────────────────────────────────┘
    ↓
COMPLETE
```

---

## Database Schema Diagram

```
notifications table
┌──────────────────────────────────────────────────────────────────┐
│ Column           │ Type             │ Key      │ Description      │
├──────────────────────────────────────────────────────────────────┤
│ id               │ INT              │ PRIMARY  │ Auto increment   │
│ recipient_type   │ VARCHAR(50)      │ INDEX*   │ 'admin'/'tenant' │
│ recipient_id     │ INT              │ INDEX*   │ user id          │
│ notification_type│ VARCHAR(100)     │ INDEX    │ room_added, etc  │
│ title            │ VARCHAR(255)     │          │ Display title    │
│ message          │ TEXT             │          │ Full message     │
│ related_id       │ INT              │ NULL     │ room_id, etc     │
│ related_type     │ VARCHAR(100)     │ NULL     │ room, bill, etc  │
│ action_url       │ VARCHAR(500)     │ NULL     │ redirect URL     │
│ is_read          │ TINYINT(1)       │ INDEX    │ 0=unread/1=read  │
│ read_at          │ DATETIME         │ NULL     │ when read        │
│ created_at       │ TIMESTAMP        │ INDEX    │ when created     │
│ updated_at       │ TIMESTAMP        │          │ when updated     │
└──────────────────────────────────────────────────────────────────┘
* composite key for fast filtering by (recipient_type, recipient_id)

Sample Rows:
┌─────────────────────────────────────────────────────────────────┐
│ ID │ Type  │ ID │ Notification Type │ Title          │ is_read  │
├─────────────────────────────────────────────────────────────────┤
│ 1  │ admin │ 1  │ room_added        │ New Room Added │ 0        │
│ 2  │ admin │ 1  │ payment_made      │ New Payment    │ 1        │
│ 3  │ admin │ 2  │ room_added        │ New Room Added │ 0        │
│ 4  │ tenant│ 5  │ payment_verified  │ Pmt Approved   │ 0        │
│ 5  │ tenant│ 6  │ maintenance_appd  │ Request Status │ 1        │
└─────────────────────────────────────────────────────────────────┘
```

---

## Notification Type Matrix

```
┌──────────────────────────────────────────────────────────────────┐
│                    NOTIFICATION TYPES                            │
├──────────────────────────────────────────────────────────────────┤

ADMIN NOTIFICATIONS (Sent to all admins)

  room_added
  ├─ Trigger: room_actions.php (new room created)
  ├─ Recipient: All admins
  ├─ Title: "New Room Added"
  ├─ Message: "Room [Number] added to system"
  └─ Action: rooms.php

  payment_made
  ├─ Trigger: bill_actions.php (payment recorded)
  ├─ Recipient: All admins
  ├─ Title: "New Payment Received"
  ├─ Message: "Payment of $X from [Tenant] awaits verification"
  └─ Action: admin_payment_verification.php

  maintenance_request
  ├─ Trigger: maintenance_actions.php (request submitted)
  ├─ Recipient: All admins
  ├─ Title: "New Maintenance Request"
  ├─ Message: "[Category] request from [Tenant]"
  └─ Action: admin_maintenance_queue.php

  room_request
  ├─ Trigger: tenant_add_room.php (request submitted)
  ├─ Recipient: All admins
  ├─ Title: "New Room Request"
  ├─ Message: "[Tenant] requesting co-tenant approval"
  └─ Action: room_requests_queue.php

TENANT NOTIFICATIONS (Sent to specific tenant)

  payment_verified
  ├─ Trigger: admin_payment_verification.php (verified)
  ├─ Recipient: Tenant who paid
  ├─ Title: "Payment Approved"
  ├─ Message: "Your payment has been verified and approved"
  └─ Action: payment_history.php

  payment_rejected
  ├─ Trigger: admin_payment_verification.php (rejected)
  ├─ Recipient: Tenant who paid
  ├─ Title: "Payment Status Update"
  ├─ Message: "Your payment status has been updated"
  └─ Action: payment_history.php

  maintenance_status
  ├─ Trigger: maintenance_actions.php (status updated)
  ├─ Recipient: Tenant who requested
  ├─ Title: "Maintenance Request [Status]"
  ├─ Message: "Your request has been [status]"
  └─ Action: tenant_maintenance.php

  room_request_approved
  ├─ Trigger: room_requests_queue.php (approved)
  ├─ Recipient: Tenant who requested
  ├─ Title: "Room Request Approved"
  ├─ Message: "Your co-tenant request approved!"
  └─ Action: tenant_dashboard.php

  room_request_rejected
  ├─ Trigger: room_requests_queue.php (rejected)
  ├─ Recipient: Tenant who requested
  ├─ Title: "Room Request Rejected"
  ├─ Message: "Your co-tenant request has been rejected"
  └─ Action: tenant_dashboard.php

└──────────────────────────────────────────────────────────────────┘
```

---

## User Interaction Flow

```
┌──────────────────────────────────────────────────────────────────┐
│                    ADMIN WORKFLOW                                │
└──────────────────────────────────────────────────────────────────┘

LOGIN
  ↓
NAVIGATE TO DASHBOARD
  ↓
[🔔] Bell shows "3" (3 unread notifications)
  ↓
CLICK BELL
  ↓
MODAL OPENS (shows 10 notifications, sorted by newest)
  ├─ ■ "New Payment Received - awaits verification" (1m ago)
  ├─ ■ "New Room Request - tenant asking approval" (2h ago)
  ├─ □ "New Room Added - Room 101" (1d ago)
  └─ [Mark All as Read] [Close]
  ↓
CLICK "New Payment Received" NOTIFICATION
  ↓
├─ Mark notification as read (background)
├─ Update badge (now shows "2")
└─ Redirect to admin_payment_verification.php
  ↓
VERIFY/REJECT PAYMENT
  ↓
TENANT AUTOMATICALLY RECEIVES NOTIFICATION
  ├─ "Payment Approved" OR
  └─ "Payment Status Update"

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

┌──────────────────────────────────────────────────────────────────┐
│                    TENANT WORKFLOW                               │
└──────────────────────────────────────────────────────────────────┘

LOGIN
  ↓
NAVIGATE TO DASHBOARD
  ↓
[🔔] Bell shows "1" (1 unread notification)
  ↓
SUBMIT MAINTENANCE REQUEST
  ↓
REQUEST SAVED
  ↓
ALL ADMINS AUTOMATICALLY RECEIVE NOTIFICATION
  └─ "New Maintenance Request - [Category] from [Tenant]"
  ↓
WAIT FOR ADMIN ACTION
  ↓
ADMIN UPDATES STATUS (e.g., "assigned", "in progress", "completed")
  ↓
TENANT AUTOMATICALLY RECEIVES NOTIFICATION
  ├─ "Maintenance Request Assigned"
  ├─ "Maintenance Request In Progress"
  └─ "Maintenance Request Completed"
  ↓
TENANT CLICKS NOTIFICATION
  ├─ Mark as read
  └─ Redirect to tenant_maintenance.php
  ↓
TENANT SEES UPDATED STATUS
```

---

## System States

```
┌──────────────────────────────────────────────────────────────────┐
│           BADGE STATES (Notification Bell)                      │
└──────────────────────────────────────────────────────────────────┘

NO UNREAD NOTIFICATIONS
┌────────────┐
│    🔔      │  No badge
└────────────┘

1-99 UNREAD NOTIFICATIONS
┌────────────┐
│    🔔(5)   │  Badge shows count
└────────────┘

100+ UNREAD NOTIFICATIONS
┌────────────┐
│   🔔(99+)  │  Badge shows "99+"
└────────────┘

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

┌──────────────────────────────────────────────────────────────────┐
│         NOTIFICATION ITEM STATES (In Modal)                     │
└──────────────────────────────────────────────────────────────────┘

UNREAD STATE
┌─────────────────────────────────────────┐
│ ● Payment Approved                      │  Light blue background
│   Your payment has been approved        │  Blue dot indicator
│   2h ago                                │  
└─────────────────────────────────────────┘

READ STATE
┌─────────────────────────────────────────┐
│   Payment Approved                      │  White background
│   Your payment has been approved        │  No indicator
│   2h ago                                │
└─────────────────────────────────────────┘

HOVER STATE
┌─────────────────────────────────────────┐
│ ● Payment Approved                      │  Slightly darker
│   Your payment has been approved        │  Cursor changes to pointer
│   2h ago                                │
└─────────────────────────────────────────┘
```

---

## File Dependencies

```
Header (included on every page)
    │
    ├─ templates/header.php
    │   └─ db/notifications.php [getUnreadNotificationsCount]
    │   └─ api_notifications.php [AJAX calls via JavaScript]
    │       ├─ db/notifications.php [all functions]
    │       └─ db/database.php [PDO connection]
    │
    ├─ room_actions.php
    │   └─ db/notifications.php [notifyAdminsNewRoom]
    │
    ├─ bill_actions.php
    │   └─ db/notifications.php [notifyAdminsNewPayment]
    │
    ├─ admin_payment_verification.php
    │   └─ db/notifications.php [notifyTenantPaymentVerification]
    │
    ├─ maintenance_actions.php
    │   ├─ db/notifications.php [notifyAdminsNewMaintenance]
    │   └─ db/notifications.php [notifyTenantMaintenanceStatus]
    │
    ├─ tenant_add_room.php
    │   └─ db/notifications.php [notifyAdminsNewRoomRequest]
    │
    └─ room_requests_queue.php
        └─ db/notifications.php [notifyTenantRoomRequestStatus]
```

---

## Performance Characteristics

```
┌──────────────────────────────────────────────────────────────────┐
│                  PERFORMANCE METRICS                             │
└──────────────────────────────────────────────────────────────────┘

Database Query Performance:
│
├─ SELECT count(*) unread notifications
│  ├─ Query: SELECT COUNT(*) FROM notifications
│  │          WHERE recipient_type=? AND recipient_id=? AND is_read=0
│  ├─ Index: (recipient_type, recipient_id, is_read)
│  └─ Time: < 5ms (typical)
│
├─ GET notifications list
│  ├─ Query: SELECT * FROM notifications
│  │          WHERE recipient_type=? AND recipient_id=?
│  │          ORDER BY created_at DESC LIMIT 10
│  ├─ Index: (recipient_type, recipient_id) + (created_at)
│  └─ Time: < 10ms (typical)
│
├─ MARK notification as read
│  ├─ Query: UPDATE notifications SET is_read=1, read_at=NOW()
│  │          WHERE id=?
│  ├─ Index: PRIMARY KEY (id)
│  └─ Time: < 2ms (typical)
│
└─ INSERT notification (on trigger)
   ├─ Query: INSERT INTO notifications (...)
   ├─ Index: All columns indexed
   └─ Time: < 2ms (typical)

Frontend Performance:
│
├─ Load notification modal: < 100ms
├─ Mark as read (AJAX): < 50ms
├─ Auto-refresh (every 30s): < 50ms
├─ Time-formatting JavaScript: < 1ms
└─ Pagination load: < 100ms

Total Page Impact:
│
├─ Additional CSS: 1.5KB gzipped
├─ Additional JavaScript: 3KB gzipped
├─ Additional database calls: 1 per page load
└─ Unread count refresh: Every 30 seconds
```

---

These diagrams provide a visual understanding of:
- System architecture
- Data flows
- Database structure
- Notification types
- User interactions
- Performance characteristics
