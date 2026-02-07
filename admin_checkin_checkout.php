<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}

require_once "db/database.php";

$message = '';
$message_type = '';
$search_query = '';
$search_type = 'name';
$guest_info = null;
$booking_details = null;
$verification_issues = [];
$active_tab = isset($_POST['active_tab']) ? $_POST['active_tab'] : 'checkin';
$checkout_info = null;
$checkout_details = null;

// Fetch available guests for dropdown
$available_guests = [];
$available_rooms = [];
$available_bookings = [];
$checked_in_guests = []; // For checkout

try {
    // Get all guests with bookings
    $guest_stmt = $conn->query("SELECT DISTINCT t.id, t.name FROM tenants t LEFT JOIN room_requests rr ON t.id = rr.tenant_id WHERE rr.id IS NOT NULL ORDER BY t.name ASC");
    $available_guests = $guest_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all occupied rooms
    $room_stmt = $conn->query("SELECT DISTINCT r.id, r.room_number FROM rooms r WHERE r.status IN ('available', 'occupied') ORDER BY r.room_number ASC");
    $available_rooms = $room_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all active bookings
    $booking_stmt = $conn->query("SELECT DISTINCT rr.id, CONCAT('Ref #', rr.id, ' - ', t.name) as booking_desc FROM room_requests rr LEFT JOIN tenants t ON rr.tenant_id = t.id ORDER BY rr.id DESC");
    $available_bookings = $booking_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get currently checked-in guests
    $checked_in_stmt = $conn->query("SELECT DISTINCT t.id, t.name FROM tenants t WHERE t.checkin_time IS NOT NULL AND t.checkin_time != '0000-00-00 00:00:00' AND (t.checkout_time IS NULL OR t.checkout_time = '0000-00-00 00:00:00') ORDER BY t.name ASC");
    $checked_in_guests = $checked_in_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Silently fail on dropdown population
}

