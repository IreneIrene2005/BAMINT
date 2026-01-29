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
        $name = $_POST['name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $room_id = $_POST['room_id'] ?? null;
        $start_date = $_POST['start_date'];

        $conn->beginTransaction();
        try {
            // Create tenant without room assignment (admin approves and assigns room later)
            $sql = "INSERT INTO tenants (name, email, phone, room_id, start_date, status) VALUES (:name, :email, :phone, :room_id, :start_date, 'inactive')";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'room_id' => $room_id, 'start_date' => $start_date]);

            $sql_update_room = "UPDATE rooms SET status = 'occupied' WHERE id = :room_id AND :room_id IS NOT NULL";
            $stmt_update_room = $conn->prepare($sql_update_room);
            $stmt_update_room->execute(['room_id' => $room_id]);
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            echo "Failed: " . $e->getMessage();
            exit;
        }


        header("location: tenants.php");
        exit;
    } elseif ($action === 'edit') {
        $id = $_POST['id'];
        $name = $_POST['name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $room_id = $_POST['room_id'] ?? null;
        $start_date = $_POST['start_date'];
        $original_room_id = $_POST['original_room_id'] ?? null;

        $conn->beginTransaction();
        try {
            $sql = "UPDATE tenants SET name = :name, email = :email, phone = :phone, room_id = :room_id, start_date = :start_date WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'room_id' => $room_id, 'start_date' => $start_date, 'id' => $id]);

            if ($original_room_id != $room_id) {
                // Update old room to available
                $sql_update_old_room = "UPDATE rooms SET status = 'available' WHERE id = :original_room_id";
                $stmt_update_old_room = $conn->prepare($sql_update_old_room);
                $stmt_update_old_room->execute(['original_room_id' => $original_room_id]);

                // Update new room to occupied
                $sql_update_new_room = "UPDATE rooms SET status = 'occupied' WHERE id = :room_id";
                $stmt_update_new_room = $conn->prepare($sql_update_new_room);
                $stmt_update_new_room->execute(['room_id' => $room_id]);
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            echo "Failed: " . $e->getMessage();
            exit;
        }
        header("location: tenants.php");
        exit;
    }
} else { // GET request
    if ($action === 'get_details') {
        $id = $_GET['id'];
        
        // Check user role
        if ($_SESSION["role"] !== "admin") {
            die("Unauthorized");
        }
        
        try {
            $stmt = $conn->prepare("
                SELECT t.*, r.room_number, r.room_type, r.rate,
                       ta.email,
                       COUNT(b.id) as total_bills,
                       SUM(CASE WHEN b.status = 'unpaid' THEN b.amount_due ELSE 0 END) as unpaid_amount
                FROM tenants t
                LEFT JOIN tenant_accounts ta ON t.id = ta.tenant_id
                LEFT JOIN rooms r ON t.room_id = r.id
                LEFT JOIN bills b ON t.id = b.tenant_id
                WHERE t.id = :id
                GROUP BY t.id
            ");
            $stmt->execute(['id' => $id]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tenant) {
                die("Tenant not found");
            }
            
            // Get recent bills
            $stmt = $conn->prepare("
                SELECT * FROM bills 
                WHERE tenant_id = :tenant_id
                ORDER BY due_date DESC
                LIMIT 5
            ");
            $stmt->execute(['tenant_id' => $id]);
            $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ?>
            <div class="row">
                <div class="col-md-6">
                    <h6>Personal Information</h6>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($tenant['name']); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($tenant['email']); ?></p>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($tenant['phone'] ?? 'N/A'); ?></p>
                </div>
                <div class="col-md-6">
                    <h6>Room & Lease Information</h6>
                    <?php if ($tenant['room_id']): ?>
                        <p><strong>Room:</strong> <?php echo htmlspecialchars($tenant['room_number']); ?></p>
                        <p><strong>Room Type:</strong> <?php echo htmlspecialchars($tenant['room_type'] ?? 'N/A'); ?></p>
                        <p><strong>Monthly Rent:</strong> ₱<?php echo number_format($tenant['rate'], 2); ?></p>
                        <p><strong>Move-in Date:</strong> <?php echo date('M d, Y', strtotime($tenant['start_date'])); ?></p>
                    <?php else: ?>
                        <p><em>No room assigned</em></p>
                    <?php endif; ?>
                    <p><strong>Status:</strong> <span class="badge bg-<?php echo $tenant['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($tenant['status']); ?></span></p>
                </div>
            </div>
            
            <hr>
            
            <h6>Billing Summary</h6>
            <p><strong>Total Bills:</strong> <?php echo $tenant['total_bills']; ?></p>
            <p><strong>Unpaid Amount:</strong> <span class="text-danger">₱<?php echo number_format($tenant['unpaid_amount'] ?? 0, 2); ?></span></p>
            
            <?php if (!empty($bills)): ?>
                <h6 class="mt-3">Recent Bills</h6>
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Amount Due</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bills as $bill): ?>
                            <tr>
                                <td><?php echo date('M Y', strtotime($bill['billing_month'])); ?></td>
                                <td>₱<?php echo number_format($bill['amount_due'], 2); ?></td>
                                <td><span class="badge bg-<?php echo $bill['status'] === 'paid' ? 'success' : ($bill['status'] === 'pending' ? 'warning' : 'danger'); ?>"><?php echo ucfirst($bill['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php
        } catch (Exception $e) {
            die("Error: " . $e->getMessage());
        }
        exit;
    } elseif ($action === 'delete') {
        $id = $_GET['id'];

        $conn->beginTransaction();
        try {
            $sql_get_room = "SELECT room_id FROM tenants WHERE id = :id";
            $stmt_get_room = $conn->prepare($sql_get_room);
            $stmt_get_room->execute(['id' => $id]);
            $room_id = $stmt_get_room->fetchColumn();

            $sql = "DELETE FROM tenants WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['id' => $id]);

            $sql_update_room = "UPDATE rooms SET status = 'available' WHERE id = :room_id";
            $stmt_update_room = $conn->prepare($sql_update_room);
            $stmt_update_room->execute(['room_id' => $room_id]);

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            echo "Failed: " . $e->getMessage();
            exit;
        }

        header("location: tenants.php");
        exit;
    } elseif ($action === 'deactivate') {
        $id = $_GET['id'];

        try {
            $sql = "UPDATE tenants SET status = 'inactive' WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['id' => $id]);
        } catch (Exception $e) {
            echo "Failed: " . $e->getMessage();
            exit;
        }

        header("location: tenants.php");
        exit;
    } elseif ($action === 'activate') {
        $id = $_GET['id'];

        try {
            $sql = "UPDATE tenants SET status = 'active' WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['id' => $id]);
        } catch (Exception $e) {
            echo "Failed: " . $e->getMessage();
            exit;
        }

        header("location: tenants.php");
        exit;
    } elseif ($action === 'edit') {
        $id = $_GET['id'];
        $sql = "SELECT * FROM tenants WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['id' => $id]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

        $sql_rooms = "SELECT * FROM rooms WHERE status = 'available' OR id = :current_room_id";
        $stmt_rooms = $conn->prepare($sql_rooms);
        $stmt_rooms->execute(['current_room_id' => $tenant['room_id']]);
        $available_rooms = $stmt_rooms->fetchAll(PDO::FETCH_ASSOC);


        if (!$tenant) {
            header("location: tenants.php");
            exit;
        }

        // Show an edit form
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Edit Tenant</title>
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
                        <h1 class="h2">Edit Tenant</h1>
                    </div>
                    <form action="tenant_actions.php?action=edit" method="post">
                        <input type="hidden" name="id" value="<?php echo $tenant['id']; ?>">
                        <input type="hidden" name="original_room_id" value="<?php echo $tenant['room_id']; ?>">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($tenant['name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($tenant['email']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($tenant['phone']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="id_number" class="form-label">ID Number</label>
                            <input type="text" class="form-control" id="id_number" name="id_number" value="<?php echo htmlspecialchars($tenant['id_number'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="room_id" class="form-label">Room</label>
                            <select class="form-control" id="room_id" name="room_id" required>
                                <option value="">Select a room</option>
                                <?php foreach($available_rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>" <?php echo $tenant['room_id'] == $room['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($room['room_number']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($tenant['start_date']); ?>" required>
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