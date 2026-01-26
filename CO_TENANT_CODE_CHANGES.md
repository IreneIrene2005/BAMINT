# Co-Tenant Feature - Code Changes Reference

## Summary of Modifications

### Files Modified: 2
### Files Created: 1 migration + 3 documentation
### Total Lines Added: ~97 code lines + documentation

---

## 1. NEW FILE: `db/migrate_add_co_tenants.php`

**Purpose**: Standalone migration script to create the co_tenants table

**Size**: 26 lines

**Content**:
```php
<?php
require_once "database.php";

try {
    // Create co_tenants table
    $sql = "CREATE TABLE IF NOT EXISTS `co_tenants` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $conn->exec($sql);
    echo "✅ co_tenants table created successfully!";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
```

**How to Use**:
```
Navigate to: http://localhost/BAMINT/db/migrate_add_co_tenants.php
Expected Output: ✅ co_tenants table created successfully!
```

---

## 2. MODIFIED FILE: `db/init.sql`

**Change**: Added co_tenants table definition

**Location**: End of file, after room_requests table

**SQL Added** (18 lines):
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

---

## 3. MODIFIED FILE: `tenant_add_room.php`

### Change 1: POST Handler - Co-tenant Insertion Logic
**Location**: Lines 59-113 (in the room request POST handler)

**Before**:
```php
if (empty($errors)) {
    // ... existing code ...
    if ($room_id > 0) {
        // Single INSERT statement for room_request only
        $stmt = $conn->prepare("INSERT INTO room_requests (...) VALUES (...)");
        $stmt->execute([...]);
        $message = "Room request submitted successfully!";
        $message_type = "success";
    }
}
```

**After**:
```php
if (!empty($errors)) {
    // ... error handling ...
} elseif ($room_id > 0) {
    try {
        // Check if tenant already has a pending request for this room
        $check_stmt = $conn->prepare("
            SELECT id FROM room_requests 
            WHERE tenant_id = :tenant_id AND room_id = :room_id AND status = 'pending'
        ");
        $check_stmt->execute(['tenant_id' => $tenant_id, 'room_id' => $room_id]);
        
        if ($check_stmt->rowCount() > 0) {
            $message = "You already have a pending request for this room.";
            $message_type = "warning";
        } else {
            // Start transaction
            $conn->beginTransaction();
            try {
                // Insert room request with occupancy info
                $stmt = $conn->prepare("
                    INSERT INTO room_requests (tenant_id, room_id, tenant_count, tenant_info_name, tenant_info_email, tenant_info_phone, tenant_info_address, notes, status) 
                    VALUES (:tenant_id, :room_id, :tenant_count, :tenant_info_name, :tenant_info_email, :tenant_info_phone, :tenant_info_address, :notes, 'pending')
                ");
                $stmt->execute([
                    'tenant_id' => $tenant_id,
                    'room_id' => $room_id,
                    'tenant_count' => $tenant_count,
                    'tenant_info_name' => $tenant_info_name,
                    'tenant_info_email' => $tenant_info_email,
                    'tenant_info_phone' => $tenant_info_phone,
                    'tenant_info_address' => $tenant_info_address,
                    'notes' => $notes
                ]);

                // Save co-tenants if this is a shared/bedspace room with multiple occupants
                if ($tenant_count > 1) {
                    for ($i = 1; $i < $tenant_count; $i++) {
                        $co_name = isset($_POST['co_tenant_name_' . $i]) ? trim($_POST['co_tenant_name_' . $i]) : '';
                        $co_email = isset($_POST['co_tenant_email_' . $i]) ? trim($_POST['co_tenant_email_' . $i]) : '';
                        $co_phone = isset($_POST['co_tenant_phone_' . $i]) ? trim($_POST['co_tenant_phone_' . $i]) : '';
                        $co_id = isset($_POST['co_tenant_id_' . $i]) ? trim($_POST['co_tenant_id_' . $i]) : '';
                        $co_address = isset($_POST['co_tenant_address_' . $i]) ? trim($_POST['co_tenant_address_' . $i]) : '';

                        if (!empty($co_name)) {
                            $co_stmt = $conn->prepare("
                                INSERT INTO co_tenants (primary_tenant_id, room_id, name, email, phone, id_number, address) 
                                VALUES (:primary_tenant_id, :room_id, :name, :email, :phone, :id_number, :address)
                            ");
                            $co_stmt->execute([
                                'primary_tenant_id' => $tenant_id,
                                'room_id' => $room_id,
                                'name' => $co_name,
                                'email' => $co_email,
                                'phone' => $co_phone,
                                'id_number' => $co_id,
                                'address' => $co_address
                            ]);
                        }
                    }
                }

                $conn->commit();
                $message = "Room request submitted successfully! The admin will review your request soon.";
                $message_type = "success";
            } catch (Exception $e) {
                $conn->rollBack();
                $message = "Error submitting request: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    } catch (Exception $e) {
        $message = "Error checking request: " . $e->getMessage();
        $message_type = "danger";
    }
}
```

