<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'front_desk'])) {
    header("location: index.php");
    exit;
}

require_once "db/database.php";

// Handle archive action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'archive') {
    $tenant_id = intval($_POST['tenant_id'] ?? 0);
    if ($tenant_id > 0) {
        try {
            $stmt = $conn->prepare("UPDATE tenants SET status = 'inactive' WHERE id = :id");
            $stmt->execute(['id' => $tenant_id]);
            $_SESSION['flash_message'] = 'Customer archived successfully.';
            header("location: tenants.php?view=active");
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'Error archiving customer: ' . $e->getMessage();
        }
    }
}

// Search and filter variables
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_room = isset($_GET['room']) ? $_GET['room'] : '';
// view: 'active' or 'archive'
$view = isset($_GET['view']) ? $_GET['view'] : 'active';

// Build the SQL query with search and filter
// Include tenants with room requests that are either:
// 1. Pending payment with payment verified (awaiting admin approval)
// 2. Approved/occupied (fully confirmed bookings)
// This shows customers who have paid and are waiting for approval, plus those already approved
// Also include walk-in customers that have checkin_time and checkout_time in tenants table
$sql = "SELECT DISTINCT tenants.*, COALESCE(rooms.room_number, (
            SELECT r2.room_number FROM bills b 
            JOIN rooms r2 ON b.room_id = r2.id 
            WHERE b.tenant_id = tenants.id ORDER BY b.id DESC LIMIT 1
        )) as room_number, COALESCE(
            (SELECT tenant_info_address FROM room_requests WHERE tenant_id = tenants.id AND status IN ('pending_payment', 'approved', 'occupied') ORDER BY id DESC LIMIT 1),
            tenants.address
        ) as address FROM tenants 
        LEFT JOIN rooms ON tenants.room_id = rooms.id
        WHERE tenants.status != 'inactive'
        AND (
            EXISTS (
                SELECT 1 FROM room_requests rr
                WHERE rr.tenant_id = tenants.id 
                AND (
                    (rr.status IN ('approved', 'occupied'))
                    OR (rr.status = 'pending_payment' AND EXISTS (
                        SELECT 1 FROM bills b 
                        WHERE b.tenant_id = tenants.id 
                        AND b.room_id = rr.room_id 
                        AND b.status IN ('paid', 'partial')
                    ))
                )
            )
            OR (tenants.checkin_time IS NOT NULL AND tenants.checkin_time != '0000-00-00 00:00:00')
        )";

if ($search) {
    $sql .= " AND (tenants.name LIKE :search OR tenants.email LIKE :search OR tenants.phone LIKE :search OR tenants.id_number LIKE :search OR (SELECT tenant_info_address FROM room_requests WHERE tenant_id = tenants.id AND status IN ('pending_payment', 'approved', 'occupied') ORDER BY id DESC LIMIT 1) LIKE :search)";
}

if ($filter_status) {
    $sql .= " AND tenants.status = :status";
}

if ($filter_room) {
    $sql .= " AND tenants.room_id = :room_id";
}


// (status filter already applied above)

$sql .= " ORDER BY tenants.name ASC";

// Prepare active tenants statement
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

// Prepare archive query - show all archived tenants (unless deleted by admin/front desk)
$archive_sql = "SELECT DISTINCT tenants.*, COALESCE(rooms.room_number, (
            SELECT r2.room_number FROM bills b 
            JOIN rooms r2 ON b.room_id = r2.id 
            WHERE b.tenant_id = tenants.id ORDER BY b.id DESC LIMIT 1
        )) as room_number, COALESCE(
            (SELECT tenant_info_address FROM room_requests WHERE tenant_id = tenants.id ORDER BY id DESC LIMIT 1),
            tenants.address
        ) as address FROM tenants 
        LEFT JOIN rooms ON tenants.room_id = rooms.id
        WHERE tenants.status = 'inactive'";

if ($search) {
    $archive_sql .= " AND (tenants.name LIKE :search OR tenants.email LIKE :search OR tenants.phone LIKE :search OR tenants.id_number LIKE :search OR (SELECT tenant_info_address FROM room_requests WHERE tenant_id = tenants.id ORDER BY id DESC LIMIT 1) LIKE :search)";
}

