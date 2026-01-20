# Personal Information Management - Setup & Usage Guide

## Quick Start

### Step 1: Run Database Migration
Before using the Personal Information Management system, run the database migration to add required columns:

```bash
# Visit in browser:
http://localhost/BAMINT/db/migrate_add_verification.php
```

This will add:
- `verification_notes` - Admin notes about verification
- `verification_date` - When profile was verified
- `verified_by` - Which admin verified the profile

### Step 2: Access the Feature

#### For Tenants:
1. Login at `http://localhost/BAMINT/index.php` with tenant credentials
2. Click "My Profile" in the sidebar
3. View or edit your personal information

#### For Admins:
1. Login at `http://localhost/BAMINT/index.php` with admin credentials
2. Click "Tenant Management" in the sidebar
3. Browse, search, and verify tenant profiles

## Feature Walkthrough

### Tenant Profile Page

**URL:** `tenant_profile.php`

**What Tenants Can Do:**
- View their personal information (name, phone, email, ID)
- Edit phone number and email address
- View room assignment details
- See lease information (room number, type, rent, move-in date)
- View account status

**Form Validation:**
```
Phone Number: Must contain 10+ digits/symbols
Email: Must be valid email format
No Duplicate Emails: Can't use email already assigned to another tenant
Name: Required field
```

**Form Submission Flow:**
1. Fill in desired changes
2. Click "Save Changes"
3. System validates all fields
4. If valid → Database updated → Success message shown
5. If invalid → Error message shown → User corrects and resubmits

### Admin Tenant Management Page

**URL:** `admin_tenants.php`

**What Admins Can Do:**
- View all tenant profiles
- Search by name, email, or phone
- Filter by status (Active/Inactive)
- View detailed tenant information in modal
- Verify tenant profiles
- Edit tenant information
- View billing summary
- Monitor recent bills
- Add verification notes

**Statistics Dashboard:**
- Total Tenants count
- Active Tenants count
- Rooms Assigned count
- Unassigned Tenants count

**Search & Filter:**
```
Name Search: "John Smith" → finds all tenants named John Smith
Email Search: "john@email.com" → finds tenant with that email
Phone Search: "555-1234" → finds tenant with that phone number
Status Filter: Active, Inactive, or All
```

**Verification Workflow:**
1. Find tenant using search/filter
2. Click "View Details" to review information
3. Click "Verify Profile" to mark as reviewed
4. Optionally add verification notes
5. Click "Verify Profile" button to confirm
6. Success message shown, verification recorded

## File Structure

```
BAMINT/
├── tenant_profile.php              # Tenant profile view & edit
├── admin_tenants.php               # Admin tenant management
├── tenant_actions.php              # Backend for get_details AJAX
├── templates/
│   └── sidebar.php                 # Navigation (updated with new links)
├── db/
│   ├── database.php                # Database connection
│   └── migrate_add_verification.php # Migration script
└── PERSONAL_INFO_MANAGEMENT.md     # Full documentation
```

## Database Schema Changes

### Tenants Table Columns

**New Columns Added:**
```sql
ALTER TABLE tenants ADD COLUMN verification_notes TEXT NULL AFTER status;
ALTER TABLE tenants ADD COLUMN verification_date TIMESTAMP NULL AFTER verification_notes;
ALTER TABLE tenants ADD COLUMN verified_by VARCHAR(255) NULL AFTER verification_date;
```

**Updated Columns:**
```sql
-- phone: Can now be updated by tenant (was admin-only before)
-- email: Now also stored in tenants table for reference
-- name: Can now be updated by tenant (was admin-only before)
```

### Example Data:
```sql
-- Tenant with verified profile
INSERT INTO tenants (name, phone, email, id_number, room_id, status, verification_notes, verification_date, verified_by)
VALUES ('John Doe', '555-1234567', 'john@email.com', 'DL123456', 1, 'active', 'Information verified and accurate', NOW(), 'admin_user');

-- Tenant without room assignment (during registration)
INSERT INTO tenants (name, phone, email, id_number, room_id, status)
VALUES ('Jane Smith', '555-7654321', 'jane@email.com', NULL, NULL, 'active');
```

## API/Action Endpoints

### Get Tenant Details (AJAX)
```
GET /tenant_actions.php?action=get_details&id=1

Response: HTML fragment with tenant information
Requires: Admin session role
```

### Edit Tenant (Form POST)
```
POST /tenant_actions.php?action=edit
Parameters: id, name, phone, email, id_number, room_id, start_date, original_room_id

Response: Redirect to tenants.php on success
Requires: Admin session role
```

### Tenant Profile Update
```
POST /tenant_profile.php
Parameters: name, phone, email, id_number, update_profile=1

Response: Same page with success/error message
Requires: Tenant session role and valid tenant_id
```

## Security Considerations

### Authentication
- ✅ Tenant can only edit own profile (session_tenant_id check)
- ✅ Admin can view and edit all tenant profiles (role check)
- ✅ All access routes protected with session validation

