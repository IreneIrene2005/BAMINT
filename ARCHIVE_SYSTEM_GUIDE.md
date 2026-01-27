# Archive System Setup Guide

## Overview
The BAMINT archive system automatically moves old, completed records (payments and maintenance requests) into archive tables after 30 days of completion. This keeps the active records clean while maintaining a complete audit trail.

## Setup Instructions

### 1. Create Archive Tables
First, create the necessary archive tables by running the SQL from `db/create_archive_tables.sql` in your MySQL database:

```bash
mysql -u root -p bamint < db/create_archive_tables.sql
```

Or manually execute the SQL in phpMyAdmin.

### 2. Set Up the Archive Manager
The `ArchiveManager.php` class handles all archiving operations. It includes:
- `archiveOldPayments()` - Archives verified payments older than 30 days
- `archiveOldMaintenanceRequests()` - Archives completed maintenance requests older than 30 days
- `getArchivedPayments($tenant_id)` - Retrieves archived payments for a tenant
- `getArchivedMaintenanceRequests($tenant_id)` - Retrieves archived maintenance requests for a tenant

### 3. Configure Automated Archiving

#### Option A: Via Linux Cron Job (Recommended)
Add the following to your server's crontab to run the archiving job daily at 2 AM:

```bash
0 2 * * * /usr/bin/php /var/www/html/BAMINT/db/archive_cron.php >> /var/log/bamint_archive.log 2>&1
```

#### Option B: Manual Trigger
Visit the admin panel and click "Run Archive" button (if implemented), or visit:
```
https://your-domain/BAMINT/db/archive_cron.php?run_archive=1
```

### 4. Tenant Archives Page
Tenants can view their archived records in the new "Archives" page available in their dashboard:
- View archived payments (by month)
- View archived maintenance requests
- See when records were archived
- Statistics on total archived records

## Archive Configuration

### Change Archive Age (Default: 30 days)
Edit `ArchiveManager.php` and modify this line:
```php
private $archive_age_days = 30; // Change this value
```

### Records Archived

**Payment Transactions:**
- Only "verified" payments are archived
- Archived after 30 days of creation date
- Can be restored if needed (records are copied, not deleted immediately)

**Maintenance Requests:**
- Only "completed" requests are archived
- Archived after 30 days of creation date
- Preserved for audit trail purposes

## Archive Tables Structure

### `payment_transactions_archive`
- `id` - Transaction ID (primary key)
- `bill_id` - Associated bill
- `tenant_id` - Tenant who made payment
- `payment_amount` - Amount paid
- `payment_method` - Cash, check, transfer, etc.
- `payment_type` - Online or offline
- `payment_status` - Status (verified, approved, etc.)
- `verified_by` - Admin who verified
- `verification_date` - When verified
- `notes` - Additional notes
- `created_at` - Original creation date
- `archived_at` - When archived (automatic timestamp)

### `maintenance_requests_archive`
- `id` - Request ID (primary key)
- `tenant_id` - Tenant who submitted
- `room_id` - Room involved
- `category` - Category of maintenance
- `description` - Full description
- `priority` - High, medium, low
- `status` - Status (completed, etc.)
- `assigned_to` - Admin assigned to
- `completion_date` - When completed
- `notes` - Additional notes
- `created_at` - Original creation date
- `archived_at` - When archived (automatic timestamp)

## Viewing Archives

### As a Tenant
1. Log in to tenant portal
2. Click "Archives" in the sidebar
3. Switch between "Archived Payments" and "Archived Maintenance" tabs
4. View details of archived records

### As an Admin
Coming soon - Admin archive management page

## Retrieving Archived Records

### Via PHP Code
```php
require_once "db/ArchiveManager.php";
$manager = new ArchiveManager($conn);

// Get archived payments for a tenant
$payments = $manager->getArchivedPayments($tenant_id);

// Get archived maintenance requests for a tenant
$maintenance = $manager->getArchivedMaintenanceRequests($tenant_id);

// Get archive statistics
$stats = $manager->getArchiveStats();
echo "Total archived records: " . $stats['total_archived'];
```

## Maintenance Notes

- Archives grow daily as records age. Monitor disk space.
- Archive tables have indexes on `tenant_id` and `archived_at` for performance.
- Regularly backup archive tables for regulatory compliance.
- Old archived data can be exported to a separate backup database after 1 year.

## Troubleshooting

### Archives not being created
- Check cron job is running: `grep CRON /var/log/syslog | tail -20`
- Verify archive tables exist in database
- Check error logs in `/var/log/bamint_archive.log`

### Performance issues
- Add more indexes to archive tables if searches are slow
- Consider archiving to separate database for large installations

### Restore archived record
To restore an archived record, insert it back into the active table:
```sql
-- Restore archived payment
INSERT INTO payment_transactions SELECT * FROM payment_transactions_archive WHERE id = ?;
DELETE FROM payment_transactions_archive WHERE id = ?;
```
