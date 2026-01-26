<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    if ($action === 'add') {
        $room_number = $_POST['room_number'];
        $room_type = $_POST['room_type'];
        $description = $_POST['description'];
        $rate = $_POST['rate'];

        $sql = "INSERT INTO rooms (room_number, room_type, description, rate) VALUES (:room_number, :room_type, :description, :rate)";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['room_number' => $room_number, 'room_type' => $room_type, 'description' => $description, 'rate' => $rate]);

        header("location: rooms.php");
        exit;
    } elseif ($action === 'edit') {
        $id = $_POST['id'];
        $room_number = $_POST['room_number'];
        $room_type = $_POST['room_type'];
        $description = $_POST['description'];
        $rate = $_POST['rate'];
        $status = $_POST['status'];

        $sql = "UPDATE rooms SET room_number = :room_number, room_type = :room_type, description = :description, rate = :rate, status = :status WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['room_number' => $room_number, 'room_type' => $room_type, 'description' => $description, 'rate' => $rate, 'status' => $status, 'id' => $id]);

        header("location: rooms.php");
        exit;
    }
} else { // GET request
    if ($action === 'delete') {
        $id = $_GET['id'];
        $sql = "DELETE FROM rooms WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['id' => $id]);

        header("location: rooms.php");
        exit;
    } elseif ($action === 'edit') {
        $id = $_GET['id'];
        $sql = "SELECT * FROM rooms WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['id' => $id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            header("location: rooms.php");
            exit;
        }

        // Show an edit form
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Edit Room</title>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="public/css/style.css">
        </head>
        <body>
        <?php include 'templates/header.php'; ?>
        <div class="container-fluid">
            <div class="row">
                <?php include 'templates/sidebar.php'; ?>
                <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2">Edit Room</h1>
                    </div>
                    <form action="room_actions.php?action=edit" method="post">
                        <input type="hidden" name="id" value="<?php echo $room['id']; ?>">
                        <div class="mb-3">
                            <label for="room_number" class="form-label">Room Number</label>
                            <input type="text" class="form-control" id="room_number" name="room_number" value="<?php echo htmlspecialchars($room['room_number']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="room_type" class="form-label">Room Type</label>
                            <select class="form-control" id="room_type" name="room_type" required>
                              <option value="">Select Room Type</option>
                              <option value="Single" <?php echo $room['room_type'] === 'Single' ? 'selected' : ''; ?>>Single</option>
                              <option value="Shared" <?php echo $room['room_type'] === 'Shared' ? 'selected' : ''; ?>>Shared</option>
                              <option value="Bedspace" <?php echo $room['room_type'] === 'Bedspace' ? 'selected' : ''; ?>>Bedspace</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description"><?php echo htmlspecialchars($room['description']); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="rate" class="form-label">Rate (₱)</label>
                            <input type="number" step="0.01" class="form-control" id="rate" name="rate" value="<?php echo htmlspecialchars($room['rate']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-control" id="status" name="status">
                                <option value="available" <?php echo $room['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="occupied" <?php echo $room['status'] === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </form>
                </main>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
        </body>
        </html>
        <?php
    }
}
?>