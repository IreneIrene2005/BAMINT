<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'front_desk'])) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

$success_msg = "";
$error_msg = "";

// Handle add/edit/delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (!empty($name) && $price >= 0) {
            try {
                if ($action === 'add') {
                    $stmt = $conn->prepare("
                        INSERT INTO extra_amenities (name, description, price, is_active)
                        VALUES (:name, :description, :price, :is_active)
                    ");
                    $stmt->execute([
                        'name' => $name,
                        'description' => $description,
                        'price' => $price,
                        'is_active' => $is_active
                    ]);
                    $success_msg = "Amenity added successfully!";
                } else {
                    $stmt = $conn->prepare("
                        UPDATE extra_amenities 
                        SET name = :name, description = :description, price = :price, is_active = :is_active
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'id' => $id,
                        'name' => $name,
                        'description' => $description,
                        'price' => $price,
                        'is_active' => $is_active
                    ]);
                    $success_msg = "Amenity updated successfully!";
                }
            } catch (Exception $e) {
                $error_msg = "Error: " . $e->getMessage();
            }
        } else {
            $error_msg = "Please fill in all required fields.";
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id']);
        try {
            $stmt = $conn->prepare("DELETE FROM extra_amenities WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $success_msg = "Amenity deleted successfully!";
        } catch (Exception $e) {
            $error_msg = "Error deleting amenity: " . $e->getMessage();
        }
    }
}

