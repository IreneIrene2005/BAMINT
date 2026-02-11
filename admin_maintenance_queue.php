
<?php
// admin_maintenance_queue.php
// Hotel Amenities Management - Admin Amenities Queue
// Refactored to use new schema and business logic (2026)

require_once 'db_connect.php';
session_start();
// Check admin login using standard session variables
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'front_desk'])) {
    header("location: index.php");
    exit;
}

// Ensure archived column exists in maintenance_requests table
try {
    $conn->query("ALTER TABLE maintenance_requests ADD COLUMN archived TINYINT DEFAULT 0");
} catch (Exception $e) {
    // Column may already exist, continue (ignore column exists error)
}

// Handle status update actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['request_id'])) {
    $action = $_POST['action'];
    $request_id = intval($_POST['request_id']);
    if ($action === 'start') {
        // Move from Pending to In Progress
        $stmt = $conn->prepare("UPDATE maintenance_requests SET status='In Progress', updated_at=NOW() WHERE id=? AND status='Pending'");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'complete') {
        // Move from In Progress to Completed, then archive to history
        // Get request details (no strict status check so we capture requests regardless of string casing)
        $stmt = $conn->prepare("SELECT * FROM maintenance_requests WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        if ($row && strtolower(trim(str_replace(' ', '_', $row['status']))) !== 'completed') {
            // Ensure maintenance_history has completed_by/completed_at columns (safe to run repeatedly) — used for amenity history
            $conn->query("ALTER TABLE maintenance_history ADD COLUMN IF NOT EXISTS completed_by INT NULL, ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL");

            // Archive to history and record who completed it
            $completedBy = isset($_SESSION['admin_id']) ? intval($_SESSION['admin_id']) : null;
            $stmt = $conn->prepare("INSERT INTO maintenance_history (maintenance_request_id, completed_by, completed_at) VALUES (?, ?, NOW())");
            $stmt->bind_param('ii', $row['id'], $completedBy);
            $stmt->execute();
            $stmt->close();

            // Mark request completed (do not delete — FK requires the parent row to exist)
            $stmt = $conn->prepare("UPDATE maintenance_requests SET status='completed', completion_date=NOW(), updated_at=NOW() WHERE id=?");
            $stmt->bind_param('i', $request_id);
            $stmt->execute();
            $stmt->close();

            // If this amenity had a cost, add it to the tenant's bill via shared helper
            $amenity_cost = isset($row['cost']) && $row['cost'] !== null ? floatval($row['cost']) : 0;
            if ($amenity_cost > 0) {
                // Use shared helper to add to next month's bill and attach note (so our UI can find it)
                require_once __DIR__ . '/db_pdo.php';
                require_once __DIR__ . '/db/notifications.php';
                if (isset($pdo)) {
                    $billId = addMaintenanceCostToBill($pdo, intval($row['tenant_id']), $amenity_cost, intval($row['id']), $row['category']);
                    if ($billId) {
                        $tnMsg = 'An additional charge for amenity "' . $row['category'] . '" has been added to your bill. Amount: ₱' . number_format($amenity_cost, 2);
                        createNotification($pdo, 'tenant', intval($row['tenant_id']), 'new_bill', 'New Bill Generated', $tnMsg, $billId, 'bill', 'tenant_bills.php');
                    }
                }
            }

            // Create admin notification for amenity completion
            require_once __DIR__ . '/db_pdo.php';
            require_once __DIR__ . '/db/notifications.php';
            $adminId = $_SESSION['admin_id'];
            $roomNumber = '';
            // Get room number for message
            $roomStmt = $conn->prepare("SELECT room_number FROM rooms WHERE id=?");
            $roomStmt->bind_param('i', $row['room_id']);
            $roomStmt->execute();
            $roomResult = $roomStmt->get_result();
            if ($roomRow = $roomResult->fetch_assoc()) {
                $roomNumber = $roomRow['room_number'];
            }
            $roomStmt->close();
            $title = 'Amenity Request Completed';
            $message = 'Amenity request for Room ' . htmlspecialchars($roomNumber) . ' has been marked as completed.';
            $type = 'amenity_completed';
            $actionUrl = 'admin_maintenance_queue.php';
            // Use PDO for notification
            createNotification($pdo, 'admin', $adminId, $type, $title, $message, $row['id'], 'amenity', $actionUrl);
        }
    } elseif ($action === 'dismiss') {
        // Dismiss a pending request (optional: archive as dismissed)
        $stmt = $conn->prepare("DELETE FROM maintenance_requests WHERE id=? AND status='Pending'");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'assign') {
        // Assign request to a staff member and mark In Progress (amenity)
        $assigned_to = isset($_POST['assigned_to']) ? intval($_POST['assigned_to']) : 0;
        if ($assigned_to > 0) {
            $stmt = $conn->prepare("UPDATE maintenance_requests SET assigned_to=?, status='In Progress', updated_at=NOW() WHERE id=?");
            $stmt->bind_param('ii', $assigned_to, $request_id);
            $stmt->execute();
            $stmt->close();

            // Notify assigned admin (use PDO helper)
            require_once __DIR__ . '/db_pdo.php';
            require_once __DIR__ . '/db/notifications.php';
            $title = 'Assigned Amenity Request';
            $message = 'You have been assigned to amenity request #' . intval($request_id) . '.';
            createNotification($pdo, 'admin', $assigned_to, 'amenity_assigned', $title, $message, $request_id, 'amenity', 'admin_maintenance_queue.php');

            $_SESSION['message'] = 'Request assigned successfully.';
        } else {
            $_SESSION['error'] = 'Please select a staff member to assign.';
        }
    } elseif ($action === 'archive') {
        // Archive a completed request
        $stmt = $conn->prepare("UPDATE maintenance_requests SET archived=1, updated_at=NOW() WHERE id=? AND LOWER(TRIM(REPLACE(status,' ','_'))) = 'completed'");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = 'Request archived successfully.';
        } else {
            $_SESSION['error'] = 'Request not found or cannot be archived.';
        }
        $stmt->close();
    } elseif ($action === 'delete_amenity') {
        // Permanently delete a request (with confirmation on frontend)
        $stmt = $conn->prepare("DELETE FROM maintenance_requests WHERE id=?");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = 'Request deleted permanently.';
        } else {
            $_SESSION['error'] = 'Request not found.';
        }
        $stmt->close();
    } elseif ($action === 'restore_archive') {
        // Restore an archived request back to active view
        $stmt = $conn->prepare("UPDATE maintenance_requests SET archived=0, updated_at=NOW() WHERE id=?");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $_SESSION['message'] = 'Request restored to active view.';
        } else {
            $_SESSION['error'] = 'Request not found or cannot be restored.';
        }
        $stmt->close();
    }
    header('Location: admin_maintenance_queue.php');
    exit();
}

