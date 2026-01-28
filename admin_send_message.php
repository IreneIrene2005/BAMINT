<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

$admin_id = $_SESSION["admin_id"];
$message_sent = '';
$error_msg = '';

// Handle message sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $tenant_id = intval($_POST['tenant_id']);
    $subject = trim($_POST['subject']);
    $message_text = trim($_POST['message']);
    $related_type = $_POST['related_type'] ?? null;
    $related_id = $_POST['related_id'] ?? null;
    
    if ($tenant_id && $subject && $message_text) {
        if (sendMessage($conn, 'admin', $admin_id, 'tenant', $tenant_id, $subject, $message_text, $related_type, $related_id)) {
            $message_sent = "Message sent to tenant successfully!";
        } else {
            $error_msg = "Error sending message. Please try again.";
        }
    } else {
        $error_msg = "Please fill in all required fields.";
    }
}

// Fetch tenants with unpaid balances
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT t.id, t.name, t.email,
               SUM(b.amount_due - b.amount_paid) as balance
        FROM tenants t
        JOIN bills b ON t.id = b.tenant_id
        WHERE b.status IN ('pending', 'partial')
        AND (b.amount_due - b.amount_paid) > 0
        GROUP BY t.id, t.name, t.email
        ORDER BY t.name ASC
    ");
    $stmt->execute();
    $tenants_with_balance = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tenants_with_balance = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Send Message to Tenant - Admin</title>
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
                <h1 class="h2"><i class="bi bi-envelope-open"></i> Send Message to Tenant</h1>
            </div>

            <?php if ($message_sent): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($message_sent); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error_msg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-primary bg-opacity-10">
                            <h6 class="mb-0"><i class="bi bi-pencil-square"></i> Compose Message</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="tenant_id" class="form-label">Select Tenant *</label>
                                    <select class="form-select" id="tenant_id" name="tenant_id" required onchange="updateTenantInfo()">
                                        <option value="">Choose a tenant...</option>
                                        <?php foreach ($tenants_with_balance as $t): ?>
                                            <option value="<?php echo $t['id']; ?>" data-balance="<?php echo $t['balance']; ?>">
                                                <?php echo htmlspecialchars($t['name']); ?> - Balance: ₱<?php echo number_format($t['balance'], 2); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="subject" class="form-label">Subject *</label>
                                    <input type="text" class="form-control" id="subject" name="subject" placeholder="e.g., Partial Payment Received - Balance Reminder" required>
                                </div>

                                <div class="mb-3">
                                    <label for="message" class="form-label">Message *</label>
                                    <textarea class="form-control" id="message" name="message" rows="8" placeholder="Type your message here..." required></textarea>
                                    <small class="form-text text-muted">You can send payment reminders, payment instructions, or any other important information.</small>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="related_type" class="form-label">Related To (Optional)</label>
                                            <select class="form-select" id="related_type" name="related_type">
                                                <option value="">-- Not related to specific record --</option>
                                                <option value="bill">Bill</option>
                                                <option value="payment_transaction">Payment Transaction</option>
                                                <option value="maintenance_request">Maintenance Request</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="related_id" class="form-label">Record ID (Optional)</label>
                                            <input type="number" class="form-control" id="related_id" name="related_id" placeholder="Leave blank if not applicable">
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="submit" name="send_message" class="btn btn-primary">
                                        <i class="bi bi-send"></i> Send Message
                                    </button>
                                    <a href="admin_payment_verification.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Back
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-info bg-opacity-10">
                            <h6 class="mb-0"><i class="bi bi-info-circle"></i> Quick Templates</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <small><strong>Balance Reminder:</strong></small>
                                <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="insertTemplate('balanceReminder')">Insert</button>
                                <div style="display:none;" id="template-balanceReminder">
Dear Tenant,

We received your partial payment. There is still an outstanding balance on your account.

Please settle the remaining balance at your earliest convenience.

For payment instructions, please contact the management office.

Thank you.
                                </div>
                            </div>

                            <div class="mb-3">
                                <small><strong>Payment Confirmation:</strong></small>
                                <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="insertTemplate('paymentConfirm')">Insert</button>
                                <div style="display:none;" id="template-paymentConfirm">
Dear Tenant,

Thank you for your recent payment. We confirm receipt of your payment and have credited your account.

If you have any questions or concerns, please do not hesitate to contact us.

Best regards,
Management
                                </div>
                            </div>

                            <hr>
                            <small class="text-muted">Click "Insert" to add a template to the message field and customize as needed.</small>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function insertTemplate(templateId) {
        const templateContent = document.getElementById('template-' + templateId).textContent;
        document.getElementById('message').value = templateContent.trim();
        document.getElementById('message').focus();
    }

    function updateTenantInfo() {
        const select = document.getElementById('tenant_id');
        const selected = select.options[select.selectedIndex];
        const balance = selected.getAttribute('data-balance');
        
        if (balance) {
            const subject = `Payment Balance Reminder - Outstanding: ₱${parseFloat(balance).toFixed(2)}`;
            document.getElementById('subject').value = subject;
        }
    }
</script>
</body>
</html>
