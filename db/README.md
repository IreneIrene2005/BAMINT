# Database Setup Files

This folder contains all database-related files for the BAMINT system.

## Files Description

### 1. **setup.php** ⭐ START HERE
**Purpose**: Initial database and table creation  
**When to use**: First time installation or if tables are missing  
**What it does**:
- Creates the `bamint` database if it doesn't exist
- Creates all 6 required tables from init.sql
- Verifies all tables were created successfully
- Shows list of created tables

**How to use**:
1. Open browser
2. Navigate to: `http://localhost/XAMPP/db/setup.php`
3. Wait for success message
4. See list of all created tables

---

### 2. **database.php**
**Purpose**: Database connection configuration  
**What it does**:
- Establishes PDO connection to MySQL
- Sets error mode to exceptions
- Available globally via `require_once "db/database.php"`

**Configuration**:
```php
$host = "localhost";      // Database server
$username = "root";       // MySQL username
$password = "";           // MySQL password (empty by default in XAMPP)
$dbname = "bamint";       // Database name
```

**Usage**:
```php
<?php
require_once "db/database.php";
// $conn is now available throughout the script
$stmt = $conn->prepare("SELECT * FROM tenants");
```

---

### 3. **init.sql**
**Purpose**: Complete database schema definition  
**What it contains**: SQL statements to create all 6 tables:
1. **admins** - Administrator/staff accounts
2. **tenants** - Tenant information
3. **rooms** - Room inventory
4. **bills** - Monthly billing records
5. **payment_transactions** - Payment tracking
6. **maintenance_requests** - Maintenance request tracking

**Table Structure**:

#### admins
```sql
- id (INT, Primary Key, Auto-increment)
- username (VARCHAR, Unique)
- password (VARCHAR, Hashed)
- created_at, updated_at (Timestamps)
```

#### tenants
```sql
- id (INT, Primary Key)
- name, email, phone, id_number (VARCHAR)
- room_id (INT, Foreign Key → rooms)
- move_in_date, move_out_date (DATE)
- status (VARCHAR: 'active' or 'inactive')
- created_at, updated_at (Timestamps)
```

#### rooms
```sql
- id (INT, Primary Key)
- room_number (VARCHAR, Unique)
- room_type (VARCHAR: Standard, Deluxe, Economy, Suite, etc.)
- description (TEXT)
- rate (DECIMAL: monthly rent amount)
- status (VARCHAR: 'occupied' or 'vacant' or 'maintenance')
- created_at, updated_at (Timestamps)
```

#### bills
```sql
- id (INT, Primary Key)
- tenant_id, room_id (INT, Foreign Keys)
- billing_month (VARCHAR: YYYY-MM format)
- amount_due, discount, amount_paid (DECIMAL)
- status (VARCHAR: 'unpaid', 'partially_paid', 'paid', 'overdue')
- generated_date, paid_date (DATE)
- created_at, updated_at (Timestamps)
```

#### payment_transactions
```sql
- id (INT, Primary Key)
- bill_id (INT, Foreign Key → bills)
- tenant_id (INT, Foreign Key → tenants)
- payment_amount (DECIMAL)
- payment_method (VARCHAR: Cash, Check, Online, Cheque)
- payment_date (DATE)
- notes (TEXT)
- recorded_by (INT, Foreign Key → admins)
- created_at, updated_at (Timestamps)
```

#### maintenance_requests
```sql
- id (INT, Primary Key)
- tenant_id, room_id (INT, Foreign Keys)
- category (VARCHAR: Plumbing, Electrical, HVAC, etc.)
- description (TEXT)
- priority (VARCHAR: low, normal, high)
- status (VARCHAR: pending, in_progress, completed, cancelled)
- assigned_to (INT, Foreign Key → admins)
- submitted_date, start_date, completion_date (DATETIME)
- cost (DECIMAL)
- notes (TEXT)
- created_at, updated_at (Timestamps)
```

