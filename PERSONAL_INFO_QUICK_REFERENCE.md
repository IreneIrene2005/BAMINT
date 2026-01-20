# Personal Information Management - Quick Reference

## 🎯 Feature Overview

**What:** Tenants can update their personal info. Admins can verify changes.
**Where:** Tenants → My Profile | Admins → Tenant Management
**When:** Available 24/7 for tenants, periodic verification for admins
**Why:** Keep information current and accurate

## 👨‍💼 For Tenants

### Access Profile
```
1. Login at http://localhost/BAMINT/
2. Use tenant credentials
3. Click "My Profile" in sidebar
```

### Edit Information
```
1. Click "My Profile"
2. Update fields:
   - Full Name
   - Phone Number
   - Email Address
   - ID Number
3. Click "Save Changes"
4. Success message appears
```

### Form Validation
```
Phone: 10+ digits (with dashes/spaces OK)
Email: Must be valid format (user@domain.com)
Name: Cannot be empty
Duplicate Email: Cannot use another tenant's email
```

### View Information
```
✓ Personal Info (name, phone, email, ID)
✓ Room Details (number, type, rent, move-in date)
✓ Account Status (active/inactive)
✓ Account Creation Date
```

## 👨‍⚖️ For Admins

### Access Tenant Management
```
1. Login as admin
2. Click "Tenant Management" in sidebar
3. Browse or search for tenants
```

### Search & Filter
```
Search:
- By Name: "John Smith"
- By Email: "john@email.com"
- By Phone: "555-1234567"

Filter:
- All / Active / Inactive
```

### View Tenant Details
```
1. Find tenant in list
2. Click "View Details" button
3. Modal shows:
   - Personal info
   - Room assignment
   - Billing summary
   - Recent bills
```

### Verify Profile
```
1. Click "Verify Profile" button
2. Optionally add notes
3. Click "Verify Profile" to confirm
4. Verification recorded
```

### Edit Tenant
```
1. Click "Edit" button
2. Update information
3. Change room assignment
4. Update dates
5. Click "Save Changes"
```

## 📊 Database Verification

### Check Verification Status
```sql
-- Unverified tenants
SELECT name, phone, email, verification_date 
FROM tenants 
WHERE verification_date IS NULL;

-- All verifications
SELECT name, verified_by, verification_date, verification_notes 
FROM tenants 
WHERE verification_date IS NOT NULL 
ORDER BY verification_date DESC;
```

## 🔒 Security Quick Check

- ✅ Login required (both tenant and admin)
- ✅ Role-based access (tenant can't access admin pages)
- ✅ Email validation (prevents invalid emails)
- ✅ Duplicate prevention (can't use another's email)
- ✅ Phone validation (must be proper format)
- ✅ Data isolation (tenants see only their info)
- ✅ SQL protection (all queries prepared statements)

## 📍 File Locations

```
Tenant Files:
├── tenant_profile.php         (view & edit)
├── tenant_dashboard.php       (navigation)
├── tenant_bills.php           (navigation)
├── tenant_payments.php        (navigation)
└── tenant_maintenance.php     (navigation)

Admin Files:
├── admin_tenants.php          (management)
├── templates/sidebar.php      (navigation)
└── tenant_actions.php         (backend)

Database:
├── db/database.php            (connection)
└── db/migrate_add_verification.php (schema update)

Documentation:
├── PERSONAL_INFO_MANAGEMENT.md (technical)
├── PERSONAL_INFO_SETUP.md (usage guide)
└── PERSONAL_INFO_IMPLEMENTATION.md (summary)
```

## 🆘 Common Issues & Solutions

**Can't access My Profile?**
→ Make sure you're logged in as tenant, not admin

**Email validation failed?**
→ Check format is user@domain.com (valid email)

**Phone number rejected?**
→ Must have 10+ digits (dashes and spaces OK)

**Duplicate email error?**
→ That email is already used by another tenant

**Changes not saving?**
→ Check error messages, fix validation issues

**Admin can't see Tenant Management?**
→ Make sure you're logged in as admin user

**Verification not showing?**
→ Check database, verify admin confirmed action

## 📈 Usage Statistics

### Useful Queries

**Active unverified tenants:**
```sql
SELECT COUNT(*) FROM tenants 
WHERE status = 'active' AND verification_date IS NULL;
```

**Tenants updated recently:**
```sql
SELECT name, phone, updated_at FROM tenants 
ORDER BY updated_at DESC LIMIT 10;
```

**Verification rate:**
```sql
SELECT 
  COUNT(*) as total,
  SUM(CASE WHEN verification_date IS NOT NULL THEN 1 ELSE 0 END) as verified,
  ROUND(100 * SUM(CASE WHEN verification_date IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*), 2) as verification_rate
FROM tenants;
```

## 🧪 Quick Test Scenarios

**Scenario 1: Tenant Updates Phone**
```
1. Login as tenant
2. Go to My Profile
3. Change phone to "555-9876543"
4. Click Save
5. ✓ Should show success message
```

**Scenario 2: Admin Verifies Profile**
```
1. Login as admin
2. Go to Tenant Management
3. Click "Verify Profile" on any tenant
4. Add note: "Information correct"
5. Click Verify
6. ✓ Should show success message
```

**Scenario 3: Invalid Email**
```
1. Go to My Profile
2. Enter "notanemail"
3. Click Save
4. ✓ Should show error message
```

## 🚀 Performance Tips

- Search uses indexed columns (name, email, phone)
- Modal loads via AJAX (lighter page loads)
- Database queries optimized with JOINs
- Session caching reduces database calls
- Prepared statements prevent slowdown

## 📞 Support Resources

1. **PERSONAL_INFO_MANAGEMENT.md** - Full technical details
2. **PERSONAL_INFO_SETUP.md** - Complete setup guide
3. **Database Logs** - Check for errors
4. **Browser Console** - Check for JavaScript errors

## 🔄 Workflow Summary

```
TENANT WORKFLOW:
Profile Page → Edit Form → Validation → Database Update → Success → Verified by Admin

ADMIN WORKFLOW:
Tenant List → Search/Filter → View Details → Verify → Add Notes → Recorded
```

## 📅 Maintenance Tasks

**Weekly:**
- Review unverified tenant profiles
- Check for missing information
- Follow up on recent changes

**Monthly:**
- Generate verification report
- Archive old verification records
- Monitor email change patterns

**Quarterly:**
- Validate all phone numbers
- Check for duplicate emails
- Review verification coverage

## 🎓 Training Points

**For Tenants:**
- "Your profile page is available anytime"
- "Update your phone or email as needed"
- "Admin will verify your changes"
- "Use valid email format for login"

**For Admins:**
- "Review tenant info regularly"
- "Add notes during verification"
- "Search efficiently using filters"
- "Edit when corrections needed"

---

Last Updated: January 20, 2026
System Version: v1.0
