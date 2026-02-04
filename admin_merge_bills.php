<?php
session_start();
require_once 'db_pdo.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header('location: index.php');
    exit;
}

$pdo = $db; // db_pdo.php provides $db PDO instance
$message = '';
$error = '';

// Find tenants with more than one active bill
$dupesStmt = $pdo->query("SELECT t.id, t.name, COUNT(b.id) as cnt FROM tenants t JOIN bills b ON b.tenant_id = t.id WHERE (b.status != 'paid' OR DATE_ADD(b.updated_at, INTERVAL 7 DAY) >= NOW()) GROUP BY t.id HAVING cnt > 1 ORDER BY t.name ASC");
$duplicates = $dupesStmt->fetchAll(PDO::FETCH_ASSOC);

// Preview or merge for a tenant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tenant_id'])) {
    $tenant_id = intval($_POST['tenant_id']);
    if (isset($_POST['action']) && $_POST['action'] === 'preview') {
        // just load the bills for preview
        $stmt = $pdo->prepare("SELECT id, room_id, billing_month, amount_due, amount_paid, status, notes, updated_at FROM bills WHERE tenant_id = :tid AND (status != 'paid' OR DATE_ADD(updated_at, INTERVAL 7 DAY) >= NOW()) ORDER BY billing_month DESC");
        $stmt->execute(['tid' => $tenant_id]);
        $previewBills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // fetch tenant name for heading
        $tstmt = $pdo->prepare("SELECT id, name FROM tenants WHERE id = :id LIMIT 1");
        $tstmt->execute(['id' => $tenant_id]);
        $previewTenant = $tstmt->fetch(PDO::FETCH_ASSOC);
    } elseif (isset($_POST['action']) && $_POST['action'] === 'merge') {
        // perform merge into most recent (first) bill (confirmed via client-side prompt)
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id, room_id, billing_month, amount_due, amount_paid, status, notes FROM bills WHERE tenant_id = :tid AND (status != 'paid' OR DATE_ADD(updated_at, INTERVAL 7 DAY) >= NOW()) ORDER BY billing_month DESC");
            $stmt->execute(['tid' => $tenant_id]);
            $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($bills) < 2) {
                throw new Exception('No duplicate active bills found for this tenant.');
            }

            $target = $bills[0];
            $sourceBills = array_slice($bills, 1);
            $targetId = intval($target['id']);

            $totalAddedDue = 0;

            foreach ($sourceBills as $s) {
                $sid = intval($s['id']);
                // Move bill_items
                $updItems = $pdo->prepare("UPDATE bill_items SET bill_id = :target WHERE bill_id = :source");
                $updItems->execute(['target' => $targetId, 'source' => $sid]);

                // Move maintenance_requests references
                $updReq = $pdo->prepare("UPDATE maintenance_requests SET billed_bill_id = :target WHERE billed_bill_id = :source");
                $updReq->execute(['target' => $targetId, 'source' => $sid]);

                // Move payment transactions
                $updPay = $pdo->prepare("UPDATE payment_transactions SET bill_id = :target WHERE bill_id = :source");
                $updPay->execute(['target' => $targetId, 'source' => $sid]);

                // Sum amounts to add
                $totalAddedDue += floatval($s['amount_due']);

                // Append notes (avoid duplicates)
                $notes = trim($s['notes'] ?? '');
                if ($notes) {
                    $existingNotes = $target['notes'] ?? '';
                    if (stripos($existingNotes, $notes) === false) {
                        $existingNotes = trim($existingNotes . ' | ' . $notes, " |\t\n\r");
                        $updateNotes = $pdo->prepare("UPDATE bills SET notes = :notes WHERE id = :id");
                        $updateNotes->execute(['notes' => $existingNotes, 'id' => $targetId]);
                    }
                }

                // Delete the source bill
                $del = $pdo->prepare("DELETE FROM bills WHERE id = :id");
                $del->execute(['id' => $sid]);
            }

            // Recompute target amount_due and amount_paid and status
            $sumDueStmt = $pdo->prepare("SELECT amount_due FROM bills WHERE id = :id LIMIT 1");
            $sumDueStmt->execute(['id' => $targetId]);
            $currentDue = floatval($sumDueStmt->fetchColumn());

            $newDue = $currentDue + $totalAddedDue;

            // Recompute amount_paid from payment_transactions (verified/approved)
            $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM payment_transactions WHERE bill_id = :id AND payment_status IN ('verified','approved')");
            $paidStmt->execute(['id' => $targetId]);
            $newPaid = floatval($paidStmt->fetchColumn());

            $newStatus = ($newPaid >= $newDue && $newDue > 0) ? 'paid' : (($newPaid > 0) ? 'partial' : 'pending');

            $updateBill = $pdo->prepare("UPDATE bills SET amount_due = :due, amount_paid = :paid, status = :status, updated_at = NOW() WHERE id = :id");
            $updateBill->execute(['due' => $newDue, 'paid' => $newPaid, 'status' => $newStatus, 'id' => $targetId]);

            $pdo->commit();
            $message = 'Merged ' . count($sourceBills) . ' bill(s) into Bill #' . $targetId . '.';

            // refresh duplicates list
            $dupesStmt = $pdo->query("SELECT t.id, t.name, COUNT(b.id) as cnt FROM tenants t JOIN bills b ON b.tenant_id = t.id WHERE (b.status != 'paid' OR DATE_ADD(b.updated_at, INTERVAL 7 DAY) >= NOW()) GROUP BY t.id HAVING cnt > 1 ORDER BY t.name ASC");
            $duplicates = $dupesStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Merge failed: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merge Duplicate Bills - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <h1 class="h2 mb-3">Merge Duplicate Active Bills</h1>
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (empty($duplicates)): ?>
                <div class="alert alert-info">No tenants with duplicate active bills found.</div>
            <?php else: ?>
                <div class="list-group mb-4">
                    <?php foreach ($duplicates as $d): ?>
                        <form method="POST" class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?php echo htmlspecialchars($d['name']); ?></strong>
                                <div class="small text-muted"><?php echo intval($d['cnt']); ?> active bills</div>
                            </div>
                            <div>
                                <button name="action" value="preview" type="submit" class="btn btn-outline-secondary btn-sm" formaction="" formmethod="post">Preview</button>
                                <input type="hidden" name="tenant_id" value="<?php echo intval($d['id']); ?>">
                                <button name="action" value="merge" type="submit" class="btn btn-danger btn-sm ms-2" onclick="return confirm('Merge all active bills for <?php echo addslashes(htmlspecialchars($d['name'])); ?> into one? This operation is irreversible. Proceed?');">Merge</button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($previewBills)): ?>
                <h3>Preview for <?php echo htmlspecialchars($previewBills[0]['tenant_id'] ?? $_POST['tenant_id']); ?></h3>
                <p class="text-muted">Target bill will be the most recent billing month (first row).</p>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead><tr><th>Bill ID</th><th>Month</th><th>Due</th><th>Paid</th><th>Status</th><th>Notes</th></tr></thead>
                        <tbody>
                            <?php foreach ($previewBills as $i => $b): ?>
                                <tr <?php echo $i === 0 ? 'class="table-success"' : ''; ?>>
                                    <td><?php echo intval($b['id']); ?></td>
                                    <td><?php echo htmlspecialchars($b['billing_month']); ?></td>
                                    <td>₱<?php echo number_format($b['amount_due'],2); ?></td>
                                    <td>₱<?php echo number_format($b['amount_paid'],2); ?></td>
                                    <td><?php echo htmlspecialchars($b['status']); ?></td>
                                    <td><?php echo htmlspecialchars($b['notes'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>
</body>
</html>