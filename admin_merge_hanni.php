<?php
session_start();
require_once 'db_pdo.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header('location: index.php');
    exit;
}

$pdo = $db ?? $pdo; // fallback depending on include
$message = '';
$error = '';
$customerName = 'hanni'; // target customer name to merge

// find customer by name (case-insensitive)
$cstmt = $pdo->prepare("SELECT id, name FROM customers WHERE name LIKE :n LIMIT 1");
$cstmt->execute(['n' => "%$customerName%"]);
$customer = $cstmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    $error = "No customer found with name like '{$customerName}'.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'merge' && $customer) {
    // perform merge for this customer
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, room_id, billing_month, amount_due, amount_paid, status, notes FROM bills WHERE customer_id = :cid AND (status != 'paid' OR DATE_ADD(updated_at, INTERVAL 7 DAY) >= NOW()) ORDER BY billing_month DESC");
        $stmt->execute(['cid' => $customer['id']]);
        $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($bills) < 2) {
            throw new Exception('No duplicate active bills found for this customer.');
        }

        $target = $bills[0];
        $sourceBills = array_slice($bills, 1);
        $targetId = intval($target['id']);
        $totalAddedDue = 0;
        $movedItems = 0;

        foreach ($sourceBills as $s) {
            $sid = intval($s['id']);
            // Move bill_items
            $updItems = $pdo->prepare("UPDATE bill_items SET bill_id = :target WHERE bill_id = :source");
            $updItems->execute(['target' => $targetId, 'source' => $sid]);
            $movedItems += $updItems->rowCount();

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
        $message = 'Merged ' . count($sourceBills) . ' bill(s) into Bill #' . $targetId . '. Moved ' . $movedItems . ' item(s).';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Merge failed: ' . $e->getMessage();
    }
}

// fetch bills for preview (if customer exists)
$previewBills = [];
if ($customer) {
    $stmt = $pdo->prepare("SELECT id, room_id, billing_month, amount_due, amount_paid, status, notes, updated_at FROM bills WHERE customer_id = :cid AND (status != 'paid' OR DATE_ADD(updated_at, INTERVAL 7 DAY) >= NOW()) ORDER BY billing_month DESC");
    $stmt->execute(['cid' => $customer['id']]);
    $previewBills = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merge "hanni" Bills - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container mt-4">
    <h1 class="h3">Merge bills for "<?php echo htmlspecialchars($customerName); ?>"</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <p class="text-muted">Please ensure you have a DB backup before proceeding. This operation is irreversible.</p>

    <?php if ($customer): ?>
        <h5>Customer: <?php echo htmlspecialchars($customer['name']); ?> (ID: <?php echo intval($customer['id']); ?>)</h5>

        <?php if (empty($previewBills) || count($previewBills) < 2): ?>
            <div class="alert alert-info">No duplicate active bills found for this customer.</div>
        <?php else: ?>
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

            <form method="POST" onsubmit="return confirm('Merge all active bills for <?php echo addslashes(htmlspecialchars($customer['name'])); ?> into the most recent one? This is irreversible. Proceed?');">
                <input type="hidden" name="action" value="merge">
                <button class="btn btn-danger">Merge all active bills for "<?php echo htmlspecialchars($customer['name']); ?>"</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <div class="mt-3">
        <a href="admin_merge_bills.php" class="btn btn-outline-secondary">Back to Merge Utility</a>
        <a href="bills.php" class="btn btn-outline-primary ms-2">View Bills</a>
    </div>
</div>
</body>
</html>