**Key Changes**:
1. Wrapped in `$conn->beginTransaction()` and `$conn->commit()`
2. Added co-tenant loop: `for ($i = 1; $i < $tenant_count; $i++)`
3. Extracts POST data: `$_POST['co_tenant_name_' . $i]`, etc.
4. Inserts each co-tenant: `INSERT INTO co_tenants (...) VALUES (...)`
5. Error handling: `$conn->rollBack()` on exception
6. Prepared statements prevent SQL injection

### Change 2: Form HTML - Co-Tenants Section
**Location**: Lines 438-452 (in the room request form)

**Before**:
```html
<div class="mb-3">
    <label for="tenant_count" class="form-label">Number of Occupants</label>
    <input type="number" class="form-control" id="tenant_count" name="tenant_count" min="1" value="1" required>
</div>

<div class="mb-3">
    <label for="notes" class="form-label">Notes (Optional)</label>
    <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
</div>
```

**After**:
```html
<div class="mb-3">
    <label for="tenant_count_<?php echo htmlspecialchars($room['id']); ?>" class="form-label">Number of Occupants <span class="text-danger">*</span></label>
    <input type="number" class="form-control tenant-count-input" id="tenant_count_<?php echo htmlspecialchars($room['id']); ?>" name="tenant_count" min="1" max="<?php echo $max_occupancy; ?>" value="1" required data-room-id="<?php echo htmlspecialchars($room['id']); ?>">
    <small class="text-muted">Maximum <?php echo $max_occupancy; ?> person(s) for this room type</small>
</div>

<!-- Co-Tenants Section (shown when occupants > 1) -->
<div class="co-tenants-section" id="co_tenants_<?php echo htmlspecialchars($room['id']); ?>" style="display: none;">
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> Please provide information for all roommates. You will be the primary tenant responsible for payments.
    </div>
    <div id="co_tenant_fields_<?php echo htmlspecialchars($room['id']); ?>"></div>
</div>

<div class="mb-3">
    <label for="notes_<?php echo htmlspecialchars($room['id']); ?>" class="form-label">Notes (Optional)</label>
    <textarea class="form-control" id="notes_<?php echo htmlspecialchars($room['id']); ?>" name="notes" rows="2" placeholder="Add any notes about your request..."></textarea>
</div>
```

**Key Changes**:
1. Added `class="tenant-count-input"` for JavaScript targeting
2. Added `data-room-id="<?php echo htmlspecialchars($room['id']); ?>"` for room ID tracking
3. Updated `max` attribute to use `$max_occupancy` variable
4. Added helper text: "Maximum X person(s) for this room type"
5. Added co-tenants section with:
   - `class="co-tenants-section"`
   - `id="co_tenants_<?php echo htmlspecialchars($room['id']); ?>"`
   - `style="display: none;"` (hidden by default)
6. Added info alert explaining primary tenant responsibility
7. Added container for dynamic co-tenant fields:
   - `id="co_tenant_fields_<?php echo htmlspecialchars($room['id']); ?>"`

### Change 3: JavaScript - Dynamic Field Generation
**Location**: Lines 534-583 (before closing </body> tag)

