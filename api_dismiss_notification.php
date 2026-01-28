<?php
/**
 * AJAX endpoint to dismiss advance payment notification permanently
 * Called when tenant clicks the dismiss button on the advance payment notification
 */

session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Only allow tenants to use this endpoint
if (!isset($_SESSION["tenant_id"])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require_once "db/database.php";

header('Content-Type: application/json');

$action = isset($_GET['action']) ? $_GET['action'] : '';
$tenant_id = $_SESSION["tenant_id"];

switch ($action) {
    case 'dismiss_advance_payment':
        try {
            // Update the tenants table to mark advance payment notification as dismissed
            $stmt = $conn->prepare("
                UPDATE tenants 
                SET advance_payment_dismissed = 1 
                WHERE id = :tenant_id
            ");
            $stmt->execute(['tenant_id' => $tenant_id]);
            
            echo json_encode(['success' => true, 'message' => 'Notification dismissed']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
?>
