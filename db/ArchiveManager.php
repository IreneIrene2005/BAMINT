<?php
/**
 * Archive Management System
 * Archives old records (payments and maintenance requests) after 1 month
 */

require_once "db/database.php";

class ArchiveManager {
    private $conn;
    private $archive_age_days = 30; // Archive records older than 30 days
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    /**
     * Archive old payment transactions (completed ones older than 1 month)
     */
    public function archiveOldPayments() {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO payment_transactions_archive 
                (id, bill_id, tenant_id, payment_amount, payment_method, payment_type, 
                 payment_status, verified_by, verification_date, notes, created_at)
                SELECT id, bill_id, tenant_id, payment_amount, payment_method, payment_type,
                       payment_status, verified_by, verification_date, notes, created_at
                FROM payment_transactions
                WHERE payment_status = 'verified'
                AND DATE_ADD(created_at, INTERVAL ? DAY) <= NOW()
                AND id NOT IN (SELECT id FROM payment_transactions_archive)
            ");
            
            $stmt->bindParam(1, $this->archive_age_days, PDO::PARAM_INT);
            $stmt->execute();
            
            // Delete archived records from active table
            $delete_stmt = $this->conn->prepare("
                DELETE FROM payment_transactions
                WHERE payment_status = 'verified'
                AND DATE_ADD(created_at, INTERVAL ? DAY) <= NOW()
            ");
            $delete_stmt->bindParam(1, $this->archive_age_days, PDO::PARAM_INT);
            $delete_stmt->execute();
            
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("Error archiving payments: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Archive old maintenance requests (completed ones older than 1 month)
     */
    public function archiveOldMaintenanceRequests() {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO maintenance_requests_archive
                (id, tenant_id, room_id, category, description, priority, status,
                 assigned_to, completion_date, notes, created_at)
                SELECT id, tenant_id, room_id, category, description, priority, status,
                       assigned_to, completion_date, notes, created_at
                FROM maintenance_requests
                WHERE status = 'completed'
                AND DATE_ADD(created_at, INTERVAL ? DAY) <= NOW()
                AND id NOT IN (SELECT id FROM maintenance_requests_archive)
            ");
            
            $stmt->bindParam(1, $this->archive_age_days, PDO::PARAM_INT);
            $stmt->execute();
            
            // Delete archived records from active table
            $delete_stmt = $this->conn->prepare("
                DELETE FROM maintenance_requests
                WHERE status = 'completed'
                AND DATE_ADD(created_at, INTERVAL ? DAY) <= NOW()
            ");
            $delete_stmt->bindParam(1, $this->archive_age_days, PDO::PARAM_INT);
            $delete_stmt->execute();
            
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("Error archiving maintenance requests: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get archived payment transactions for a tenant
     */
    public function getArchivedPayments($tenant_id = null) {
        try {
            if ($tenant_id) {
                $stmt = $this->conn->prepare("
                    SELECT pta.*, b.billing_month, b.amount_due,
                           t.name as tenant_name, r.room_number
                    FROM payment_transactions_archive pta
                    LEFT JOIN bills b ON pta.bill_id = b.id
                    LEFT JOIN tenants t ON pta.tenant_id = t.id
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE pta.tenant_id = :tenant_id
                    ORDER BY pta.archived_at DESC
                ");
                $stmt->execute(['tenant_id' => $tenant_id]);
            } else {
                $stmt = $this->conn->prepare("
                    SELECT pta.*, b.billing_month, b.amount_due,
                           t.name as tenant_name, r.room_number
                    FROM payment_transactions_archive pta
                    LEFT JOIN bills b ON pta.bill_id = b.id
                    LEFT JOIN tenants t ON pta.tenant_id = t.id
                    LEFT JOIN rooms r ON b.room_id = r.id
                    ORDER BY pta.archived_at DESC
                ");
                $stmt->execute();
            }
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error retrieving archived payments: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get archived maintenance requests for a tenant
     */
    public function getArchivedMaintenanceRequests($tenant_id = null) {
        try {
            if ($tenant_id) {
                $stmt = $this->conn->prepare("
                    SELECT mra.*, t.name as tenant_name, r.room_number
                    FROM maintenance_requests_archive mra
                    LEFT JOIN tenants t ON mra.tenant_id = t.id
                    LEFT JOIN rooms r ON mra.room_id = r.id
                    WHERE mra.tenant_id = :tenant_id
                    ORDER BY mra.archived_at DESC
                ");
                $stmt->execute(['tenant_id' => $tenant_id]);
            } else {
                $stmt = $this->conn->prepare("
                    SELECT mra.*, t.name as tenant_name, r.room_number
                    FROM maintenance_requests_archive mra
                    LEFT JOIN tenants t ON mra.tenant_id = t.id
                    LEFT JOIN rooms r ON mra.room_id = r.id
                    ORDER BY mra.archived_at DESC
                ");
                $stmt->execute();
            }
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error retrieving archived maintenance requests: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get archive statistics
     */
    public function getArchiveStats() {
        try {
            $stats = [];
            
            // Payment archives count
            $stmt = $this->conn->query("SELECT COUNT(*) as count FROM payment_transactions_archive");
            $stats['archived_payments'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Maintenance archives count
            $stmt = $this->conn->query("SELECT COUNT(*) as count FROM maintenance_requests_archive");
            $stats['archived_maintenance'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Total archived records
            $stats['total_archived'] = $stats['archived_payments'] + $stats['archived_maintenance'];
            
            return $stats;
        } catch (Exception $e) {
            error_log("Error retrieving archive statistics: " . $e->getMessage());
            return ['archived_payments' => 0, 'archived_maintenance' => 0, 'total_archived' => 0];
        }
    }
}

// Run archiving if requested via cron or manual trigger
if (php_sapi_name() === 'cli' || isset($_GET['run_archive'])) {
    $manager = new ArchiveManager($conn);
    $payments_archived = $manager->archiveOldPayments();
    $maintenance_archived = $manager->archiveOldMaintenanceRequests();
    
    if (php_sapi_name() === 'cli') {
        echo "Archived $payments_archived payments and $maintenance_archived maintenance requests\n";
    }
}
?>