### Data Validation
- ✅ Email format validated before database update
- ✅ Phone number format validated (10+ characters)
- ✅ Duplicate email prevention
- ✅ SQL injection prevention (prepared statements)
- ✅ XSS prevention (htmlspecialchars on output)

### Database Operations
- ✅ All queries use PDO prepared statements
- ✅ Foreign key constraints enforced
- ✅ Transactions used for multi-step operations
- ✅ Error handling with try-catch blocks

## Testing Scenarios

### Scenario 1: Tenant Updates Phone Number
```
1. Login as tenant
2. Go to My Profile
3. Change phone from "555-1234567" to "555-9999999"
4. Click Save Changes
5. Verify:
   - Success message appears
   - Phone number updated in display
   - Phone number persists on page refresh
   - Database shows new phone number
```

### Scenario 2: Tenant Tries Invalid Email
```
1. Login as tenant
2. Go to My Profile
3. Enter invalid email "notanemail"
4. Click Save Changes
5. Verify:
   - Error message: "Please enter a valid email address"
   - Form does NOT submit
   - Phone number is not saved
```

### Scenario 3: Duplicate Email Prevention
```
1. Have two tenant accounts (A and B)
2. Login as tenant A
3. Go to My Profile
4. Try to change email to tenant B's email
5. Click Save Changes
6. Verify:
   - Error message: "This email is already registered to another account"
   - Email is NOT changed
```

### Scenario 4: Admin Verifies Tenant Profile
```
1. Login as admin
2. Go to Tenant Management
3. Search for specific tenant
4. Click "View Details"
5. Review information in modal
6. Click "Verify Profile"
7. Enter note: "Profile reviewed and verified"
8. Click "Verify Profile" button
9. Verify:
   - Success message appears
   - Verification date recorded in database
   - Notes saved
```

### Scenario 5: Admin Edits Tenant Room Assignment
```
1. Login as admin
2. Go to Tenant Management
3. Click "View Details" on tenant without room
4. Click "Edit"
5. Select room from dropdown
6. Update Start Date
7. Click "Save Changes"
8. Verify:
   - Tenant record updated
   - Room status changed to occupied
   - Old room status changed to available
```

## Troubleshooting

### Issue: "Profile updated successfully!" but changes not showing
**Solution:** 
- Clear browser cache (Ctrl+Shift+Delete)
- Refresh page with Ctrl+F5
- Check if JavaScript disabled
- Check browser console for errors

### Issue: Email validation fails for valid email
**Solution:**
- Check email format is correct (user@domain.com)
- Ensure no extra spaces before/after email
- Verify email doesn't already exist for another tenant

### Issue: Phone number validation fails
**Solution:**
- Must include 10+ digits minimum
- Can use dashes, spaces, parentheses, plus sign
- Examples: "5551234567", "555-123-4567", "+1 555 123 4567"

### Issue: Can't access admin_tenants.php (redirect to login)
**Solution:**
- Verify you're logged in as admin (not tenant)
- Check session is active
- Try logging out and back in
- Clear cookies if persistent login issues

### Issue: Migration script shows errors
**Solution:**
- Run migration script at `db/migrate_add_verification.php`
- Check PHP error logs
- Verify database connection works
- Ensure columns don't already exist

## Performance Notes

- Tenant profile loads single tenant record (fast)
- Admin tenant list uses pagination ready (currently shows all)
- Search queries indexed on name, email, phone
- AJAX modal loading avoids full page reload
- Database queries optimized with JOINs

## Related Features

This Personal Information Management system integrates with:
- **Authentication System** (index.php, register.php)
- **Tenant Dashboard** (tenant_dashboard.php)
- **Admin Dashboard** (dashboard.php)
- **Billing System** (bills management)
- **Maintenance System** (maintenance requests)

## Next Steps

After implementing this feature:

1. **Test thoroughly** using the testing scenarios above
2. **Train admins** on the verification workflow
3. **Inform tenants** that they can update their profiles
4. **Set verification policies** for how often to review profiles
5. **Monitor changes** by regularly viewing audit trails
6. **Plan enhancements** like:
   - Automatic email verification
   - Change history/audit log
   - Bulk profile verification
   - Document upload (ID verification)
   - Profile photo upload

## Support & Maintenance

### Regular Maintenance Tasks
- Review unverified tenant profiles weekly
- Archive old verification records quarterly
- Monitor for duplicate email conflicts
- Validate phone number formats regularly

### Monitoring
```sql
-- View unverified tenants
SELECT * FROM tenants WHERE verification_date IS NULL AND status = 'active';

-- View verification activity
SELECT name, verification_date, verified_by FROM tenants 
WHERE verification_date IS NOT NULL 
ORDER BY verification_date DESC;

-- Find tenants without assigned rooms
SELECT * FROM tenants WHERE room_id IS NULL AND status = 'active';
```

## Version History

- **v1.0** (Jan 2026) - Initial implementation
  - Tenant profile view and edit
  - Admin tenant management and verification
  - Email validation and duplicate prevention
  - Phone number validation
  - Room assignment management