if ($filter_status) {
    $archive_sql .= " AND tenants.status = :status";
}

if ($filter_room) {
    $archive_sql .= " AND tenants.room_id = :room_id";
}

    $archive_sql .= " ORDER BY tenants.name ASC";

// prepare statements

$archive_stmt = $conn->prepare($archive_sql);

// bind same params for archive statement if needed
if ($search) {
    $archive_stmt->bindParam(':search', $search_param);
}

if ($filter_status) {
    $archive_stmt->bindParam(':status', $filter_status);
}

if ($filter_room) {
    $archive_stmt->bindParam(':room_id', $filter_room);
}

$stmt->execute();
$archive_stmt->execute();

// choose which result set to iterate
$tenants = ($view === 'archive') ? $archive_stmt : $stmt;



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
    <title>Customers</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="public/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>
<body>

<?php include 'templates/header.php'; ?>

<!-- Flash Messages -->
<?php if (isset($_SESSION['flash_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show m-3" role="alert">
        <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (isset($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show m-3" role="alert">
        <?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

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
                    <div class="btn-group me-2" role="group" aria-label="View switch">
                        <a href="tenants.php?view=active" class="btn btn-sm <?php echo $view === 'active' ? 'btn-primary' : 'btn-outline-primary'; ?>">Active</a>
                        <a href="tenants.php?view=archive" class="btn btn-sm <?php echo $view === 'archive' ? 'btn-primary' : 'btn-outline-primary'; ?>">Archive</a>
                    </div>
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="row mb-3">
                <div class="col-md-12">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" placeholder="Name, email, phone, address, ID..." value="<?php echo htmlspecialchars($search); ?>">
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
                            <th>Address</th>
                            <th>Room</th>
                            <th>Stay Duration</th>
                            <th>Status</th>
                            <th>Booking Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $tenants->fetch(PDO::FETCH_ASSOC)) : ?>
                        <?php
                            // Count roommates for this tenant
                            $roommate_stmt = $conn->prepare("SELECT COUNT(*) as roommate_count FROM co_tenants WHERE primary_tenant_id = :tenant_id");
                            $roommate_stmt->execute(['tenant_id' => $row['id']]);
                            $roommate_count = intval($roommate_stmt->fetchColumn());
                        ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($row['name']); ?>
                                <?php if ($roommate_count > 0): ?>
                                    <span class="badge bg-info ms-2" title="Click View Details to see roommates">
                                        <i class="bi bi-plus-circle"></i> <?php echo $roommate_count; ?> roommate<?php echo $roommate_count > 1 ? 's' : ''; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td><?php echo htmlspecialchars($row['phone']); ?></td>
                            <td><?php echo htmlspecialchars($row['address'] ?? '-'); ?></td>
                            <td>
                                <?php
                                    // Show room for pending_payment (paid), approved, or occupied bookings
                                    $room_req_stmt = $conn->prepare("SELECT status FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                    $room_req_stmt->execute(['tenant_id' => $row['id'], 'room_id' => $row['room_id']]);
                                    $room_req = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                    if ($room_req && in_array($room_req['status'], ['pending_payment', 'approved', 'occupied'])) {
                                        echo htmlspecialchars($row['room_number']);
                                    } else {
                                        echo '-';
                                    }
                                ?>
                            </td>
                            <td>
                                <?php
                                // Show stay duration for active/paid bookings
                                $room_req_stmt = $conn->prepare("SELECT checkin_date, checkout_date, checkin_time, checkout_time, status FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                $room_req_stmt->execute(['tenant_id' => $row['id'], 'room_id' => $row['room_id']]);
                                $dates = $room_req_stmt->fetch(PDO::FETCH_ASSOC);
                                if ($dates && in_array($dates['status'], ['pending_payment', 'approved', 'occupied'])) {
                                    if (!empty($dates['checkin_date']) && !empty($dates['checkout_date'])) {
                                        $ci_fmt = date('M d, Y', strtotime($dates['checkin_date']));
                                        $co_fmt = date('M d, Y', strtotime($dates['checkout_date']));
                                        // Add times if present
                                        if (!empty($dates['checkin_time']) && $dates['checkin_time'] !== '00:00:00') {
                                            $ci_fmt .= ' ' . date('g:i A', strtotime($dates['checkin_time']));
                                        }
                                        if (!empty($dates['checkout_time']) && $dates['checkout_time'] !== '00:00:00') {
                                            $co_fmt .= ' ' . date('g:i A', strtotime($dates['checkout_time']));
                                        }
                                        echo htmlspecialchars($ci_fmt . ' - ' . $co_fmt);
                                    } elseif (!empty($dates['checkin_date'])) {
                                        $ci_fmt = date('M d, Y', strtotime($dates['checkin_date']));
                                        if (!empty($dates['checkin_time']) && $dates['checkin_time'] !== '00:00:00') {
                                            $ci_fmt .= ' ' . date('g:i A', strtotime($dates['checkin_time']));
                                        }
                                        echo htmlspecialchars($ci_fmt . ' - Present');
                                    } else {
                                        echo '-';
                                    }
                                } else {
                                    // If no room_request times, check tenants table (for walk-in customers added via form)
                                    if (!empty($row['checkin_time']) && $row['checkin_time'] !== '0000-00-00 00:00:00' && 
                                        !empty($row['checkout_time']) && $row['checkout_time'] !== '0000-00-00 00:00:00') {
                                        try {
                                            $ci_date_obj = new DateTime($row['checkin_time']);
                                            $co_date_obj = new DateTime($row['checkout_time']);
                                            $ci_fmt = $ci_date_obj->format('M d, Y g:i A');
                                            $co_fmt = $co_date_obj->format('M d, Y g:i A');
                                            echo htmlspecialchars($ci_fmt . ' - ' . $co_fmt);
                                        } catch (Exception $e) {
                                            echo '-';
                                        }
                                    } else {
                                        echo '-';
                                    }
                                }
                                ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $row['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo ucfirst($row['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                    // Show booking status from room_requests
                                    $booking_stmt = $conn->prepare("SELECT status FROM room_requests WHERE tenant_id = :tenant_id AND room_id = :room_id ORDER BY id DESC LIMIT 1");
                                    $booking_stmt->execute(['tenant_id' => $row['id'], 'room_id' => $row['room_id']]);
                                    $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);
                                    if ($booking) {
                                        if ($booking['status'] === 'approved' || $booking['status'] === 'occupied') {
                                            echo '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Approved</span>';
                                        } elseif ($booking['status'] === 'pending_payment') {
                                            echo '<span class="badge bg-warning"><i class="bi bi-clock"></i> Awaiting Approval</span>';
                                        } else {
                                            echo '<span class="badge bg-secondary">' . ucfirst($booking['status']) . '</span>';
                                        }
                                    }
                                ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-info" title="View Details" data-bs-toggle="modal" data-bs-target="#viewDetailsModal" onclick="loadCustomerDetails(<?php echo $row['id']; ?>)"><i class="bi bi-eye"></i></button>
                                <a href="tenant_actions.php?action=edit&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil-square"></i></a>
                                <?php if ($view === 'archive'): ?>
                                    <a href="tenant_actions.php?action=restore&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-success" title="Restore" onclick="return confirm('Restore this customer from archive?');"><i class="bi bi-arrow-counterclockwise"></i></a>
                                <?php else: ?>
                                    <?php if($row['status'] === 'active'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="tenant_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Archive" onclick="return confirm('Archive this customer?');"><i class="bi bi-archive"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <a href="tenant_actions.php?action=activate&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-success" title="Activate"><i class="bi bi-play-circle"></i></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <a href="tenant_actions.php?action=delete&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this customer?');"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- View Customer Details Modal -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-labelledby="viewDetailsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewDetailsModalLabel">Customer Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="detailsContent">
        <div class="text-center">
          <div class="spinner-border" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
        </div>
      </div>
    </div>
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
            <label for="address" class="form-label">Address</label>
            <input type="text" class="form-control" id="address" name="address" placeholder="Street address">
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
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="checkin_time" class="form-label">Check-in Time</label>
                            <input type="text" class="form-control flat-time" id="checkin_time" name="checkin_time" value="<?php echo date('H:i'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="checkout_time" class="form-label">Check-out Time</label>
                            <input type="text" class="form-control flat-time" id="checkout_time" name="checkout_time" value="<?php echo date('H:i', strtotime('+1 hour')); ?>">
                        </div>
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
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const checkinEl = document.getElementById('checkin_time');
    const checkoutEl = document.getElementById('checkout_time');
    
    flatpickr('.flat-time', {
        enableTime: true,
        noCalendar: true,
        dateFormat: 'h:i K',
        time_24hr: false,
        minuteIncrement: 1,
        onChange: function(selectedDates, dateStr, instance) {
            // If this is the check-in field, auto-update checkout
            if (instance.element === checkinEl && checkoutEl) {
                checkoutEl.value = dateStr;
                // Trigger flatpickr update on checkout field
                if (checkoutEl._flatpickr) {
                    checkoutEl._flatpickr.setDate(dateStr, true);
                }
            }
        }
    });
});
</script>
<script>
// Load customer details via AJAX
function loadCustomerDetails(tenantId) {
    const detailsContent = document.getElementById('detailsContent');
    detailsContent.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    
    fetch('get_customer_details.php?id=' + encodeURIComponent(tenantId))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = `
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Name:</strong><br>
                            ${escapeHtml(data.customer.name)}
                        </div>
                        <div class="col-md-6">
                            <strong>Email:</strong><br>
                            ${escapeHtml(data.customer.email)}
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Phone:</strong><br>
                            ${escapeHtml(data.customer.phone)}
                        </div>
                        <div class="col-md-6">
                            <strong>Status:</strong><br>
                            <span class="badge ${data.customer.status === 'active' ? 'bg-success' : 'bg-danger'}">${data.customer.status.toUpperCase()}</span>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <strong>Address:</strong><br>
                            ${data.customer.address ? escapeHtml(data.customer.address) : '<span class="text-muted">-</span>'}
                        </div>
                    </div>
                `;
                
                if (data.room_info) {
                    html += `
                        <hr>
                        <h6>Room Information</h6>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Room Number:</strong><br>
                                ${escapeHtml(data.room_info.room_number)}
                            </div>
                            <div class="col-md-6">
                                <strong>Room Type:</strong><br>
                                ${escapeHtml(data.room_info.room_type)}
                            </div>
                        </div>
                    `;
                }
                
                if (data.stay_info) {
                    html += `
                        <hr>
                        <h6>Check-In/Check-Out Information</h6>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Check-in Date:</strong><br>
                                ${data.stay_info.checkin_date ? new Date(data.stay_info.checkin_date).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'}) : 'N/A'}
                            </div>
                            <div class="col-md-6">
                                <strong>Check-in Time:</strong><br>
                                ${data.stay_info.checkin_time ? data.stay_info.checkin_time : 'N/A'}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Check-out Date:</strong><br>
                                ${data.stay_info.checkout_date ? new Date(data.stay_info.checkout_date).toLocaleDateString('en-US', {year: 'numeric', month: 'short', day: 'numeric'}) : 'N/A'}
                            </div>
                            <div class="col-md-6">
                                <strong>Check-out Time:</strong><br>
                                ${data.stay_info.checkout_time ? data.stay_info.checkout_time : 'N/A'}
                            </div>
                        </div>
                    `;
                }
                
                if (data.co_tenants && data.co_tenants.length > 0) {
                    html += `
                        <hr>
                        <h6><i class="bi bi-people"></i> Roommates (${data.co_tenants.length})</h6>
                        <div class="list-group">
                    `;
                    data.co_tenants.forEach(tenant => {
                        html += `
                            <div class="list-group-item">
                                <strong>${escapeHtml(tenant.name)}</strong><br>
                                ${tenant.email ? '<small>Email: ' + escapeHtml(tenant.email) + '</small><br>' : ''}
                                ${tenant.phone ? '<small>Phone: ' + escapeHtml(tenant.phone) + '</small>' : ''}
                            </div>
                        `;
                    });
                    html += `</div>`;
                }
                
                detailsContent.innerHTML = html;
            } else {
                detailsContent.innerHTML = '<div class="alert alert-danger">Error loading customer details: ' + escapeHtml(data.message) + '</div>';
            }
        })
        .catch(error => {
            detailsContent.innerHTML = '<div class="alert alert-danger">Error loading customer details: ' + escapeHtml(error.message) + '</div>';
        });
}

function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

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