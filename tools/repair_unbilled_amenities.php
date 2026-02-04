<?php
session_start();
require_once __DIR__ . '/../db_pdo.php';
require_once __DIR__ . '/../db/notifications.php';

// Simple admin-only repair script to attach amenity charges to bills (id in notes)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo "Forbidden - admin login required.";
    exit;
}

$pdo = $pdo ?? null;
if (!$pdo) {
    echo "PDO connection not found.";
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, tenant_id, category, cost, completion_date FROM maintenance_requests WHERE status = 'completed' AND cost IS NOT NULL AND cost > 0 ORDER BY completion_date ASC");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $processed = 0;
    $skipped = 0;
    $errors = [];

    foreach ($rows as $r) {
        $pattern = '%Request #' . intval($r['id']) . '%';
        $check = $pdo->prepare("SELECT id FROM bills WHERE tenant_id = :tenant_id AND notes LIKE :pattern LIMIT 1");
        $check->execute(['tenant_id' => $r['tenant_id'], 'pattern' => $pattern]);
        $exists = $check->fetch(PDO::FETCH_ASSOC);
        if ($exists) {
            $skipped++;
            continue; // already referenced
        }

        $billId = addMaintenanceCostToBill($pdo, intval($r['tenant_id']), floatval($r['cost']), intval($r['id']), $r['category']);
        if ($billId) {
            $processed++;
        } else {
            $errors[] = "Failed to bill Request #{$r['id']} (Tenant: {$r['tenant_id']})";
        }
    }

    echo "Repair completed. Processed: $processed. Skipped (already billed): $skipped.";
    if (!empty($errors)) {
        echo "\nErrors:\n" . implode("\n", $errors);
    }
} catch (Exception $e) {
    echo "Error running repair: " . $e->getMessage();
}