// Fetch amenity request counts (for summary badges)
$countSql = "SELECT 
  SUM(CASE WHEN LOWER(TRIM(REPLACE(status,' ','_'))) = 'pending' THEN 1 ELSE 0 END) AS pending_count,
  SUM(CASE WHEN LOWER(TRIM(REPLACE(status,' ','_'))) = 'in_progress' THEN 1 ELSE 0 END) AS ongoing_count,
  SUM(CASE WHEN LOWER(TRIM(REPLACE(status,' ','_'))) = 'completed' THEN 1 ELSE 0 END) AS completed_count,
  SUM(CASE WHEN (archived = 1) THEN 1 ELSE 0 END) AS archived_count
  FROM maintenance_requests";
$countRes = $conn->query($countSql);
$counts = $countRes ? $countRes->fetch_assoc() : ['pending_count' => 0, 'ongoing_count' => 0, 'completed_count' => 0, 'archived_count' => 0];

// Auto-bill completed amenities that were not yet added to bills
try {
    require_once __DIR__ . '/db_pdo.php';
    require_once __DIR__ . '/db/notifications.php';
    if (isset($pdo)) {
        $checkStmt = $pdo->prepare("SELECT id, tenant_id, category, cost FROM maintenance_requests mr WHERE mr.status = 'completed' AND mr.cost IS NOT NULL AND mr.cost > 0 AND NOT EXISTS (SELECT 1 FROM bills b WHERE b.tenant_id = mr.tenant_id AND b.notes LIKE CONCAT('%Request #', mr.id, '%'))");
        $checkStmt->execute();
        $toBill = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
        $processedAutoBill = 0;
        $autoBilledDetails = [];
        foreach ($toBill as $r) {
            $billId = addMaintenanceCostToBill($pdo, intval($r['tenant_id']), floatval($r['cost']), intval($r['id']), $r['category']);
            if ($billId) {
                // Notify tenant
                createNotification($pdo, 'tenant', intval($r['tenant_id']), 'new_bill', 'New Bill Generated', 'An additional charge for amenity "' . $r['category'] . '" has been added to your bill. Amount: ₱' . number_format($r['cost'], 2), $billId, 'bill', 'tenant_bills.php');
                $processedAutoBill++;
                $autoBilledDetails[] = 'Request #' . intval($r['id']) . ' (₱' . number_format($r['cost'],2) . ') -> Bill #' . intval($billId);
            }
        }
        if ($processedAutoBill > 0) {
            $_SESSION['message'] = "Auto-billed $processedAutoBill completed amenity request(s): " . implode(', ', $autoBilledDetails);
        }
    }
} catch (Exception $e) {
    // Log and continue without interrupting admin UI
    error_log('Auto-billing error: ' . $e->getMessage());
}

