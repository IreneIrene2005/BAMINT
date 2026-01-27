<?php
/**
 * Archive Cron Job
 * This script should be run periodically (daily) via server cron to archive old records
 * 
 * Usage in crontab:
 * 0 2 * * * /usr/bin/php /var/www/html/BAMINT/db/archive_cron.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.");
}

require_once "database.php";
require_once "ArchiveManager.php";

try {
    $manager = new ArchiveManager($conn);
    
    // Run archiving
    $payments_archived = $manager->archiveOldPayments();
    $maintenance_archived = $manager->archiveOldMaintenanceRequests();
    
    echo "[" . date('Y-m-d H:i:s') . "] Archive job completed successfully.\n";
    echo "- Payments archived: $payments_archived\n";
    echo "- Maintenance requests archived: $maintenance_archived\n";
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Archive job failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
