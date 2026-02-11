<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once "db/database.php";
require_once "db_pdo.php";
require_once "db/notifications.php";

header('Content-Type: application/json');

$action = isset($_GET['action']) ? $_GET['action'] : '';
$recipientType = $_SESSION["role"] ?? null;
$recipientId = (($_SESSION["role"] === "admin") || ($_SESSION["role"] === "front_desk")) ? $_SESSION["admin_id"] : (isset($_SESSION["tenant_id"]) ? $_SESSION["tenant_id"] : null);

if (!$recipientType || !$recipientId || !isset($pdo)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user session']);
    exit;
}

switch ($action) {
    case 'get_count':
        $count = getUnreadNotificationsCount($pdo, $recipientType, $recipientId);
        echo json_encode(['count' => $count]);
        break;
    
    case 'get_notifications':
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        $notifications = getNotifications($pdo, $recipientType, $recipientId, $limit, $offset);
        echo json_encode(['notifications' => $notifications]);
        break;
    
    case 'mark_read':
        $notificationId = isset($_GET['notification_id']) ? (int)$_GET['notification_id'] : null;
        
        if ($notificationId) {
            markNotificationAsRead($pdo, $notificationId);
            $count = getUnreadNotificationsCount($pdo, $recipientType, $recipientId);
            echo json_encode(['success' => true, 'remaining_count' => $count]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Missing notification_id']);
        }
        break;
    
    case 'mark_all_read':
        markAllNotificationsAsRead($pdo, $recipientType, $recipientId);
        echo json_encode(['success' => true]);
        break;
    
    case 'delete':
        $notificationId = isset($_GET['notification_id']) ? (int)$_GET['notification_id'] : null;
        
        if ($notificationId) {
            deleteNotification($pdo, $notificationId);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Missing notification_id']);
        }
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
?>
