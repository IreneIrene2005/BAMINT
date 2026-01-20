# BAMINT - Boarding House Management System

## System Overview

BAMINT is a comprehensive PHP/MySQL-based boarding house management system that handles tenant management, room inventory, billing, payment tracking, and maintenance requests.

## Completed Features

### 1. Authentication & Authorization
- **Login System** (index.php)
  - Admin/staff login with session management
  - Password hashing using PHP's password_hash()
  - Session-based authentication
  - Redirect to login for unauthorized access

- **Registration System** (register.php)
  - Admin account creation
  - Form validation (username uniqueness, password confirmation)
  - Minimum 6-character password requirement
  - Secure password hashing

### 2. Tenant Management
- **View Tenants** (tenants.php)
  - List all tenants with complete information
  - Real-time occupancy status
  - Status indicators (Active/Inactive)
  
- **Search & Filter**
  - Filter by Name, Email, Phone, ID Number, Status
  - Real-time search functionality
  
- **CRUD Operations** (tenant_actions.php)
  - Add new tenants with all details
  - Edit tenant information
  - Delete tenants (with cascade delete to rooms)
  - Deactivate/Activate tenant status
  - Automatic room status updates when tenant assigned/removed

### 3. Room Management
- **View Rooms** (rooms.php)
  - Complete room inventory with:
    - Room number and type
    - Monthly rate (in Philippine Pesos ₱)
    - Occupancy status
    - Tenant details
  
- **Search & Filter**
  - Filter by Room Number, Type, Status
  - Occupancy count display
  
- **CRUD Operations** (room_actions.php)
  - Add new rooms with details
  - Edit room information
  - Delete rooms
  - Manage room types (Standard, Deluxe, Economy, Suite, etc.)
  - Room status management (Occupied/Vacant/Maintenance)

### 4. Monthly Billing
- **Bills Management** (bills.php)
  - Generate monthly bills automatically
  - View all bills with filtering
  - Manual bill entry capability
  - Real-time balance calculation
  - Bill status tracking (Unpaid, Partially Paid, Paid, Overdue)
  
- **Bill Operations** (bill_actions.php)
  - Auto-generate bills for all active tenants
  - Edit payments and discounts
  - Record payment methods
  - Professional invoice generation with print functionality
  - Payment status updates
  
- **Search & Filter**
  - Filter by Month, Tenant, Status
  - Quick search functionality

### 5. Payment Tracking
- **Payment History** (payment_history.php)
  - Complete transaction history
  - Payment method tracking (Cash, Check, Online, Cheque)
  - Payment date and amount recording
  - Summary statistics:
    - Total payments received
    - Average payment amount
    - Total transactions
  
- **Search & Filter**
  - Filter by Tenant, Payment Method, Date Range
  - Transaction search functionality

### 6. Overdue Management
- **Overdue Tracking** (overdue_reminders.php)
  - Identify overdue bills
  - Calculate days overdue
  - Show upcoming bills due within 7 days
  - Summary statistics:
    - Total overdue amount
    - Number of accounts overdue
    - Upcoming payments due
  
- **Reminder System**
  - Send reminder notifications (framework in place)
  - Track reminder history

### 7. Maintenance Request System
- **Request Management** (maintenance_requests.php)
  - Submit new maintenance requests
  - View all pending/active maintenance requests
  - Comprehensive request information:
    - Tenant identification
    - Room location
    - Category (Plumbing, Electrical, HVAC, Furniture, Cleaning, Security, Internet, Other)
    - Priority levels (Low, Normal, High)
    - Description
  
- **Request Operations** (maintenance_actions.php)
  - Submit new requests with auto-populated tenant/room
  - View detailed request information
  - Edit requests with:
    - Status updates (Pending → In Progress → Completed/Cancelled)
    - Staff assignment
    - Start and completion dates
    - Cost tracking
    - Notes/comments
  - Delete requests
  - AJAX endpoint for room auto-population
  
- **Request Filtering**
  - Filter by Status (Pending, In Progress, Completed, Cancelled)
  - Filter by Priority (High, Normal, Low)
  - Filter by Category
  - Filter by Assigned Staff
  - Text search (tenant name, description, room)
  
- **Summary Statistics**
  - Total requests count
  - Pending count
  - In Progress count
  - Completed count
  - Urgent requests (High priority)

