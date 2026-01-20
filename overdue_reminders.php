<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

// Get overdue bills and statistics
$sql_overdue = "SELECT bills.*, tenants.name, tenants.email, tenants.phone, rooms.room_number,
                       (bills.amount_due - bills.amount_paid - bills.discount) as balance,
                       DATEDIFF(CURDATE(), bills.due_date) as days_overdue
                FROM bills
                JOIN tenants ON bills.tenant_id = tenants.id
                JOIN rooms ON bills.room_id = rooms.id
                WHERE bills.status != 'paid' 
                AND bills.due_date IS NOT NULL 
                AND bills.due_date < CURDATE()
                ORDER BY bills.due_date ASC";

$overdue_bills = $conn->query($sql_overdue)->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$sql_summary = "SELECT 
    COUNT(*) as overdue_count,
    SUM(bills.amount_due - bills.amount_paid - bills.discount) as total_outstanding,
    COUNT(DISTINCT bills.tenant_id) as affected_tenants
    FROM bills
    WHERE bills.status != 'paid' 
    AND bills.due_date IS NOT NULL 
    AND bills.due_date < CURDATE()";

$summary = $conn->query($sql_summary)->fetch(PDO::FETCH_ASSOC);

// Get upcoming bills (due within 7 days)
$sql_upcoming = "SELECT bills.*, tenants.name, tenants.email, rooms.room_number,
                        (bills.amount_due - bills.amount_paid - bills.discount) as balance,
                        DATEDIFF(bills.due_date, CURDATE()) as days_until_due
                 FROM bills
                 JOIN tenants ON bills.tenant_id = tenants.id
                 JOIN rooms ON bills.room_id = rooms.id
                 WHERE bills.status != 'paid'
                 AND bills.due_date IS NOT NULL
                 AND bills.due_date >= CURDATE()
                 AND bills.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                 ORDER BY bills.due_date ASC";

$upcoming_bills = $conn->query($sql_upcoming)->fetchAll(PDO::FETCH_ASSOC);
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
                    <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                    Overdue Bills & Reminders
                </h1>
            </div>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card border-danger">
                        <div class="card-body">
                            <h6 class="card-title text-danger"><i class="bi bi-exclamation-circle"></i> Overdue Bills</h6>
                            <p class="card-text display-5 text-danger"><?php echo htmlspecialchars($summary['overdue_count']); ?></p>
                            <small class="text-muted">Accounts with overdue payments</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-danger">
                        <div class="card-body">
                            <h6 class="card-title text-danger"><i class="bi bi-cash-coin"></i> Total Outstanding</h6>
                            <p class="card-text display-6 text-danger">₱<?php echo number_format($summary['total_outstanding'] ?? 0, 2); ?></p>
                            <small class="text-muted">Amount to collect</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-danger">
                        <div class="card-body">
                            <h6 class="card-title text-danger"><i class="bi bi-people-fill"></i> Affected Tenants</h6>
                            <p class="card-text display-5 text-danger"><?php echo htmlspecialchars($summary['affected_tenants']); ?></p>
                            <small class="text-muted">Tenants with overdue accounts</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-warning">
                        <div class="card-body">
                            <h6 class="card-title text-warning"><i class="bi bi-clock-history"></i> Upcoming Due</h6>
                            <p class="card-text display-5 text-warning"><?php echo count($upcoming_bills); ?></p>
                            <small class="text-muted">Bills due within 7 days</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overdue Bills Section -->
            <div class="mb-4">
                <h3 class="mb-3">
                    <i class="bi bi-exclamation-triangle text-danger"></i>
                    Overdue Accounts
                </h3>
                
                <?php if (empty($overdue_bills)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> No overdue bills! All accounts are current.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-danger">
                                <tr>
                                    <th>Tenant</th>
                                    <th>Contact</th>
                                    <th>Room</th>
                                    <th>Billing Month</th>
                                    <th>Due Date</th>
                                    <th>Days Overdue</th>
                                    <th>Balance Due (₱)</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($overdue_bills as $bill): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($bill['name']); ?></strong></td>
                                    <td>
                                        <small>
                                            Email: <?php echo htmlspecialchars($bill['email']); ?><br>
                                            Phone: <?php echo htmlspecialchars($bill['phone']); ?>
                                        </small>
                                    </td>
                                    <td><?php echo htmlspecialchars($bill['room_number']); ?></td>
                                    <td><?php echo date('F Y', strtotime($bill['billing_month'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($bill['due_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-danger"><?php echo htmlspecialchars($bill['days_overdue']); ?> days</span>
                                    </td>
                                    <td><strong class="text-danger">₱<?php echo number_format($bill['balance'], 2); ?></strong></td>
                                    <td>
                                        <span class="badge bg-<?php echo $bill['status'] === 'partial' ? 'warning' : 'secondary'; ?>">
                                            <?php echo ucfirst($bill['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="bills.php?search=<?php echo htmlspecialchars($bill['name']); ?>" class="btn btn-sm btn-outline-primary" title="View Bill">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#sendReminderModal" 
                                                onclick="setReminderData('<?php echo htmlspecialchars($bill['name']); ?>', '<?php echo htmlspecialchars($bill['email']); ?>', '<?php echo htmlspecialchars($bill['phone']); ?>', <?php echo $bill['id']; ?>)">
                                            <i class="bi bi-send"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming Due Bills Section -->
            <div class="mb-4">
                <h3 class="mb-3">
                    <i class="bi bi-clock-history text-warning"></i>
                    Bills Due Within 7 Days
                </h3>
                
                <?php if (empty($upcoming_bills)): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> No bills coming due within the next 7 days.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-warning">
                                <tr>
                                    <th>Tenant</th>
                                    <th>Contact</th>
                                    <th>Room</th>
                                    <th>Billing Month</th>
                                    <th>Due Date</th>
                                    <th>Days Until Due</th>
                                    <th>Amount (₱)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcoming_bills as $bill): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($bill['name']); ?></strong></td>
                                    <td>
                                        <small>
                                            Email: <?php echo htmlspecialchars($bill['email']); ?><br>
                                        </small>
                                    </td>
                                    <td><?php echo htmlspecialchars($bill['room_number']); ?></td>
                                    <td><?php echo date('F Y', strtotime($bill['billing_month'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($bill['due_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-warning"><?php echo htmlspecialchars($bill['days_until_due']); ?> days</span>
                                    </td>
                                    <td><strong>₱<?php echo number_format($bill['balance'], 2); ?></strong></td>
                                    <td>
                                        <span class="badge bg-<?php echo $bill['status'] === 'partial' ? 'warning' : 'secondary'; ?>">
                                            <?php echo ucfirst($bill['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
</script>
</body>
</html>
