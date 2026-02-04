<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

// Map maintenance category to default cost (₱)
function getCategoryCost($category) {
    $prices = [
        'Door/Lock' => 150,
        'Walls/Paint' => 200,
        'Furniture' => 200,
        'Cleaning' => 100,
        'Light/Bulb' => 50,
        'Leak/Water' => 150,
        'Pest/Bedbugs' => 100,
        'Appliances' => 200,
        'Other' => null
    ];

    return array_key_exists($category, $prices) ? $prices[$category] : null;
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'add') {
        // Submit new maintenance request
        $tenant_id = $_POST['tenant_id'];
        $room_id = $_POST['room_id'];
        $category = $_POST['category'];
        $priority = $_POST['priority'];
        $description = $_POST['description'];
        
        try {
            // determine cost from category (admin can change later in edit)
            $cost = getCategoryCost($category);

                 $sql = "INSERT INTO maintenance_requests (tenant_id, room_id, category, priority, description, status, cost) VALUES (:tenant_id, :room_id, :category, :priority, :description, 'pending', :cost)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                'tenant_id' => $tenant_id,
                'room_id' => $room_id,
                'category' => $category,
                'priority' => $priority,
                'description' => $description,
                'cost' => $cost
            ]);
            
            $maintenanceId = $conn->lastInsertId();
            
            // Notify all admins about new maintenance request
            notifyAdminsNewMaintenance($conn, $maintenanceId, $tenant_id, $category);
            
            $_SESSION['message'] = "Maintenance request submitted successfully!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Error submitting request: " . $e->getMessage();
        }
        
        header("location: maintenance_requests.php");
        exit;
        
    } elseif ($action === 'edit') {
        // Update maintenance request
        $id = $_POST['id'];
        $assigned_to = $_POST['assigned_to'] ?? null;
        $status = $_POST['status'];
        $priority = $_POST['priority'];
        $category = $_POST['category'];
        $start_date = $_POST['start_date'] ?? null;
        $completion_date = $_POST['completion_date'] ?? null;
        $cost = $_POST['cost'] ?? null;
        $notes = $_POST['notes'] ?? '';

        // If no explicit cost provided, derive from category
        if ($cost === null || $cost === '') {
            $cost = getCategoryCost($category);
        }
        
        try {
            // Get tenant ID for notification
            $getMaintenanceStmt = $conn->prepare("SELECT tenant_id FROM maintenance_requests WHERE id = :id");
            $getMaintenanceStmt->execute(['id' => $id]);
            $maintenance = $getMaintenanceStmt->fetch(PDO::FETCH_ASSOC);
            
            $conn->beginTransaction();
            
            $sql = "UPDATE maintenance_requests SET 
                   assigned_to = :assigned_to,
                   status = :status,
                   priority = :priority,
                   category = :category,
                   start_date = :start_date,
                   completion_date = :completion_date,
                   cost = :cost,
                   notes = :notes
                   WHERE id = :id";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                'assigned_to' => $assigned_to ?: null,
                'status' => $status,
                'priority' => $priority,
                'category' => $category,
                'start_date' => $start_date,
                'completion_date' => $completion_date,
                'cost' => $cost,
                'notes' => $notes,
                'id' => $id
            ]);
            
            $conn->commit();
            
            // If status changed to completed and cost exists, add to tenant's next bill
            if ($status === 'completed' && $cost && $maintenance) {
                // Include request id and category so the bill notes include the reference
                addMaintenanceCostToBill($conn, $maintenance['tenant_id'], $cost, $id, $category);
            }
            
            // Notify tenant about maintenance status change
            if ($maintenance) {
                notifyTenantMaintenanceStatus($conn, $maintenance['tenant_id'], $id, $status);
            }
            
            $_SESSION['message'] = "Request updated successfully!";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error updating request: " . $e->getMessage();
        }
        
        header("location: maintenance_requests.php");
        exit;
    }
} else { // GET request
    if ($action === 'delete') {
        $id = $_GET['id'];
        
        try {
            $sql = "DELETE FROM maintenance_requests WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            $_SESSION['message'] = "Request deleted successfully!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Error deleting request: " . $e->getMessage();
        }
        
        header("location: maintenance_requests.php");
        exit;
        
    } elseif ($action === 'get_room') {
        // AJAX endpoint to get room details
        header('Content-Type: application/json');
        $room_id = $_GET['id'];
        $sql = "SELECT id, room_number FROM rooms WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['id' => $room_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($room);
        exit;
        
    } elseif ($action === 'view') {
        // Show request details view
        $id = $_GET['id'];
        $sql = "SELECT maintenance_requests.*, tenants.name as tenant_name, tenants.email, tenants.phone, 
                       rooms.room_number, admins.username 
                FROM maintenance_requests
                LEFT JOIN tenants ON maintenance_requests.tenant_id = tenants.id
                LEFT JOIN rooms ON maintenance_requests.room_id = rooms.id
                LEFT JOIN admins ON maintenance_requests.assigned_to = admins.id
                WHERE maintenance_requests.id = :id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute(['id' => $id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            header("location: maintenance_requests.php");
            exit;
        }
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>View Request</title>
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
                        <h1 class="h2">Request #<?php echo str_pad($request['id'], 4, '0', STR_PAD_LEFT); ?></h1>
                        <a href="maintenance_requests.php" class="btn btn-secondary">Back</a>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h5 class="card-title mb-0"><?php echo htmlspecialchars($request['category']); ?></h5>
                                </div>
                                <div class="card-body">
                                    <p class="card-text"><?php echo nl2br(htmlspecialchars($request['description'])); ?></p>
                                </div>
                            </div>
                            
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Tenant Information</h5>
                                </div>
                                <div class="card-body">
                                    <p>
                                        <strong>Name:</strong> <?php echo htmlspecialchars($request['tenant_name']); ?><br>
                                        <strong>Room:</strong> <?php echo htmlspecialchars($request['room_number']); ?><br>
                                        <strong>Email:</strong> <?php echo htmlspecialchars($request['email']); ?><br>
                                        <strong>Phone:</strong> <?php echo htmlspecialchars($request['phone']); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <?php if ($request['notes']): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Notes</h5>
                                </div>
                                <div class="card-body">
                                    <p><?php echo nl2br(htmlspecialchars($request['notes'])); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">Status</h6>
                                </div>
                                <div class="card-body">
                                    <span class="badge bg-<?php 
                                        echo $request['status'] === 'completed' ? 'success' : ($request['status'] === 'in_progress' ? 'primary' : 'warning');
                                    ?> mb-2">
                                        <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                    </span>
                                    <br>
                                    <span class="badge bg-<?php 
                                        echo $request['priority'] === 'high' ? 'danger' : ($request['priority'] === 'normal' ? 'warning' : 'info');
                                    ?>">
                                        Priority: <?php echo ucfirst($request['priority']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">Assigned To</h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($request['username']): ?>
                                        <p class="mb-0"><strong><?php echo htmlspecialchars($request['username']); ?></strong></p>
                                    <?php else: ?>
                                        <p class="mb-0 text-muted">Unassigned</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">Timeline</h6>
                                </div>
                                <div class="card-body">
                                    <small>
                                        <strong>Submitted:</strong> <?php echo date('M d, Y g:i A', strtotime($request['submitted_date'])); ?><br>
                                        <?php if ($request['start_date']): ?>
                                        <strong>Started:</strong> <?php echo date('M d, Y g:i A', strtotime($request['start_date'])); ?><br>
                                        <?php endif; ?>
                                        <?php if ($request['completion_date']): ?>
                                        <strong>Completed:</strong> <?php echo date('M d, Y g:i A', strtotime($request['completion_date'])); ?><br>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                            
                            <?php if ($request['cost']): ?>
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">Cost</h6>
                                </div>
                                <div class="card-body">
                                    <p class="display-6 mb-0">₱<?php echo number_format($request['cost'], 2); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <a href="maintenance_actions.php?action=edit&id=<?php echo $request['id']; ?>" class="btn btn-warning w-100">Edit Request</a>
                        </div>
                    </div>
                </main>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
        </body>
        </html>
        <?php
        
    } elseif ($action === 'edit') {
        // Show edit form
        $id = $_GET['id'];
        $sql = "SELECT maintenance_requests.*, tenants.name as tenant_name, rooms.room_number
                FROM maintenance_requests
                LEFT JOIN tenants ON maintenance_requests.tenant_id = tenants.id
                LEFT JOIN rooms ON maintenance_requests.room_id = rooms.id
                WHERE maintenance_requests.id = :id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute(['id' => $id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            header("location: maintenance_requests.php");
            exit;
        }
        
        // Get all staff
        $sql_staff = "SELECT id, username FROM admins ORDER BY username ASC";
        $all_staff = $conn->query($sql_staff);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Edit Request</title>
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
                        <h1 class="h2">Edit Request #<?php echo str_pad($request['id'], 4, '0', STR_PAD_LEFT); ?></h1>
                    </div>
                    
                    <form action="maintenance_actions.php?action=edit" method="post" class="row">
                        <input type="hidden" name="id" value="<?php echo $request['id']; ?>">
                        
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Request Details</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label"><strong>Tenant</strong></label>
                                        <p class="form-control-plaintext"><?php echo htmlspecialchars($request['tenant_name']); ?></p>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label"><strong>Room</strong></label>
                                        <p class="form-control-plaintext"><?php echo htmlspecialchars($request['room_number']); ?></p>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="category" class="form-label">Category</label>
                                        <select class="form-control" id="category" name="category" required>
                                            <option value="">Select category</option>
                                            <option value="Door/Lock" <?php echo $request['category'] === 'Door/Lock' ? 'selected' : ''; ?>>Door/Lock – Broken lock, stuck door ₱150</option>
                                            <option value="Walls/Paint" <?php echo $request['category'] === 'Walls/Paint' ? 'selected' : ''; ?>>Walls/Paint – Scratches, peeling paint ₱200</option>
                                            <option value="Furniture" <?php echo $request['category'] === 'Furniture' ? 'selected' : ''; ?>>Furniture – Bedframe/furniture repair ₱200</option>
                                            <option value="Cleaning" <?php echo $request['category'] === 'Cleaning' ? 'selected' : ''; ?>>Cleaning – Deep cleaning, carpet/fan cleaning ₱100</option>
                                            <option value="Light/Bulb" <?php echo $request['category'] === 'Light/Bulb' ? 'selected' : ''; ?>>Light/Bulb – Bulb replacement, fixture issues ₱50</option>
                                            <option value="Leak/Water" <?php echo $request['category'] === 'Leak/Water' ? 'selected' : ''; ?>>Leak/Water – Faucet drips, small pipe leak ₱150</option>
                                            <option value="Pest/Bedbugs" <?php echo $request['category'] === 'Pest/Bedbugs' ? 'selected' : ''; ?>>Pest/Bedbugs – Cockroaches, ants, bedbugs ₱100</option>
                                            <option value="Appliances" <?php echo $request['category'] === 'Appliances' ? 'selected' : ''; ?>>Appliances – Fan, fridge, microwave repair ₱200</option>
                                            <option value="Other" <?php echo $request['category'] === 'Other' ? 'selected' : ''; ?>>Other – Describe your issue (Cost determined by admin)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="priority" class="form-label">Priority</label>
                                        <select class="form-control" id="priority" name="priority" required>
                                            <option value="low" <?php echo $request['priority'] === 'low' ? 'selected' : ''; ?>>Low</option>
                                            <option value="normal" <?php echo $request['priority'] === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                            <option value="high" <?php echo $request['priority'] === 'high' ? 'selected' : ''; ?>>High</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Status & Assignment</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-control" id="status" name="status" required>
                                            <option value="pending" <?php echo $request['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="in_progress" <?php echo $request['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="completed" <?php echo $request['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="cancelled" <?php echo $request['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="assigned_to" class="form-label">Assign To</label>
                                        <select class="form-control" id="assigned_to" name="assigned_to">
                                            <option value="">-- Unassigned --</option>
                                            <?php 
                                            while($staff = $all_staff->fetch(PDO::FETCH_ASSOC)): ?>
                                                <option value="<?php echo $staff['id']; ?>" <?php echo $request['assigned_to'] == $staff['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($staff['username']); ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="cost" class="form-label">Cost (₱)</label>
                                        <input type="number" step="0.01" class="form-control" id="cost" name="cost" value="<?php echo $request['cost'] ?? ''; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Timeline & Notes</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="start_date" class="form-label">Start Date</label>
                                                <input type="datetime-local" class="form-control" id="start_date" name="start_date" value="<?php echo $request['start_date'] ? date('Y-m-d\TH:i', strtotime($request['start_date'])) : ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="completion_date" class="form-label">Completion Date</label>
                                                <input type="datetime-local" class="form-control" id="completion_date" name="completion_date" value="<?php echo $request['completion_date'] ? date('Y-m-d\TH:i', strtotime($request['completion_date'])) : ''; ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Notes</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="4"><?php echo htmlspecialchars($request['notes']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                            <a href="maintenance_requests.php" class="btn btn-secondary">Cancel</a>
                        </div>
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
