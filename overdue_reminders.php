<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

// Convert this page into a Notification Center
$recipientType = $_SESSION["role"] ?? null;
$recipientId = ($recipientType === 'admin') ? ($_SESSION['admin_id'] ?? null) : ($_SESSION['tenant_id'] ?? null);

$limit = 50;
$offset = 0;
$notifications = [];
$unreadCount = 0;
if ($recipientType && $recipientId) {
    $notifications = getNotifications($conn, $recipientType, $recipientId, $limit, $offset);
    $unreadCount = getUnreadNotificationsCount($conn, $recipientType, $recipientId);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Overdue Bills & Reminders</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="bi bi-bell-fill text-primary"></i>
                    Notification Center
                </h1>
                <div>
                    <button class="btn btn-outline-secondary me-2" id="markAllReadPage">Mark All Read</button>
                    <a href="overdue_reminders.php" class="btn btn-outline-primary">Refresh</a>
                </div>
            </div>

            <div class="mb-4">
                <h5 class="mb-3">Unread: <span class="badge bg-danger"><?php echo intval($unreadCount); ?></span></h5>

                <?php if (empty($notifications)): ?>
                    <div class="alert alert-info">No notifications yet.</div>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($notifications as $notif): ?>
                            <div class="list-group-item list-group-item-action <?php echo $notif['is_read'] == 0 ? 'list-group-item-info' : ''; ?> d-flex justify-content-between align-items-start" id="notif-<?php echo $notif['id']; ?>">
                                <div style="flex:1; cursor:pointer;" onclick="handleNotificationClick(<?php echo $notif['id']; ?>, '<?php echo $notif['action_url']; ?>')">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($notif['message']); ?></div>
                                    <div class="small text-muted mt-1"><?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></div>
                                </div>
                                <div class="ms-3 text-end">
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteNotificationHandler(event, <?php echo $notif['id']; ?>)"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<!-- Send Reminder Modal -->
<div class="modal fade" id="sendReminderModal" tabindex="-1" aria-labelledby="sendReminderModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sendReminderModalLabel">Send Payment Reminder</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="reminderForm">
          <input type="hidden" id="billId" name="bill_id">
          
          <div class="mb-3">
            <label class="form-label"><strong>Tenant Name</strong></label>
            <p class="form-control-plaintext" id="tenantName"></p>
          </div>
          
          <div class="mb-3">
            <label class="form-label"><strong>Email</strong></label>
            <p class="form-control-plaintext" id="tenantEmail"></p>
          </div>
          
          <div class="mb-3">
            <label class="form-label"><strong>Phone</strong></label>
            <p class="form-control-plaintext" id="tenantPhone"></p>
          </div>
          
          <div class="mb-3">
            <label for="reminderType" class="form-label">Reminder Type</label>
            <select class="form-control" id="reminderType" name="reminder_type" required>
                <option value="">Select reminder type</option>
                <option value="email">Email Reminder</option>
                <option value="sms">SMS Reminder</option>
                <option value="both">Both Email & SMS</option>
                <option value="manual">Manual (Notes Only)</option>
            </select>
          </div>
          
          <div class="mb-3">
            <label for="reminderMessage" class="form-label">Message</label>
            <textarea class="form-control" id="reminderMessage" name="message" rows="4" placeholder="Enter reminder message..."></textarea>
            <small class="text-muted">Leave blank to use default message.</small>
          </div>
          
          <p class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            <strong>Note:</strong> This is a reminder logging system. For automated SMS/email, configure your email/SMS gateway in settings.
          </p>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" onclick="sendReminder()">Send Reminder</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function setReminderData(name, email, phone, billId) {
    document.getElementById('tenantName').textContent = name;
    document.getElementById('tenantEmail').textContent = email;
    document.getElementById('tenantPhone').textContent = phone;
    document.getElementById('billId').value = billId;
    document.getElementById('reminderMessage').value = '';
}

function sendReminder() {
    const billId = document.getElementById('billId').value;
    const reminderType = document.getElementById('reminderType').value;
    const message = document.getElementById('reminderMessage').value;
    
    if (!reminderType) {
        alert('Please select a reminder type');
        return;
    }
    
    // Here you would send the reminder via AJAX or form submission
    // For now, we'll just show a confirmation
    alert('Reminder queued for sending:\nType: ' + reminderType + '\nMessage: ' + (message || 'Default message'));
    
    // In a real implementation, send via AJAX:
    // fetch('reminder_actions.php', {
    //     method: 'POST',
    //     headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    //     body: 'action=send&bill_id=' + billId + '&type=' + reminderType + '&message=' + encodeURIComponent(message)
    // }).then(response => response.text()).then(data => {
    //     alert(data);
    //     location.reload();
    // });
    
    const modal = bootstrap.Modal.getInstance(document.getElementById('sendReminderModal'));
    modal.hide();
}

  // Notification actions for this page
  function deleteNotificationHandler(event, notificationId) {
    event.stopPropagation();
    if (!confirm('Delete this notification permanently?')) return;
    fetch('api_notifications.php?action=delete&notification_id=' + notificationId)
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          const el = document.getElementById('notif-' + notificationId);
          if (el) el.remove();
          // optional: reload to refresh counts
          setTimeout(() => location.reload(), 300);
        } else {
          alert('Failed to delete notification');
        }
      }).catch(err => { console.error(err); alert('Error deleting notification'); });
  }

  function handleNotificationClick(notificationId, actionUrl) {
    fetch('api_notifications.php?action=mark_read&notification_id=' + notificationId)
      .then(r => r.json())
      .then(data => {
        // navigate if actionUrl provided
        if (actionUrl && actionUrl.length > 0) {
          window.location.href = actionUrl;
        } else {
          // reload to reflect read state
          setTimeout(() => location.reload(), 100);
        }
      }).catch(err => { console.error(err); });
  }

  document.getElementById('markAllReadPage').addEventListener('click', function() {
    if (!confirm('Mark all notifications as read?')) return;
    fetch('api_notifications.php?action=mark_all_read')
      .then(r => r.json())
      .then(data => {
        if (data.success) location.reload();
      });
  });
</script>
</body>
</html>