// Handle amenity order for walk-in customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'order_amenity') {
    $tenant_id = isset($_POST['tenant_id']) ? intval($_POST['tenant_id']) : 0;
    $amenity_id = isset($_POST['amenity_id']) ? intval($_POST['amenity_id']) : 0;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    $notes = trim($_POST['notes'] ?? '');
    
    if ($tenant_id > 0 && $amenity_id > 0 && $quantity > 0) {
        try {
            // Get amenity details
            $stmt = $conn->prepare("SELECT name, price FROM extra_amenities WHERE id = :id");
            $stmt->execute(['id' => $amenity_id]);
            $amenity = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($amenity) {
                // Get tenant's room_id
                $stmt = $conn->prepare("SELECT room_id FROM tenants WHERE id = :id");
                $stmt->execute(['id' => $tenant_id]);
                $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
                $room_id = $tenant['room_id'] ?? 0;
                
                // Calculate total cost
                $total_cost = $amenity['price'] * $quantity;
                
                // Record the amenity order in maintenance_requests table
                $stmt = $conn->prepare("
                    INSERT INTO maintenance_requests (tenant_id, room_id, category, description, priority, status, cost, notes)
                    VALUES (:tenant_id, :room_id, :category, :description, 'normal', 'pending', :cost, :notes)
                ");
                $stmt->execute([
                    'tenant_id' => $tenant_id,
                    'room_id' => $room_id,
                    'category' => $amenity['name'],
                    'description' => 'Amenity order - Qty: ' . $quantity,
                    'cost' => $total_cost,
                    'notes' => $notes
                ]);
                
                $success_msg = "Amenity order recorded successfully! Cost: ₱" . number_format($total_cost, 2);
            } else {
                $error_msg = "Amenity not found.";
            }
        } catch (Exception $e) {
            $error_msg = "Error recording amenity order: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please select a customer and amenity.";
    }
}

// Fetch active amenities
$active_amenities = [];
try {
    $stmt = $conn->prepare("SELECT id, name, price FROM extra_amenities WHERE is_active = 1 ORDER BY name ASC");
    $stmt->execute();
    $active_amenities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without amenities list
}

// Fetch active tenants for dropdown: only active tenants with a room assigned
$active_tenants = [];
try {
    $stmt = $conn->prepare("
        SELECT t.id, t.name, t.phone, t.email, r.room_number 
        FROM tenants t 
        LEFT JOIN rooms r ON t.room_id = r.id 
        WHERE t.status = 'active' AND t.room_id IS NOT NULL 
        ORDER BY t.name ASC
    ");
    $stmt->execute();
    $active_tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without tenants list
}

// Fetch all amenities
$amenities = [];
try {
    $stmt = $conn->prepare("SELECT * FROM extra_amenities ORDER BY name ASC");
    $stmt->execute();
    $amenities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_msg = "Error loading amenities: " . $e->getMessage();
}

// Get statistics
$total_amenities = count($amenities);
$active_amenities_count = count(array_filter($amenities, fn($a) => $a['is_active'] == 1));
$total_revenue_potential = array_sum(array_column($amenities, 'price'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extra Amenities - BAMINT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        body { background-color: #f8f9fa; }
        .amenity-card {
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
        }
        .amenity-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .amenity-card.inactive {
            border-left-color: #ccc;
            opacity: 0.7;
        }
        .stat-card {
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .stat-value { font-size: 1.75rem; font-weight: 700; }
        .price-badge {
            background: #e7f5ff;
            color: #1971c2;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
    </style>
</head>
<body>
    <?php include 'templates/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'templates/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Page Header -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="mb-1"><i class="bi bi-gift-fill"></i> Extra Amenities</h1>
                        <p class="text-muted"><?php echo $_SESSION['role'] === 'admin' ? 'Manage guest amenities and pricing' : 'Available amenities for ordering'; ?></p>
                    </div>
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#orderAmenityModal">
                            <i class="bi bi-bag-plus"></i> Order Amenities
                        </button>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAmenityModal">
                            <i class="bi bi-plus-circle"></i> Add Amenity
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success_msg): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-sm-6">
                        <div class="card stat-card bg-primary bg-opacity-10 h-100">
                            <div class="card-body text-center">
                                <p class="text-muted mb-2"><i class="bi bi-gift"></i> Total Amenities</p>
                                <div class="stat-value text-primary"><?php echo $total_amenities; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-sm-6">
                        <div class="card stat-card bg-success bg-opacity-10 h-100">
                            <div class="card-body text-center">
                                <p class="text-muted mb-2"><i class="bi bi-check-circle"></i> Active</p>
                                <div class="stat-value text-success"><?php echo $active_amenities_count; ?></div>
                            </div>
                        </div>
                    </div>
                    <!-- Avg/Max Price cards removed as requested -->
                </div>

                <!-- Amenities Grid -->
                <div class="row g-3">
                    <?php if (empty($amenities)): ?>
                        <div class="col-12">
                            <div class="alert alert-info text-center py-5">
                                <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                <p class="mt-3">No amenities found. Click "Add Amenity" to create one.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($amenities as $amenity): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="card amenity-card <?php echo $amenity['is_active'] ? '' : 'inactive'; ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h6 class="card-title mb-1"><?php echo htmlspecialchars($amenity['name']); ?></h6>
                                                <?php if (!$amenity['is_active']): ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="price-badge">₱<?php echo number_format($amenity['price'], 2); ?></span>
                                        </div>
                                        <p class="card-text text-muted small mb-3">
                                            <?php echo htmlspecialchars($amenity['description'] ?? 'No description'); ?>
                                        </p>
                                        <?php if ($_SESSION['role'] === 'admin'): ?>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editAmenityModal<?php echo $amenity['id']; ?>">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $amenity['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this amenity?');">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($_SESSION['role'] === 'admin'): ?>
                            <!-- Edit Amenity Modal -->
                            <div class="modal fade" id="editAmenityModal<?php echo $amenity['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Amenity</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="edit">
                                                <input type="hidden" name="id" value="<?php echo $amenity['id']; ?>">
                                                <div class="mb-3">
                                                    <label for="name_<?php echo $amenity['id']; ?>" class="form-label">Amenity Name <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" id="name_<?php echo $amenity['id']; ?>" name="name" value="<?php echo htmlspecialchars($amenity['name']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="description_<?php echo $amenity['id']; ?>" class="form-label">Description</label>
                                                    <textarea class="form-control" id="description_<?php echo $amenity['id']; ?>" name="description" rows="2"><?php echo htmlspecialchars($amenity['description'] ?? ''); ?></textarea>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="price_<?php echo $amenity['id']; ?>" class="form-label">Price (₱) <span class="text-danger">*</span></label>
                                                    <input type="number" class="form-control" id="price_<?php echo $amenity['id']; ?>" name="price" value="<?php echo $amenity['price']; ?>" step="0.01" required>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="is_active_<?php echo $amenity['id']; ?>" name="is_active" <?php echo $amenity['is_active'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="is_active_<?php echo $amenity['id']; ?>">
                                                        Active
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <?php if ($_SESSION['role'] === 'admin'): ?>
    <!-- Add Amenity Modal -->
    <div class="modal fade" id="addAmenityModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Amenity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="name_new" class="form-label">Amenity Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name_new" name="name" placeholder="e.g., Extra pillow" required>
                        </div>
                        <div class="mb-3">
                            <label for="description_new" class="form-label">Description</label>
                            <textarea class="form-control" id="description_new" name="description" placeholder="Brief description of the amenity" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="price_new" class="form-label">Price (₱) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="price_new" name="price" placeholder="0.00" step="0.01" required>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_active_new" name="is_active" checked>
                            <label class="form-check-label" for="is_active_new">
                                Active
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Amenity</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Order Amenity Modal -->
    <div class="modal fade" id="orderAmenityModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title"><i class="bi bi-bag-plus"></i> Order Amenities</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter: brightness(0) invert(1);"></button>
                </div>
                <form method="POST" id="orderAmenityForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="order_amenity">
                        
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <label for="tenant_select" class="form-label">Select Customer <span class="text-danger">*</span></label>
                                <select class="form-select" id="tenant_select" name="tenant_id" required onchange="updateTenantDetails()">
                                    <option value="">-- Choose a Customer --</option>
                                    <?php foreach ($active_tenants as $tenant): ?>
                                        <option value="<?php echo $tenant['id']; ?>" 
                                                data-room="<?php echo htmlspecialchars($tenant['room_number'] ?? 'N/A'); ?>"
                                                data-name="<?php echo htmlspecialchars($tenant['name']); ?>"
                                                data-phone="<?php echo htmlspecialchars($tenant['phone']); ?>"
                                                data-email="<?php echo htmlspecialchars($tenant['email']); ?>">
                                            <?php echo htmlspecialchars($tenant['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Customer Details Card -->
                        <div id="customerDetailsCard" class="card mb-3" style="display: none;">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-2">
                                            <strong><i class="bi bi-person"></i> Customer Name:</strong><br>
                                            <span id="detailName" class="text-muted">-</span>
                                        </p>
                                        <p class="mb-2">
                                            <strong><i class="bi bi-telephone"></i> Phone:</strong><br>
                                            <span id="detailPhone" class="text-muted">-</span>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-2">
                                            <strong><i class="bi bi-door-closed"></i> Room Number:</strong><br>
                                            <span id="detailRoom" class="text-muted badge bg-info" style="font-size: 0.9rem; padding: 0.5rem 0.75rem;">-</span>
                                        </p>
                                        <p class="mb-2">
                                            <strong><i class="bi bi-envelope"></i> Email:</strong><br>
                                            <span id="detailEmail" class="text-muted small">-</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <label for="amenity_select" class="form-label">Select Amenity <span class="text-danger">*</span></label>
                                <select class="form-select" id="amenity_select" name="amenity_id" required onchange="updateAmenityCost()">
                                    <option value="">-- Choose an Amenity --</option>
                                    <?php foreach ($active_amenities as $amenity): ?>
                                        <option value="<?php echo $amenity['id']; ?>" data-price="<?php echo $amenity['price']; ?>">
                                            <?php echo htmlspecialchars($amenity['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="quantity_input" class="form-label">Quantity <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="quantity_input" name="quantity" value="1" min="1" required onchange="updateAmenityCost()">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Unit Cost</label>
                                <input type="text" class="form-control" id="unit_cost_display" readonly placeholder="₱0.00">
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="card bg-light border-info">
                                    <div class="card-body">
                                        <h5 class="mb-3">Total Cost</h5>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span style="font-size: 1.25rem; font-weight: 600;">Total:</span>
                                            <span id="total_cost_display" style="font-size: 1.5rem; color: #0dcaf0; font-weight: 700;">₱0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label for="notes_input" class="form-label">Additional Notes (Optional)</label>
                            <textarea class="form-control" id="notes_input" name="notes" placeholder="Any special instructions or notes..." rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Record Amenity Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Real-time amenities update for front desk users
        const isFrontDesk = '<?php echo $_SESSION['role']; ?>' === 'front_desk';
        let lastAmenitiesChecksum = null;
        
        // Calculate checksum for amenities to detect changes
        function calculateChecksum(data) {
            let str = JSON.stringify(data);
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash; // Convert to 32bit integer
            }
            return hash.toString();
        }
        
        // Fetch amenities from API
        async function fetchAmenities() {
            try {
                const response = await fetch('api_get_amenities.php');
                const result = await response.json();
                
                if (result.success) {
                    const currentChecksum = calculateChecksum(result.data);
                    
                    // If amenities changed, reload the page
                    if (lastAmenitiesChecksum !== null && lastAmenitiesChecksum !== currentChecksum) {
                        console.log('Amenities updated by admin, refreshing page...');
                        location.reload();
                    }
                    
                    lastAmenitiesChecksum = currentChecksum;
                }
            } catch (error) {
                console.error('Error fetching amenities:', error);
            }
        }
        
        // Start polling for front desk users (check every 5 seconds)
        if (isFrontDesk) {
            // Initial fetch
            fetchAmenities();
            // Poll every 5 seconds
            setInterval(fetchAmenities, 5000);
            console.log('Real-time amenities polling enabled (5 second interval)');
        }
        
        function updateTenantDetails() {
            const select = document.getElementById('tenant_select');
            const option = select.options[select.selectedIndex];
            const detailCard = document.getElementById('customerDetailsCard');
            
            if (select.value) {
                document.getElementById('detailName').textContent = option.dataset.name;
                document.getElementById('detailPhone').textContent = option.dataset.phone;
                document.getElementById('detailRoom').textContent = option.dataset.room;
                document.getElementById('detailEmail').textContent = option.dataset.email;
                detailCard.style.display = 'block';
            } else {
                detailCard.style.display = 'none';
            }
        }

        function updateAmenityCost() {
            const amenitySelect = document.getElementById('amenity_select');
            const quantity = parseInt(document.getElementById('quantity_input').value) || 1;
            const option = amenitySelect.options[amenitySelect.selectedIndex];
            const price = parseFloat(option.dataset.price) || 0;
            
            const unitCostDisplay = document.getElementById('unit_cost_display');
            const totalCostDisplay = document.getElementById('total_cost_display');
            
            if (amenitySelect.value) {
                unitCostDisplay.value = '₱' + price.toFixed(2);
                const totalCost = price * quantity;
                totalCostDisplay.textContent = '₱' + totalCost.toFixed(2);
            } else {
                unitCostDisplay.value = '';
                totalCostDisplay.textContent = '₱0.00';
            }
        }
    </script>
</body>
</html>
