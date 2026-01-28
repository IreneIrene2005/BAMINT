# Notification System - Quick Start Guide

## What's New?
A comprehensive notification system has been implemented in BAMINT. All admins and tenants now receive real-time notifications about important events.

## What You'll See

### Notification Bell
In the top-right corner of the navbar, you'll see a bell icon with a red badge showing the number of unread notifications.

![Notification Bell](notifications-bell.png)

### Clicking the Bell
Click the bell to open a modal with:
- List of all your recent notifications
- Unread notifications highlighted in light blue
- "Mark All as Read" button
- Auto-refresh every 30 seconds

## What Notifications You Get

### If You're an Admin ✅
You'll be notified when:
1. **A new room is added** - Someone adds a new room to the system
2. **A payment is received** - A tenant makes a payment (needs your verification)
3. **A maintenance request is submitted** - A tenant needs maintenance help
4. **A room request is submitted** - A tenant requests to add co-tenants

### If You're a Tenant ✅
You'll be notified when:
1. **Your payment is verified** - Admin approved your payment
2. **Your payment is rejected** - Admin rejected your payment (you need to resubmit)
3. **Your maintenance status updates** - Admin approves/completes your maintenance request
4. **Your room request is approved** - Admin approved your co-tenant request
5. **Your room request is rejected** - Admin rejected your co-tenant request

## How to Use

### Viewing Notifications
1. Click the bell icon in the top-right
2. A modal pops up showing your notifications
3. Click any notification to:
   - Mark it as read
   - Navigate to the relevant page

### Managing Notifications
- **Mark as read**: Click on a notification
- **Mark all as read**: Click "Mark All as Read" button at the bottom
- **Clear count**: Mark all notifications as read to clear the badge

### Understanding Notification Colors
- **Light Blue Background** = Unread notification
- **White Background** = Already read
- **Time Display** = Shows when notification was created (e.g., "2h ago")

## Examples

### Admin Example
1. A tenant adds a co-tenant request
2. Admin gets notification: "New Room Request - Tenant requested approval to add co-tenant"
3. Admin clicks notification → Redirects to Room Requests Queue page
4. Admin reviews and approves/rejects the request
5. Tenant gets notification about the decision

### Tenant Example
1. Tenant submits a payment
2. Admin receives notification: "New Payment Received - Payment awaits verification"
3. Admin verifies the payment
4. Tenant receives notification: "Payment Approved - Your payment has been verified and approved"
5. Tenant checks payment history to confirm

## Technical Details

### Files Changed
- `db/init.sql` - Added notifications table
- `db/notifications.php` - Helper functions
- `api_notifications.php` - API endpoint
- `templates/header.php` - Bell UI and modal
- `room_actions.php` - Room added notifications
- `bill_actions.php` - Payment notifications
- `admin_payment_verification.php` - Payment verification notifications
- `maintenance_actions.php` - Maintenance notifications
- `tenant_add_room.php` - Room request notifications
- `room_requests_queue.php` - Room request status notifications

### Database
- New `notifications` table with proper indexes for performance
- Tracks notification read status
- Links to related records (rooms, bills, requests, etc.)

## Troubleshooting

### I don't see the notification bell
- Check that you're logged in
- Clear browser cache (Ctrl+Shift+R)
- Check that JavaScript is enabled
- Check browser console for errors (F12 > Console)

### The modal doesn't open
- Try refreshing the page
- Check browser console for errors
- Make sure Bootstrap is loaded

### Notifications not showing up
- Make sure you're the recipient of the action
- Check that your session is active
- Try refreshing the page
- Check that api_notifications.php is accessible

### Badge count not updating
- Manually refresh the page
- Or wait 30 seconds for auto-refresh
- Check that JavaScript is enabled

## Tips & Tricks

1. **Badge shows 99+** - This means you have more than 99 unread notifications. Mark some as read!

2. **Empty notifications** - Once you have no unread notifications, the badge disappears

3. **Time display** - Notifications show "just now", "5m ago", "2h ago", etc. This auto-updates

4. **Auto-refresh** - The badge automatically refreshes every 30 seconds, so you'll see new notifications without manual refresh

5. **Click to navigate** - Clicking a notification not only marks it as read but also takes you to the relevant page

## What's Working

✅ Admin notifications for:
- New rooms
- New payments
- Maintenance requests
- Room requests

✅ Tenant notifications for:
- Payment verification/rejection
- Maintenance status updates
- Room request approval/rejection

✅ UI Features:
- Real-time badge updates
- Click-to-navigate
- Mark as read functionality
- Auto-refresh every 30 seconds
- Responsive design for all devices

## Future Enhancements

Planned for future versions:
- Email notifications
- SMS alerts
- Notification filtering/search
- Notification history archive
- Sound alerts option
- Notification preferences

## Questions?

Refer to the full documentation in [NOTIFICATION_SYSTEM_GUIDE.md](NOTIFICATION_SYSTEM_GUIDE.md)

---

**Enjoy your new notification system!** 🔔
