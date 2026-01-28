# BAMINT Notification System Documentation

## Overview
The BAMINT notification system provides real-time alerts to both admins and tenants about important events in the system. Every admin and tenant can see a notification bell in the top-right corner of the navbar that displays unread notification count.

## Features

### For Admins
Admins receive notifications for:
1. **New Room Added** - When a new room is added to the system
2. **New Payment Received** - When a tenant makes a payment (needs verification)
3. **Maintenance Request Submitted** - When a tenant submits a new maintenance request
4. **New Room Request** - When a tenant requests to add co-tenants to their room

### For Tenants
Tenants receive notifications for:
1. **Payment Verified/Approved** - When admin verifies their payment
2. **Payment Rejected** - When admin rejects their payment
3. **Maintenance Status Update** - When admin updates maintenance request status
4. **Room Request Approved** - When admin approves their room request
5. **Room Request Rejected** - When admin rejects their room request

## Database Structure

### Notifications Table
```sql
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_type` varchar(50) NOT NULL COMMENT 'admin or tenant',
  `recipient_id` int(11) NOT NULL COMMENT 'admin_id or tenant_id',
  `notification_type` varchar(100) NOT NULL COMMENT 'room_added, payment_made, etc',
  `title` varchar(255) NOT NULL,
  `message` text,
  `related_id` int(11) COMMENT 'room_id, bill_id, etc',
  `related_type` varchar(100) COMMENT 'room, bill, maintenance_request, etc',
  `action_url` varchar(500) COMMENT 'URL to navigate when clicked',
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` datetime,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `recipient_type_id` (`recipient_type`, `recipient_id`),
  KEY `is_read` (`is_read`),
  KEY `created_at` (`created_at`),
  KEY `notification_type` (`notification_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## File Structure

### Core Files
- **db/notifications.php** - Contains all notification helper functions
- **api_notifications.php** - API endpoint for notification operations
- **templates/header.php** - Notification bell UI and modal

### Updated Files (with notification support)
- **room_actions.php** - Notifies admins when new room is added
- **bill_actions.php** - Notifies admins when payment is recorded
- **admin_payment_verification.php** - Notifies tenants when payment is verified/rejected
- **maintenance_actions.php** - Notifies admins of new requests and tenants of status changes
- **tenant_add_room.php** - Notifies admins of new room requests
- **room_requests_queue.php** - Notifies tenants of approval/rejection

## Helper Functions

All notification functions are located in [db/notifications.php](db/notifications.php)

### Creating Notifications

```php
// Notify all admins about new room
notifyAdminsNewRoom($conn, $roomId, $roomNumber);

// Notify all admins about new payment
notifyAdminsNewPayment($conn, $billId, $tenantId, $amount);

// Notify all admins about new maintenance request
notifyAdminsNewMaintenance($conn, $maintenanceId, $tenantId, $category);

// Notify all admins about new room request
notifyAdminsNewRoomRequest($conn, $roomRequestId, $tenantId, $tenantCount);

// Notify tenant about payment verification
notifyTenantPaymentVerification($conn, $tenantId, $paymentId, $status);

// Notify tenant about maintenance status
notifyTenantMaintenanceStatus($conn, $tenantId, $maintenanceId, $status);

// Notify tenant about room request status
notifyTenantRoomRequestStatus($conn, $tenantId, $roomRequestId, $status);
```

### Retrieving Notifications

```php
// Get unread count for user
$count = getUnreadNotificationsCount($conn, 'admin', $adminId);

// Get all notifications (paginated)
$notifications = getNotifications($conn, 'tenant', $tenantId, 10, 0);

// Get specific notification
$notification = getNotificationById($conn, $notificationId);
```

### Managing Notifications

```php
// Mark notification as read
markNotificationAsRead($conn, $notificationId);

// Mark all as read for user
markAllNotificationsAsRead($conn, 'admin', $adminId);

// Delete notification
deleteNotification($conn, $notificationId);

// Delete all for user
deleteAllNotifications($conn, 'tenant', $tenantId);
```

## API Endpoints

### GET api_notifications.php

#### Get unread count
```
GET api_notifications.php?action=get_count
Response: {"count": 5}
```

#### Get notifications list
```
GET api_notifications.php?action=get_notifications&limit=10&offset=0
Response: {"notifications": [...]}
```

#### Mark as read
```
GET api_notifications.php?action=mark_read&notification_id=123
Response: {"success": true, "remaining_count": 4}
```

#### Mark all as read
```
GET api_notifications.php?action=mark_all_read
Response: {"success": true}
```

#### Delete notification
```
GET api_notifications.php?action=delete&notification_id=123
Response: {"success": true}
```

## UI Components

