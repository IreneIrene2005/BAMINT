<?php
/**
 * Notifications Helper Functions
 * Manages all notification-related operations
 */

/**
 * Create a notification
 * @param PDO $conn Database connection
 * @param string $recipientType 'admin' or 'tenant'
 * @param int $recipientId Admin ID or Tenant ID
 * @param string $type Notification type (room_added, payment_made, etc)
 * @param string $title Notification title
 * @param string $message Notification message
 * @param int|null $relatedId Related record ID
 * @param string|null $relatedType Related record type
 * @param string|null $actionUrl URL to navigate to
 * @return bool|int Returns notification ID on success, false on failure
 */
function createNotification($conn, $recipientType, $recipientId, $type, $title, $message, $relatedId = null, $relatedType = null, $actionUrl = null) {
    try {
        $sql = "INSERT INTO notifications (
                    recipient_type, 
                    recipient_id, 
                    notification_type, 
                    title, 
                    message, 
                    related_id, 
                    related_type, 
                    action_url,
                    created_at
                ) VALUES (
                    :recipient_type,
                    :recipient_id,
                    :notification_type,
                    :title,
                    :message,
                    :related_id,
                    :related_type,
                    :action_url,
                    NOW()
                )";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':recipient_type' => $recipientType,
            ':recipient_id' => $recipientId,
            ':notification_type' => $type,
            ':title' => $title,
            ':message' => $message,
            ':related_id' => $relatedId,
            ':related_type' => $relatedType,
            ':action_url' => $actionUrl
        ]);
        
        return $conn->lastInsertId();
    } catch (Exception $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Get unread notifications count for a user
 * @param PDO $conn Database connection
 * @param string $recipientType 'admin' or 'tenant'
 * @param int $recipientId Admin ID or Tenant ID
 * @return int Count of unread notifications
 */
function getUnreadNotificationsCount($conn, $recipientType, $recipientId) {
    try {
        $sql = "SELECT COUNT(*) as count FROM notifications 
                WHERE recipient_type = :recipient_type 
                AND recipient_id = :recipient_id 
                AND is_read = 0";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':recipient_type' => $recipientType,
            ':recipient_id' => $recipientId
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['count'];
    } catch (Exception $e) {
        error_log("Error getting unread notifications count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get all notifications for a user (paginated)
 * @param PDO $conn Database connection
 * @param string $recipientType 'admin' or 'tenant'
 * @param int $recipientId Admin ID or Tenant ID
 * @param int $limit Number of notifications to retrieve
 * @param int $offset Offset for pagination
 * @return array Array of notifications
 */
function getNotifications($conn, $recipientType, $recipientId, $limit = 10, $offset = 0) {
    try {
        $sql = "SELECT * FROM notifications 
                WHERE recipient_type = :recipient_type 
                AND recipient_id = :recipient_id 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':recipient_type', $recipientType, PDO::PARAM_STR);
        $stmt->bindParam(':recipient_id', $recipientId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting notifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark notification as read
 * @param PDO $conn Database connection
 * @param int $notificationId Notification ID
 * @return bool Success status
 */
function markNotificationAsRead($conn, $notificationId) {
    try {
        $sql = "UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE id = :id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $notificationId]);
        
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Error marking notification as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all notifications as read for a user
 * @param PDO $conn Database connection
 * @param string $recipientType 'admin' or 'tenant'
 * @param int $recipientId Admin ID or Tenant ID
 * @return bool Success status
 */
function markAllNotificationsAsRead($conn, $recipientType, $recipientId) {
    try {
        $sql = "UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE recipient_type = :recipient_type 
                AND recipient_id = :recipient_id 
                AND is_read = 0";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':recipient_type' => $recipientType,
            ':recipient_id' => $recipientId
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Error marking all notifications as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Get notification by ID
 * @param PDO $conn Database connection
 * @param int $notificationId Notification ID
 * @return array|null Notification data or null if not found
 */
function getNotificationById($conn, $notificationId) {
    try {
        $sql = "SELECT * FROM notifications WHERE id = :id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $notificationId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting notification by ID: " . $e->getMessage());
        return null;
    }
}

/**
 * Delete notification
 * @param PDO $conn Database connection
 * @param int $notificationId Notification ID
 * @return bool Success status
 */
function deleteNotification($conn, $notificationId) {
    try {
        $sql = "DELETE FROM notifications WHERE id = :id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $notificationId]);
        
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Error deleting notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete all notifications for a user
 * @param PDO $conn Database connection
 * @param string $recipientType 'admin' or 'tenant'
 * @param int $recipientId Admin ID or Tenant ID
 * @return bool Success status
 */
function deleteAllNotifications($conn, $recipientType, $recipientId) {
    try {
        $sql = "DELETE FROM notifications 
                WHERE recipient_type = :recipient_type 
                AND recipient_id = :recipient_id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':recipient_type' => $recipientType,
            ':recipient_id' => $recipientId
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Error deleting all notifications: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify all admins about a new room
 * @param PDO $conn Database connection
 * @param int $roomId Room ID
 * @param string $roomNumber Room number
 * @return bool Success status
 */
function notifyAdminsNewRoom($conn, $roomId, $roomNumber) {
    try {
        // Get all admins
        $sql = "SELECT id FROM admins";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($admins as $admin) {
            createNotification(
                $conn,
                'admin',
                $admin['id'],
                'room_added',
                'New Room Added',
                'A new room ' . $roomNumber . ' has been added to the system.',
                $roomId,
                'room',
                'rooms.php'
            );
        }
        return true;
    } catch (Exception $e) {
        error_log("Error notifying admins about new room: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify tenant about payment verification
 * @param PDO $conn Database connection
 * @param int $tenantId Tenant ID
 * @param int $paymentId Payment transaction ID
 * @param string $status Payment status (approved, rejected, etc)
 * @return bool Success status
 */
function notifyTenantPaymentVerification($conn, $tenantId, $paymentId, $status) {
    try {
        // Get payment and bill details to check if it's advance payment
        $payment_stmt = $conn->prepare("
            SELECT pt.bill_id, b.notes, b.amount_due, b.billing_month
            FROM payment_transactions pt
            JOIN bills b ON pt.bill_id = b.id
            WHERE pt.id = :id
        ");
        $payment_stmt->execute([':id' => $paymentId]);
        $payment_details = $payment_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if this is an advance payment (move-in)
        $isAdvancePayment = $payment_details && strpos($payment_details['notes'], 'ADVANCE PAYMENT') !== false;
        
        if ($isAdvancePayment) {
            $title = $status === 'approved' ? '✅ Advance Payment Approved!' : 'Advance Payment Status Update';
            $message = $status === 'approved' 
                ? 'Your advance payment of ₱' . number_format($payment_details['amount_due'], 2) . ' has been verified and approved by admin. You can now move in!' 
                : 'Your advance payment status has been updated.';
        } else {
            $title = $status === 'approved' ? 'Payment Approved' : 'Payment Status Update';
            $message = $status === 'approved' 
                ? 'Your payment has been verified and approved.' 
                : 'Your payment status has been updated.';
        }
        
        createNotification(
            $conn,
            'tenant',
            $tenantId,
            'payment_verified',
            $title,
            $message,
            $paymentId,
            'payment_transaction',
            'payment_history.php'
        );
        return true;
    } catch (Exception $e) {
        error_log("Error notifying tenant about payment: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify all admins about new payment
 * @param PDO $conn Database connection
 * @param int $billId Bill ID
 * @param int $tenantId Tenant ID
 * @param float $amount Payment amount
 * @return bool Success status
 */
function notifyAdminsNewPayment($conn, $billId, $tenantId, $amount) {
    try {
        // Get tenant name
        $stmt = $conn->prepare("SELECT name FROM tenants WHERE id = :id");
        $stmt->execute([':id' => $tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        $tenantName = $tenant ? $tenant['name'] : 'Unknown Tenant';
        
        // Get all admins
        $sql = "SELECT id FROM admins";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($admins as $admin) {
            createNotification(
                $conn,
                'admin',
                $admin['id'],
                'payment_made',
                'New Payment Received',
                'Payment of $' . number_format($amount, 2) . ' from ' . $tenantName . ' awaits verification.',
                $billId,
                'bill',
                'admin_payment_verification.php'
            );
        }
        return true;
    } catch (Exception $e) {
        error_log("Error notifying admins about new payment: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify tenant about maintenance request approval/status change
 * @param PDO $conn Database connection
 * @param int $tenantId Tenant ID
 * @param int $maintenanceId Maintenance request ID
 * @param string $status New status
 * @return bool Success status
 */
function notifyTenantMaintenanceStatus($conn, $tenantId, $maintenanceId, $status) {
    try {
        $statusText = ucfirst($status);
        
        createNotification(
            $conn,
            'tenant',
            $tenantId,
            'maintenance_approved',
            'Maintenance Request ' . $statusText,
            'Your maintenance request has been ' . strtolower($statusText) . '.',
            $maintenanceId,
            'maintenance_request',
            'tenant_maintenance.php'
        );
        return true;
    } catch (Exception $e) {
        error_log("Error notifying tenant about maintenance: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify all admins about new maintenance request
 * @param PDO $conn Database connection
 * @param int $maintenanceId Maintenance request ID
 * @param int $tenantId Tenant ID
 * @param string $category Maintenance category
 * @return bool Success status
 */
function notifyAdminsNewMaintenance($conn, $maintenanceId, $tenantId, $category) {
    try {
        // Get tenant name
        $stmt = $conn->prepare("SELECT name FROM tenants WHERE id = :id");
        $stmt->execute([':id' => $tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        $tenantName = $tenant ? $tenant['name'] : 'Unknown Tenant';
        
        // Get all admins
        $sql = "SELECT id FROM admins";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($admins as $admin) {
            createNotification(
                $conn,
                'admin',
                $admin['id'],
                'maintenance_request',
                'New Maintenance Request',
                'New ' . $category . ' maintenance request from ' . $tenantName . '.',
                $maintenanceId,
                'maintenance_request',
                'admin_maintenance_queue.php'
            );
        }
        return true;
    } catch (Exception $e) {
        error_log("Error notifying admins about maintenance: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify tenant about room request approval
 * @param PDO $conn Database connection
 * @param int $tenantId Tenant ID
 * @param int $roomRequestId Room request ID
 * @param string $status Approval status (approved, rejected, etc)
 * @return bool Success status
 */
function notifyTenantRoomRequestStatus($conn, $tenantId, $roomRequestId, $status) {
    try {
        $statusText = $status === 'approved' ? 'Approved' : ucfirst($status);
        $message = $status === 'approved' 
            ? 'Your co-tenant room request has been approved!' 
            : 'Your co-tenant room request has been ' . strtolower($statusText) . '.';
        
        createNotification(
            $conn,
            'tenant',
            $tenantId,
            'room_request_approved',
            'Room Request ' . $statusText,
            $message,
            $roomRequestId,
            'room_request',
            'tenant_dashboard.php'
        );
        return true;
    } catch (Exception $e) {
        error_log("Error notifying tenant about room request: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify all admins about new room request
 * @param PDO $conn Database connection
 * @param int $roomRequestId Room request ID
 * @param int $tenantId Tenant ID
 * @param int $tenantCount Number of co-tenants requested
 * @return bool Success status
 */
function notifyAdminsNewRoomRequest($conn, $roomRequestId, $tenantId, $tenantCount) {
    try {
        // Get tenant name
        $stmt = $conn->prepare("SELECT name FROM tenants WHERE id = :id");
        $stmt->execute([':id' => $tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        $tenantName = $tenant ? $tenant['name'] : 'Unknown Tenant';
        
        // Get all admins
        $sql = "SELECT id FROM admins";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $coTenantText = $tenantCount == 1 ? '1 co-tenant' : $tenantCount . ' co-tenants';
        
        foreach ($admins as $admin) {
            createNotification(
                $conn,
                'admin',
                $admin['id'],
                'room_request',
                'New Room Request',
                $tenantName . ' requested approval to add ' . $coTenantText . '.',
                $roomRequestId,
                'room_request',
                'room_requests_queue.php'
            );
        }
        return true;
    } catch (Exception $e) {
        error_log("Error notifying admins about room request: " . $e->getMessage());
        return false;
    }
}

/**
 * Add maintenance cost to tenant's next monthly bill
 * Creates bill if it doesn't exist, updates if it does
 * @param PDO $conn Database connection
 * @param int $tenantId Tenant ID
 * @param decimal $cost Maintenance cost in ₱
 * @return bool Success status
 */
function addMaintenanceCostToBill($conn, $tenantId, $cost, $requestId = null, $requestCategory = null) {
    if (!$tenantId || !$cost || $cost <= 0) {
        return false;
    }

    try {
        // Get tenant's room_id
        $tenantStmt = $conn->prepare("SELECT room_id FROM tenants WHERE id = :tenant_id");
        $tenantStmt->execute(['tenant_id' => $tenantId]);
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tenant || !$tenant['room_id']) {
            return false;
        }

        $roomId = $tenant['room_id'];

        // Determine billing month later (we target the current month by default)
        // Prepare note text if request info provided
        $noteText = null;
        if ($requestId !== null || $requestCategory !== null) {
            $noteText = 'Amenity: ' . ($requestCategory ?? 'amenity') . ' (Request #' . intval($requestId) . ')';
        }

        // If request ID provided, check if this request is already referenced in any bill for this tenant (avoid double billing)
        if ($requestId !== null) {
            $pattern = '%' . 'Request #' . intval($requestId) . '%';
            $existingCheck = $conn->prepare("SELECT id FROM bills WHERE tenant_id = :tenant_id AND notes LIKE :pattern LIMIT 1");
            $existingCheck->execute([
                'tenant_id' => $tenantId,
                'pattern' => $pattern
            ]);
            $existingBill = $existingCheck->fetch(PDO::FETCH_ASSOC);
            if ($existingBill) {
                // Already billed and referenced; nothing to do
                return $existingBill['id'];
            }
        }

        // Target billing month: current month (first day)
        $targetMonth = date('Y-m-01');

        // Prefer attaching to an existing active bill for this tenant (avoid creating duplicate bills for same customer)
        // Active = not paid OR paid but within recent grace interval (7 days) — matches archive logic used in listing
        $activeStmt = $conn->prepare("SELECT id, amount_due, notes, billing_month FROM bills WHERE tenant_id = :tenant_id AND (status != 'paid' OR DATE_ADD(updated_at, INTERVAL 7 DAY) >= NOW()) ORDER BY billing_month DESC LIMIT 1");
        $activeStmt->execute(['tenant_id' => $tenantId]);
        $bill = $activeStmt->fetch(PDO::FETCH_ASSOC);
        // Debug: log whether an active bill was found for this tenant
        error_log("addMaintenanceCostToBill: tenant {$tenantId} active bill: " . json_encode($bill));

        // If no active bill found, fall back to checking the current month bill
        if (!$bill) {
            $billStmt = $conn->prepare("
                SELECT id, amount_due, notes, billing_month FROM bills 
                WHERE tenant_id = :tenant_id AND billing_month = :billing_month
            ");
            $billStmt->execute([
                'tenant_id' => $tenantId,
                'billing_month' => $targetMonth
            ]);
            $bill = $billStmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($bill) {
            // Update existing bill by adding cost
            $updateStmt = $conn->prepare("
                UPDATE bills 
                SET amount_due = amount_due + :cost,
                    updated_at = NOW()
                WHERE id = :bill_id
            ");
            $updateStmt->execute([
                'cost' => $cost,
                'bill_id' => $bill['id']
            ]);

            // Debug: record that we appended this amenity to an existing bill
            error_log("addMaintenanceCostToBill: appended cost {$cost} to bill {$bill['id']} for tenant {$tenantId}");

            // Append note if available, avoid duplicate note text
            if ($noteText) {
                $existingNotes = $bill['notes'] ?? '';
                $newNotes = $existingNotes;
                if ($noteText && stripos($existingNotes, $noteText) === false) {
                    $newNotes = trim($existingNotes . ' | ' . $noteText, " |\t\n\r");
                }
                $noteStmt = $conn->prepare("UPDATE bills SET notes = :notes WHERE id = :bill_id");
                $noteStmt->execute(['notes' => $newNotes, 'bill_id' => $bill['id']]);
            }

            // Record itemized entry in bill_items and mark maintenance request as billed
            try {
                if ($requestId !== null) {
                    $itemStmt = $conn->prepare("INSERT INTO bill_items (bill_id, request_id, tenant_id, description, amount) VALUES (:bill_id, :request_id, :tenant_id, :description, :amount)");
                    $itemStmt->execute([
                        'bill_id' => $bill['id'],
                        'request_id' => intval($requestId),
                        'tenant_id' => $tenantId,
                        'description' => $noteText,
                        'amount' => $cost
                    ]);

                    // Update maintenance_requests to mark billed and reference bill
                    $updReq = $conn->prepare("UPDATE maintenance_requests SET billed = 1, billed_bill_id = :bill_id WHERE id = :request_id");
                    $updReq->execute(['bill_id' => $bill['id'], 'request_id' => intval($requestId)]);
                }
            } catch (Exception $e) {
                // Ignore item recording failures but log
                error_log('Failed to record bill item: ' . $e->getMessage());
            }

            return $bill['id'];
        } else {
            // Create new bill for next month with maintenance cost
            // Get room rate
            $roomStmt = $conn->prepare("SELECT rate FROM rooms WHERE id = :room_id");
            $roomStmt->execute(['room_id' => $roomId]);
            $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
            
            $roomRate = $room['rate'] ?? 0;
            // For a standalone bill created only because no active bill existed, only charge the additional amenity (do not duplicate room rate)
            $totalAmount = $cost;

            $insertSql = "
                INSERT INTO bills 
                (tenant_id, room_id, billing_month, amount_due, amount_paid, status, notes, created_at, updated_at)
                VALUES (:tenant_id, :room_id, :billing_month, :amount_due, 0, 'pending', :notes, NOW(), NOW())
            ";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->execute([
                'tenant_id' => $tenantId,
                'room_id' => $roomId,
                'billing_month' => $targetMonth,
                'amount_due' => $totalAmount,
                'notes' => $noteText
            ]);

            $newBillId = $conn->lastInsertId();
            // Record bill item and mark maintenance request billed
            try {
                if ($requestId !== null) {
                    $itemStmt = $conn->prepare("INSERT INTO bill_items (bill_id, request_id, tenant_id, description, amount) VALUES (:bill_id, :request_id, :tenant_id, :description, :amount)");
                    $itemStmt->execute([
                        'bill_id' => $newBillId,
                        'request_id' => intval($requestId),
                        'tenant_id' => $tenantId,
                        'description' => $noteText,
                        'amount' => $cost
                    ]);

                    $updReq = $conn->prepare("UPDATE maintenance_requests SET billed = 1, billed_bill_id = :bill_id WHERE id = :request_id");
                    $updReq->execute(['bill_id' => $newBillId, 'request_id' => intval($requestId)]);
                }
            } catch (Exception $e) {
                error_log('Failed to record bill item for new bill: ' . $e->getMessage());
            }

            return $newBillId;
        }

        return false;
    } catch (Exception $e) {
        error_log("Error adding maintenance cost to bill: " . $e->getMessage());
        return false;
    }
}

/**
 * Send message from admin/tenant to another user
 * @param PDO $conn Database connection
 * @param string $senderType 'admin' or 'tenant'
 * @param int $senderId admin_id or tenant_id
 * @param string $recipientType 'admin' or 'tenant'
 * @param int $recipientId admin_id or tenant_id
 * @param string $subject Message subject
 * @param string $text Message content
 * @param string|null $relatedType Related record type (bill, payment_transaction, etc)
 * @param int|null $relatedId Related record ID
 * @return bool Success status
 */
function sendMessage($conn, $senderType, $senderId, $recipientType, $recipientId, $subject, $text, $relatedType = null, $relatedId = null) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO messages 
            (sender_type, sender_id, recipient_type, recipient_id, subject, message, related_type, related_id, created_at)
            VALUES (:sender_type, :sender_id, :recipient_type, :recipient_id, :subject, :message, :related_type, :related_id, NOW())
        ");
        
        return $stmt->execute([
            ':sender_type' => $senderType,
            ':sender_id' => $senderId,
            ':recipient_type' => $recipientType,
            ':recipient_id' => $recipientId,
            ':subject' => $subject,
            ':message' => $text,
            ':related_type' => $relatedType,
            ':related_id' => $relatedId
        ]);
    } catch (Exception $e) {
        error_log("Error sending message: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify tenant and admin about partial payment
 * @param PDO $conn Database connection
 * @param int $tenantId Tenant ID
 * @param int $billId Bill ID
 * @param decimal $amountDue Total amount due
 * @param decimal $amountPaid Amount paid
 * @param int $paymentTransactionId Payment transaction ID
 * @return bool Success status
 */
function notifyPartialPayment($conn, $tenantId, $billId, $amountDue, $amountPaid, $paymentTransactionId) {
    try {
        $remainingBalance = $amountDue - $amountPaid;
        
        // Get bill details for tenant notification
        $billStmt = $conn->prepare("SELECT billing_month FROM bills WHERE id = :id");
        $billStmt->execute(['id' => $billId]);
        $bill = $billStmt->fetch(PDO::FETCH_ASSOC);
        $billingMonth = $bill ? date('F Y', strtotime($bill['billing_month'])) : 'current month';
        
        // Admin notification message
        $adminNotifyMsg = "Payment received: ₱" . number_format($amountPaid, 2) . " but ₱" . number_format($remainingBalance, 2) . " still due";
        
        // Tenant notification message - your partial payment has been approved
        $tenantNotifyMsg = "Your partial payment has been approved. Kindly settle the remaining balance of ₱" . 
                          number_format($remainingBalance, 2) . " to complete your monthly bill payment.";
        
        // Get tenant name and admin IDs
        $tenantStmt = $conn->prepare("SELECT name FROM tenants WHERE id = :id");
        $tenantStmt->execute(['id' => $tenantId]);
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
        $tenantName = $tenant['name'] ?? 'Tenant #' . $tenantId;
        
        $adminStmt = $conn->query("SELECT id FROM admins");
        $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Notify all admins
        foreach ($admins as $admin) {
            createNotification($conn, 'admin', $admin['id'], 'partial_payment', 
                'Partial Payment from ' . $tenantName, 
                $adminNotifyMsg, 
                $billId, 'bill', 'admin_payment_verification.php');
        }
        
        // Notify tenant with approval message
        createNotification($conn, 'tenant', $tenantId, 'partial_payment_approved',
            'Partial Payment Approved',
            $tenantNotifyMsg,
            $billId, 'bill', 'tenant_bills.php');
        
        return true;
    } catch (Exception $e) {
        error_log("Error notifying partial payment: " . $e->getMessage());
        return false;
    }
}
?>
