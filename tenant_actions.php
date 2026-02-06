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
        $tenant_count = isset($_POST['tenant_count']) ? intval($_POST['tenant_count']) : 1;
        $source = $_POST['source'] ?? 'walk-in';

        $conn->beginTransaction();
        try {
            // Create tenant record WITHOUT room assignment (walk-in customer)
            $sql = "INSERT INTO tenants (name, email, phone, status) VALUES (:name, :email, :phone, 'inactive')";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['name' => $name, 'email' => $email, 'phone' => $phone]);
            $newTenantId = $conn->lastInsertId();

            // Save co-tenants if occupants > 1
            if ($tenant_count > 1) {
                for ($i = 1; $i < $tenant_count; $i++) {
                    $co_name = isset($_POST['co_tenant_name_' . $i]) ? trim($_POST['co_tenant_name_' . $i]) : '';
                    $co_email = isset($_POST['co_tenant_email_' . $i]) ? trim($_POST['co_tenant_email_' . $i]) : '';
                    $co_phone = isset($_POST['co_tenant_phone_' . $i]) ? trim($_POST['co_tenant_phone_' . $i]) : '';
                    if (!empty($co_name)) {
                        $co_stmt = $conn->prepare("INSERT INTO co_tenants (primary_tenant_id, name, email, phone) VALUES (:primary_tenant_id, :name, :email, :phone)");
                        $co_stmt->execute([
                            'primary_tenant_id' => $newTenantId,
                            'name' => $co_name,
                            'email' => $co_email,
                            'phone' => $co_phone
                        ]);
                    }
                }
            }

            // If room and dates provided, create a room_request and generate an advance bill
            $room_id = $_POST['room_id'] ?? null;
            $checkin_date = $_POST['checkin_date'] ?? null;
            $checkout_date = $_POST['checkout_date'] ?? null;
            $notes = $_POST['notes'] ?? null;

            if ($room_id && $checkin_date && $checkout_date) {
                // Insert room request (pending payment)
                $rr_stmt = $conn->prepare("INSERT INTO room_requests (tenant_id, room_id, tenant_count, tenant_info_name, tenant_info_email, tenant_info_phone, tenant_info_address, notes, status, checkin_date, checkout_date) VALUES (:tenant_id, :room_id, :tenant_count, :tenant_info_name, :tenant_info_email, :tenant_info_phone, :tenant_info_address, :notes, 'pending_payment', :checkin_date, :checkout_date)");
                $rr_stmt->execute([
                    'tenant_id' => $newTenantId,
                    'room_id' => $room_id,
                    'tenant_count' => $tenant_count,
                    'tenant_info_name' => $name,
                    'tenant_info_email' => $email,
                    'tenant_info_phone' => $phone,
                    'tenant_info_address' => null,
                    'notes' => $notes,
                    'checkin_date' => $checkin_date,
                    'checkout_date' => $checkout_date
                ]);

                // Calculate advance amount (nights * rate)
                $rate_stmt = $conn->prepare("SELECT rate FROM rooms WHERE id = :room_id");
                $rate_stmt->execute(['room_id' => $room_id]);
                $rate = floatval($rate_stmt->fetchColumn());
                $nights = 0;
                try {
                    $ci = new DateTime($checkin_date);
                    $co = new DateTime($checkout_date);
                    $interval = $ci->diff($co);
                    $nights = max(0, (int)$interval->days);
                } catch (Exception $e) {
                    $nights = 0;
                }
                $total_cost = $rate * max(1, $nights);

                $bill_notes = "ADVANCE PAYMENT - Move-in fee (" . $nights . " night" . ($nights != 1 ? 's' : '') . ", ₱" . number_format($rate,2) . "/night)";
                $billing_month = $checkin_date ? (new DateTime($checkin_date))->format('Y-m') : date('Y-m');
                $due_date = $checkin_date ?: date('Y-m-d');

                $bill_stmt = $conn->prepare("INSERT INTO bills (tenant_id, room_id, billing_month, amount_due, due_date, status, notes, created_at, updated_at) VALUES (:tenant_id, :room_id, :billing_month, :amount_due, :due_date, 'pending', :notes, NOW(), NOW())");
                $bill_stmt->execute([
                    'tenant_id' => $newTenantId,
                    'room_id' => $room_id,
                    'billing_month' => $billing_month,
                    'amount_due' => $total_cost,
                    'due_date' => $due_date,
                    'notes' => $bill_notes
                ]);
                // Mark room as booked until payment is verified
                $update_room_booked = $conn->prepare("UPDATE rooms SET status = 'booked' WHERE id = :room_id");
                $update_room_booked->execute(['room_id' => $room_id]);
            } else {
                // Fallback: create initial placeholder bill/invoice for walk-in customer (pending payment)
                $billing_month = date('Y-m');
                $bill_notes = "WALK-IN CUSTOMER - Pending room selection and payment";
                $bill_stmt = $conn->prepare("INSERT INTO bills (tenant_id, billing_month, amount_due, due_date, status, notes) VALUES (:tenant_id, :billing_month, 0, NOW(), 'pending', :notes)");
                $bill_stmt->execute([
                    'tenant_id' => $newTenantId,
                    'billing_month' => $billing_month,
                    'notes' => $bill_notes
                ]);
            }
            
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
        $checkin_date = $_POST['checkin_date'] ?? null;
        $checkout_date = $_POST['checkout_date'] ?? null;
        $start_date = $checkin_date; // Use checkin_date as start_date for backward compatibility
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
                            <label for="room_id" class="form-label">Room</label>
                            <select class="form-control" id="room_id" name="room_id" required>
                                <option value="">Select a room</option>
                                <?php foreach($available_rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>" <?php echo $tenant['room_id'] == $room['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($room['room_number']); ?> (<?php echo htmlspecialchars($room['room_type']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="checkin_date" class="form-label">Check-in Date</label>
                            <input type="date" class="form-control" id="checkin_date" name="checkin_date" value="<?php echo htmlspecialchars($tenant['start_date']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="checkout_date" class="form-label">Check-out Date</label>
                            <input type="date" class="form-control" id="checkout_date" name="checkout_date" value="">
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