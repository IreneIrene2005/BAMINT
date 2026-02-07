<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}

require_once "db/database.php";

$filter_status = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$success_msg = "";
$error_msg = "";
$stats = [];
$cancellations = [];

try {
    // Build query for cancellations
    $query = "
        SELECT bc.*, t.name, t.email, t.phone, r.room_number, r.room_type
        FROM booking_cancellations bc
        INNER JOIN tenants t ON bc.tenant_id = t.id
        INNER JOIN rooms r ON bc.room_id = r.id
        WHERE 1=1
    ";

    $params = [];

    if (!empty($search_query)) {
        $query .= " AND (t.name LIKE :search OR t.email LIKE :search OR t.phone LIKE :search OR r.room_number LIKE :search)";
        $params['search'] = "%$search_query%";
    }

    $date_filter = $_GET['date_filter'] ?? 'all';
    if ($date_filter === 'today') {
        $query .= " AND DATE(bc.cancelled_at) = CURDATE()";
    } elseif ($date_filter === 'week') {
        $query .= " AND DATE(bc.cancelled_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($date_filter === 'month') {
        $query .= " AND DATE(bc.cancelled_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    }

    $query .= " ORDER BY bc.cancelled_at DESC";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $cancellations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_cancellations,
            COUNT(CASE WHEN DATE(cancelled_at) = CURDATE() THEN 1 END) as today_cancellations,
            SUM(payment_amount) as total_cancelled_payment,
            COUNT(DISTINCT tenant_id) as unique_customers
        FROM booking_cancellations
    ";
    
    $stmt = $conn->prepare($stats_query);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error_msg = "Error loading cancellations: " . $e->getMessage();
}

// Handle actions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $cancellation_id = intval($_POST['cancellation_id'] ?? 0);
    
    if ($_POST['action'] === 'approve_refund') {
        try {
            $refund_notes = $_POST['refund_notes'] ?? '';
            $refund_amount = floatval($_POST['refund_amount'] ?? 0);

            $conn->beginTransaction();

            // Update cancellation record with refund info
            $stmt = $conn->prepare("
                UPDATE booking_cancellations 
                SET refund_approved = 1, refund_amount = :amount, refund_notes = :notes, refund_date = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'amount' => $refund_amount,
                'notes' => $refund_notes,
                'id' => $cancellation_id
            ]);

            $conn->commit();
            $success_msg = "Refund approved and processed.";
        } catch (Exception $e) {
            $conn->rollBack();
            $error_msg = "Error processing refund: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'review_note') {
        try {
            $review_note = $_POST['review_note'] ?? '';

            $stmt = $conn->prepare("
                UPDATE booking_cancellations 
                SET admin_notes = :notes, reviewed_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'notes' => $review_note,
                'id' => $cancellation_id
            ]);

            $success_msg = "Review note added.";
        } catch (Exception $e) {
            $error_msg = "Error saving note: " . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Cancellations - BAMINT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .metric-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        .metric-value {
            font-size: 2.5rem;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .metric-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        .cancellation-card {
            background: white;
            border-radius: 8px;
            border-left: 4px solid #dc3545;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: transform 0.3s;
        }
        .cancellation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .customer-name {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.9rem;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #6c757d;
            font-weight: 500;
        }
        .info-value {
            color: #333;
            font-weight: 500;
        }
        .badge-status {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <?php include 'templates/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'templates/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="header-banner">
                    <h1><i class="bi bi-x-circle"></i> Booking Cancellations</h1>
                    <p class="mb-0">View and manage customer booking cancellations and refunds</p>
                </div>

                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value"><?php echo $stats['total_cancellations'] ?? 0; ?></div>
                            <div class="metric-label">Total Cancellations</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"><?php echo $stats['today_cancellations'] ?? 0; ?></div>
                            <div class="metric-label">Cancelled Today</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">₱<?php echo number_format($stats['total_cancelled_payment'] ?? 0, 0); ?></div>
                            <div class="metric-label">Total Cancelled Payment</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-value" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"><?php echo $stats['unique_customers'] ?? 0; ?></div>
                            <div class="metric-label">Customers Cancelled</div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-5">
                                <label for="search" class="form-label">Search by Name, Email, Phone, or Room</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search...">
                            </div>
                            <div class="col-md-4">
                                <label for="date_filter" class="form-label">Filter by Date</label>
                                <select class="form-select" id="date_filter" name="date_filter">
                                    <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Time</option>
                                    <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                                    <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                                    <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Cancellations List -->
                <div>
                    <?php if (empty($cancellations)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No cancellations found matching the criteria.
                        </div>
                    <?php else: ?>
                        <?php foreach ($cancellations as $cancel): ?>
                            <div class="cancellation-card">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="customer-name">
                                            <?php echo htmlspecialchars($cancel['name']); ?>
                                            <span class="badge badge-status" style="background: #dc3545; color: white;">
                                                Cancelled
                                            </span>
                                        </div>

                                        <div class="info-row">
                                            <span class="info-label"><i class="bi bi-envelope"></i> Email:</span>
                                            <span class="info-value"><?php echo htmlspecialchars($cancel['email'] ?? 'N/A'); ?></span>
                                        </div>

                                        <div class="info-row">
                                            <span class="info-label"><i class="bi bi-telephone"></i> Phone:</span>
                                            <span class="info-value"><?php echo htmlspecialchars($cancel['phone'] ?? 'N/A'); ?></span>
                                        </div>

                                        <div class="info-row">
                                            <span class="info-label"><i class="bi bi-door-open"></i> Room:</span>
                                            <span class="info-value"><?php echo htmlspecialchars($cancel['room_number']); ?> (<?php echo htmlspecialchars($cancel['room_type']); ?>)</span>
                                        </div>

                                        <div class="info-row">
                                            <span class="info-label"><i class="bi bi-calendar-check"></i> Check-in:</span>
                                            <span class="info-value"><?php echo $cancel['checkin_date'] ? date('M d, Y', strtotime($cancel['checkin_date'])) : 'N/A'; ?></span>
                                        </div>

                                        <div class="info-row">
                                            <span class="info-label"><i class="bi bi-calendar-x"></i> Check-out:</span>
                                            <span class="info-value"><?php echo $cancel['checkout_date'] ? date('M d, Y', strtotime($cancel['checkout_date'])) : 'N/A'; ?></span>
                                        </div>

                                        <div class="info-row">
                                            <span class="info-label"><i class="bi bi-cash-coin"></i> Payment Amount:</span>
                                            <span class="info-value text-danger">₱<?php echo number_format($cancel['payment_amount'], 2); ?></span>
                                        </div>

                                        <div class="info-row">
                                            <span class="info-label"><i class="bi bi-clock"></i> Cancelled on:</span>
                                            <span class="info-value"><?php echo 'Oct ' . date('d, Y • H:i A', strtotime($cancel['cancelled_at'])); ?></span>
                                        </div>

                                        <?php if (!empty($cancel['reason']) && $cancel['reason'] !== 'Customer initiated cancellation'): ?>
                                            <div class="info-row" style="background: #f8f9fa; padding: 0.75rem; border-radius: 4px; margin-top: 0.5rem; border-left: 3px solid #6c757d;">
                                                <div style="width: 100%;">
                                                    <span class="info-label"><i class="bi bi-chat-left-text"></i> Cancellation Reason:</span>
                                                    <div class="info-value" style="margin-top: 0.3rem; color: #555;"><?php echo htmlspecialchars($cancel['reason']); ?></div>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($cancel['refund_approved']): ?>
                                            <div class="info-row" style="background: #d4edda; padding: 0.75rem; border-radius: 4px; margin-top: 0.5rem;">
                                                <span class="info-label text-success"><i class="bi bi-check-circle"></i> Refund Approved:</span>
                                                <span class="info-value text-success">₱<?php echo number_format($cancel['refund_amount'], 2); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="d-flex flex-column gap-2">
                                            <button class="btn btn-sm btn-danger" onclick="confirmArchiveAccount(<?php echo $cancel['id']; ?>, <?php echo $cancel['tenant_id']; ?>, '<?php echo htmlspecialchars($cancel['name']); ?>')">
                                                <i class="bi bi-archive"></i> Archive Account
                                            </button>

                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                                                    data-bs-target="#notesModal" 
                                                    onclick="setNotesData(<?php echo $cancel['id']; ?>, '<?php echo htmlspecialchars($cancel['admin_notes'] ?? ''); ?>')">
                                                <i class="bi bi-sticky"></i> Add Note
                                            </button>

                                            <button class="btn btn-sm btn-outline-info" onclick="viewCancellationDetails(<?php echo $cancel['id']; ?>)">
                                                <i class="bi bi-eye"></i> View Details
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Notes Modal -->
    <div class="modal fade" id="notesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-sticky"></i> Add Review Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="cancellation_id" id="notesCancellationId">
                        <input type="hidden" name="action" value="review_note">
                        
                        <div class="mb-3">
                            <label for="reviewNote" class="form-label">Add Review Note</label>
                            <textarea class="form-control" id="reviewNote" name="review_note" rows="4"
                                      placeholder="Add internal notes about this cancellation..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Save Note
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setNotesData(cancellationId, existingNotes) {
            document.getElementById('notesCancellationId').value = cancellationId;
            document.getElementById('reviewNote').value = existingNotes;
        }

        function viewCancellationDetails(cancellationId) {
            // Could implement a detailed view modal
            console.log('View details for cancellation:', cancellationId);
        }

        function confirmArchiveAccount(cancellationId, tenantId, customerName) {
            if (confirm(`Are you sure you want to archive the account for ${customerName}? This action will move their account to the archive.`)) {
                archiveAccount(cancellationId, tenantId);
            }
        }

        function archiveAccount(cancellationId, tenantId) {
            const formData = new FormData();
            formData.append('cancellation_id', cancellationId);
            formData.append('tenant_id', tenantId);

            fetch('api_archive_tenant.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    alert('Account archived successfully!');
                    // Reload the page to reflect changes
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to archive account'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error archiving account: ' + error.message);
            });
        }
    </script>
</body>
</html>
