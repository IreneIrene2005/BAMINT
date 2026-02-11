<?php
/**
 * Sync tenant names into tenant_accounts.name
 * Run: php db/migrations/sync_tenant_account_names.php
 */

require_once __DIR__ . '/../database.php';

try {
    $sql = "UPDATE tenant_accounts ta
            JOIN tenants t ON ta.tenant_id = t.id
            SET ta.name = t.name
            WHERE ta.name IS NULL OR ta.name = ''";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $count = $stmt->rowCount();

    echo $count . " rows updated.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

?>
