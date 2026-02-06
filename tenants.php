<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

// Search and filter variables
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_room = isset($_GET['room']) ? $_GET['room'] : '';

// Build the SQL query with search and filter
$sql = "SELECT tenants.*, COALESCE(rooms.room_number, (
            SELECT r2.room_number FROM bills b 
            JOIN rooms r2 ON b.room_id = r2.id 
            LEFT JOIN payment_transactions pt ON pt.bill_id = b.id AND pt.payment_status IN ('verified','approved')
            WHERE b.tenant_id = tenants.id AND (
                b.amount_paid > 0 OR b.status IN ('partial','paid') OR pt.id IS NOT NULL
            ) ORDER BY b.id DESC LIMIT 1
        )) as room_number FROM tenants LEFT JOIN rooms ON tenants.room_id = rooms.id WHERE 1=1";

if ($search) {
    $sql .= " AND (tenants.name LIKE :search OR tenants.email LIKE :search OR tenants.phone LIKE :search OR tenants.id_number LIKE :search)";
}

if ($filter_status) {
    $sql .= " AND tenants.status = :status";
}

if ($filter_room) {
    $sql .= " AND tenants.room_id = :room_id";
}

$sql .= " ORDER BY tenants.name ASC";

$stmt = $conn->prepare($sql);

if ($search) {
    $search_param = "%$search%";
    $stmt->bindParam(':search', $search_param);
}

if ($filter_status) {
    $stmt->bindParam(':status', $filter_status);
}

if ($filter_room) {
    $stmt->bindParam(':room_id', $filter_room);
}

$stmt->execute();
$tenants = $stmt;



// Fetch all rooms for filter dropdown
$sql_rooms = "SELECT * FROM rooms ORDER BY room_number ASC";
$all_rooms = $conn->query($sql_rooms);

