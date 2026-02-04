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
                        Add New Customer
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
                                    $displayRoom = $row['room_number'];
                                    $tenantStatus = $row['status'];
                                    if (empty($displayRoom)) {
                                        try {
                                            $stmt2 = $conn->prepare("SELECT b.room_id, r.room_number FROM bills b JOIN rooms r ON b.room_id = r.id LEFT JOIN payment_transactions pt ON pt.bill_id = b.id AND pt.payment_status IN ('verified','approved') WHERE b.tenant_id = :tenant_id AND (b.amount_paid > 0 OR b.status IN ('partial','paid') OR pt.id IS NOT NULL) ORDER BY b.id DESC LIMIT 1");
                                            $stmt2->execute(['tenant_id' => $row['id']]);
                                            $brow = $stmt2->fetch(PDO::FETCH_ASSOC);
                                            if ($brow && !empty($brow['room_id'])) {
                                                // persist assignment so it is consistent across pages
                                                $updateTenantRoom = $conn->prepare("UPDATE tenants SET room_id = :room_id, status = 'active' WHERE id = :tenant_id");
                                                $updateTenantRoom->execute(['room_id' => $brow['room_id'], 'tenant_id' => $row['id']]);
                                                $displayRoom = $brow['room_number'];
                                                $tenantStatus = 'active';
                                            }
                                        } catch (Exception $e) {
                                            // ignore and fallback to empty
                                        }
                                    }
                                    echo htmlspecialchars($displayRoom ?: '-');
                                ?>
                            </td>
                            <td>
                                <?php
                                // Show stay duration from room_requests if approved/occupied
                                $room_req_stmt = $conn->prepare("SELECT checkin_date, checkout_date, status FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                $room_req_stmt->execute(['tenant_id' => $row['id'], 'room_id' => $row['room_id']]);
                                $dates = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                if ($dates && $dates['checkin_date'] && $dates['checkout_date'] && in_array($dates['status'], ['approved', 'occupied'])) {
                                    echo htmlspecialchars(date('M d, Y', strtotime($dates['checkin_date'])) . ' - ' . date('M d, Y', strtotime($dates['checkout_date'])));
                                } else {
                                    echo htmlspecialchars($row['start_date']);
                                }
                                ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $row['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo ucfirst($tenantStatus); ?>
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
        <h5 class="modal-title" id="addTenantModalLabel">Add New Tenant</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form action="tenant_actions.php?action=add" method="post">
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
            <label for="id_number" class="form-label">ID Number</label>
            <input type="text" class="form-control" id="id_number" name="id_number">
          </div>
          <div class="mb-3">
            <label for="room_id" class="form-label">Room</label>
            <select class="form-control" id="room_id" name="room_id" required>
                <option value="">Select a room</option>
                <?php 
                $available_rooms->execute();
                while($room = $available_rooms->fetch(PDO::FETCH_ASSOC)): ?>
                    <option value="<?php echo $room['id']; ?>"><?php echo htmlspecialchars($room['room_number']); ?></option>
                <?php endwhile; ?>
            </select>
          </div>
          <div class="mb-3">
            <label for="start_date" class="form-label">Start Date</label>
            <input type="date" class="form-control" id="start_date" name="start_date" required>
          </div>
          <button type="submit" class="btn btn-primary">Save Tenant</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>