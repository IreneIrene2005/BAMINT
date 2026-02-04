
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../db/notifications.php";

// Get current user info for notifications
$recipientType = isset($_SESSION["role"]) ? $_SESSION["role"] : null;
$recipientId = ($recipientType === "admin") ? ($_SESSION["admin_id"] ?? null) : (isset($_SESSION["tenant_id"]) ? $_SESSION["tenant_id"] : null);
$unreadCount = 0;

if ($recipientType && $recipientId) {
    $unreadCount = getUnreadNotificationsCount($conn, $recipientType, $recipientId);
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'dashboard.php' : 'tenant_dashboard.php'; ?>">BAMINT</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <button class="btn btn-link nav-link position-relative" data-bs-toggle="modal" data-bs-target="#notificationsModal" id="notificationBell" title="Notifications">
                        <i class="bi bi-bell" style="font-size: 1.5rem; color: white;"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?>
                            </span>
                        <?php endif; ?>
                    </button>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Notifications Modal -->
<div class="modal fade" id="notificationsModal" tabindex="-1" aria-labelledby="notificationsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="notificationsModalLabel">
                    <i class="bi bi-bell"></i> Notifications
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="notificationsContainer" style="max-height: 500px; overflow-y: auto;">
                <div class="text-center py-4">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="markAllReadBtn">Mark All as Read</button>
            </div>
        </div>
    </div>
</div>

<style>
    .notification-item {
        padding: 12px 15px;
        border-bottom: 1px solid #eee;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    
    .notification-item:hover {
        background-color: #f5f5f5;
    }
    
    .notification-item.unread {
        background-color: #e8f4f8;
        font-weight: 500;
    }
    
    .notification-item.unread::before {
        content: '';
        display: inline-block;
        width: 8px;
        height: 8px;
        background-color: #007bff;
        border-radius: 50%;
        margin-right: 10px;
    }
    
    .notification-title {
        font-weight: 600;
        margin-bottom: 4px;
    }
    
    .notification-message {
        font-size: 0.9rem;
        color: #666;
        margin-bottom: 4px;
    }
    
    .notification-time {
        font-size: 0.8rem;
        color: #999;
    }
    
    .notification-item .btn-link {
        opacity: 0.6;
        transition: opacity 0.2s;
        padding: 0.25rem 0.5rem;
    }
    
    .notification-item:hover .btn-link {
        opacity: 1;
    }
    
    .notification-item .btn-link:hover {
        opacity: 1 !important;
        text-decoration: none;
    }
    
    .no-notifications {
        text-align: center;
        padding: 30px 20px;
        color: #999;
    }
</style>

<script>
// Load notifications when modal is shown
document.getElementById('notificationsModal').addEventListener('show.bs.modal', function() {
    loadNotifications();
});

// Mark all as read button
document.getElementById('markAllReadBtn').addEventListener('click', function() {
    markAllNotificationsAsRead();
});

function loadNotifications() {
    fetch('api_notifications.php?action=get_notifications')
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('notificationsContainer');
            
            if (data.notifications && data.notifications.length > 0) {
                let html = '';
                data.notifications.forEach(notif => {
                    const isUnread = notif.is_read == 0 ? 'unread' : '';
                    const timeAgo = getTimeAgo(notif.created_at);
                    
                    html += `
                        <div class="notification-item ${isUnread}" id="notif-${notif.id}">
                            <div class="d-flex justify-content-between align-items-start">
                                <div style="flex: 1;" onclick="handleNotificationClick(${notif.id}, '${notif.action_url}')">
                                    <div class="notification-title">${notif.title}</div>
                                    <div class="notification-message">${notif.message}</div>
                                    <div class="notification-time">${timeAgo}</div>
                                </div>
                                <button class="btn btn-sm btn-link text-danger ms-2" title="Delete notification" onclick="deleteNotificationHandler(event, ${notif.id})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="no-notifications"><i class="bi bi-bell-slash" style="font-size: 3rem; display: block; margin-bottom: 10px;"></i>No notifications yet</div>';
            }
        })
        .catch(error => {
            console.error('Error loading notifications:', error);
            document.getElementById('notificationsContainer').innerHTML = '<div class="alert alert-danger">Error loading notifications</div>';
        });
}

function deleteNotificationHandler(event, notificationId) {
    event.stopPropagation();
    
    if (confirm('Delete this notification permanently?')) {
        fetch('api_notifications.php?action=delete&notification_id=' + notificationId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove notification from DOM
                    const notifElement = document.getElementById('notif-' + notificationId);
                    if (notifElement) {
                        notifElement.remove();
                    }
                    
                    // Reload notifications to update list
                    loadNotifications();
                    
                    // Update badge count
                    updateNotificationBadge();
                    
                    // Check if no notifications left
                    const container = document.getElementById('notificationsContainer');
                    if (container.children.length === 0) {
                        container.innerHTML = '<div class="no-notifications"><i class="bi bi-bell-slash" style="font-size: 3rem; display: block; margin-bottom: 10px;"></i>No notifications yet</div>';
                    }
                } else {
                    alert('Error deleting notification');
                }
            })
            .catch(error => {
                console.error('Error deleting notification:', error);
                alert('Error deleting notification');
            });
    }
}

function handleNotificationClick(notificationId, actionUrl) {
    // Mark as read
    fetch('api_notifications.php?action=mark_read&notification_id=' + notificationId)
        .then(response => response.json())
        .then(data => {
            // Update badge count
            updateNotificationBadge();
            
            // Navigate if action URL exists
            if (actionUrl) {
                window.location.href = actionUrl;
            } else {
                loadNotifications();
            }
        });
}

function markAllNotificationsAsRead() {
    fetch('api_notifications.php?action=mark_all_read')
        .then(response => response.json())
        .then(data => {
            loadNotifications();
            updateNotificationBadge();
        });
}

function updateNotificationBadge() {
    fetch('api_notifications.php?action=get_count')
        .then(response => response.json())
        .then(data => {
            const badge = document.querySelector('#notificationBell .badge');
            if (data.count > 0) {
                if (badge) {
                    badge.textContent = data.count > 99 ? '99+' : data.count;
                } else {
                    const newBadge = document.createElement('span');
                    newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                    newBadge.textContent = data.count > 99 ? '99+' : data.count;
                    document.getElementById('notificationBell').appendChild(newBadge);
                }
            } else {
                if (badge) {
                    badge.remove();
                }
            }
        });
}

function getTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
    if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
    if (seconds < 604800) return Math.floor(seconds / 86400) + 'd ago';
    
    return date.toLocaleDateString();
}

// Refresh notifications every 30 seconds
setInterval(updateNotificationBadge, 30000);
</script>