// Fetch available rooms for the add form
$sql_available_rooms = "SELECT * FROM rooms WHERE status = 'available' ORDER BY room_number ASC";
$available_rooms = $conn->query($sql_available_rooms);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tenants</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php
        ob_start();
        include 'templates/sidebar.php';
        $sidebar = ob_get_clean();
        echo str_replace('Tenants', 'Customers', $sidebar);
        ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Customers</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addTenantModal">
                        <i class="bi bi-plus-circle"></i>
                        Add Walk-in Customer
                    </button>
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="row mb-3">
                <div class="col-md-12">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" placeholder="Name, email, phone, ID..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-control" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="room" class="form-label">Room</label>
                            <select class="form-control" id="room" name="room">
                                <option value="">All Rooms</option>
                                <?php 
                                $all_rooms->execute();
                                while($room = $all_rooms->fetch(PDO::FETCH_ASSOC)): ?>
                                    <option value="<?php echo $room['id']; ?>" <?php echo $filter_room == $room['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($room['room_number']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Search</button>
                        </div>
                        <div class="col-md-2">
                            <a href="tenants.php" class="btn btn-secondary w-100">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-sm">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Room</th>
                            <th>Stay Duration</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $tenants->fetch(PDO::FETCH_ASSOC)) : ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td><?php echo htmlspecialchars($row['phone']); ?></td>
                            <td>
                                <?php
                                    // Only show room if latest room_request is approved or occupied
                                    $room_req_stmt = $conn->prepare("SELECT status FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                    $room_req_stmt->execute(['tenant_id' => $row['id'], 'room_id' => $row['room_id']]);
                                    $room_req = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                    if ($room_req && in_array($room_req['status'], ['approved', 'occupied'])) {
                                        echo htmlspecialchars($row['room_number']);
                                    } else {
                                        echo '-';
                                    }
                                ?>
                            </td>
                            <td>
                                <?php
                                // Show stay duration as check-in to check-out when available
                                $room_req_stmt = $conn->prepare("SELECT checkin_date, checkout_date, status FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                $room_req_stmt->execute(['tenant_id' => $row['id'], 'room_id' => $row['room_id']]);
                                $dates = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                if ($dates && in_array($dates['status'], ['approved', 'occupied'])) {
                                    if (!empty($dates['checkin_date']) && !empty($dates['checkout_date'])) {
                                        echo htmlspecialchars(date('M d, Y', strtotime($dates['checkin_date'])) . ' - ' . date('M d, Y', strtotime($dates['checkout_date'])));
                                    } elseif (!empty($dates['checkin_date'])) {
                                        echo htmlspecialchars(date('M d, Y', strtotime($dates['checkin_date'])) . ' - Present');
                                    } else {
                                        echo '-';
                                    }
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $row['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo ucfirst($row['status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="tenant_actions.php?action=edit&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil-square"></i></a>
                                <?php if($row['status'] === 'active'): ?>
                                    <a href="tenant_actions.php?action=deactivate&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-warning" title="Deactivate" onclick="return confirm('Deactivate this tenant?');"><i class="bi bi-pause-circle"></i></a>
                                <?php else: ?>
                                    <a href="tenant_actions.php?action=activate&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-success" title="Activate"><i class="bi bi-play-circle"></i></a>
                                <?php endif; ?>
                                <a href="tenant_actions.php?action=delete&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this tenant?');"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Add Tenant Modal -->
<div class="modal fade" id="addTenantModal" tabindex="-1" aria-labelledby="addTenantModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addTenantModalLabel">Add Walk-in Customer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
                <form action="tenant_actions.php?action=add" method="post">
                    <input type="hidden" name="source" value="walk-in">
          <div class="mb-3">
            <label for="name" class="form-label">Name</label>
            <input type="text" class="form-control" id="name" name="name" required>
          </div>
          <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email" required>
          </div>
          <div class="mb-3">
            <label for="phone" class="form-label">Phone</label>
            <input type="text" class="form-control" id="phone" name="phone" required>
          </div>

                                        <div class="mb-3">
                                                <label for="room_type" class="form-label">Room Type</label>
                                                <select class="form-control" id="room_type" name="room_type">
                                                                <option value="">Any Type</option>
                                                                <option value="single">Single</option>
                                                                <option value="double">Double</option>
                                                                <option value="family">Family</option>
                                                </select>
                                        </div>

                                        <div class="mb-3">
                                                <label for="room_id" class="form-label">Room</label>
                                                <select class="form-control" id="room_id" name="room_id">
                                                                <option value="">Select a room (optional)</option>
                                                                <?php 
                                                                $available_rooms->execute();
                                                                while($room = $available_rooms->fetch(PDO::FETCH_ASSOC)): ?>
                                                                                <option value="<?php echo $room['id']; ?>" data-room-type="<?php echo htmlspecialchars(strtolower($room['room_type'])); ?>"><?php echo htmlspecialchars($room['room_number']); ?> (<?php echo htmlspecialchars($room['room_type']); ?>) - ₱<?php echo number_format($room['rate'],2); ?></option>
                                                                <?php endwhile; ?>
                                                </select>
                                        </div>

                                        <div class="mb-3">
                                                <label for="tenant_count" class="form-label">Number of Occupants</label>
                                                <input type="number" class="form-control" id="tenant_count" name="tenant_count" value="1" min="1">
                                        </div>

                                        <div id="co_tenants_container"></div>

                    <div class="mb-3">
                        <label for="checkin_date" class="form-label">Check-in Date</label>
                        <input type="date" class="form-control" id="checkin_date" name="checkin_date">
                    </div>
                    <div class="mb-3">
                        <label for="checkout_date" class="form-label">Check-out Date</label>
                        <input type="date" class="form-control" id="checkout_date" name="checkout_date">
                    </div>
                    <button type="submit" class="btn btn-primary">Save Walk-in Customer</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Client-side filtering, roommate fields and validation for Add Walk-in Customer modal
document.addEventListener('DOMContentLoaded', function(){
    const roomTypeEl = document.getElementById('room_type');
    const roomSelect = document.getElementById('room_id');
    const tenantCountEl = document.getElementById('tenant_count');
    const coTenantContainer = document.getElementById('co_tenants_container');

    function filterRooms(){
        if(!roomTypeEl || !roomSelect) return;
        const type = (roomTypeEl.value || '').toLowerCase();
        for(const opt of roomSelect.options){
            const rt = (opt.dataset.roomType || '').toLowerCase();
            if(!type || type === rt){
                opt.style.display = '';
            } else {
                opt.style.display = 'none';
                if(roomSelect.value === opt.value) roomSelect.value = '';
            }
        }
    }

    function renderCoTenants(){
        coTenantContainer.innerHTML = '';
        const count = parseInt(tenantCountEl.value) || 1;
        if(count > 1){
            for(let i=1;i< count;i++){
                const idx = i;
                const card = document.createElement('div');
                card.className = 'card mb-2 p-2';
                card.innerHTML = `
                    <h6 class="mb-2">Roommate ${idx}</h6>
                    <div class="mb-2">
                        <input type="text" class="form-control" name="co_tenant_name_${idx}" placeholder="Full name" required>
                    </div>
                    <div class="mb-2">
                        <input type="email" class="form-control" name="co_tenant_email_${idx}" placeholder="Email (optional)">
                    </div>
                    <div class="mb-2">
                        <input type="text" class="form-control" name="co_tenant_phone_${idx}" placeholder="Phone (optional)">
                    </div>
                `;
                coTenantContainer.appendChild(card);
            }
        }
    }

    roomTypeEl && roomTypeEl.addEventListener('change', function(){
        const t = this.value;
        if(t === 'single'){
            tenantCountEl.value = 1; tenantCountEl.min = 1; tenantCountEl.disabled = true;
        } else if(t === 'double'){
            tenantCountEl.value = 2; tenantCountEl.min = 2; tenantCountEl.disabled = true;
        } else {
            tenantCountEl.disabled = false; tenantCountEl.min = 1;
        }
        filterRooms(); renderCoTenants();
    });

    tenantCountEl && tenantCountEl.addEventListener('input', function(){ renderCoTenants(); });

    // initial render
    filterRooms(); renderCoTenants();

    // Validate before submit
    const addForm = document.querySelector('#addTenantModal form');
    if(addForm){
        addForm.addEventListener('submit', function(e){
            const type = (roomTypeEl && roomTypeEl.value) ? roomTypeEl.value.toLowerCase() : '';
            const count = parseInt(tenantCountEl.value) || 1;
            if(type === 'single' && count !== 1){ e.preventDefault(); alert('Single rooms must have exactly 1 occupant.'); return; }
            if(type === 'double' && count !== 2){ e.preventDefault(); alert('Double rooms must have exactly 2 occupants.'); return; }
            // if count >1 ensure roommate names filled
            if(count > 1){
                for(let i=1;i<count;i++){
                    const nameEl = addForm.querySelector('[name="co_tenant_name_' + i + '"]');
                    if(!nameEl || !nameEl.value.trim()){ e.preventDefault(); alert('Please fill roommate ' + i + "'s name."); return; }
                }
            }
            // If room selected, require checkin and checkout
            const roomSelectEl = document.getElementById('room_id');
            const checkinEl = document.getElementById('checkin_date');
            const checkoutEl = document.getElementById('checkout_date');
            if(roomSelectEl && roomSelectEl.value){
                if(!checkinEl.value || !checkoutEl.value){ e.preventDefault(); alert('Please select check-in and check-out dates when selecting a room.'); return; }
            }
        });
    }
});
</script>
</body>
</html>