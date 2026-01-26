# Co-Tenant System Implementation

## Overview
When a tenant requests a shared or bedspace room with multiple occupants, they now need to provide information for all roommates. Only the primary tenant (the one who made the request) is responsible for payments.

## Database Changes

### New Table: `co_tenants`
```sql
CREATE TABLE IF NOT EXISTS `co_tenants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `primary_tenant_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255),
  `phone` varchar(20),
  `id_number` varchar(255),
  `address` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `primary_tenant_id` (`primary_tenant_id`),
  KEY `room_id` (`room_id`),
  CONSTRAINT `co_tenants_ibfk_1` FOREIGN KEY (`primary_tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `co_tenants_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Setup Instructions

### Step 1: Create the Table
Run the migration:
```
http://localhost/BAMINT/db/migrate_add_co_tenants.php
```

Or manually run the SQL query in phpMyAdmin.

### Step 2: Features Enabled

1. **Tenant Room Request Form**
   - When tenant selects shared/bedspace room
   - Shows occupant count input
   - Dynamically generates co-tenant fields based on count
   - Co-tenant fields include: name, email, phone, ID number, address

2. **Data Storage**
   - Primary tenant info stored in `room_requests` table
   - Co-tenant info stored in `co_tenants` table
   - All co-tenants linked to primary tenant

3. **Payment Processing**
   - Only primary tenant can make payments
   - All bills go to primary tenant
   - Co-tenants listed for reference only

## How It Works

### For Tenants

**When requesting a room:**
1. Tenant selects a room
2. Clicks "Request Room"
3. Fills in their information (Name, Email, Phone, Address)
4. Selects number of occupants
5. If > 1 occupant, co-tenant fields appear
6. Fills in all co-tenant information
7. Submits request

**System behavior:**
- Primary tenant (logged-in user) is responsible for payments
- Co-tenants are listed in the system for reference
- Admin can see all occupants when reviewing room request

### For Admins

**When approving room requests:**
1. Admin reviews room request
2. Sees primary tenant info
3. Can view all co-tenant information
4. When approved, primary tenant is added to `tenants` table
5. Primary tenant's name goes to bills (not co-tenants)
6. Can view co-tenant list from room details

## Database Relationships

```
room_requests
  └─ tenant_id (FK) → tenants
  └─ room_id (FK) → rooms

co_tenants
  ├─ primary_tenant_id (FK) → tenants
  └─ room_id (FK) → rooms
```

## Queries for Admin Use

### View all co-tenants for a room
```sql
SELECT ct.* FROM co_tenants ct
WHERE ct.room_id = 1
ORDER BY ct.created_at ASC;
```

### View co-tenants for a primary tenant
```sql
SELECT ct.* FROM co_tenants ct
WHERE ct.primary_tenant_id = 5
ORDER BY ct.created_at ASC;
```

### View pending room requests with co-tenant counts
```sql
SELECT rr.id, rr.tenant_id, rr.tenant_count, 
       COUNT(ct.id) as co_tenant_count
FROM room_requests rr
LEFT JOIN co_tenants ct ON rr.room_id = ct.room_id AND rr.tenant_id = ct.primary_tenant_id
WHERE rr.status = 'pending'
GROUP BY rr.id;
```

## Important Notes

- **Primary Tenant**: The logged-in user who made the room request
- **Co-Tenants**: Other occupants listed for reference
- **Payment Responsibility**: Only primary tenant
- **Bills**: Generated only for primary tenant
- **Occupancy**: Verified based on room type (Single: 1, Shared: 2, Bedspace: 4)

## Testing

### To test the feature:

1. **Create a bedspace room** in the system (max 4 occupants)
2. **Login as a tenant**
3. **Request the bedspace room** with 3 occupants
4. **Fill in:**
   - Your info (primary tenant)
   - 2 co-tenant information forms should appear
5. **Submit the request**
6. **Admin approves** the request
7. **Verify:**
   - Only primary tenant appears in tenants list
   - Co-tenants are in co_tenants table
   - Bills are only for primary tenant

## Files Modified

- `tenant_add_room.php` - Added co-tenant form fields and JavaScript
- `db/init.sql` - Added co_tenants table schema
- `db/migrate_add_co_tenants.php` - Migration script

## Future Enhancements

- Email notification to co-tenants
- Co-tenant status dashboard
- Co-tenant agreement form
- Co-tenant payment contributions tracking
- Co-tenant billing breakdown
