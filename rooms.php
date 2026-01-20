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
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';

// Build the SQL query with search and filter
$sql = "SELECT rooms.*, COUNT(tenants.id) as tenant_count FROM rooms LEFT JOIN tenants ON rooms.id = tenants.room_id WHERE 1=1";

if ($search) {
    $sql .= " AND (rooms.room_number LIKE :search OR COALESCE(rooms.room_type, '') LIKE :search OR COALESCE(rooms.description, '') LIKE :search)";
}

if ($filter_status) {
    $sql .= " AND rooms.status = :status";
}

if ($filter_type) {
    $sql .= " AND rooms.room_type = :room_type";
}

$sql .= " GROUP BY rooms.id ORDER BY rooms.room_number ASC";

$stmt = $conn->prepare($sql);

if ($search) {
    $search_param = "%$search%";
    $stmt->bindParam(':search', $search_param);
}

if ($filter_status) {
    $stmt->bindParam(':status', $filter_status);
}

if ($filter_type) {
    $stmt->bindParam(':room_type', $filter_type);
}

$stmt->execute();
$rooms = $stmt;

// Fetch all room types for filter dropdown
$sql_types = "SELECT DISTINCT room_type FROM rooms WHERE room_type IS NOT NULL ORDER BY room_type ASC";
try {
    $room_types = $conn->query($sql_types);
} catch (PDOException $e) {
    // If room_type column doesn't exist yet, create empty result
    $room_types = $conn->query("SELECT NULL as room_type LIMIT 0");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rooms</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Rooms</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addRoomModal">
                        <i class="bi bi-plus-circle"></i>
                        Add New Room
                    </button>
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="row mb-3">
                <div class="col-md-12">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" placeholder="Room number, type..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-control" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="available" <?php echo $filter_status === 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="occupied" <?php echo $filter_status === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="type" class="form-label">Room Type</label>
                            <select class="form-control" id="type" name="type">
                                <option value="">All Types</option>
                                <?php 
                                while($type = $room_types->fetch(PDO::FETCH_ASSOC)): ?>
                                    <option value="<?php echo htmlspecialchars($type['room_type']); ?>" <?php echo $filter_type === $type['room_type'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['room_type']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Search</button>
                        </div>
                        <div class="col-md-2">
                            <a href="rooms.php" class="btn btn-secondary w-100">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-sm">
                    <thead>
                        <tr>
                            <th>Room Number</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Rate (₱)</th>
                            <th>Status</th>
                            <th>Occupancy</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $rooms->fetch(PDO::FETCH_ASSOC)) : ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['room_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['room_type'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['description'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars(number_format($row['rate'], 2)); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $row['status'] == 'available' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst(htmlspecialchars($row['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $row['tenant_count'] == 0 ? 'info' : 'danger'; ?>">
                                    <?php echo htmlspecialchars($row['tenant_count']); ?> <?php echo $row['tenant_count'] == 1 ? 'tenant' : 'tenants'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="room_actions.php?action=edit&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil-square"></i></a>
                                <a href="room_actions.php?action=delete&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this room?');"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Add Room Modal -->
<div class="modal fade" id="addRoomModal" tabindex="-1" aria-labelledby="addRoomModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addRoomModalLabel">Add New Room</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form action="room_actions.php?action=add" method="post">
          <div class="mb-3">
            <label for="room_number" class="form-label">Room Number</label>
            <input type="text" class="form-control" id="room_number" name="room_number" required>
          </div>
          <div class="mb-3">
            <label for="room_type" class="form-label">Room Type</label>
            <input type="text" class="form-control" id="room_type" name="room_type" placeholder="e.g., Single, Double, Suite" required>
          </div>
          <div class="mb-3">
            <label for="description" class="form-label">Description</label>
            <textarea class="form-control" id="description" name="description" placeholder="Additional details about the room"></textarea>
          </div>
          <div class="mb-3">
            <label for="rate" class="form-label">Rate (₱)</label>
            <input type="number" step="0.01" class="form-control" id="rate" name="rate" required>
          </div>
          <button type="submit" class="btn btn-primary">Save Room</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>