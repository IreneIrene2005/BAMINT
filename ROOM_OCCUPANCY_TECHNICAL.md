# Room Occupancy System - Technical Reference

## Database Schema

### room_requests Table Structure

```sql
CREATE TABLE `room_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `tenant_count` int(11) DEFAULT 1,                    -- NEW: Number of occupants
  `tenant_info_name` varchar(255),                     -- NEW: Occupant name
  `tenant_info_email` varchar(255),                    -- NEW: Occupant email
  `tenant_info_phone` varchar(20),                     -- NEW: Occupant phone
  `tenant_info_address` text,                          -- NEW: Occupant address
  `request_date` timestamp DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `notes` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `approved_date` datetime DEFAULT NULL,               -- NEW: Approval timestamp
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `room_id` (`room_id`),
  KEY `status` (`status`),
  KEY `request_date` (`request_date`),
  CONSTRAINT `room_requests_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `room_requests_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Related Tables (Unchanged)

**rooms table:**
- id, room_number, room_type, description, rate, status

**tenants table:**
- id, name, email, phone, id_number, room_id, start_date, end_date, status

## Code Flow Diagrams

### Tenant Request Submission

```
POST /tenant_add_room.php
    ↓
Validate Input (lines 15-45)
    ├─ Check required fields (name, email, phone, address, count)
    ├─ Validate email format
    ├─ Get room details
    └─ Check occupancy limits based on room_type
         ├─ Single → max 1
         ├─ Shared → max 2
         └─ Bedspace → max 4
    ↓
Check for Duplicate (lines 47-52)
    └─ SELECT room_requests WHERE tenant_id=X AND room_id=Y AND status='pending'
    ↓
INSERT room_requests (lines 54-66)
    └─ INSERT INTO room_requests (tenant_id, room_id, tenant_count, 
       tenant_info_name, tenant_info_email, tenant_info_phone, 
       tenant_info_address, notes, status='pending')
    ↓
Return Success Message
```

### Admin Approval Process

```
POST /room_requests_queue.php?action=approve
    ↓
Get Request Details (lines 23-33)
    └─ SELECT room_requests, rooms, tenants
       WHERE room_requests.id = request_id
    ↓
Update Primary Tenant (lines 35-50)
    └─ UPDATE tenants 
       SET room_id, start_date=TODAY, status='active', 
           name, email, phone
       WHERE id = tenant_id
    ↓
Create Additional Tenants (lines 52-70)
    └─ IF tenant_count > 1:
       └─ FOR i=2 TO tenant_count:
          └─ INSERT INTO tenants
             (name="Primary Name - Occupant i", email='', phone='',
              room_id, start_date=TODAY, status='active')
    ↓
Update Room Status (lines 72-78)
    └─ UPDATE rooms 
       SET status='occupied' 
       WHERE id = room_id
    ↓
Update Request Status (lines 80-92)
    └─ UPDATE room_requests 
       SET status='approved', approved_date=NOW() 
       WHERE id = request_id
    ↓
Return Success Message
```

## Validation Rules

### Input Validation (tenant_add_room.php)

```php
// Tenant Information Validation
$errors = [];
if (empty($tenant_info_name)) $errors[] = "Name is required";
if (empty($tenant_info_email) || !filter_var($tenant_info_email, FILTER_VALIDATE_EMAIL)) 
    $errors[] = "Valid email is required";
if (empty($tenant_info_phone)) $errors[] = "Phone number is required";
if (empty($tenant_info_address)) $errors[] = "Address is required";
if ($tenant_count < 1) $errors[] = "Number of occupants must be at least 1";

// Occupancy Limit Validation
$room_type = strtolower($room['room_type']);
if ($room_type === 'single' && $tenant_count > 1) 
    $errors[] = "Single rooms can only accommodate 1 person.";
elseif ($room_type === 'shared' && $tenant_count > 2) 
    $errors[] = "Shared rooms can accommodate maximum 2 people.";
elseif ($room_type === 'bedspace' && $tenant_count > 4) 
    $errors[] = "Bedspace rooms can accommodate maximum 4 people.";
```

## SQL Queries

### Get Room Request with Details

```sql
SELECT 
    rr.id, rr.tenant_id, rr.room_id,
    rr.request_date, rr.status, rr.notes,
    rr.tenant_count,
    rr.tenant_info_name, rr.tenant_info_email,
    rr.tenant_info_phone, rr.tenant_info_address,
    t.name as tenant_name, t.email as tenant_email,
    t.phone as tenant_phone,
    r.room_number, r.room_type, r.rate, r.status as room_status
FROM room_requests rr
JOIN tenants t ON rr.tenant_id = t.id
JOIN rooms r ON rr.room_id = r.id
WHERE rr.status = 'pending'
ORDER BY rr.request_date DESC;
```

### Get Rooms with Occupancy Count

```sql
SELECT 
    r.id, r.room_number, r.room_type, r.rate, r.status,
    COUNT(t.id) as tenant_count,
    GROUP_CONCAT(t.name SEPARATOR ', ') as tenant_names
FROM rooms r
LEFT JOIN tenants t ON r.id = t.room_id AND t.status = 'active'
GROUP BY r.id
ORDER BY r.room_number ASC;
```

### Get Room Occupancy Statistics

