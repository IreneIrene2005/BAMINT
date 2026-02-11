<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "front_desk") {
    header("location: index.php?role=front_desk");
    exit;
}

require_once "db/database.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Front Desk Dashboard - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
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
            echo $sidebar;
            ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-person-badge"></i> Front Desk Dashboard</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-download"></i> Export
                        </button>
                    </div>
                </div>

                <!-- Welcome Section -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm bg-light">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="bi bi-hand-index"></i> Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!
                                </h5>
                                <p class="card-text text-muted">
                                    You are logged in as <strong>Front Desk Staff</strong>. Use the sidebar menu to access your duties and manage customer check-ins/check-outs.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <i class="bi bi-door-open"></i> Front Desk Check-in & Check-out
                            </div>
                            <div class="card-body">
                                <p class="card-text">Manage customer check-ins and check-outs for rooms.</p>
                                <a href="admin_checkin_checkout.php" class="btn btn-sm btn-primary">
                                    <i class="bi bi-arrow-right-circle"></i> Manage Check-in/Check-out
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-success text-white">
                                <i class="bi bi-door-closed"></i> Room Requests
                            </div>
                            <div class="card-body">
                                <p class="card-text">View and process customer room requests and bookings.</p>
                                <a href="room_requests_queue.php" class="btn btn-sm btn-success">
                                    <i class="bi bi-arrow-right-circle"></i> Room Requests Queue
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Pages -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="bi bi-grid-3x3-gap"></i> Quick Access Pages
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <a href="admin_bookings.php" class="btn btn-outline-danger w-100">
                                            <i class="bi bi-x-circle"></i> Cancellations
                                        </a>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <a href="admin_maintenance_queue.php" class="btn btn-outline-warning w-100">
                                            <i class="bi bi-tools"></i> Amenities Queue
                                        </a>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <a href="admin_resident_list.php" class="btn btn-outline-info w-100">
                                            <i class="bi bi-gift"></i> Extra Amenities
                                        </a>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <a href="bills.php" class="btn btn-outline-secondary w-100">
                                            <i class="bi bi-receipt"></i> Bills & Billing
                                        </a>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <a href="admin_additional_charges.php" class="btn btn-outline-success w-100">
                                            <i class="bi bi-cart-plus"></i> Additional Charges
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Section -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card metric-card bg-info text-white h-100">
                            <div class="card-body text-center">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">
                                    <i class="bi bi-door-open"></i>
                                </div>
                                <div class="metric-label small">Active Rooms</div>
                                <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;">
                                    <?php
                                    try {
                                        $stmt = $conn->query("SELECT COUNT(*) as count FROM rooms WHERE status = 'occupied'");
                                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                        echo $result['count'] ?? 0;
                                    } catch (Exception $e) {
                                        echo "0";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card metric-card bg-success text-white h-100">
                            <div class="card-body text-center">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                                <div class="metric-label small">Active Customers</div>
                                <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;">
                                    <?php
                                    try {
                                        $stmt = $conn->query("SELECT COUNT(*) as count FROM tenants WHERE status = 'active'");
                                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                        echo $result['count'] ?? 0;
                                    } catch (Exception $e) {
                                        echo "0";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card metric-card bg-warning text-dark h-100">
                            <div class="card-body text-center">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">
                                    <i class="bi bi-door-closed"></i>
                                </div>
                                <div class="metric-label small">Available Rooms</div>
                                <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;">
                                    <?php
                                    try {
                                        $stmt = $conn->query("SELECT COUNT(*) as count FROM rooms WHERE status = 'available'");
                                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                        echo $result['count'] ?? 0;
                                    } catch (Exception $e) {
                                        echo "0";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card metric-card bg-danger text-white h-100">
                            <div class="card-body text-center">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">
                                    <i class="bi bi-exclamation-circle"></i>
                                </div>
                                <div class="metric-label small">Maintenance</div>
                                <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;">
                                    <?php
                                    try {
                                        $stmt = $conn->query("SELECT COUNT(*) as count FROM maintenance_requests WHERE status IN ('Pending', 'In Progress')");
                                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                        echo $result['count'] ?? 0;
                                    } catch (Exception $e) {
                                        echo "0";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Information Panel -->
                <div class="row">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="bi bi-info-circle"></i> Front Desk Responsibilities
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold mb-3">Main Duties</h6>
                                        <ul class="list-group list-group-flush">
                                            <li class="list-group-item">Process customer check-ins and assign rooms</li>
                                            <li class="list-group-item">Process customer check-outs and settle final bills</li>
                                            <li class="list-group-item">Handle customer inquiries and requests</li>
                                            <li class="list-group-item">Process room booking requests from customers</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold mb-3">Available Functions</h6>
                                        <ul class="list-group list-group-flush">
                                            <li class="list-group-item">View and manage cancellations</li>
                                            <li class="list-group-item">Process amenity requests (Extra Amenities)</li>
                                            <li class="list-group-item">Track billing and additional charges</li>
                                            <li class="list-group-item">Log maintenance/amenity issues</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
