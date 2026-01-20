# Personal Information Management System

## Overview
The Personal Information Management system allows tenants to view and edit their personal information, while administrators can review and verify changes for accuracy and compliance.

## Features

### Tenant Features (tenant_profile.php)

#### 1. **View Personal Information**
- Full name
- Phone number
- Email address
- Government ID number

#### 2. **Edit Personal Information**
- Update phone number
- Update email address
- Update full name
- Update ID number
- Real-time form validation

#### 3. **View Room & Lease Details** (Read-only)
- Room number
- Room type
- Monthly rent amount
- Move-in date
- Tenant status (Active/Inactive)
- Move-out date (if applicable)

#### 4. **Account Information Display**
- Account status
- Account creation date
- Security note about password management

#### 5. **Change Validation**
- Email format validation (must be valid email)
- Phone number validation (minimum 10 digits)
- Duplicate email checking (ensures no other tenant uses same email)
- Name required validation
- Database transaction safety

### Admin Features (admin_tenants.php)

#### 1. **Tenant Management Dashboard**
- Statistics cards showing:
  - Total tenants
  - Active tenants
  - Rooms assigned
  - Unassigned tenants

#### 2. **Search & Filter Capabilities**
- Search by tenant name
- Search by email address
- Search by phone number
- Filter by status (Active/Inactive/All)

#### 3. **View Tenant Details Modal**
- Personal information
- Room and lease information
- Billing summary
- Recent bills display
- Verification of tenant information

#### 4. **Verify Profile Feature**
- Mark tenant profile as reviewed
- Add verification notes
- Track verification status
- Confirm information accuracy

#### 5. **Manage Tenants**
- View full tenant list
- Access tenant detail pages
- Edit tenant information
- View payment history
- Monitor recent billing activity

## Database Tables

### tenants Table
```sql
- id (Primary Key)
- name (VARCHAR 255)
- phone (VARCHAR 20)
- email (VARCHAR 255) - Historical storage
- id_number (VARCHAR 50) - Government ID
- room_id (INT, Nullable) - Foreign key to rooms
- start_date (DATE) - Move-in date
- end_date (DATE, Nullable) - Move-out date
- status (ENUM: 'active', 'inactive')
- verification_notes (TEXT) - Admin notes about verification
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)
```

### tenant_accounts Table
```sql
- id (Primary Key)
- tenant_id (INT) - Foreign key to tenants
- email (VARCHAR 255, UNIQUE) - Login email
- password (VARCHAR 255) - Hashed password
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)
```

## User Workflows

### Tenant Workflow: Update Personal Information

1. **Navigate to Profile**
   - Click "My Profile" in tenant dashboard sidebar
   - View current personal information

2. **Edit Information**
   - Click in form fields to edit:
     - Full Name
     - Phone Number
     - Email Address (used for login)
     - ID Number (optional)

3. **System Validation**
   - Phone number format checked (10+ digits)
   - Email format validated
   - Email checked for duplicates
   - All required fields verified

4. **Submit Changes**
   - Click "Save Changes" button
   - Form submits via POST to same page

5. **Database Update**
   - If validation passes:
     - Tenants table updated with new values
     - Tenant_accounts table updated with new email
     - Session updated with new user data
     - Success message displayed

6. **View Updated Information**
   - Profile immediately shows new values
   - No page reload required
   - Changes persist across sessions

### Admin Workflow: Verify Tenant Information

1. **Navigate to Tenant Management**
   - Click "Tenant Management" in admin sidebar
   - View dashboard with statistics

2. **Search for Tenant**
   - Use name, email, or phone search box
   - Filter by status (Active/Inactive)
   - System displays matching tenants

3. **View Tenant Details**
   - Click "View Details" button on tenant card
   - Modal displays:
     - Personal information
     - Room assignment
     - Billing summary
     - Recent bills
   - Review information for accuracy

4. **Verify Profile**
   - Click "Verify Profile" button
   - Modal opens for verification
   - Optionally add verification notes
   - Click "Verify Profile" to confirm

5. **Verification Complete**
   - Tenant record marked as verified
   - Notes stored in database
   - Success message displayed
   - Can view verification history

6. **Edit if Necessary**
   - Click "Edit" button to modify tenant info
   - Admin can change:
     - Name
     - Phone
     - Email
     - ID Number
     - Room assignment
     - Start/end dates

## Security Features

