<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "tenant") {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";

$tenant_id = $_SESSION["tenant_id"];

try {
    // Fetch messages for this tenant
    $stmt = $conn->prepare("
        SELECT m.*, 
               CASE WHEN m.sender_type = 'admin' THEN a.username ELSE t.name END as sender_name
        FROM messages m
        LEFT JOIN admins a ON m.sender_type = 'admin' AND m.sender_id = a.id
        LEFT JOIN tenants t ON m.sender_type = 'tenant' AND m.sender_id = t.id
        WHERE m.recipient_type = 'tenant' AND m.recipient_id = :tenant_id
        ORDER BY m.created_at DESC
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark all as read when viewing inbox
    if (!empty($messages)) {
        $updateStmt = $conn->prepare("
            UPDATE messages 
            SET is_read = 1, read_at = NOW()
            WHERE recipient_type = 'tenant' AND recipient_id = :tenant_id AND is_read = 0
        ");
        $updateStmt->execute(['tenant_id' => $tenant_id]);
    }
} catch (Exception $e) {
    $error = "Error loading messages: " . $e->getMessage();
    $messages = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar {
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
            color: white;
            min-height: 100vh;
            padding: 2rem 0;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.7);
            padding: 1rem 1.5rem;
            border-left: 3px solid transparent;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
            border-left-color: white;
        }
        .user-info {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 1rem;
        }
        .user-info h5 { margin-bottom: 0.25rem; }
        .user-info p { font-size: 0.9rem; opacity: 0.8; margin-bottom: 0; }
        .message-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .message-item:hover {
            background-color: #f0f0f0;
        }
        .message-item.unread {
            background-color: #e8f4f8;
            font-weight: 600;
        }
        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }
        .message-sender {
            font-weight: 600;
            color: #333;
        }
        .message-date {
            font-size: 0.85rem;
            color: #999;
        }
        .message-subject {
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }
        .message-preview {
            font-size: 0.9rem;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .btn-logout {
            background: #dc3545;
            color: white;
            border: none;
            margin-top: 1rem;
            width: 100%;
        }
        .btn-logout:hover {
            background: #c82333;
            color: white;
        }
    </style>
</head>
<body>
    <?php include 'templates/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'templates/tenant_sidebar.php'; ?>

                    <form action="logout.php" method="post">
                        <button type="submit" class="btn btn-logout">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </form>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="header-banner">
                    <h1><i class="bi bi-envelope"></i> Messages</h1>
                    <p class="mb-0">Receive important messages from management</p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Messages List -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-inbox"></i> Your Messages</h6>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php if (!empty($messages)): ?>
                            <?php foreach ($messages as $msg): ?>
                            <div class="message-item <?php echo $msg['is_read'] ? '' : 'unread'; ?>" onclick="expandMessage(this)">
                                <div class="message-header">
                                    <div>
                                        <div class="message-sender"><i class="bi bi-person-fill"></i> <?php echo htmlspecialchars($msg['sender_name']); ?></div>
                                        <div class="message-subject"><?php echo htmlspecialchars($msg['subject']); ?></div>
                                        <div class="message-preview"><?php echo htmlspecialchars(substr($msg['message'], 0, 100)); ?></div>
                                    </div>
                                    <div class="message-date"><?php echo date('M d, Y H:i', strtotime($msg['created_at'])); ?></div>
                                </div>
                                <div style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                                    <div style="white-space: pre-wrap; line-height: 1.6; color: #333;">
                                        <?php echo htmlspecialchars($msg['message']); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">
                                <i class="bi bi-inbox" style="font-size: 2rem; opacity: 0.5;"></i>
                                <p class="mt-3">No messages yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function expandMessage(elem) {
            const content = elem.querySelector('div[style*="display: none"]');
            if (content) {
                const isHidden = content.style.display === 'none';
                content.style.display = isHidden ? 'block' : 'none';
            }
        }
    </script>
</body>
</html>
