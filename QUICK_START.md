# BAMINT Quick Reference Card

## 🚀 First Time Setup (3 Steps)

### Step 1: Initialize Database
```
Visit: http://localhost/BAMINT/db/setup.php
✓ Creates database and all tables
✓ Shows list of created tables
```

### Step 2: Create Admin Account
```
Visit: http://localhost/BAMINT/register.php
✓ Enter username and password
✓ System auto-logs you in
```

### Step 3: Start Using
```
Visit: http://localhost/BAMINT/
✓ Dashboard loads
✓ All modules available
```

---

## 📊 Main Features at a Glance

| Feature | Link | Purpose |
|---------|------|---------|
| 🏠 Dashboard | /dashboard.php | System overview |
| 👥 Tenants | /tenants.php | Manage tenants |
| 🏢 Rooms | /rooms.php | Manage rooms |
| 📝 Bills | /bills.php | Billing & payments |
| 💰 Payment History | /payment_history.php | Track payments |
| ⚠️ Overdue Bills | /overdue_reminders.php | Track overdue accounts |
| 🔧 Maintenance | /maintenance_requests.php | Maintenance requests |
| 📋 Maintenance History | /maintenance_history.php | Past repairs |

---

## 🔐 Login Information

**Username**: Your chosen admin username  
**Password**: Your chosen password (minimum 6 characters)

---

## 📱 Key Workflows

### Add a Tenant
1. Click Tenants → Add Tenant
2. Fill in name, email, phone, ID
3. Click Add Tenant

### Assign Tenant to Room
1. Click Tenants → Edit tenant
2. Select room
3. Click Save

### Generate Monthly Bills
1. Click Bills & Billing
2. Click "Generate Monthly Bills"
3. Select month
4. Click Generate

### Record a Payment
1. Click Bills & Billing
2. Click edit on bill
3. Enter payment amount
4. Select payment method
5. Click Save

### Submit Maintenance Request
1. Click Maintenance Requests
2. Click "Submit Request"
3. Select tenant (room auto-fills)
4. Select category, priority
5. Describe issue
6. Click Submit

### Complete a Request
1. Click Maintenance Requests
2. Click edit on request
3. Assign to staff
4. Change status to "Completed"
5. Enter completion date and cost
6. Click Save

---

## 🆘 Common Issues

### "Table doesn't exist"
→ Run: http://localhost/BAMINT/db/setup.php

### "Can't login"
→ Create account at: http://localhost/BAMINT/register.php

### "MySQL not running"
→ Start MySQL in XAMPP Control Panel

### "Page not found"
→ Verify URL: http://localhost/BAMINT/filename.php

---

## 💡 Tips & Tricks

✓ **Search**: Use search boxes on any list page  
✓ **Filter**: Use dropdown filters for status, type, etc.  
✓ **Sorting**: Click column headers to sort  
✓ **Bulk Bills**: Generate all bills at once  
✓ **Invoices**: Click invoice button to print bills  
✓ **Status Badges**: Color-coded for quick reference  
✓ **Icons**: Hover over icons to see tooltips  

---

## 🎯 Monthly Checklist

- [ ] Week 1: Add new tenants for the month
- [ ] Week 2: Generate monthly bills
- [ ] Week 3: Record payments
- [ ] Week 4: Review overdue reminders
- [ ] End of month: Check maintenance history

---

## 📞 Support Resources

- **Installation**: See DEPLOYMENT_GUIDE.md
- **Features**: See README.md
- **Maintenance**: See MAINTENANCE_GUIDE.md
- **Testing**: See TESTING_GUIDE.md
- **Database**: See db/README.md

---

## 🔒 Security Reminders

✓ Keep your password secure  
✓ Don't share admin credentials  
✓ Log out when leaving computer  
✓ Regular backups recommended  
✓ Don't modify database directly  

---

## 📊 Database Tables

**6 Tables Total**:
1. admins - Staff accounts
2. tenants - Resident info
3. rooms - Room inventory
4. bills - Monthly charges
5. payment_transactions - Payments
6. maintenance_requests - Repairs

---

## 🌐 System URLs

```
Login:                http://localhost/BAMINT/
Dashboard:           http://localhost/BAMINT/dashboard.php
Database Setup:      http://localhost/BAMINT/db/setup.php
Register New Admin:  http://localhost/BAMINT/register.php
```

---

## 💾 Backup Database

```bash
# Command line:
mysqldump -u root bamint > backup.sql

# Or via phpMyAdmin
```

---

**BAMINT Quick Reference v1.0**  
*Boarding House Management System*