### Tenant Level
1. **Session Validation** - Only logged-in tenants can access their profile
2. **Role Verification** - Session role must be 'tenant'
3. **Data Isolation** - Tenants can only view/edit their own information
4. **Input Validation** - All inputs validated before database update
5. **Prepared Statements** - All SQL queries use prepared statements (SQL injection prevention)
6. **Email Duplicate Check** - Prevents account conflicts
7. **Password Security** - Passwords only manageable by admin (not tenant)

### Admin Level
1. **Admin Role Check** - Only admins access admin_tenants.php
2. **Detailed Access Logging** - All admin verifications stored with notes
3. **Prepared Statements** - All admin queries secure
4. **Search Sanitization** - Search inputs properly parameterized

## File Locations

### Tenant-Facing Files
- `tenant_profile.php` - Tenant profile view and edit page
- `templates/sidebar.php` - Includes link to profile
- `db/database.php` - Database connection

### Admin-Facing Files
- `admin_tenants.php` - Tenant management dashboard
- `tenant_actions.php` - Handles get_details AJAX request
- `templates/sidebar.php` - Includes "Tenant Management" link

## Code Examples

### View Tenant Profile (Tenant)
```php
// In tenant_profile.php
$stmt = $conn->prepare("
    SELECT t.*, r.room_number, r.room_type, r.rate
    FROM tenants t
    LEFT JOIN rooms r ON t.room_id = r.id
    WHERE t.id = :tenant_id
");
$stmt->execute(['tenant_id' => $tenant_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);
```

### Update Personal Information
```php
// Tenant submits form
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validation_errors[] = "Please enter a valid email address.";
    }
    
    // Check for duplicate email
    $stmt = $conn->prepare("
        SELECT id FROM tenant_accounts 
        WHERE email = :email AND tenant_id != :tenant_id
    ");
    $stmt->execute(['email' => $email, 'tenant_id' => $tenant_id]);
    
    // Update if no errors
    $stmt = $conn->prepare("
        UPDATE tenants 
        SET name = :name, phone = :phone, id_number = :id_number
        WHERE id = :tenant_id
    ");
    $stmt->execute([...]);
}
```

### Search Tenants (Admin)
```php
// In admin_tenants.php
$query .= " AND (t.name LIKE :search OR ta.email LIKE :search OR t.phone LIKE :search)";
$params['search'] = "%$search_query%";

$stmt = $conn->prepare($query);
$stmt->execute($params);
```

## Error Handling

### Validation Errors
- Email format validation
- Phone number format validation
- Duplicate email detection
- Required field validation

### Database Errors
- Connection failures caught and reported
- Transaction failures rolled back
- Foreign key constraint violations handled
- Error messages displayed to user

## Testing Checklist

### Tenant Features
- [ ] Tenant can login and access profile page
- [ ] Personal information displays correctly
- [ ] Phone number format validation works
- [ ] Email format validation works
- [ ] Duplicate email check prevents update
- [ ] Form submission updates database
- [ ] Session data refreshed after update
- [ ] Room details display correctly
- [ ] Page responsive on mobile devices
- [ ] Success message appears after update

### Admin Features
- [ ] Admin can access tenant management page
- [ ] Statistics cards display correct numbers
- [ ] Search by name works
- [ ] Search by email works
- [ ] Search by phone works
- [ ] Status filter works
- [ ] View details modal loads tenant information
- [ ] View details shows recent bills
- [ ] Verify profile modal opens
- [ ] Verification notes save to database
- [ ] Success message appears after verification
- [ ] Edit button navigates to edit page
- [ ] Admin can modify tenant information
- [ ] Room change updates room status

## Performance Considerations

1. **Database Queries**
   - Personal info queries use prepared statements
   - Indexed fields: tenant_id, email
   - LEFT JOIN for optional room data

2. **Session Management**
   - Tenant session stored securely
   - Email updated in session after change
   - Name updated in session after change

3. **AJAX Details Loading**
   - Modal loads tenant details via AJAX
   - Reduces page load impact
   - Smooth user experience

## Future Enhancements

1. **Audit Trail** - Track all profile edits with timestamps
2. **Change History** - Display previous values when edited
3. **Photo Upload** - Allow tenants to upload profile pictures
4. **Email Notifications** - Notify admin when tenant updates info
5. **Two-Factor Authentication** - Verify email changes
6. **Export Reports** - Export tenant information as PDF/CSV
7. **Bulk Actions** - Admin verify multiple profiles at once
8. **Activity Log** - View all verification activities

## Support

For issues or questions:
1. Check validation messages for required fixes
2. Verify email format and phone number format
3. Ensure no duplicate email exists
4. Check browser console for JavaScript errors
5. Verify admin/tenant session is active