// Fetch amenity requests (include assigned admin and robust ordering, exclude archived by default)
$sql = "SELECT mr.*, r.room_number, t.name AS customer_name, a.username AS assigned_admin
    FROM maintenance_requests mr
    LEFT JOIN rooms r ON mr.room_id = r.id
    LEFT JOIN tenants t ON mr.tenant_id = t.id
    LEFT JOIN admins a ON mr.assigned_to = a.id
    WHERE (mr.archived = 0 OR mr.archived IS NULL)
    ORDER BY
      CASE WHEN LOWER(TRIM(REPLACE(mr.status,' ','_'))) = 'pending' THEN 1
           WHEN LOWER(TRIM(REPLACE(mr.status,' ','_'))) = 'in_progress' THEN 2
           WHEN LOWER(TRIM(REPLACE(mr.status,' ','_'))) = 'completed' THEN 3
           ELSE 4 END, mr.created_at ASC";
$requests = $conn->query($sql);

// Fetch staff list for inline assignment
$staffSql = "SELECT id, username FROM admins ORDER BY username ASC";
$staffRes = $conn->query($staffSql);
$staffList = [];
if ($staffRes) {
    while ($s = $staffRes->fetch_assoc()) {
        $staffList[] = $s;
    }
}



?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Amenities Queue</title>
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
            <div class="header-banner mb-4">
                <h1 class="h2 mb-0"><i class="bi bi-gift"></i> Amenities Queue</h1>
                <p class="mb-0">View, update, and manage all amenity requests and history.</p>
            </div>

            <!-- Messages/Alerts -->
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($_SESSION['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Amenity Requests Table -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-list-task"></i> Active Amenity Requests</div>
                    <div>
                        <span class="badge bg-warning text-dark me-1">Pending: <?= intval(
                            $counts['pending_count'] ?? 0) ?></span>
                        <span class="badge bg-info text-dark me-1">Ongoing: <?= intval(
                            $counts['ongoing_count'] ?? 0) ?></span>
                        <span class="badge bg-success me-1">Completed: <?= intval(
                            $counts['completed_count'] ?? 0) ?></span>
                        <span class="badge bg-secondary">Archived: <?= intval(
                            $counts['archived_count'] ?? 0) ?></span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Room</th>
                                    <th>Customer</th>
                                    <th>Assigned</th>
                                    <th>Status</th>
                                    <th>Requested</th>
                                    <th>Last Updated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($requests->num_rows > 0): $i = 1; while ($row = $requests->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td><?= htmlspecialchars($row['room_number']) ?></td>
                                    <td><?= htmlspecialchars($row['customer_name']) ?></td>
                                    <td><?= htmlspecialchars($row['assigned_admin'] ?? '') ?></td>
                                    <td>
                                        <?php
                                            $status_norm = strtolower(str_replace(' ', '_', trim($row['status'])));
                                            if ($status_norm === 'pending') {
                                                echo '<span class="badge bg-warning text-dark">Pending</span>';
                                            } elseif ($status_norm === 'in_progress') {
                                                // Clear 'Ongoing' label for better visibility
                                                echo '<span class="badge bg-info text-dark">Ongoing</span>';
                                            } elseif ($status_norm === 'completed') {
                                                echo '<span class="badge bg-success">Completed</span>';
                                            } else {
                                                echo '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($row['status'])) . '</span>';
                                            }
                                        ?>
                                    </td>
                                    <td><?= date('Y-m-d H:i', strtotime($row['created_at'])) ?></td>
                                    <td><?= date('Y-m-d H:i', strtotime($row['updated_at'])) ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1">
                                            <!-- View Details Button -->
                                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#detailsModal<?= intval($row['id']) ?>" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>

                                            <?php if ($status_norm === 'pending'): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" name="action" value="start" class="btn btn-success btn-sm" title="Start">
                                                        <i class="bi bi-play"></i>
                                                    </button>
                                                </form>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" name="action" value="dismiss" class="btn btn-outline-danger btn-sm" title="Dismiss">
                                                        <i class="bi bi-x"></i>
                                                    </button>
                                                </form>
                                            <?php elseif ($status_norm === 'in_progress'): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" name="action" value="complete" class="btn btn-primary btn-sm" title="Mark as Completed">
                                                        <i class="bi bi-check2-circle"></i>
                                                    </button>
                                                </form>
                                            <?php elseif ($status_norm === 'completed'): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Archive this request?');">
                                                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" name="action" value="archive" class="btn btn-outline-secondary btn-sm" title="Archive">
                                                        <i class="bi bi-archive"></i>
                                                    </button>
                                                </form>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete this request? This cannot be undone.');">
                                                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" name="action" value="delete_amenity" class="btn btn-outline-danger btn-sm" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($status_norm !== 'completed'): ?>
                                                <form method="post" class="d-inline ms-2">
                                                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                    <select name="assigned_to" class="form-select form-select-sm d-inline-block" style="width:auto; display:inline-block; vertical-align:middle;">
                                                        <option value="">Assign...</option>
                                                        <?php foreach ($staffList as $s): ?>
                                                            <option value="<?= intval($s['id']) ?>" <?= (isset($row['assigned_to']) && intval($row['assigned_to']) === intval($s['id'])) ? 'selected' : '' ?>><?= htmlspecialchars($s['username']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" name="action" value="assign" class="btn btn-outline-primary btn-sm" title="Assign">
                                                        <i class="bi bi-person-plus"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="9" class="text-center text-muted">No active amenity requests.</td></tr>
                                <?php endif; ?>8
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Archive View Toggle -->
            <div class="mb-3 d-flex gap-2">
                <a href="admin_maintenance_queue.php" class="btn btn-sm btn-primary <?php echo !isset($_GET['view']) || $_GET['view'] !== 'archived' ? 'active' : 'btn-outline-primary'; ?>">
                    <i class="bi bi-inbox"></i> Active Requests
                </a>
                <a href="admin_maintenance_queue.php?view=archived" class="btn btn-sm btn-outline-primary <?php echo isset($_GET['view']) && $_GET['view'] === 'archived' ? 'active' : ''; ?>">
                    <i class="bi bi-archive"></i> Archived Requests
                </a>
            </div>

            <?php
            // Show archived requests section if view=archived
            if (isset($_GET['view']) && $_GET['view'] === 'archived') {
                $archived_sql = "SELECT mr.*, r.room_number, t.name AS customer_name, a.username AS assigned_admin
                    FROM maintenance_requests mr
                    LEFT JOIN rooms r ON mr.room_id = r.id
                    LEFT JOIN tenants t ON mr.tenant_id = t.id
                    LEFT JOIN admins a ON mr.assigned_to = a.id
                    WHERE (mr.archived = 1)
                    ORDER BY mr.updated_at DESC";
                $archived_result = $conn->query($archived_sql);
                ?>
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <i class="bi bi-archive"></i> Archived Amenity Requests
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Room</th>
                                        <th>Customer</th>
                                        <th>Assigned</th>
                                        <th>Status</th>
                                        <th>Requested</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($archived_result && $archived_result->num_rows > 0): $i = 1; while ($row = $archived_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= $i++ ?></td>
                                        <td><?= htmlspecialchars($row['room_number']) ?></td>
                                        <td><?= htmlspecialchars($row['customer_name']) ?></td>
                                        <td><?= htmlspecialchars($row['assigned_admin'] ?? '') ?></td>
                                        <td>
                                            <?php
                                            $status_norm = strtolower(trim(str_replace(' ', '_', $row['status'])));
                                            $status_colors = [
                                                'pending' => 'warning',
                                                'in_progress' => 'info',
                                                'completed' => 'success'
                                            ];
                                            $status_color = $status_colors[$status_norm] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $status_color ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span>
                                        </td>
                                        <td><?= date('Y-m-d H:i', strtotime($row['created_at'])) ?></td>
                                        <td><?= date('Y-m-d H:i', strtotime($row['updated_at'])) ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <!-- View Details Button -->
                                                <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#detailsModal<?= intval($row['id']) ?>" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <!-- Restore Button -->
                                                <form method="post" class="d-inline" onsubmit="return confirm('Restore this request to active view?');">
                                                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" name="action" value="restore_archive" class="btn btn-sm btn-outline-success" title="Restore">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </button>
                                                </form>
                                                <!-- Delete Button -->
                                                <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete this request? This cannot be undone.');">
                                                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" name="action" value="delete_amenity" class="btn btn-sm btn-outline-danger" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="9" class="text-center text-muted">No archived amenity requests.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php }
            ?>

        </main>
    </div>
</div>

<!-- Details Modals for each request -->
<?php
// Ensure archived column exists
try {
    $conn->query("ALTER TABLE maintenance_requests ADD COLUMN archived TINYINT DEFAULT 0");
} catch (Exception $e) {
    // Column may already exist
}

// Render modals for all maintenance requests
$modal_sql = "SELECT mr.*, r.room_number, t.name AS customer_name, t.email, t.phone, a.username AS assigned_admin
    FROM maintenance_requests mr
    LEFT JOIN rooms r ON mr.room_id = r.id
    LEFT JOIN tenants t ON mr.tenant_id = t.id
    LEFT JOIN admins a ON mr.assigned_to = a.id";
    
$modal_result = $conn->query($modal_sql);

if ($modal_result && $modal_result->num_rows > 0) {
    while ($row = $modal_result->fetch_assoc()) {
        if (empty($row['id'])) continue; // Skip if no ID
        ?>
        <!-- Details Modal for Request <?= intval($row['id']) ?> -->
        <div class="modal fade" id="detailsModal<?= intval($row['id']) ?>" tabindex="-1" aria-labelledby="detailsModalLabel<?= intval($row['id']) ?>" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="detailsModalLabel<?= intval($row['id']) ?>">
                            <i class="bi bi-list-check"></i> Amenity Request Details
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-muted">Request ID</h6>
                                <p class="fw-bold">#<?= intval($row['id']) ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Status</h6>
                                <p><span class="badge bg-<?php 
                                    $status_norm = strtolower(trim(str_replace(' ', '_', $row['status'] ?? 'pending')));
                                    echo ($status_norm === 'completed' ? 'success' : ($status_norm === 'in_progress' ? 'info' : 'warning')); 
                                ?>"><?= htmlspecialchars(ucfirst($row['status'] ?? 'Pending')) ?></span></p>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-muted">Room</h6>
                                <p class="fw-bold"><?= htmlspecialchars($row['room_number'] ?? 'N/A') ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Customer</h6>
                                <p class="fw-bold"><?= htmlspecialchars($row['customer_name'] ?? 'N/A') ?></p>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-muted">Customer Phone</h6>
                                <p><?= htmlspecialchars($row['phone'] ?? 'N/A') ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Customer Email</h6>
                                <p><?= htmlspecialchars($row['email'] ?? 'N/A') ?></p>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-muted">Assigned To</h6>
                                <p class="fw-bold"><?= htmlspecialchars($row['assigned_admin'] ?? 'Unassigned') ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Category</h6>
                                <p class="fw-bold"><?= htmlspecialchars($row['category'] ?? 'N/A') ?></p>
                            </div>
                        </div>

                        <div class="mb-3">
                            <h6 class="text-muted">Description / Request Details</h6>
                            <p class="border rounded p-3 bg-light"><?= nl2br(htmlspecialchars($row['description'] ?? 'No description provided')) ?></p>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-muted">Requested Date</h6>
                                <p><?= isset($row['created_at']) ? date('M d, Y H:i', strtotime($row['created_at'])) : 'N/A' ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Last Updated</h6>
                                <p><?= isset($row['updated_at']) ? date('M d, Y H:i', strtotime($row['updated_at'])) : 'N/A' ?></p>
                            </div>
                        </div>

                        <?php if (!empty($row['cost'])): ?>
                        <div class="mb-3">
                            <h6 class="text-muted">Cost</h6>
                            <p class="fw-bold">₱<?= number_format(floatval($row['cost']), 2) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
