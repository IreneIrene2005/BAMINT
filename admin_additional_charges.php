<?php
session_start();
require_once 'db_connect.php';
require_once 'db_pdo.php';
require_once 'db/notifications.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}

$tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : 0;
$message = '';
$error = '';
// Show flash messages (set after POST redirects)
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'bill_now' && isset($_POST['request_id'])) {
        $request_id = intval($_POST['request_id']);
        try {
            // Get request details
            $stmt = $pdo->prepare("SELECT id, tenant_id, category, cost FROM maintenance_requests WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $request_id]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($req && floatval($req['cost']) > 0) {
                $billId = addMaintenanceCostToBill($pdo, intval($req['tenant_id']), floatval($req['cost']), intval($req['id']), $req['category']);
                if ($billId) {
                    createNotification($pdo, 'tenant', intval($req['tenant_id']), 'new_bill', 'New Bill Generated', 'An additional charge for amenity "' . $req['category'] . '" has been added to your bill. Amount: ₱' . number_format($req['cost'], 2), $billId, 'bill', 'tenant_bills.php');
                    $message = 'Request #' . $req['id'] . ' billed to Bill #' . $billId . '.';
                } else {
                    $error = 'Failed to bill the request.';
                }
            } else {
                $error = 'Request not found or has no cost.';
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'bill_all' && isset($_POST['tenant_id'])) {
        $bulkTenantId = intval($_POST['tenant_id']);
        try {
            // Find completed charges for tenant that are not yet billed (robust check: prefer maintenance_requests.billed when available)
            $cstmt = $pdo->prepare("SELECT id, category, cost FROM maintenance_requests WHERE tenant_id = :tenant_id AND status = 'completed' AND cost > 0 ORDER BY completion_date DESC");
            $cstmt->execute(['tenant_id' => $bulkTenantId]);
            $rows = $cstmt->fetchAll(PDO::FETCH_ASSOC);
            $billedCount = 0;
            $billedAmount = 0.0;
            foreach ($rows as $r) {
                // skip if already referenced in a bill
                $pattern = '%Request #' . intval($r['id']) . '%';
                $check = $pdo->prepare("SELECT id FROM bills WHERE tenant_id = :tenant_id AND notes LIKE :pattern LIMIT 1");
                $check->execute(['tenant_id' => $bulkTenantId, 'pattern' => $pattern]);
                $exists = $check->fetch(PDO::FETCH_ASSOC);
                if ($exists) {
                    continue;
                }
                $resBillId = addMaintenanceCostToBill($pdo, $bulkTenantId, floatval($r['cost']), intval($r['id']), $r['category']);
                if ($resBillId) {
                    createNotification($pdo, 'tenant', $bulkTenantId, 'new_bill', 'New Bill Generated', 'An additional charge for amenity "' . $r['category'] . '" has been added to your bill. Amount: ₱' . number_format($r['cost'], 2), $resBillId, 'bill', 'tenant_bills.php');
                    $billedCount++;
                    $billedAmount += floatval($r['cost']);
                }
            }
            if ($billedCount > 0) {
                $message = 'Billed ' . $billedCount . ' request(s) totaling ₱' . number_format($billedAmount, 2) . '.';
            } else {
                $message = 'No unbilled requests found for this tenant.';
            }
        } catch (Exception $e) {
            $error = 'Bulk billing error: ' . $e->getMessage();
        }
    }
}

// Fetch tenants for selector (exclude archived tenants: status = 'inactive')
$tenants = [];
try {
    // Use room_number for display (join rooms)
    $tstmt = $pdo->query("SELECT t.id, t.name, t.room_id, r.room_number FROM tenants t LEFT JOIN rooms r ON t.room_id = r.id WHERE t.status != 'inactive' ORDER BY t.name ASC");
    $tenants = $tstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

// Selected tenant info (for header and contextual actions)
$selectedTenant = null;
if ($tenant_id > 0) {
    try {
        $pt = $pdo->prepare("SELECT t.id, t.name, r.room_number, t.status FROM tenants t LEFT JOIN rooms r ON t.room_id = r.id WHERE t.id = :id LIMIT 1");
        $pt->execute(['id' => $tenant_id]);
        $selectedTenant = $pt->fetch(PDO::FETCH_ASSOC);
        
        // Prevent viewing archived tenants
        if ($selectedTenant && $selectedTenant['status'] === 'inactive') {
            $selectedTenant = null;
            $error = 'This customer is archived and cannot be accessed.';
            $tenant_id = 0;
        }
    } catch (Exception $e) {
        // ignore
    }
}

$charges = [];
$total_cost = 0;
$total_unbilled = 0;
$has_billed_column = true;
$migration_notice = '';
if ($tenant_id > 0) {
    try {
        // Try the enhanced query (newer schema)
        $stmt = $pdo->prepare("SELECT id, category, cost, status, submitted_date, completion_date, billed, billed_bill_id FROM maintenance_requests WHERE tenant_id = :tenant_id AND status = 'completed' AND cost > 0 ORDER BY submitted_date DESC");
        $stmt->execute(['tenant_id' => $tenant_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Likely the 'billed' column is missing (migration not applied). Fall back to older query and notify admin.
        $has_billed_column = false;
        $migration_notice = 'Database migration missing: maintenance_requests.billed column not found. Please run the migration at db/migrate_add_bill_items.php or apply the schema updates.';
        try {
            $stmt = $pdo->prepare("SELECT id, category, cost, status, submitted_date, completion_date FROM maintenance_requests WHERE tenant_id = :tenant_id AND status = 'completed' AND cost > 0 ORDER BY submitted_date DESC");
            $stmt->execute(['tenant_id' => $tenant_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            $error = 'Error loading charges: ' . $e2->getMessage();
            $rows = [];
        }
    }

    $billLookup = $pdo->prepare("SELECT id, billing_month, amount_due, amount_paid, status, notes FROM bills WHERE tenant_id = :tenant_id AND notes LIKE :pattern LIMIT 1");

    foreach ($rows as $r) {
        $billed = false;
        $bill_id = null;
        $billing_month = null;
        $bill_status = 'not_billed';

        if ($has_billed_column && !empty($r['billed'])) {
            if (!empty($r['billed_bill_id'])) {
                // Try to fetch bill referenced by billed_bill_id
                $bb = $pdo->prepare("SELECT id, billing_month, status FROM bills WHERE id = :id LIMIT 1");
                $bb->execute(['id' => $r['billed_bill_id']]);
                $billRec = $bb->fetch(PDO::FETCH_ASSOC);
                if ($billRec) {
                    $billed = true;
                    $bill_id = $billRec['id'];
                    $billing_month = $billRec['billing_month'];
                    $bill_status = $billRec['status'];
                } else {
                    // Fallback to notes lookup
                    $pattern = '%Request #' . intval($r['id']) . '%';
                    $billLookup->execute(['tenant_id' => $tenant_id, 'pattern' => $pattern]);
                    $bill = $billLookup->fetch(PDO::FETCH_ASSOC);
                    if ($bill) {
                        $billed = true;
                        $bill_id = $bill['id'];
                        $billing_month = $bill['billing_month'];
                        $bill_status = $bill['status'];
                    } else {
                        $billed = false;
                        $total_unbilled += floatval($r['cost']);
                    }
                }
            } else {
                $pattern = '%Request #' . intval($r['id']) . '%';
                $billLookup->execute(['tenant_id' => $tenant_id, 'pattern' => $pattern]);
                $bill = $billLookup->fetch(PDO::FETCH_ASSOC);
                if ($bill) {
                    $billed = true;
                    $bill_id = $bill['id'];
                    $billing_month = $bill['billing_month'];
                    $bill_status = $bill['status'];
                } else {
                    $billed = false;
                    $total_unbilled += floatval($r['cost']);
                }
            }
        } else {
            // Older schema: determine billed status only by searching bills notes
            $pattern = '%Request #' . intval($r['id']) . '%';
            $billLookup->execute(['tenant_id' => $tenant_id, 'pattern' => $pattern]);
            $bill = $billLookup->fetch(PDO::FETCH_ASSOC);
            if ($bill) {
                $billed = true;
                $bill_id = $bill['id'];
                $billing_month = $bill['billing_month'];
                $bill_status = $bill['status'];
            } else {
                $billed = false;
                $total_unbilled += floatval($r['cost']);
            }
        }

        $total_cost += floatval($r['cost']);
        // Determine display month: prefer the request's submitted_date (month when tenant added the amenity), fallback to bill's billing_month, then completion_date
        $display_month = null;
        $display_title = null;
        if (!empty($r['submitted_date'])) {
            $display_month = date('F Y', strtotime($r['submitted_date']));
            $display_title = 'Added: ' . date('Y-m-d', strtotime($r['submitted_date']));
        } elseif ($billed && !empty($billing_month)) {
            $display_month = date('F Y', strtotime($billing_month));
            $display_title = 'Billed: ' . date('Y-m-d', strtotime($billing_month));
        } elseif (!empty($r['completion_date'])) {
            $display_month = date('F Y', strtotime($r['completion_date']));
            $display_title = 'Completed: ' . date('Y-m-d', strtotime($r['completion_date']));
        }
        $charges[] = [
            'request_id' => intval($r['id']),
            'category' => $r['category'],
            'cost' => floatval($r['cost']),
            'billed' => $billed,
            'bill_id' => $bill_id,
            'billing_month' => $billing_month,
            'bill_status' => $bill_status,
            'display_month' => $display_month,
            'display_title' => $display_title
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Additional Charges</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <div class="header-banner mb-4">
                <h1 class="h2 mb-0"><i class="bi bi-wallet2"></i> Additional Charges</h1>
                <p class="mb-0">View and manage amenity charges added to tenant bills. Use the actions to bill individual items or bill all unbilled charges for a tenant.</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if (!empty($migration_notice)): ?>
                <div class="alert alert-warning d-flex justify-content-between align-items-center">
                    <div><?php echo htmlspecialchars($migration_notice); ?> <strong>Run:</strong> <code>db/migrate_add_bill_items.php</code></div>
                    <div>
                        <a href="/BAMINT/db/run_migration.php" class="btn btn-sm btn-outline-danger">Run migration</a>
                    </div>
                </div>
            <?php endif; ?>



            <form method="GET" class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="tenant_id" class="form-label">Select Tenant</label>
                    <select name="tenant_id" id="tenant_id" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Select tenant --</option>
                        <?php foreach ($tenants as $t): ?>
                            <?php $roomLabel = !empty($t['room_number']) ? $t['room_number'] : $t['room_id']; ?>
                            <option value="<?php echo intval($t['id']); ?>" <?php echo $tenant_id == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['name']); ?> (Room <?php echo htmlspecialchars($roomLabel); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 d-flex align-items-end justify-content-end">
                    <div class="me-3 text-end">
                        <div><strong>Total Charges:</strong> ₱<?php echo number_format($total_cost, 2); ?></div>
                    </div>

                </div>
            </form>

            <?php if ($tenant_id > 0): ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <div><i class="bi bi-list-task"></i> Additional Charges for <?php echo htmlspecialchars($selectedTenant['name'] ?? ''); ?><?php echo !empty($selectedTenant['room_number']) ? ' (Room ' . htmlspecialchars($selectedTenant['room_number']) . ')' : ''; ?></div>
                            <div>
                            <span class="badge bg-secondary">Total: ₱<?php echo number_format($total_cost,2); ?></span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($charges)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Amenity</th>
                                            <th class="text-end">Amount</th>
                                            <th>Month</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($charges as $c): ?>
                                            <tr>
                                                <td><?php echo $c['request_id']; ?></td>
                                                <td><?php echo htmlspecialchars($c['category']); ?></td>
                                                <td class="text-end">₱<?php echo number_format($c['cost'], 2); ?></td>
                                                <td <?php echo !empty($c['display_title']) ? 'title="' . htmlspecialchars($c['display_title']) . '"' : ''; ?>><?php echo $c['display_month'] ? htmlspecialchars($c['display_month']) : 'Not billed'; ?></td>
                                                <td>
                                                    <?php if ($c['billed']): ?>
                                                        <span class="badge bg-success">Billed</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Not billed</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($c['billed'] && $c['bill_id']): ?>
                                                        <a href="bill_actions.php?action=view&id=<?php echo intval($c['bill_id']); ?>" class="btn btn-sm btn-outline-primary" title="View Bill"><i class="bi bi-eye"></i> View</a>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not billed</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="card-body p-3"><p class="text-muted mb-0">No additional charges found for this tenant.</p></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-muted">Select a tenant to view their additional charges.</p>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
</body>
</html>