// Handle guest search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_guest'])) {
    $search_query = trim($_POST['search_query'] ?? '');
    $search_type = $_POST['search_type'] ?? 'name';
    
    if (empty($search_query)) {
        $message = 'Please select a guest or booking.';
        $message_type = 'warning';
    } else {
        try {
            // First get basic booking info
            $sql = "SELECT t.id, t.name, t.email, t.phone, t.room_id, t.checkin_time, t.checkout_time,
                           r.room_number, r.status as room_status,
                           rr.id as booking_id, rr.checkin_date, rr.checkout_date,
                           b.id as bill_id, b.amount_paid, b.amount_due, b.status as bill_status
                    FROM tenants t
                    LEFT JOIN rooms r ON t.room_id = r.id
                    LEFT JOIN room_requests rr ON t.id = rr.tenant_id
                    LEFT JOIN bills b ON t.id = b.tenant_id
                    WHERE ";
            
            if ($search_type === 'name') {
                $sql .= "t.id = :query";
                $query_param = intval($search_query);
            } elseif ($search_type === 'room') {
                $sql .= "r.id = :query";
                $query_param = intval($search_query);
            } elseif ($search_type === 'booking_ref') {
                $sql .= "rr.id = :query";
                $query_param = intval($search_query);
            }
            
            $sql .= " ORDER BY rr.checkin_date DESC, b.id DESC LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute(['query' => $query_param]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If we have a bill_id, check for payment transactions
            if ($result && $result['bill_id']) {
                $payment_stmt = $conn->prepare("SELECT payment_status FROM payment_transactions WHERE bill_id = :bill_id ORDER BY id DESC LIMIT 1");
                $payment_stmt->execute(['bill_id' => $result['bill_id']]);
                $payment_row = $payment_stmt->fetch(PDO::FETCH_ASSOC);
                $result['payment_status'] = $payment_row ? ($payment_row['payment_status'] ?? 'pending') : 'pending';
            } else {
                $result['payment_status'] = 'pending';
            }
            
            if ($result) {
                $guest_info = $result;
                
                // Gather booking details and verification issues
                $booking_details = [
                    'guest_name' => $result['name'],
                    'email' => $result['email'],
                    'phone' => $result['phone'],
                    'room_number' => $result['room_number'] ?? 'Not Assigned',
                    'room_status' => $result['room_status'] ?? 'N/A',
                    'checkin_scheduled' => $result['checkin_date'] ? date('M d, Y H:i', strtotime($result['checkin_date'])) : 'N/A',
                    'checkout_scheduled' => $result['checkout_date'] ? date('M d, Y H:i', strtotime($result['checkout_date'])) : 'N/A',
                    'checkin_actual' => $result['checkin_time'] && $result['checkin_time'] !== '0000-00-00 00:00:00' ? date('M d, Y H:i', strtotime($result['checkin_time'])) : null,
                    'payment_status' => $result['payment_status'] ?? 'No payment found',
                    'amount_paid' => $result['amount_paid'] ?? 0,
                    'amount_due' => $result['amount_due'] ?? 0
                ];
                
                // Verify booking conditions
                if (!$result['room_id']) {
                    $verification_issues[] = ['type' => 'error', 'msg' => 'No room assigned to this booking'];
                }
                
                if ($result['room_status'] !== 'available' && $result['room_status'] !== 'occupied') {
                    $verification_issues[] = ['type' => 'warning', 'msg' => 'Room status is: ' . $result['room_status']];
                }
                
                // Check if today is check-in date
                $checkin_date_obj = $result['checkin_date'] ? new DateTime($result['checkin_date']) : null;
                $today = new DateTime();
                if (!$checkin_date_obj || $checkin_date_obj->format('Y-m-d') !== $today->format('Y-m-d')) {
                    $verification_issues[] = ['type' => 'warning', 'msg' => 'Today is not the scheduled check-in date'];
                }
                
                // Check payment status
                if (!$result['payment_status']) {
                    $verification_issues[] = ['type' => 'error', 'msg' => 'No payment record found'];
                } elseif ($result['payment_status'] === 'pending') {
                    $verification_issues[] = ['type' => 'error', 'msg' => 'Payment is pending - not verified'];
                } elseif ($result['payment_status'] === 'partially_paid') {
                    $verification_issues[] = ['type' => 'info', 'msg' => 'Downpayment received: ₱' . number_format($result['amount_paid'], 2)];
                } elseif ($result['payment_status'] === 'verified' || $result['payment_status'] === 'approved') {
                    // Payment verified - OK
                }
                
                // Check if already checked in
                if ($result['checkin_time'] && $result['checkin_time'] !== '0000-00-00 00:00:00') {
                    $verification_issues[] = ['type' => 'warning', 'msg' => 'Guest already checked in at ' . date('M d, Y H:i', strtotime($result['checkin_time']))];
                }
            } else {
                $message = 'Guest not found. Please verify the selection.';
                $message_type = 'warning';
            }
        } catch (Exception $e) {
            $message = 'Search error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Handle check-in approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_checkin'])) {
    $tenant_id = intval($_POST['tenant_id'] ?? 0);
    if ($tenant_id > 0) {
        try {
            $stmt = $conn->prepare("UPDATE tenants SET checkin_time = NOW() WHERE id = :id");
            $stmt->execute(['id' => $tenant_id]);
            $message = '✓ Guest checked in successfully!';
            $message_type = 'success';
            $guest_info = null;
            $booking_details = null;
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Handle checkout search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_checkout'])) {
    $active_tab = 'checkout';
    $checkout_query = trim($_POST['checkout_query'] ?? '');
    
    if (empty($checkout_query)) {
        $message = 'Please select a guest for checkout.';
        $message_type = 'warning';
    } else {
        try {
            $sql = "SELECT t.id, t.name, t.email, t.phone, t.room_id, t.checkin_time, t.checkout_time,
                           r.room_number, r.status as room_status,
                           rr.id as booking_id, rr.checkin_date, rr.checkout_date,
                           b.id as bill_id, b.amount_paid, b.amount_due, b.status as bill_status
                    FROM tenants t
                    LEFT JOIN rooms r ON t.room_id = r.id
                    LEFT JOIN room_requests rr ON t.id = rr.tenant_id
                    LEFT JOIN bills b ON t.id = b.tenant_id
                    WHERE t.id = :query AND t.checkin_time IS NOT NULL AND t.checkin_time != '0000-00-00 00:00:00'
                    AND (t.checkout_time IS NULL OR t.checkout_time = '0000-00-00 00:00:00')
                    ORDER BY b.id DESC LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute(['query' => intval($checkout_query)]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['bill_id']) {
                $payment_stmt = $conn->prepare("SELECT payment_status FROM payment_transactions WHERE bill_id = :bill_id ORDER BY id DESC LIMIT 1");
                $payment_stmt->execute(['bill_id' => $result['bill_id']]);
                $payment_row = $payment_stmt->fetch(PDO::FETCH_ASSOC);
                $result['payment_status'] = $payment_row ? ($payment_row['payment_status'] ?? 'pending') : 'pending';
            } else {
                $result['payment_status'] = 'pending';
            }
            
            if ($result) {
                $checkout_info = $result;
                
                $checkout_details = [
                    'guest_name' => $result['name'],
                    'email' => $result['email'],
                    'phone' => $result['phone'],
                    'room_number' => $result['room_number'] ?? 'N/A',
                    'room_status' => $result['room_status'] ?? 'N/A',
                    'checkin_date' => $result['checkin_date'] ? date('M d, Y H:i', strtotime($result['checkin_date'])) : 'N/A',
                    'checkout_date' => $result['checkout_date'] ? date('M d, Y H:i', strtotime($result['checkout_date'])) : 'N/A',
                    'checkin_actual' => $result['checkin_time'] && $result['checkin_time'] !== '0000-00-00 00:00:00' ? date('M d, Y H:i', strtotime($result['checkin_time'])) : 'N/A',
                    'amount_due' => $result['amount_due'] ?? 0,
                    'amount_paid' => $result['amount_paid'] ?? 0
                ];
                
                // Calculate nights stayed
                if ($result['checkin_time'] && $result['checkout_date']) {
                    $checkin_dt = new DateTime($result['checkin_time']);
                    $checkout_dt = new DateTime($result['checkout_date']);
                    $checkout_details['nights_stayed'] = $checkout_dt->diff($checkin_dt)->days;
                }
            } else {
                $message = 'Guest not found or not currently checked in.';
                $message_type = 'warning';
            }
        } catch (Exception $e) {
            $message = 'Search error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Handle checkout approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_checkout'])) {
    $tenant_id = intval($_POST['tenant_id'] ?? 0);
    if ($tenant_id > 0) {
        try {
            $stmt = $conn->prepare("UPDATE tenants SET checkout_time = NOW() WHERE id = :id");
            $stmt->execute(['id' => $tenant_id]);
            $message = '✓ Guest checked out successfully!';
            $message_type = 'success';
            $checkout_info = null;
            $checkout_details = null;
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Metrics
try {
    $ready = $conn->query("SELECT COUNT(DISTINCT t.id) FROM tenants t 
                           JOIN payment_transactions pt ON t.id = pt.tenant_id 
                           WHERE pt.payment_status IN ('verified','approved', 'partially_paid') 
                           AND (t.checkin_time IS NULL OR t.checkin_time = '0000-00-00 00:00:00')")->fetchColumn();
    
    $checkedin = $conn->query("SELECT COUNT(DISTINCT t.id) FROM tenants t 
                              WHERE t.checkin_time IS NOT NULL 
                              AND t.checkin_time != '0000-00-00 00:00:00'
                              AND (t.checkout_time IS NULL OR t.checkout_time = '0000-00-00 00:00:00')")->fetchColumn();
    
    $checkedout = $conn->query("SELECT COUNT(DISTINCT t.id) FROM tenants t 
                               WHERE t.checkout_time IS NOT NULL 
                               AND t.checkout_time != '0000-00-00 00:00:00'")->fetchColumn();
} catch (Exception $e) {
    $ready = 0;
    $checkedin = 0;
    $checkedout = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-in & Check-out - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .metric-card { border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .metric-value { font-size: 1.6rem; font-weight: 700; }
    </style>
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="bi bi-door-open"></i> Front Desk Check-in & Check-out</h1>
            </div>

            <!-- TAB NAVIGATION -->
            <ul class="nav nav-tabs mb-4" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab === 'checkin' ? 'active' : ''; ?>" id="checkin-tab" 
                            onclick="switchTab('checkin')" type="button" role="tab">
                        <i class="bi bi-person-check"></i> Check-in Process
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab === 'checkout' ? 'active' : ''; ?>" id="checkout-tab" 
                            onclick="switchTab('checkout')" type="button" role="tab">
                        <i class="bi bi-person-lock"></i> Check-out Process
                    </button>
                </li>
            </ul>

            <input type="hidden" id="activeTab" name="active_tab" value="<?php echo $active_tab; ?>">

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- CHECK-IN TAB -->
            <div id="checkin-content" style="display: <?php echo $active_tab === 'checkin' ? 'block' : 'none'; ?>;">

            <!-- STEP 1: GUEST SEARCH -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white">
                    <span class="fw-semibold"><i class="bi bi-1-circle-fill"></i> Guest Arrival - Search Booking</span>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3" id="searchForm">
                        <div class="col-md-5">
                            <label class="form-label">Search by</label>
                            <select name="search_type" class="form-select" id="searchType" onchange="updateSearchOptions(); resetSearchInput();">
                                <option value="name">Guest Name</option>
                                <option value="room">Room Number</option>
                                <option value="booking_ref">Booking Reference</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Search & Select Guest/Room</label>
                            <div class="position-relative">
                                <input type="text" class="form-control" id="searchInput" placeholder="Start typing..." 
                                       onkeyup="filterSearchOptions()" onclear="resetSearchResults()" required>
                                <input type="hidden" name="search_query" id="selectedValue" value="">
                                <div id="searchResults" class="position-absolute w-100 bg-white border border-top-0 rounded-bottom" 
                                     style="max-height: 250px; overflow-y: auto; display: none; z-index: 10; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2" id="selectedDisplay"></small>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" name="search_guest" class="btn btn-primary w-100" 
                                    onclick="return validateSelection()">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            // Store all options by type
            const allOptions = {
                name: [
                    <?php foreach ($available_guests as $g): ?>
                        {id: '<?php echo $g['id']; ?>', display: '<?php echo htmlspecialchars(addslashes($g['name'])); ?>'},
                    <?php endforeach; ?>
                ],
                room: [
                    <?php foreach ($available_rooms as $r): ?>
                        {id: '<?php echo $r['id']; ?>', display: 'Room <?php echo htmlspecialchars(addslashes($r['room_number'])); ?>'},
                    <?php endforeach; ?>
                ],
                booking_ref: [
                    <?php foreach ($available_bookings as $b): ?>
                        {id: '<?php echo $b['id']; ?>', display: '<?php echo htmlspecialchars(addslashes($b['booking_desc'])); ?>'},
                    <?php endforeach; ?>
                ]
            };

            function resetSearchInput() {
                document.getElementById('searchInput').value = '';
                document.getElementById('selectedValue').value = '';
                document.getElementById('selectedDisplay').innerText = '';
                document.getElementById('searchResults').style.display = 'none';
            }

            function resetSearchResults() {
                document.getElementById('searchResults').style.display = 'none';
            }

            function filterSearchOptions() {
                const searchType = document.getElementById('searchType').value;
                const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
                const resultsDiv = document.getElementById('searchResults');
                const options = allOptions[searchType] || [];

                resultsDiv.innerHTML = '';

                if (searchTerm.length === 0) {
                    resultsDiv.style.display = 'none';
                    document.getElementById('selectedValue').value = '';
                    document.getElementById('selectedDisplay').innerText = '';
                    return;
                }

                const filtered = options.filter(opt => 
                    opt.display.toLowerCase().includes(searchTerm)
                );

                if (filtered.length === 0) {
                    resultsDiv.innerHTML = '<div class="p-2 text-muted">No results found</div>';
                    resultsDiv.style.display = 'block';
                    document.getElementById('selectedValue').value = '';
                    document.getElementById('selectedDisplay').innerText = '';
                    return;
                }

                filtered.forEach(opt => {
                    const div = document.createElement('div');
                    div.className = 'p-2 cursor-pointer border-bottom';
                    div.style.cursor = 'pointer';
                    div.style.transition = 'background-color 0.2s';
                    div.innerText = opt.display;
                    div.onmouseover = function() { this.style.backgroundColor = '#f0f0f0'; };
                    div.onmouseout = function() { this.style.backgroundColor = 'transparent'; };
                    div.onclick = function() { selectOption(opt.id, opt.display); };
                    resultsDiv.appendChild(div);
                });

                resultsDiv.style.display = 'block';
            }

            function selectOption(id, display) {
                document.getElementById('searchInput').value = display;
                document.getElementById('selectedValue').value = id;
                document.getElementById('selectedDisplay').innerText = '✓ Selected: ' + display;
                document.getElementById('searchResults').style.display = 'none';
            }

            function validateSelection() {
                const selectedValue = document.getElementById('selectedValue').value;
                if (!selectedValue) {
                    alert('Please select a guest or booking from the list');
                    return false;
                }
                return true;
            }

            // Show results on input focus
            document.getElementById('searchInput').addEventListener('focus', function() {
                if (this.value.length > 0) {
                    document.getElementById('searchResults').style.display = 'block';
                }
            });

            // Hide results on click outside
            document.addEventListener('click', function(event) {
                const searchInput = document.getElementById('searchInput');
                const searchResults = document.getElementById('searchResults');
                if (event.target !== searchInput && !searchResults.contains(event.target)) {
                    searchResults.style.display = 'none';
                }
            });
            </script>

            <?php if ($guest_info && $booking_details): ?>
            <!-- STEP 2: BOOKING VERIFICATION -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-info text-white">
                    <span class="fw-semibold"><i class="bi bi-2-circle-fill"></i> Booking Verification</span>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <!-- Guest Information -->
                        <div class="col-md-6">
                            <h6 class="fw-semibold mb-3 text-secondary">Guest Information</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="fw-semibold">Guest Name:</td>
                                    <td><strong><?php echo htmlspecialchars($booking_details['guest_name']); ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Email:</td>
                                    <td><?php echo htmlspecialchars($booking_details['email']); ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Phone:</td>
                                    <td><?php echo htmlspecialchars($booking_details['phone']); ?></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Check-in/Check-out Dates -->
                        <div class="col-md-6">
                            <h6 class="fw-semibold mb-3 text-secondary">Reservation Dates</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="fw-semibold">Check-in:</td>
                                    <td><?php echo $booking_details['checkin_scheduled']; ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Check-out:</td>
                                    <td><?php echo $booking_details['checkout_scheduled']; ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Days:</td>
                                    <td>
                                        <?php 
                                            if ($guest_info['checkin_date'] && $guest_info['checkout_date']) {
                                                $checkin = new DateTime($guest_info['checkin_date']);
                                                $checkout = new DateTime($guest_info['checkout_date']);
                                                $days = $checkout->diff($checkin)->days;
                                                echo $days . ' night' . ($days != 1 ? 's' : '');
                                            }
                                        ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Verification Checklist -->
                    <hr>
                    <h6 class="fw-semibold mb-3">Verification Checklist</h6>
                    
                    <div class="verification-checks">
                        <!-- Room Assignment Check -->
                        <div class="alert <?php echo $guest_info['room_id'] ? 'alert-success' : 'alert-danger'; ?> mb-2">
                            <div class="d-flex gap-2">
                                <i class="bi <?php echo $guest_info['room_id'] ? 'bi-check-circle-fill' : 'bi-x-circle-fill'; ?>" style="font-size: 1.2rem;"></i>
                                <div>
                                    <strong>Room Assigned:</strong> <?php echo $guest_info['room_id'] ? htmlspecialchars($booking_details['room_number']) . ' (' . htmlspecialchars($booking_details['room_status']) . ')' : 'NO'; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Check-in Date Check -->
                        <div class="alert <?php 
                            $checkin_today = false;
                            if ($guest_info['checkin_date']) {
                                $checkin_obj = new DateTime($guest_info['checkin_date']);
                                $today_obj = new DateTime();
                                $checkin_today = ($checkin_obj->format('Y-m-d') === $today_obj->format('Y-m-d'));
                            }
                            echo $checkin_today ? 'alert-success' : 'alert-warning'; 
                        ?> mb-2">
                            <div class="d-flex gap-2">
                                <i class="bi <?php echo $checkin_today ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'; ?>" style="font-size: 1.2rem;"></i>
                                <div>
                                    <strong>Check-in Date:</strong> <?php echo $checkin_today ? 'Today ✓' : 'Not today'; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Status Check -->
                        <div class="alert <?php 
                            $payment_ok = in_array($guest_info['payment_status'], ['verified', 'approved', 'partially_paid']);
                            echo $payment_ok ? 'alert-success' : 'alert-danger'; 
                        ?> mb-2">
                            <div class="d-flex gap-2">
                                <i class="bi <?php echo $payment_ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill'; ?>" style="font-size: 1.2rem;"></i>
                                <div>
                                    <strong>Payment Status:</strong> 
                                    <?php 
                                        if ($guest_info['payment_status'] === 'verified' || $guest_info['payment_status'] === 'approved') {
                                            echo 'Fully Paid ✓ (₱' . number_format($guest_info['amount_paid'], 2) . ')';
                                        } elseif ($guest_info['payment_status'] === 'partially_paid') {
                                            echo 'Downpayment: ₱' . number_format($guest_info['amount_paid'], 2) . ' / ₱' . number_format($guest_info['amount_due'], 2);
                                        } else {
                                            echo htmlspecialchars($guest_info['payment_status']);
                                        }
                                    ?>
                                </div>
                            </div>
                        </div>

                        <!-- Already Checked In Check -->
                        <?php if ($guest_info['checkin_time'] && $guest_info['checkin_time'] !== '0000-00-00 00:00:00'): ?>
                        <div class="alert alert-info mb-2">
                            <div class="d-flex gap-2">
                                <i class="bi bi-info-circle-fill" style="font-size: 1.2rem;"></i>
                                <div>
                                    <strong>Guest Status:</strong> Already checked in on <?php echo date('M d, Y \a\t H:i', strtotime($guest_info['checkin_time'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Additional Issues -->
                        <?php foreach ($verification_issues as $issue): ?>
                        <div class="alert alert-<?php echo $issue['type']; ?> mb-2">
                            <div class="d-flex gap-2">
                                <i class="bi bi-<?php echo $issue['type'] === 'error' ? 'x-circle-fill' : ($issue['type'] === 'warning' ? 'exclamation-circle-fill' : 'info-circle-fill'); ?>" style="font-size: 1.2rem;"></i>
                                <div><?php echo htmlspecialchars($issue['msg']); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- STEP 3: CHECK-IN APPROVAL -->
            <?php if (!($guest_info['checkin_time'] && $guest_info['checkin_time'] !== '0000-00-00 00:00:00')): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-success text-white">
                    <span class="fw-semibold"><i class="bi bi-3-circle-fill"></i> Check-in Approval</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="tenant_id" value="<?php echo $guest_info['id']; ?>">
                        <input type="hidden" name="active_tab" value="checkin">
                        <div class="alert alert-info mb-3">
                            <strong>Ready to check in guest?</strong> 
                            Click the button below to record the check-in time and complete the process.
                        </div>
                        <button type="submit" name="approve_checkin" class="btn btn-success btn-lg">
                            <i class="bi bi-check2-square"></i> Approve & Check In Guest
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>
            </div><!-- END CHECK-IN TAB -->

            <!-- CHECK-OUT TAB -->
            <div id="checkout-content" style="display: <?php echo $active_tab === 'checkout' ? 'block' : 'none'; ?>;">

            <!-- STEP 1: CHECKOUT GUEST SEARCH -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white">
                    <span class="fw-semibold"><i class="bi bi-1-circle-fill"></i> Guest Departure - Select Guest</span>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3" id="checkoutForm">
                        <input type="hidden" name="active_tab" value="checkout">
                        <div class="col-md-10">
                            <label class="form-label">Search Checked-in Guests</label>
                            <div class="position-relative">
                                <input type="text" class="form-control" id="checkoutSearchInput" placeholder="Start typing guest name..." 
                                       onkeyup="filterCheckoutOptions()" onclear="resetCheckoutResults()" required>
                                <input type="hidden" name="checkout_query" id="checkoutSelectedValue" value="">
                                <div id="checkoutSearchResults" class="position-absolute w-100 bg-white border border-top-0 rounded-bottom" 
                                     style="max-height: 250px; overflow-y: auto; display: none; z-index: 10; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2" id="checkoutSelectedDisplay"></small>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" name="search_checkout" class="btn btn-primary w-100" 
                                    onclick="return validateCheckoutSelection()">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($checkout_info && $checkout_details): ?>
            <!-- STEP 2: CHECKOUT VERIFICATION -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-info text-white">
                    <span class="fw-semibold"><i class="bi bi-2-circle-fill"></i> Checkout Verification & Bill Review</span>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <!-- Guest Information -->
                        <div class="col-md-6">
                            <h6 class="fw-semibold mb-3 text-secondary">Guest Information</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="fw-semibold">Guest Name:</td>
                                    <td><strong><?php echo htmlspecialchars($checkout_details['guest_name']); ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Room #:</td>
                                    <td><strong><?php echo htmlspecialchars($checkout_details['room_number']); ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Email:</td>
                                    <td><?php echo htmlspecialchars($checkout_details['email']); ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Phone:</td>
                                    <td><?php echo htmlspecialchars($checkout_details['phone']); ?></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Stay Summary -->
                        <div class="col-md-6">
                            <h6 class="fw-semibold mb-3 text-secondary">Stay Summary</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="fw-semibold">Check-in:</td>
                                    <td><?php echo $checkout_details['checkin_actual']; ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Checkout Time:</td>
                                    <td><?php echo $checkout_details['checkout_date']; ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-semibold">Nights Stayed:</td>
                                    <td><strong><?php echo isset($checkout_details['nights_stayed']) ? $checkout_details['nights_stayed'] : '-'; ?></strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Bill Summary -->
                    <hr>
                    <h6 class="fw-semibold mb-3">Bill Summary</h6>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card border-primary">
                                <div class="card-body text-center">
                                    <div class="text-muted small">Total Due</div>
                                    <div class="metric-value text-dark">₱<?php echo number_format($checkout_details['amount_due'], 2); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-success">
                                <div class="card-body text-center">
                                    <div class="text-muted small">Amount Paid</div>
                                    <div class="metric-value text-success">₱<?php echo number_format($checkout_details['amount_paid'], 2); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card <?php $balance = $checkout_details['amount_due'] - $checkout_details['amount_paid']; echo ($balance <= 0) ? 'border-success' : 'border-warning'; ?>">
                                <div class="card-body text-center">
                                    <div class="text-muted small"><?php echo ($balance > 0) ? 'Balance Due' : 'Credit'; ?></div>
                                    <div class="metric-value <?php echo ($balance > 0) ? 'text-warning' : 'text-success'; ?>">
                                        ₱<?php echo number_format(abs($balance), 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($balance > 0): ?>
                    <div class="alert alert-warning mt-3">
                        <i class="bi bi-exclamation-triangle-fill"></i> 
                        <strong>Balance Due:</strong> ₱<?php echo number_format($balance, 2); ?> - Guest needs to settle this before checkout completion.
                    </div>
                    <?php elseif ($balance < 0): ?>
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle-fill"></i> 
                        <strong>Credit/Overpayment:</strong> ₱<?php echo number_format(abs($balance), 2); ?> can be refunded or credited.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success mt-3">
                        <i class="bi bi-check-circle-fill"></i> 
                        <strong>Bill Settled:</strong> All charges have been paid in full.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STEP 3: CHECKOUT APPROVAL -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-success text-white">
                    <span class="fw-semibold"><i class="bi bi-3-circle-fill"></i> Checkout Approval</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="tenant_id" value="<?php echo $checkout_info['id']; ?>">
                        <input type="hidden" name="active_tab" value="checkout">
                        <div class="alert alert-info mb-3">
                            <strong>Ready to checkout guest?</strong> 
                            Click the button below to record the checkout time and complete the process.
                        </div>
                        <button type="submit" name="approve_checkout" class="btn btn-success btn-lg">
                            <i class="bi bi-check2-square"></i> Approve & Process Checkout
                        </button>
                    </form>
                </div>
            </div>

            <?php endif; ?>
            </div><!-- END CHECK-OUT TAB -->

            <!-- METRICS -->
            <div class="row mb-4 mt-5">
                <div class="col-md-3 mb-3">
                    <div class="card metric-card bg-warning text-dark h-100">
                        <div class="card-body text-center">
                            <div class="metric-icon mb-2"><i class="bi bi-hourglass-split" style="font-size: 2rem;"></i></div>
                            <div class="metric-label small">Ready for Check-in</div>
                            <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;"><?php echo $ready; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card metric-card bg-info text-white h-100">
                        <div class="card-body text-center">
                            <div class="metric-icon mb-2"><i class="bi bi-check-circle" style="font-size: 2rem;"></i></div>
                            <div class="metric-label small">Checked In</div>
                            <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;"><?php echo $checkedin; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card metric-card bg-success text-white h-100">
                        <div class="card-body text-center">
                            <div class="metric-icon mb-2"><i class="bi bi-check-all" style="font-size: 2rem;"></i></div>
                            <div class="metric-label small">Checked Out</div>
                            <div class="metric-value" style="font-size: 1.5rem; font-weight: 700;"><?php echo $checkedout; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Tab switching
function switchTab(tab) {
    document.getElementById('activeTab').value = tab;
    document.getElementById('checkin-content').style.display = (tab === 'checkin') ? 'block' : 'none';
    document.getElementById('checkout-content').style.display = (tab === 'checkout') ? 'block' : 'none';
    document.getElementById('checkin-tab').classList.toggle('active', tab === 'checkin');
    document.getElementById('checkout-tab').classList.toggle('active', tab === 'checkout');
}

// Checkout search functions
const checkedInGuests = [
    <?php foreach ($checked_in_guests as $g): ?>
        {id: '<?php echo $g['id']; ?>', name: '<?php echo htmlspecialchars(addslashes($g['name'])); ?>'},
    <?php endforeach; ?>
];

function filterCheckoutOptions() {
    const searchTerm = document.getElementById('checkoutSearchInput').value.toLowerCase().trim();
    const resultsDiv = document.getElementById('checkoutSearchResults');
    
    resultsDiv.innerHTML = '';
    
    if (searchTerm.length === 0) {
        resultsDiv.style.display = 'none';
        document.getElementById('checkoutSelectedValue').value = '';
        document.getElementById('checkoutSelectedDisplay').innerText = '';
        return;
    }
    
    const filtered = checkedInGuests.filter(g => g.name.toLowerCase().includes(searchTerm));
    
    if (filtered.length === 0) {
        resultsDiv.innerHTML = '<div class="p-2 text-muted">No checked-in guests found</div>';
        resultsDiv.style.display = 'block';
        document.getElementById('checkoutSelectedValue').value = '';
        document.getElementById('checkoutSelectedDisplay').innerText = '';
        return;
    }
    
    filtered.forEach(guest => {
        const div = document.createElement('div');
        div.className = 'p-2 cursor-pointer border-bottom';
        div.style.cursor = 'pointer';
        div.style.transition = 'background-color 0.2s';
        div.innerText = guest.name;
        div.onmouseover = function() { this.style.backgroundColor = '#f0f0f0'; };
        div.onmouseout = function() { this.style.backgroundColor = 'transparent'; };
        div.onclick = function() { selectCheckoutGuest(guest.id, guest.name); };
        resultsDiv.appendChild(div);
    });
    
    resultsDiv.style.display = 'block';
}

function selectCheckoutGuest(id, name) {
    document.getElementById('checkoutSearchInput').value = name;
    document.getElementById('checkoutSelectedValue').value = id;
    document.getElementById('checkoutSelectedDisplay').innerText = '✓ Selected: ' + name;
    document.getElementById('checkoutSearchResults').style.display = 'none';
}

function validateCheckoutSelection() {
    const selectedValue = document.getElementById('checkoutSelectedValue').value;
    if (!selectedValue) {
        alert('Please select a guest for checkout');
        return false;
    }
    return true;
}

function resetCheckoutResults() {
    document.getElementById('checkoutSearchResults').style.display = 'none';
}

// Hide results on click outside
document.addEventListener('click', function(event) {
    const checkoutInput = document.getElementById('checkoutSearchInput');
    const checkoutResults = document.getElementById('checkoutSearchResults');
    if (checkoutInput && checkoutResults && event.target !== checkoutInput && !checkoutResults.contains(event.target)) {
        checkoutResults.style.display = 'none';
    }
});

// Original check-in search functions (kept for compatibility)
function resetSearchInput() {
    document.getElementById('searchInput').value = '';
    document.getElementById('selectedValue').value = '';
    document.getElementById('selectedDisplay').innerText = '';
    document.getElementById('searchResults').style.display = 'none';
}

function resetSearchResults() {
    document.getElementById('searchResults').style.display = 'none';
}

function filterSearchOptions() {
    const searchType = document.getElementById('searchType').value;
    const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    const resultsDiv = document.getElementById('searchResults');
    
    resultsDiv.innerHTML = '';
    
    if (searchTerm.length === 0) {
        resultsDiv.style.display = 'none';
        document.getElementById('selectedValue').value = '';
        document.getElementById('selectedDisplay').innerText = '';
        return;
    }
    
    const allOptions = {
        name: [
            <?php foreach ($available_guests as $g): ?>
                {id: '<?php echo $g['id']; ?>', display: '<?php echo htmlspecialchars(addslashes($g['name'])); ?>'},
            <?php endforeach; ?>
        ],
        room: [
            <?php foreach ($available_rooms as $r): ?>
                {id: '<?php echo $r['id']; ?>', display: 'Room <?php echo htmlspecialchars(addslashes($r['room_number'])); ?>'},
            <?php endforeach; ?>
        ],
        booking_ref: [
            <?php foreach ($available_bookings as $b): ?>
                {id: '<?php echo $b['id']; ?>', display: '<?php echo htmlspecialchars(addslashes($b['booking_desc'])); ?>'},
            <?php endforeach; ?>
        ]
    };
    
    const options = allOptions[searchType] || [];
    const filtered = options.filter(opt => opt.display.toLowerCase().includes(searchTerm));
    
    if (filtered.length === 0) {
        resultsDiv.innerHTML = '<div class="p-2 text-muted">No results found</div>';
        resultsDiv.style.display = 'block';
        document.getElementById('selectedValue').value = '';
        document.getElementById('selectedDisplay').innerText = '';
        return;
    }
    
    filtered.forEach(opt => {
        const div = document.createElement('div');
        div.className = 'p-2 cursor-pointer border-bottom';
        div.style.cursor = 'pointer';
        div.style.transition = 'background-color 0.2s';
        div.innerText = opt.display;
        div.onmouseover = function() { this.style.backgroundColor = '#f0f0f0'; };
        div.onmouseout = function() { this.style.backgroundColor = 'transparent'; };
        div.onclick = function() { selectOption(opt.id, opt.display); };
        resultsDiv.appendChild(div);
    });
    
    resultsDiv.style.display = 'block';
}

function selectOption(id, display) {
    document.getElementById('searchInput').value = display;
    document.getElementById('selectedValue').value = id;
    document.getElementById('selectedDisplay').innerText = '✓ Selected: ' + display;
    document.getElementById('searchResults').style.display = 'none';
}

function validateSelection() {
    const selectedValue = document.getElementById('selectedValue').value;
    if (!selectedValue) {
        alert('Please select a guest or booking from the list');
        return false;
    }
    return true;
}

// Show results on input focus
if (document.getElementById('searchInput')) {
    document.getElementById('searchInput').addEventListener('focus', function() {
        if (this.value.length > 0) {
            document.getElementById('searchResults').style.display = 'block';
        }
    });
}

if (document.getElementById('checkoutSearchInput')) {
    document.getElementById('checkoutSearchInput').addEventListener('focus', function() {
        filterCheckoutOptions();
    });
}

// Hide check-in results on click outside
document.addEventListener('click', function(event) {
    if (!document.getElementById('searchInput')) return;
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    if (event.target !== searchInput && !searchResults.contains(event.target)) {
        searchResults.style.display = 'none';
    }
});
</script>
</body>
</html>