**Added JavaScript**:
```javascript
<script>
    // Handle dynamic co-tenant fields based on occupant count
    document.querySelectorAll('.tenant-count-input').forEach(input => {
        input.addEventListener('change', function() {
            const roomId = this.dataset.roomId;
            const count = parseInt(this.value);
            const coTenantSection = document.getElementById('co_tenants_' + roomId);
            const fieldsContainer = document.getElementById('co_tenant_fields_' + roomId);
            
            if (count > 1) {
                coTenantSection.style.display = 'block';
                let html = '';
                
                for (let i = 1; i < count; i++) {
                    html += `
                        <div class="card mb-3 border-secondary">
                            <div class="card-header bg-secondary bg-opacity-10">
                                <h6 class="mb-0"><i class="bi bi-person-badge"></i> Roommate ${i}</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-2">
                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="co_tenant_name_${i}" placeholder="Enter roommate's full name" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" name="co_tenant_email_${i}" placeholder="Enter roommate's email">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" name="co_tenant_phone_${i}" placeholder="Enter roommate's phone">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">ID Number</label>
                                    <input type="text" class="form-control" name="co_tenant_id_${i}" placeholder="Enter roommate's ID number">
                                </div>
                                <div class="mb-0">
                                    <label class="form-label">Address</label>
                                    <textarea class="form-control" name="co_tenant_address_${i}" rows="2" placeholder="Enter roommate's address"></textarea>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                fieldsContainer.innerHTML = html;
            } else {
                coTenantSection.style.display = 'none';
                fieldsContainer.innerHTML = '';
            }
        });
    });
</script>
```

**Key Features**:
1. Event listener on all `.tenant-count-input` elements
2. On change: reads `this.value` (occupant count) and `this.dataset.roomId` (room ID)
3. If count > 1:
   - Shows co-tenants section: `coTenantSection.style.display = 'block'`
   - Loops from i=1 to count-1 (generates count-1 roommate forms)
   - Each roommate card includes:
     - Name (required): `co_tenant_name_${i}`
     - Email (optional): `co_tenant_email_${i}`
     - Phone (optional): `co_tenant_phone_${i}`
     - ID Number (optional): `co_tenant_id_${i}`
     - Address (optional): `co_tenant_address_${i}`
4. If count = 1:
   - Hides co-tenants section
   - Clears all generated HTML
5. Uses Bootstrap cards for styling
6. Icons for UX enhancement (bi-person-badge, bi-info-circle)

---

## Code Statistics

### Lines Added by Section
- **POST Handler Enhancement**: ~55 lines (transaction, loop, error handling)
- **HTML Form Addition**: ~15 lines (co-tenants section, data attributes)
- **JavaScript Addition**: ~50 lines (event listener, dynamic HTML generation)
- **Database Migration**: ~26 lines (CREATE TABLE statement)
- **Init.sql Update**: ~18 lines (co_tenants table schema)

**Total Code Lines**: ~97 new lines (plus documentation)

### Database Changes
- **New Table**: `co_tenants` (11 columns, 2 FKs, 2 indexes)
- **Modified Tables**: None (no existing table structure changes)
- **New Relationships**: 
  - `co_tenants.primary_tenant_id` → `tenants.id` (CASCADE DELETE)
  - `co_tenants.room_id` → `rooms.id` (CASCADE DELETE)

---

## Testing the Changes

### Step 1: Apply Migration
```bash
Visit: http://localhost/XAMPP/htdocs/BAMINT/db/migrate_add_co_tenants.php
Expected: ✅ co_tenants table created successfully!
```

### Step 2: Test Single Room
1. Login as tenant
2. Select a single room
3. "Number of Occupants" should default to 1
4. Co-tenant section should NOT appear
5. Submit request
6. Verify only room_requests entry created

### Step 3: Test Shared Room
1. Select a shared room
2. Change "Number of Occupants" to 2
3. Verify: 1 co-tenant form appears
4. Fill in all fields and submit
5. Verify database:
   - 1 row in room_requests (tenant_count=2)
   - 1 row in co_tenants (primary_tenant_id=your_id)

### Step 4: Test Bedspace Room
1. Select a bedspace room
2. Change "Number of Occupants" to 4
3. Verify: 3 co-tenant forms appear
4. Fill in and submit
5. Verify database:
   - 1 row in room_requests (tenant_count=4)
   - 3 rows in co_tenants

---

## Rollback Instructions

If you need to remove this feature:

### Option 1: Keep Database, Revert Code
1. Delete lines 59-113 from tenant_add_room.php (POST handler)
2. Delete lines 438-452 from tenant_add_room.php (HTML)
3. Delete lines 534-583 from tenant_add_room.php (JavaScript)
4. Keep database table (doesn't harm anything)

### Option 2: Full Removal
1. Perform Option 1 above
2. Delete `db/migrate_add_co_tenants.php`
3. Remove co_tenants table from `db/init.sql`
4. Drop table: `DROP TABLE co_tenants;`

---

## Implementation Complete ✅

All code changes have been made and documented. The feature is ready for testing and deployment.
