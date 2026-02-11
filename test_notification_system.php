<?php
/**
 * Test Notification System
 * Run this to verify notifications are being created correctly
 */

session_start();

// Use admin credentials for testing
$_SESSION['loggedin'] = true;
$_SESSION['role'] = 'admin';
$_SESSION['admin_id'] = 1;

require_once "db/database.php";
require_once "db_pdo.php";
require_once "db/notifications.php";

echo "<pre>\n";
echo "=== Notification System Test ===\n\n";

// Test 1: Check if notifications table exists
echo "TEST 1: Check notifications table\n";
try {
    $stmt = $conn->query("DESCRIBE notifications");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✓ Table exists with " . count($columns) . " columns\n";
    echo "Columns: " . implode(', ', array_column($columns, 'Field')) . "\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 2: Test createNotification function
echo "TEST 2: Create a test notification\n";
try {
    $notifId = createNotification(
        $pdo,
        'admin',
        1,
        'test_notification',
        '🧪 Test Notification',
        'This is a test notification to verify the system is working.',
        null,
        null,
        'index.php'
    );
    
    if ($notifId) {
        echo "✓ Notification created successfully with ID: $notifId\n\n";
    } else {
        echo "✗ Failed to create notification\n\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 3: Test getUnreadNotificationsCount function
echo "TEST 3: Get unread notification count for admin\n";
try {
    $count = getUnreadNotificationsCount($pdo, 'admin', 1);
    echo "✓ Unread count: $count\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 4: Test getNotifications function
echo "TEST 4: Get all notifications for admin\n";
try {
    $notifications = getNotifications($pdo, 'admin', 1, 5);
    echo "✓ Retrieved " . count($notifications) . " notifications\n";
    
    if (!empty($notifications)) {
        echo "\nRecent notifications:\n";
        foreach ($notifications as $notif) {
            echo "  - ID: {$notif['id']}, Type: {$notif['notification_type']}, Title: {$notif['title']}\n";
            echo "    Message: " . substr($notif['message'], 0, 50) . "...\n";
            echo "    Created: {$notif['created_at']}\n";
        }
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 5: Check if any tenants exist (for partial payment test)
echo "TEST 5: Check for test tenants\n";
try {
    $stmt = $conn->query("SELECT id, name FROM tenants LIMIT 5");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($tenants)) {
        echo "✓ Found " . count($tenants) . " tenant(s):\n";
        foreach ($tenants as $tenant) {
            echo "  - ID: {$tenant['id']}, Name: {$tenant['name']}\n";
        }
    } else {
        echo "! No tenants found in database\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 6: Check if any bills exist (for partial payment test)
echo "TEST 6: Check for bills\n";
try {
    $stmt = $conn->query("SELECT id, tenant_id, amount_due, amount_paid, status FROM bills LIMIT 5");
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($bills)) {
        echo "✓ Found " . count($bills) . " bill(s):\n";
        foreach ($bills as $bill) {
            echo "  - ID: {$bill['id']}, Tenant: {$bill['tenant_id']}, Due: ₱{$bill['amount_due']}, Paid: ₱{$bill['amount_paid']}, Status: {$bill['status']}\n";
        }
    } else {
        echo "! No bills found in database\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 7: Test notifyPartialPayment function (with dummy data)
echo "TEST 7: Test partial payment notification\n";
try {
    // Find a bill with amount_due > 0
    $stmt = $conn->prepare("SELECT id, tenant_id, amount_due FROM bills WHERE amount_due > 0 LIMIT 1");
    $stmt->execute();
    $bill = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($bill) {
        $partialAmount = $bill['amount_due'] * 0.5; // Pay 50%
        
        $result = notifyPartialPayment(
            $conn,
            $bill['tenant_id'],
            $bill['id'],
            $bill['amount_due'],
            $partialAmount,
            999 // Dummy transaction ID
        );
        
        if ($result) {
            echo "✓ Partial payment notification created successfully\n";
            echo "  Bill ID: {$bill['id']}, Tenant ID: {$bill['tenant_id']}\n";
            echo "  Amount Due: ₱{$bill['amount_due']}, Amount Paid: ₱$partialAmount\n";
        } else {
            echo "✗ Failed to create partial payment notification\n";
        }
    } else {
        echo "! No bills available for testing\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 8: Check tenant notification count
echo "TEST 8: Check tenant notification count\n";
try {
    $stmt = $pdo->query("SELECT DISTINCT recipient_id FROM notifications WHERE recipient_type = 'tenant'");
    $tenantNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($tenantNotifications)) {
        echo "✓ Notifications exist for " . count($tenantNotifications) . " tenant(s):\n";
        foreach ($tenantNotifications as $tn) {
            $count = getUnreadNotificationsCount($pdo, 'tenant', $tn['recipient_id']);
            echo "  - Tenant ID: {$tn['recipient_id']}, Unread count: $count\n";
        }
    } else {
        echo "! No tenant notifications found in database\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

echo "=== Test Complete ===\n";
echo "</pre>\n";
?>