- **Maintenance History** (maintenance_history.php)
  - View completed and cancelled maintenance records
  - Historical data analysis with statistics:
    - Total completed requests
    - Cancelled request count
    - Average resolution time (hours)
    - Total cost of completed work
    - Average cost per request
  
- **History Filtering**
  - Filter by Month
  - Filter by Category
  - Filter by Tenant
  - Text search
  - View past repairs with costs

## Database Structure

### Tables
1. **admins** - Administrator/staff accounts
   - id, username, password

2. **tenants** - Tenant information
   - id, name, email, phone, id_number, room_id, move_in_date, move_out_date, status

3. **rooms** - Room inventory
   - id, room_number, room_type, description, rate, status

4. **bills** - Monthly billing records
   - id, tenant_id, room_id, billing_month, amount_due, discount, amount_paid, status, generated_date, paid_date

5. **payment_transactions** - Payment records
   - id, bill_id, tenant_id, payment_amount, payment_method, payment_date, notes, recorded_by

6. **maintenance_requests** - Maintenance tracking
   - id, tenant_id, room_id, category, description, priority, status, assigned_to
   - submitted_date, start_date, completion_date
   - cost, notes, created_at, updated_at

### Database Features
- Foreign key constraints with CASCADE delete
- Proper indexing for performance
- Timestamp tracking for all records
- Transaction support for data consistency

## Technology Stack

- **Backend**: PHP 7.4+ with PDO
- **Database**: MySQL 5.7+
- **Frontend**: Bootstrap 5.3.2
- **Icons**: Bootstrap Icons 1.11.3
- **Authentication**: Session-based with password hashing

## File Structure

```
BAMINT/
├── index.php                    # Login page
├── register.php                 # Registration page
├── dashboard.php                # Main dashboard
├── tenants.php                  # Tenant list & management
├── tenant_actions.php           # Tenant CRUD operations
├── rooms.php                    # Room list & management
├── room_actions.php             # Room CRUD operations
├── bills.php                    # Billing management
├── bill_actions.php             # Bill operations & invoices
├── payment_history.php          # Payment transaction history
├── overdue_reminders.php        # Overdue bill management
├── maintenance_requests.php     # Active maintenance requests
├── maintenance_actions.php      # Maintenance request operations
├── maintenance_history.php      # Completed maintenance records
├── logout.php                   # Session termination
├── db/
│   ├── database.php            # PDO database connection
│   ├── init.sql                # Database schema
│   └── migrate.php             # Database migration script
├── templates/
│   ├── header.php              # Navigation header
│   └── sidebar.php             # Navigation sidebar
└── public/
    └── css/
        └── style.css           # Custom styles
```

## Key Features

### Security
- PDO prepared statements to prevent SQL injection
- Password hashing using PHP password_hash()
- Session-based authentication
- Server-side validation
- CSRF protection through session management

### Data Integrity
- Foreign key constraints
- Transaction support for multi-step operations
- Cascading deletes for related records
- Atomic operations (all-or-nothing)

### User Experience
- Responsive Bootstrap UI
- Real-time search and filtering
- Status badges and color coding
- Modal dialogs for forms
- Professional invoice generation
- Intuitive navigation menu

### Business Logic
- Automatic bill generation from tenant list
- Real-time balance calculation
- Payment status automation
- Occupancy tracking
- Maintenance workflow management
- Cost tracking for repairs

## Usage Instructions

### Initial Setup
1. Import database schema from `db/init.sql`
2. Visit `db/migrate.php` to initialize/update database
3. Create admin account via `register.php`
4. Login via `index.php`

### Daily Operations
1. **Tenant Management**: Add/edit tenants, track status
2. **Room Management**: Manage inventory, track occupancy
3. **Billing**: Generate monthly bills, track payments
4. **Maintenance**: Submit and track maintenance requests
5. **Reporting**: View payment history and maintenance records

## Currency & Localization
- Default currency: Philippine Pesos (₱)
- Date format: Month DD, YYYY (e.g., Jan 15, 2024)
- All money values formatted to 2 decimal places

## Future Enhancements
- Email notifications for overdue bills
- SMS reminders for maintenance
- Detailed reporting and analytics
- Landlord/Manager export reports
- Tenant portal for viewing bills
- Automated backup system
- Multi-property support

## Support & Maintenance
For issues or feature requests, contact the system administrator.

---
**Version**: 1.0  
**Last Updated**: January 2024  
**Status**: Production Ready
