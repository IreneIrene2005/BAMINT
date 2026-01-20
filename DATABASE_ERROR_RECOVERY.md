# Database Error Recovery Guide

## Issue: "Table 'bamint.maintenance_requests' doesn't exist"

### ✅ Quick Fix (2 Steps)

**Step 1**: Run database setup
```
Visit: http://localhost/BAMINT/db/setup.php
```

**Step 2**: Refresh the page that had the error
```
The table should now exist and work properly
```

---

## Understanding the Error

### What happened?
- The maintenance_requests table wasn't created in the MySQL database
- This usually happens when:
  - Database wasn't initialized on first install
  - Migration script wasn't run
  - Database was deleted or reset

### Why it happened?
The setup process requires running the database initialization script to create all tables. If this step is skipped, tables won't exist when the application tries to use them.

---

## Complete Database Recovery

### If the above doesn't work:

**Step 1**: Delete existing database (if corrupt)
```
A) Via phpMyAdmin:
   - Login to phpMyAdmin
   - Click "bamint" database
   - Click "Drop" (delete database)

B) Via MySQL command line:
   - mysql -u root
   - DROP DATABASE bamint;
   - exit;
```

**Step 2**: Re-run setup
```
Visit: http://localhost/BAMINT/db/setup.php
```

**Step 3**: Verify tables were created
```
The page should show:
✓ Database 'bamint' created/verified
✓ All tables created/verified
✓ List of 6 tables:
  - admins
  - tenants
  - rooms
  - bills
  - payment_transactions
  - maintenance_requests
```

---

## Verify Database via phpMyAdmin

1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Click "bamint" database in left sidebar
3. Verify you see 6 tables:
   - admins
   - tenants
   - rooms
   - bills
   - payment_transactions ✓ (should be here)
   - maintenance_requests ✓ (should be here)

If you don't see these tables, run setup.php again.

---

## Verify Database via MySQL

```bash
# Connect to MySQL
mysql -u root

# Select database
USE bamint;

# List all tables
SHOW TABLES;
```

You should see output like:
```
+-----------------------------+
| Tables_in_bamint           |
+-----------------------------+
| admins                      |
| bills                       |
| maintenance_requests        |
| payment_transactions        |
| rooms                       |
| tenants                     |
+-----------------------------+
6 rows in set (0.00 sec)
```

---

## If MySQL isn't responding

### Step 1: Check MySQL Status
```
A) XAMPP Control Panel:
   - Find "MySQL" in the control panel
   - It should show "Running"
   - If not, click "Start"

B) Command Line:
   - Open Command Prompt
   - Type: mysql -u root
   - If error: MySQL is not running
```

### Step 2: Start MySQL
**Windows**:
```
A) XAMPP Control Panel:
   - Click "Start" next to MySQL
   - Wait for status to change to "Running"

B) Command Line (as Administrator):
   - net start MySQL80
   OR
   - net start MySQL57
   (depending on your MySQL version)
```

### Step 3: Verify MySQL is running
```
Visit: http://localhost/phpmyadmin
Should load without errors
```

---

## Common Causes & Solutions

### Cause 1: Setup script not run
**Solution**: 
```
Visit: http://localhost/BAMINT/db/setup.php
```

### Cause 2: MySQL not running
**Solution**:
```
1. Open XAMPP Control Panel
2. Click "Start" for MySQL
3. Wait for green status
4. Try again
```

### Cause 3: Wrong database name
**Solution**:
```
Check db/database.php:
- Should say: $dbname = "bamint";
- Should be lowercase
- Must match database created in MySQL
```

### Cause 4: MySQL permission denied
**Solution**:
```
Edit db/database.php credentials:
- Verify username is correct (usually "root")
- Verify password is correct (usually empty for XAMPP)
- MySQL should be accessible via:
  http://localhost/phpmyadmin
```

### Cause 5: Database exists but tables missing
**Solution**:
```
1. Run: http://localhost/BAMINT/db/setup.php
2. This will create all missing tables
3. No data will be lost if you have existing data
```

---

## Prevention Tips

✓ **Always run setup.php on first install**  
✓ **Keep database backups regularly**  
✓ **Verify MySQL is running before accessing app**  
✓ **Don't manually modify database structure**  
✓ **Use setup.php if you add new features**  

---

## After Recovery

### Verify System Works
1. Visit: http://localhost/BAMINT/index.php
2. Should load without errors
3. Try to login or register

### Verify Maintenance Module
1. Login or create admin account
2. Click "Maintenance Requests" in sidebar
3. Should load without errors
4. Click "Submit Request" button
5. Should open form without errors

### Verify All Features
1. Click each sidebar menu item
2. Each page should load without errors
3. Try basic operations (search, add, edit)

---

## Getting Help

If you still see errors:

1. **Check error message carefully**
   - Note exact table name mentioned
   - Note which file has the error
   - Note line number where error occurs

2. **Review setup.php output**
   - Look for ✓ marks (success)
   - Look for ⚠ marks (warnings)
   - Note any error messages

3. **Check MySQL connection**
   - Via phpMyAdmin: http://localhost/phpmyadmin
   - Should load and show databases
   - Should show "bamint" database

4. **Review documentation**
   - db/README.md - Database documentation
   - DEPLOYMENT_GUIDE.md - Installation guide
   - QUICK_START.md - Quick reference

---

## Manual Database Creation

If setup.php doesn't work:

### Step 1: Connect to MySQL
```bash
mysql -u root
```

### Step 2: Create database
```sql
CREATE DATABASE IF NOT EXISTS bamint 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE bamint;
```

### Step 3: Create tables
Open `C:\xampp\htdocs\BAMINT\db\init.sql` in a text editor, copy its contents, and paste into MySQL command line.

---

## Backup Before Attempting Recovery

Before making any changes, backup your data:

```bash
# Backup database
mysqldump -u root bamint > bamint_backup_$(date +%Y%m%d).sql

# Or via phpMyAdmin:
# 1. Click bamint database
# 2. Click "Export"
# 3. Choose "SQL"
# 4. Click "Go"
```

---

**Database Error Recovery v1.0**  
Part of BAMINT - Boarding House Management System

---

## Quick Recovery Checklist

- [ ] Check MySQL is running
- [ ] Visit http://localhost/XAMPP/db/setup.php
- [ ] Verify success message appears
- [ ] Check for 6 tables in list
- [ ] Refresh the error page
- [ ] Try accessing feature again
- [ ] If still failing: Delete DB and re-run setup.php
- [ ] If still failing: Check db/README.md for manual steps