```sql
SELECT 
    COUNT(*) as total_rooms,
    SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied_rooms,
    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as vacant_rooms,
    (SELECT COUNT(*) FROM tenants WHERE status = 'active') as total_tenants
FROM rooms;
```

## API Endpoints (Form-based)

### Submit Room Request
**File:** `tenant_add_room.php`
**Method:** POST
**Parameters:**
- `action`: "request_room"
- `room_id`: int
- `tenant_count`: int (1-4 based on room type)
- `tenant_info_name`: string (required)
- `tenant_info_email`: string (required, valid email)
- `tenant_info_phone`: string (required)
- `tenant_info_address`: string (required)
- `notes`: string (optional)

**Response:** 
- Success: Redirect to `tenant_add_room.php` with success message
- Error: Redirect with error message

### Approve/Reject Request
**File:** `room_requests_queue.php`
**Method:** POST
**Parameters:**
- `action`: "approve" or "reject"
- `request_id`: int

**Response:**
- Success: Redirect to `room_requests_queue.php` with success message
- Error: Redirect with error message

## Error Handling

### Database Errors
```php
try {
    // Database operations
} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
    $message_type = "danger";
    // Return error to user
}
```

### Validation Errors
```php
if (!empty($errors)) {
    $message = implode("<br>", $errors);
    $message_type = "danger";
    // Form is not submitted, user sees errors
}
```

## State Transitions

### Room Request States

```
┌─────────────┐
│   PENDING   │ (Initial state when submitted)
└──────┬──────┘
       │
       ├──► APPROVED ──► Room.status = 'occupied'
       │                Tenants created
       │                approved_date set
       │
       └──► REJECTED ──► Room.status unchanged
                         Room remains available
```

### Room States

```
AVAILABLE ◄──────┐
    │            │
    │ Request    │ Room change or manual update
    │ Approved   │
    │            │
    └──► OCCUPIED
```

## Common Issues & Solutions

### Issue: Additional tenants not being created

**Symptom:** Only primary tenant created on approval

**Possible Causes:**
1. `tenant_count` not saved in database
2. Loop in lines 52-70 not executing
3. Database permissions issue

**Solution:**
```php
// Verify tenant_count
echo "Tenant count: " . $request['tenant_count'];

// Check if loop executing
for ($i = 2; $i <= $request['tenant_count']; $i++) {
    echo "Creating occupant $i\n";
    // Insert code
}
```

### Issue: Room type dropdown not appearing

**Symptom:** Free-form text input shows instead of dropdown

**Possible Causes:**
1. Code not updated
2. Browser cache
3. Template not loaded

**Solution:**
1. Clear browser cache
2. Hard refresh (Ctrl+Shift+R)
3. Verify file contains `<select>` tag

### Issue: Occupancy validation not working

**Symptom:** Can request 3 people for single room

**Possible Causes:**
1. Validation code not executed
2. Room type not retrieved correctly
3. Condition logic error

**Solution:**
```php
// Debug: Log room details
$room_stmt = $conn->prepare("SELECT room_type FROM rooms WHERE id = :id");
$room_stmt->execute(['id' => $room_id]);
$room = $room_stmt->fetch(PDO::FETCH_ASSOC);
error_log("Room type: " . $room['room_type']);
```

## Performance Considerations

### Database Indexes
- `room_requests(status)` - Filter by pending/approved
- `room_requests(tenant_id)` - Find user's requests
- `room_requests(room_id)` - Find requests for room
- `tenants(room_id, status)` - Count occupants

### Query Optimization
- Use `GROUP_CONCAT()` instead of multiple queries for tenant names
- Pre-fetch room details instead of querying multiple times
- Use prepared statements for all user input

### Caching Opportunities
- Cache room type list (rarely changes)
- Cache room availability status (update on approval)
- Cache tenant count per room (update on approval)

## Security Considerations

### SQL Injection Prevention
✅ All queries use prepared statements with placeholders
✅ No direct concatenation of user input

### XSS Prevention
✅ All output uses `htmlspecialchars()` for encoding
✅ No direct echo of user input

### Authorization
⚠️ **TODO:** Add role-based checks in approval logic
```php
// Should add: Check if current user is admin
if ($_SESSION['role'] !== 'admin') {
    header('location: index.php');
    exit;
}
```

### Data Validation
✅ Email format validation with `filter_var()`
✅ Integer type casting for numeric inputs
✅ Trim and sanitize string inputs

## Testing Queries

### Find requests pending approval
```sql
SELECT * FROM room_requests WHERE status = 'pending' ORDER BY request_date DESC;
```

### Find rooms that are occupied
```sql
SELECT r.*, COUNT(t.id) as tenant_count 
FROM rooms r 
LEFT JOIN tenants t ON r.id = t.room_id AND t.status = 'active'
WHERE r.status = 'occupied'
GROUP BY r.id;
```

### Find duplicates (same tenant requested same room multiple times)
```sql
SELECT tenant_id, room_id, COUNT(*) as count
FROM room_requests
WHERE status != 'rejected'
GROUP BY tenant_id, room_id
HAVING count > 1;
```

### Find orphaned occupants (occupants without primary tenant)
```sql
SELECT * FROM tenants WHERE name LIKE '% - Occupant%' AND room_id IS NULL;
```

---

**Document Version:** 1.0
**Technical Level:** Advanced
**Last Updated:** January 2026