### Notification Bell
Located in navbar (header.php):
- Shows bell icon with unread count badge
- Badge shows "99+" for counts over 99
- Clicking bell opens notifications modal

### Notifications Modal
Features:
- Displays list of notifications sorted by newest first
- Click notification to mark as read and navigate to action URL
- "Mark All as Read" button at bottom
- "No notifications yet" message when empty
- Auto-refreshes every 30 seconds
- Loads with fade-in animation

### Notification Item Styling
- Unread items have light blue background with blue indicator dot
- Hover effect for better UX
- Shows title, message, and time ago (e.g., "2h ago")
- Clickable to open related page

## Integration Examples

### Adding a Notification on Room Creation

```php
// In room_actions.php, after INSERT:
$roomId = $conn->lastInsertId();
notifyAdminsNewRoom($conn, $roomId, $room_number);
```

### Adding a Notification on Payment Verification

```php
// In admin_payment_verification.php, after UPDATE:
notifyTenantPaymentVerification($conn, $tenantId, $paymentId, 'approved');
```

### Adding a Notification on Maintenance Request

```php
// In maintenance_actions.php, after INSERT:
$maintenanceId = $conn->lastInsertId();
notifyAdminsNewMaintenance($conn, $maintenanceId, $tenantId, $category);
```

## Notification Types

| Type | Recipient | Trigger | Action URL |
|------|-----------|---------|-----------|
| room_added | Admin | Room created | rooms.php |
| payment_made | Admin | Payment recorded | admin_payment_verification.php |
| payment_verified | Tenant | Payment verified | payment_history.php |
| maintenance_request | Admin | Request submitted | admin_maintenance_queue.php |
| maintenance_approved | Tenant | Status updated | tenant_maintenance.php |
| room_request | Admin | Request submitted | room_requests_queue.php |
| room_request_approved | Tenant | Request approved | tenant_dashboard.php |

## JavaScript Features

The notification system uses:
- **Fetch API** for AJAX calls
- **Bootstrap Modal** for popup display
- **Real-time badge updates** every 30 seconds
- **Time formatting** (just now, 5m ago, etc.)
- **Auto-navigation** on notification click

### Key JavaScript Functions

```javascript
loadNotifications()  // Fetches and displays notifications
handleNotificationClick(id, url)  // Marks read and navigates
markAllNotificationsAsRead()  // Marks all as read
updateNotificationBadge()  // Updates badge count
getTimeAgo(dateString)  // Formats date to "X ago"
```

## Browser Requirements

- Modern browser with Fetch API support
- JavaScript enabled
- Bootstrap 5.3+ (for modal and styling)
- Bootstrap Icons (for bell icon)

## Performance Considerations

1. **Database Indexing**: The notifications table has indexes on:
   - `recipient_type` and `recipient_id` (composite)
   - `is_read` flag
   - `created_at` timestamp
   - `notification_type`

2. **Pagination**: Use limit/offset to avoid loading all notifications at once

3. **Cleanup**: Consider archiving old notifications periodically (not implemented yet)

## Future Enhancements

Possible improvements:
1. Email notifications for important events
2. SMS notifications
3. Notification categories/filtering
4. Notification preferences per user
5. Bulk notification actions
6. Notification archive
7. Sound alerts
8. Notification scheduling

## Testing the System

1. **Login as Admin**: Navigate to dashboard
2. **Open Notifications**: Click bell icon to open modal
3. **Test Various Actions**:
   - Add a new room and check admin gets notification
   - Record a payment and verify admin notification
   - Submit maintenance request and check admin notification
   - As tenant, request co-tenant and check admin notification
4. **Verify Actions**: 
   - Click notification and verify it navigates to correct page
   - Click "Mark All as Read" and verify badge clears
   - Close modal and reopen - count should be updated

## Troubleshooting

### Notifications not appearing
- Ensure database migration/init.sql has been run
- Check browser console for JavaScript errors
- Verify database connection in api_notifications.php
- Check session is properly initialized

### Badge not updating
- Clear browser cache
- Check that JavaScript is enabled
- Verify Bootstrap and Bootstrap Icons are loaded

### Notification modal not opening
- Ensure Bootstrap 5.3+ is loaded
- Check for JavaScript errors in console
- Verify modal ID matches in header.php

## Security

- All API endpoints check session authentication
- Notifications are user-specific (filtered by recipient_id)
- SQL injection prevented via prepared statements
- XSS prevention via htmlspecialchars in PHP
- CSRF tokens should be added for POST requests in future

## Support

For issues or questions:
1. Check the Troubleshooting section above
2. Verify database schema matches init.sql
3. Check browser console for JavaScript errors
4. Review server logs for PHP errors
