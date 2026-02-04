
<?php
// admin_maintenance_queue.php
// Hotel Amenities Management - Admin Amenities Queue
// Refactored to use new schema and business logic (2026)

require_once 'db_connect.php';
session_start();
// Check admin login using standard session variables
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
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
    }
    header('Location: admin_maintenance_queue.php');
    exit();
}

// Fetch amenity request counts (for summary badges)
$countSql = "SELECT 
  SUM(CASE WHEN LOWER(TRIM(REPLACE(status,' ','_'))) = 'pending' THEN 1 ELSE 0 END) AS pending_count,
  SUM(CASE WHEN LOWER(TRIM(REPLACE(status,' ','_'))) = 'in_progress' THEN 1 ELSE 0 END) AS ongoing_count,
  SUM(CASE WHEN LOWER(TRIM(REPLACE(status,' ','_'))) = 'completed' THEN 1 ELSE 0 END) AS completed_count
  FROM maintenance_requests";
$countRes = $conn->query($countSql);
$counts = $countRes ? $countRes->fetch_assoc() : ['pending_count' => 0, 'ongoing_count' => 0, 'completed_count' => 0];

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

// Fetch amenity requests (include assigned admin and robust ordering)
$sql = "SELECT mr.*, r.room_number, t.name AS customer_name, a.username AS assigned_admin
    FROM maintenance_requests mr
    LEFT JOIN rooms r ON mr.room_id = r.id
    LEFT JOIN tenants t ON mr.tenant_id = t.id
    LEFT JOIN admins a ON mr.assigned_to = a.id
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

// Fetch recent amenity history (last 10)
$sql_history = "SELECT mh.*, mr.room_id, r.room_number, mr.tenant_id, t.name AS customer_name, a.username AS admin_name, cb.username AS completed_by_name
    FROM maintenance_history mh
    LEFT JOIN maintenance_requests mr ON mh.maintenance_request_id = mr.id
    LEFT JOIN rooms r ON mr.room_id = r.id
    LEFT JOIN tenants t ON mr.tenant_id = t.id
    LEFT JOIN admins a ON mr.assigned_to = a.id
    LEFT JOIN admins cb ON mh.completed_by = cb.id
    ORDER BY mh.moved_to_history_at DESC LIMIT 10";
$history = $conn->query($sql_history);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Amenities Queue</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
            <!-- Amenity Requests Table -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-list-task"></i> Active Amenity Requests</div>
                    <div>
                        <span class="badge bg-warning text-dark me-1">Pending: <?= intval(
                            $counts['pending_count'] ?? 0) ?></span>
                        <span class="badge bg-info text-dark me-1">Ongoing: <?= intval(
                            $counts['ongoing_count'] ?? 0) ?></span>
                        <span class="badge bg-success">Completed: <?= intval(
                            $counts['completed_count'] ?? 0) ?></span>
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
                                    <th>Description</th>
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
                                    <td><?= nl2br(htmlspecialchars($row['description'])) ?></td>
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
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <!-- Amenity History Table -->
            <div class="card mb-4">
                <div class="card-header bg-secondary text-white">
                    <i class="bi bi-clock-history"></i> Recent Amenity History
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Room</th>
                                    <th>Customer</th>
                                    <th>Description</th>
                                    <th>Completed By</th>
                                    <th>Completed At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($history->num_rows > 0): $i = 1; while ($row = $history->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td><?= htmlspecialchars($row['room_number']) ?></td>
                                    <td><?= htmlspecialchars($row['customer_name']) ?></td>
                                    <td><?= nl2br(htmlspecialchars($row['description'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($row['completed_by_name'] ?? $row['admin_name'] ?? '') ?></td>
                                    <td><?= isset($row['completed_at']) ? date('Y-m-d H:i', strtotime($row['completed_at'])) : (isset($row['moved_to_history_at']) ? date('Y-m-d H:i', strtotime($row['moved_to_history_at'])) : '') ?></td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="6" class="text-center text-muted">No recent amenity history.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
