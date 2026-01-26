<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}

require_once "db/database.php";

$admin_id = $_SESSION["admin_id"] ?? 0;
$message = '';
$message_type = '';

// Handle maintenance request actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;

    if ($request_id > 0) {
        try {
            if ($action === 'assign') {
                // Assign request to maintenance staff
                $assigned_to = isset($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
                $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
                $estimated_completion = isset($_POST['estimated_completion']) ? $_POST['estimated_completion'] : null;

                $stmt = $conn->prepare("
                    UPDATE maintenance_requests 
                    SET assigned_to = :assigned_to, 
                        notes = :notes,
                        completion_date = :completion_date,
                        status = 'pending'
                    WHERE id = :id
                ");
                $stmt->execute([
                    'id' => $request_id,
                    'assigned_to' => $assigned_to,
                    'notes' => $notes,
                    'completion_date' => $estimated_completion
                ]);
                $message = "✓ Request assigned successfully!";
                $message_type = "success";

            } elseif ($action === 'start') {
                // Start maintenance work (change to ongoing)
                $stmt = $conn->prepare("
                    UPDATE maintenance_requests 
                    SET status = 'in_progress', 
                        start_date = NOW()
                    WHERE id = :id AND status = 'pending'
                ");
                $stmt->execute(['id' => $request_id]);
                $message = "✓ Request marked as in progress!";
                $message_type = "success";

            } elseif ($action === 'complete') {
                // Mark as completed
                $completion_notes = isset($_POST['completion_notes']) ? trim($_POST['completion_notes']) : '';
                
                $stmt = $conn->prepare("
                    UPDATE maintenance_requests 
                    SET status = 'completed', 
                        completion_date = NOW(),
                        notes = CONCAT(COALESCE(notes, ''), '\n\nCompletion Notes: ', :notes)
                    WHERE id = :id
                ");
                $stmt->execute([
                    'id' => $request_id,
                    'notes' => $completion_notes
                ]);
                $message = "✓ Request marked as completed!";
                $message_type = "success";

            } elseif ($action === 'update_notes') {
                // Update notes and estimated date
                $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
                $estimated_completion = isset($_POST['estimated_completion']) ? $_POST['estimated_completion'] : null;

                $stmt = $conn->prepare("
                    UPDATE maintenance_requests 
                    SET notes = :notes,
                        completion_date = :completion_date
                    WHERE id = :id
                ");
                $stmt->execute([
                    'id' => $request_id,
                    'notes' => $notes,
                    'completion_date' => $estimated_completion
                ]);
                $message = "✓ Notes and estimated date updated!";
                $message_type = "success";

            } elseif ($action === 'reject') {
                // Reject request
                $rejection_reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : 'No reason provided';
                
                $stmt = $conn->prepare("
                    UPDATE maintenance_requests 
                    SET status = 'cancelled',
                        notes = :reason
                    WHERE id = :id
                ");
                $stmt->execute([
                    'id' => $request_id,
                    'reason' => "Rejected: " . $rejection_reason
                ]);
                $message = "✓ Request rejected!";
                $message_type = "success";
            }

            // Redirect to refresh page
            header("location: admin_maintenance_queue.php");
            exit;
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Fetch pending maintenance requests
try {
    $pending_stmt = $conn->prepare("
        SELECT 
            mr.id,
            mr.tenant_id,
            mr.room_id,
            mr.category,
            mr.description,
            mr.priority,
            mr.status,
            mr.assigned_to,
            mr.submitted_date,
            mr.start_date,
            mr.completion_date,
            mr.notes,
            t.name as tenant_name,
            t.email as tenant_email,
            r.room_number,
            a.username as assigned_staff
        FROM maintenance_requests mr
        JOIN tenants t ON mr.tenant_id = t.id
        JOIN rooms r ON mr.room_id = r.id
        LEFT JOIN admins a ON mr.assigned_to = a.id
        WHERE mr.status IN ('pending', 'in_progress')
        ORDER BY 
            CASE 
                WHEN mr.priority = 'high' THEN 1
                WHEN mr.priority = 'normal' THEN 2
                ELSE 3
            END,
            mr.submitted_date ASC
    ");
    $pending_stmt->execute();
    $pending_requests = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);
    $pending_count = count($pending_requests);
} catch (Exception $e) {
    error_log("Maintenance Queue Error: " . $e->getMessage());
    $message = "Database Error: " . $e->getMessage();
    $message_type = "danger";
    $pending_requests = [];
    $pending_count = 0;
}

// Fetch all staff/admins for assignment
try {
    $staff_stmt = $conn->prepare("SELECT id, username FROM admins ORDER BY username ASC");
    $staff_stmt->execute();
    $all_staff = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all_staff = [];
}

// Get summary statistics
try {
    $summary_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN priority = 'high' AND status != 'completed' THEN 1 ELSE 0 END) as urgent_count,
            SUM(CASE WHEN assigned_to IS NULL AND status != 'completed' THEN 1 ELSE 0 END) as unassigned_count
        FROM maintenance_requests
    ");
    $summary_stmt->execute();
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $summary = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Maintenance Queue - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .queue-card {
            border-left: 4px solid #007bff;
            transition: all 0.3s ease;
        }
        .queue-card.priority-high {
            border-left-color: #dc3545;
            background-color: rgba(220, 53, 69, 0.05);
        }
        .queue-card.priority-normal {
            border-left-color: #ffc107;
            background-color: rgba(255, 193, 7, 0.05);
        }
        .queue-card.priority-low {
            border-left-color: #17a2b8;
            background-color: rgba(23, 162, 184, 0.05);
        }
        .queue-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="bi bi-wrench"></i> Maintenance Queue</h1>
                <div class="text-muted">
                    <span class="badge bg-warning"><?php echo $summary['pending_count'] ?? 0; ?> Pending</span>
                    <span class="badge bg-info"><?php echo $summary['in_progress_count'] ?? 0; ?> In Progress</span>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Summary Statistics -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card border-0 bg-light">
                        <div class="card-body text-center">
                            <h6 class="text-muted">Total Requests</h6>
                            <h3 class="mb-0"><?php echo $summary['total_requests'] ?? 0; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-0 bg-warning bg-opacity-10">
                        <div class="card-body text-center">
                            <h6 class="text-warning">Pending</h6>
                            <h3 class="text-warning mb-0"><?php echo $summary['pending_count'] ?? 0; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-0 bg-info bg-opacity-10">
                        <div class="card-body text-center">
                            <h6 class="text-info">In Progress</h6>
                            <h3 class="text-info mb-0"><?php echo $summary['in_progress_count'] ?? 0; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-0 bg-success bg-opacity-10">
                        <div class="card-body text-center">
                            <h6 class="text-success">Completed</h6>
                            <h3 class="text-success mb-0"><?php echo $summary['completed_count'] ?? 0; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-0 bg-danger bg-opacity-10">
                        <div class="card-body text-center">
                            <h6 class="text-danger">Urgent</h6>
                            <h3 class="text-danger mb-0"><?php echo $summary['urgent_count'] ?? 0; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-0 bg-secondary bg-opacity-10">
                        <div class="card-body text-center">
                            <h6 class="text-secondary">Unassigned</h6>
                            <h3 class="text-secondary mb-0"><?php echo $summary['unassigned_count'] ?? 0; ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Requests Queue -->
            <h4 class="mb-3"><i class="bi bi-hourglass-split"></i> Pending & In Progress Requests</h4>
            
            <?php if (count($pending_requests) > 0): ?>
                <div class="row g-3">
                    <?php foreach ($pending_requests as $request): ?>
                    <div class="col-lg-6">
                        <div class="card queue-card priority-<?php echo $request['priority']; ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="card-title mb-1">
                                            #<?php echo str_pad($request['id'], 4, '0', STR_PAD_LEFT); ?> - <?php echo htmlspecialchars($request['category']); ?>
                                        </h6>
                                        <p class="text-muted small mb-0">
                                            <i class="bi bi-person"></i> <?php echo htmlspecialchars($request['tenant_name']); ?> (Room <?php echo htmlspecialchars($request['room_number']); ?>)
                                        </p>
                                    </div>
                                    <span class="badge bg-<?php 
                                        echo $request['priority'] === 'high' ? 'danger' : ($request['priority'] === 'normal' ? 'warning' : 'info');
                                    ?>">
                                        <?php echo ucfirst($request['priority']); ?>
                                    </span>
                                </div>

                                <p class="card-text small mb-2">
                                    <strong>Issue:</strong> <?php echo htmlspecialchars(substr($request['description'], 0, 100)); ?>
                                    <?php echo strlen($request['description']) > 100 ? '...' : ''; ?>
                                </p>

                                <div class="mb-2">
                                    <span class="status-badge bg-<?php 
                                        echo $request['status'] === 'in_progress' ? 'primary' : 'warning';
                                    ?>">
                                        <?php echo $request['status'] === 'in_progress' ? '▶ In Progress' : '⏳ Pending'; ?>
                                    </span>
                                    <?php if ($request['assigned_to']): ?>
                                        <span class="badge bg-secondary ms-2">👤 <?php echo htmlspecialchars($request['assigned_staff']); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-danger ms-2">⚠️ Unassigned</span>
                                    <?php endif; ?>
                                </div>

                                <p class="text-muted small mb-2">
                                    <i class="bi bi-calendar"></i> 
                                    Submitted: <?php echo date('M d, Y H:i', strtotime($request['submitted_date'])); ?>
                                </p>

                                <?php if ($request['notes']): ?>
                                <div class="alert alert-light small mb-2">
                                    <strong>Notes:</strong> <?php echo htmlspecialchars(substr($request['notes'], 0, 80)); ?>
                                    <?php echo strlen($request['notes']) > 80 ? '...' : ''; ?>
                                </div>
                                <?php endif; ?>

                                <div class="btn-group w-100" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#assignModal<?php echo $request['id']; ?>">
                                        <i class="bi bi-person-plus"></i> Assign
                                    </button>
                                    <?php if ($request['status'] === 'pending' && $request['assigned_to']): ?>
                                    <form method="POST" style="display: inline-flex; flex: 1;">
                                        <input type="hidden" name="action" value="start">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-info flex-grow-1">
                                            <i class="bi bi-play-fill"></i> Start Work
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#completeModal<?php echo $request['id']; ?>">
                                        <i class="bi bi-check-circle"></i> Complete
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $request['id']; ?>">
                                        <i class="bi bi-x-circle"></i> Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Assign Modal -->
                    <div class="modal fade" id="assignModal<?php echo $request['id']; ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Assign Request #<?php echo str_pad($request['id'], 4, '0', STR_PAD_LEFT); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label for="assigned_to" class="form-label">Assign To Staff</label>
                                            <select class="form-select" id="assigned_to" name="assigned_to" required>
                                                <option value="">Select staff member</option>
                                                <?php foreach ($all_staff as $staff): ?>
                                                    <option value="<?php echo $staff['id']; ?>" <?php echo $request['assigned_to'] == $staff['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($staff['username']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label for="estimated_completion" class="form-label">Estimated Completion Date</label>
                                            <input type="date" class="form-control" id="estimated_completion" name="estimated_completion">
                                        </div>
                                        <div class="mb-3">
                                            <label for="notes" class="form-label">Notes</label>
                                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Add any notes about this request..."></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">
                                            <input type="hidden" name="action" value="assign">
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                            Assign Request
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Complete Modal -->
                    <div class="modal fade" id="completeModal<?php echo $request['id']; ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Mark as Complete #<?php echo str_pad($request['id'], 4, '0', STR_PAD_LEFT); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <div class="modal-body">
                                        <div class="alert alert-info">
                                            ✓ This will mark the request as completed and set completion date to now.
                                        </div>
                                        <div class="mb-3">
                                            <label for="completion_notes" class="form-label">Completion Notes</label>
                                            <textarea class="form-control" id="completion_notes" name="completion_notes" rows="3" placeholder="What was done..."></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-success">
                                            <input type="hidden" name="action" value="complete">
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                            Mark as Completed
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Reject Modal -->
                    <div class="modal fade" id="rejectModal<?php echo $request['id']; ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Reject Request #<?php echo str_pad($request['id'], 4, '0', STR_PAD_LEFT); ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <div class="modal-body">
                                        <div class="alert alert-danger">
                                            ⚠️ This will reject the maintenance request. Provide a reason.
                                        </div>
                                        <div class="mb-3">
                                            <label for="rejection_reason" class="form-label">Rejection Reason</label>
                                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" placeholder="Why is this being rejected?" required></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-danger">
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                            Reject Request
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-success text-center py-4">
                    <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                    <p class="mt-2 mb-0">All maintenance requests have been completed! Great work!</p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
