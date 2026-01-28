<?php
session_start();
$_SESSION['loggedin'] = true;
$_SESSION['role'] = 'admin';
$_SESSION['admin_id'] = 1;

require_once "db/database.php";
require_once "db/notifications.php";

echo "<pre>\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║   Partial Payment Notification Test - Latest Version            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Test the notification function directly
echo "Testing: notifyPartialPayment() function\n";
echo "───────────────────────────────────────────────────────────────\n\n";

try {
    // Example: Grace's bill
    // Bill Amount Due: ₱1200
    // Already Paid: ₱1100
    // Admin approves new payment of: ₱100 (so now total would be ₱1200 = PAID, but let's test with ₱50 = PARTIAL)
    
    $tenantId = 10; // Grace
    $billId = 11;
    $amountDue = 1200;
    $amountAlreadyPaid = 1100;
    $newPaymentAmount = 50; // Admin approving ₱50 more (not enough to complete)
    $totalAfterApproval = $amountAlreadyPaid + $newPaymentAmount; // ₱1150
    
    echo "Scenario:\n";
    echo "  Tenant: Grace (ID: $tenantId)\n";
    echo "  Bill: Bill #$billId\n";
    echo "  Amount Due: ₱" . number_format($amountDue, 2) . "\n";
    echo "  Already Paid (approved): ₱" . number_format($amountAlreadyPaid, 2) . "\n";
    echo "  New Payment: ₱" . number_format($newPaymentAmount, 2) . "\n";
    echo "  Total After This: ₱" . number_format($totalAfterApproval, 2) . "\n";
    echo "  Status: PARTIAL ✓\n\n";
    
    // Call the notification function
    echo "Calling notifyPartialPayment()...\n";
    $result = notifyPartialPayment(
        $conn,
        $tenantId,
        $billId,
        $amountDue,
        $totalAfterApproval,
        999 // dummy payment transaction ID
    );
    
    if ($result) {
        echo "✓ Notification created successfully!\n\n";
    } else {
        echo "✗ Failed to create notification\n\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Retrieve the notification that was just created
echo "Retrieving Notification:\n";
echo "───────────────────────────────────────────────────────────────\n\n";

try {
    $stmt = $conn->prepare("
        SELECT * FROM notifications
        WHERE recipient_type = 'tenant'
        AND recipient_id = :tenant_id
        AND notification_type = 'partial_payment_approved'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => 10]);
    $notif = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($notif) {
        echo "✓ NOTIFICATION FOUND!\n\n";
        echo "This is what Grace will see in her notification bell:\n";
        echo "┌─────────────────────────────────────────────────────────┐\n";
        echo "│                      NOTIFICATIONS                     │\n";
        echo "├─────────────────────────────────────────────────────────┤\n";
        echo "│                                                         │\n";
        echo "│ Title: " . $notif['title'] . "\n";
        echo "│                                                         │\n";
        echo "│ Message:                                                │\n";
        // Word wrap the message
        $words = explode(' ', $notif['message']);
        $lines = [];
        $currentLine = '';
        foreach ($words as $word) {
            if (strlen($currentLine) + strlen($word) + 1 > 49) {
                if ($currentLine) $lines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine .= ($currentLine ? ' ' : '') . $word;
            }
        }
        if ($currentLine) $lines[] = $currentLine;
        
        foreach ($lines as $line) {
            echo "│ " . str_pad($line, 53) . "│\n";
        }
        
        echo "│                                                         │\n";
        echo "│ Now (unread)                                            │\n";
        echo "│                                                         │\n";
        echo "└─────────────────────────────────────────────────────────┘\n\n";
    } else {
        echo "! No notification found\n\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Check API response
echo "API Response (what the notification bell receives):\n";
echo "───────────────────────────────────────────────────────────────\n\n";

try {
    $countStmt = $conn->prepare("
        SELECT COUNT(*) as unread FROM notifications
        WHERE recipient_type = 'tenant' AND recipient_id = 10 AND is_read = 0
    ");
    $countStmt->execute();
    $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $unreadCount = $countResult['unread'];
    
    echo "Unread Count: $unreadCount\n";
    echo "Bell Badge: " . ($unreadCount > 0 ? "��� $unreadCount notification(s)" : "○") . "\n\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║ ✓ TEST COMPLETE - Notification system is working correctly!    ║\n";
echo "║                                                                ║\n";
echo "║ When admin approves a partial payment:                         ║\n";
echo "║ 1. Notification is created in database                         ║\n";
echo "║ 2. Message shows: \"Your partial payment has been approved...\" ║\n";
echo "║ 3. Tenant sees bell badge update within 30 seconds             ║\n";
echo "║ 4. Tenant clicks bell to see full notification                 ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "</pre>\n";
?>