**Features**:
- Foreign key constraints with CASCADE delete
- Proper indexes on frequently searched columns
- Timestamp tracking for audit trail
- UTF-8 character set for international support

---

### 4. **migrate.php**
**Purpose**: Database schema updates (legacy)  
**Note**: Use `setup.php` for initial installation instead  
**What it does**:
- Adds missing columns to existing tables
- Handles incremental database updates
- Safe for databases with existing data

**When to use**: Only if you need to add new columns to existing tables after initial setup

---

## Database Tables Summary

| Table | Purpose | Records |
|-------|---------|---------|
| admins | Staff/admin accounts | 1+ per staff member |
| tenants | Tenant information | 1+ per tenant |
| rooms | Room inventory | 1+ per room |
| bills | Monthly billing | Multiple per tenant per year |
| payment_transactions | Payment records | Multiple per bill |
| maintenance_requests | Maintenance tracking | Multiple per room/year |

---

## Foreign Key Relationships

```
admins (1) ──┬─→ (many) payment_transactions
             └─→ (many) maintenance_requests

tenants (1) ──┬─→ (many) bills
              ├─→ (many) payment_transactions
              └─→ (many) maintenance_requests

rooms (1) ────┬─→ (many) bills
              └─→ (many) maintenance_requests
```

---

## Backup & Restore

### Backup Database
```bash
# Using MySQL command line:
mysqldump -u root bamint > backup.sql

# Or use phpMyAdmin:
1. Select bamint database
2. Click "Export"
3. Choose "SQL" format
4. Click "Go"
```

### Restore Database
```bash
# Using MySQL command line:
mysql -u root bamint < backup.sql

# Or via setup.php (re-creates all tables):
1. Visit http://localhost/BAMINT/db/setup.php
2. Tables will be recreated
```

---

## Database Maintenance

### Check Database Status
```sql
-- List all tables
SHOW TABLES;

-- Check specific table structure
DESCRIBE tenants;

-- Check table sizes
SELECT table_name, 
       ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.tables
WHERE table_schema = 'bamint';
```

### Optimize Database
```bash
# After large operations:
http://localhost/BAMINT/db/optimize.php

# Or manually:
OPTIMIZE TABLE admins;
OPTIMIZE TABLE tenants;
OPTIMIZE TABLE rooms;
OPTIMIZE TABLE bills;
OPTIMIZE TABLE payment_transactions;
OPTIMIZE TABLE maintenance_requests;
```

---

## Initial Setup Workflow

1. **First Time Installation**
   ```
   a) Click: http://localhost/BAMINT/db/setup.php
   b) Verify success message
   c) Check table list displayed
   d) Visit: http://localhost/BAMINT/register.php to create admin account
   e) Login and start using system
   ```

2. **Troubleshooting**
   ```
   a) Error: "Database doesn't exist"
      → Re-run setup.php
   
   b) Error: "Table not found"
      → Re-run setup.php
   
   c) Error: "Connection failed"
      → Verify MySQL is running
      → Check database.php credentials
   ```

---

## Database Configuration

### To change database credentials:
Edit `database.php`:
```php
$host = "localhost";        // Change database host
$username = "root";         // Change MySQL username
$password = "";             // Add password if needed
$dbname = "bamint";         // Change database name
```

### To create database manually:
```sql
CREATE DATABASE IF NOT EXISTS bamint 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE bamint;

-- Then run init.sql contents
```

---

## Security Notes

- Passwords are hashed using `password_hash()` before storage
- All SQL queries use prepared statements (PDO)
- Foreign keys prevent orphaned records
- No sensitive data stored in plain text
- Audit trail via created_at/updated_at timestamps

---

## Support

For issues with database setup:
1. Verify MySQL is running
2. Run setup.php again
3. Check error messages carefully
4. Review DEPLOYMENT_GUIDE.md troubleshooting section

---

**Database Files v1.0**  
Part of BAMINT - Boarding House Management System
