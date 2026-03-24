<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'front_desk'])) {
    header("location: index.php");
    exit;
}

require_once "db_pdo.php";
require_once "db/notifications.php";

// Alias $pdo as $conn for compatibility
$conn = $pdo;

$message = '';
$message_type = '';

// Fetch all pending bookings (that have bills created but NO verified/approved payments yet)
// Group by tenant and payment timing to merge same-customer multi-room bookings
try {
    $query = "
        SELECT
            GROUP_CONCAT(DISTINCT rr.id ORDER BY rr.id SEPARATOR ',') as booking_ids,
            rr.tenant_id,
            GROUP_CONCAT(DISTINCT rr.room_id ORDER BY rr.room_id SEPARATOR ',') as room_ids,
            MIN(rr.checkin_date) as checkin_date,
            MAX(rr.checkout_date) as checkout_date,
            rr.checkin_time,
            rr.checkout_time,
            GROUP_CONCAT(DISTINCT rr.status ORDER BY rr.status SEPARATOR ',') as booking_statuses,
            MIN(rr.created_at) as booking_date,
            SUM(rr.tenant_count) as total_guests,
            rr.tenant_info_name,
            rr.tenant_info_email,
            rr.tenant_info_phone,
            rr.tenant_info_address,
            GROUP_CONCAT(DISTINCT rr.notes ORDER BY rr.id SEPARATOR '; ') as all_notes,
            t.name as tenant_name,
            t.email as tenant_email,
            t.phone as tenant_phone,
            GROUP_CONCAT(DISTINCT CONCAT(r.room_number, ':', r.room_type, ':', r.rate, ':', rr.checkin_date, ':', rr.checkout_date) ORDER BY r.room_number SEPARATOR '|') as room_details,
            GROUP_CONCAT(DISTINCT b.id ORDER BY b.id SEPARATOR ',') as bill_ids,
            GROUP_CONCAT(DISTINCT b.amount_due ORDER BY b.id SEPARATOR ',') as bill_amounts,
            GROUP_CONCAT(DISTINCT b.status ORDER BY b.status SEPARATOR ',') as bill_statuses,
            COALESCE(SUM(CASE WHEN pt.payment_status IN ('verified', 'approved') THEN pt.payment_amount ELSE 0 END), 0) as total_verified_amount,
            COALESCE(SUM(CASE WHEN pt.payment_status = 'pending' THEN pt.payment_amount ELSE 0 END), 0) as total_pending_amount,
            MAX(CASE WHEN pt.payment_status IN ('verified', 'approved') THEN pt.payment_date ELSE NULL END) as verified_payment_date,
            MAX(CASE WHEN pt.payment_status = 'pending' THEN pt.payment_date ELSE NULL END) as pending_payment_date,
            COUNT(DISTINCT rr.id) as room_count,
            MAX(pt.payment_status) as latest_payment_status
        FROM room_requests rr
        LEFT JOIN tenants t ON rr.tenant_id = t.id
        LEFT JOIN rooms r ON rr.room_id = r.id
        LEFT JOIN bills b ON b.tenant_id = rr.tenant_id AND b.room_id = rr.room_id
        LEFT JOIN payment_transactions pt ON b.id = pt.bill_id
        WHERE rr.status IN ('pending_payment', 'approved')
        AND b.id IS NOT NULL
        GROUP BY rr.tenant_id
        HAVING COUNT(DISTINCT rr.id) > 0
        ORDER BY MAX(pt.payment_date) DESC, MIN(rr.created_at) DESC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error fetching bookings: " . $e->getMessage();
    $message_type = "danger";
    $bookings = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookings Verification - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .booking-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        .booking-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .customer-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .customer-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
        }
        .room-badge {
            display: inline-block;
            background: #e8f0ff;
            color: #667eea;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .payment-badge {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #999;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #ddd;
        }
    </style>
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="bi bi-calendar-check"></i> Bookings Verification</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload();">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Bookings List -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-calendar-check"></i> Pending Bookings</span>
                    <small class="text-muted"><?php echo count($bookings); ?> booking<?php echo count($bookings) !== 1 ? 's' : ''; ?> requiring verification</small>
                </div>
                <div class="card-body">
                    <?php if (empty($bookings)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>No pending bookings found.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($bookings as $booking): ?>
                            <?php
                            $avatar_initials = substr(htmlspecialchars($booking['tenant_info_name'] ?: $booking['tenant_name']), 0, 1);
                            $nights = 0;
                            if ($booking['checkin_date'] && $booking['checkout_date']) {
                                try {
                                    // Clean the date strings to ensure they're in YYYY-MM-DD format
                                    $checkin_clean = trim(explode(' ', $booking['checkin_date'])[0]);
                                    $checkout_clean = trim(explode(' ', $booking['checkout_date'])[0]);

                                    if (!empty($checkin_clean) && !empty($checkout_clean)) {
                                        $ci = new DateTime($checkin_clean);
                                        $co = new DateTime($checkout_clean);
                                        $nights = $ci->diff($co)->days;
                                    }
                                } catch (Exception $e) {
                                    $nights = 1; // fallback
                                }
                            }

                            // Parse room details and calculate accurate total cost
                            $room_details = [];
                            $total_cost = 0;
                            if (!empty($booking['room_details'])) {
                                $rooms = explode('|', $booking['room_details']);
                                foreach ($rooms as $room) {
                                    $parts = explode(':', $room);
                                    if (count($parts) >= 5) {
                                        $room_number = $parts[0];
                                        $room_type = $parts[1];
                                        $rate = floatval($parts[2]);
                                        $checkin_date = $parts[3];
                                        $checkout_date = $parts[4];

                                        // Calculate nights for this specific room booking
                                        $room_nights = 1; // default
                                        if ($checkin_date && $checkout_date && $checkin_date !== '0000-00-00' && $checkout_date !== '0000-00-00') {
                                            try {
                                                // Clean the date strings to ensure they're in YYYY-MM-DD format
                                                $checkin_clean = trim(explode(' ', $checkin_date)[0]);
                                                $checkout_clean = trim(explode(' ', $checkout_date)[0]);

                                                if (!empty($checkin_clean) && !empty($checkout_clean)) {
                                                    $ci = new DateTime($checkin_clean);
                                                    $co = new DateTime($checkout_clean);
                                                    $room_nights = max(1, $ci->diff($co)->days);
                                                }
                                            } catch (Exception $e) {
                                                // If date parsing fails, keep default of 1 night
                                                $room_nights = 1;
                                            }
                                        }

                                        $room_details[] = [
                                            'number' => $room_number,
                                            'type' => $room_type,
                                            'rate' => $rate,
                                            'nights' => $room_nights
                                        ];

                                        // Add this room's cost to total
                                        $total_cost += $rate * $room_nights;
                                    }
                                }
                            }

                            $booking_ids = explode(',', $booking['booking_ids']);
                            $room_count = intval($booking['room_count']);
                            ?>
                            <div class="booking-card">
                                <!-- Customer Section -->
                                <div class="customer-info">
                                    <div class="customer-avatar">
                                        <?php echo strtoupper($avatar_initials); ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-0"><?php echo htmlspecialchars($booking['tenant_info_name'] ?: $booking['tenant_name']); ?>
                                            <?php if ($room_count > 1): ?>
                                                <span class="badge bg-primary ms-2"><?php echo $room_count; ?> Rooms</span>
                                            <?php endif; ?>
                                        </h5>
                                        <small class="text-muted"><?php echo htmlspecialchars($booking['tenant_info_email'] ?: $booking['tenant_email']); ?> | <?php echo htmlspecialchars($booking['tenant_info_phone'] ?: $booking['tenant_phone']); ?></small>
                                        <div class="mt-1">
                                            <small class="text-muted">Booking #<?php echo implode(', #', array_map('intval', $booking_ids)); ?> • <?php echo date('M d, Y H:i', strtotime($booking['booking_date'])); ?></small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Payment Status Badge -->
                                <div class="payment-badge <?php echo ($booking['total_verified_amount'] > 0) ? '' : ($booking['total_pending_amount'] > 0 ? 'bg-warning border-warning' : 'bg-light border-secondary'); ?>">
                                    <?php if ($booking['total_verified_amount'] > 0): ?>
                                        <i class="bi bi-check-circle-fill text-success"></i> <strong>Payment Verified & Approved</strong>
                                        <br>
                                        <small>₱<?php echo number_format($booking['total_verified_amount'], 2); ?> verified on <?php echo htmlspecialchars($booking['verified_payment_date'] ? date('M d, Y', strtotime($booking['verified_payment_date'])) : 'N/A'); ?></small>
                                        <?php if ($booking['total_pending_amount'] > 0): ?>
                                            <br>
                                            <small class="text-muted">+ ₱<?php echo number_format($booking['total_pending_amount'], 2); ?> pending verification</small>
                                        <?php endif; ?>
                                    <?php elseif ($booking['total_pending_amount'] > 0): ?>
                                        <i class="bi bi-clock text-warning"></i> <strong>Payment Pending Verification</strong>
                                        <br>
                                        <small>₱<?php echo number_format($booking['total_pending_amount'], 2); ?> awaiting verification (submitted <?php echo htmlspecialchars($booking['pending_payment_date'] ? date('M d, Y', strtotime($booking['pending_payment_date'])) : 'N/A'); ?>)</small>
                                    <?php else: ?>
                                        <i class="bi bi-exclamation-circle text-danger"></i> <strong>No Payment Recorded</strong>
                                        <br>
                                        <small>Awaiting payment from customer</small>
                                    <?php endif; ?>
                                </div>

                                <!-- Booking Details -->
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <strong>Room Information</strong>
                                            <div>
                                                <?php foreach ($room_details as $room): ?>
                                                    <span class="room-badge">Room <?php echo htmlspecialchars($room['number']); ?> (<?php echo htmlspecialchars($room['type']); ?>)</span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <strong>Total Rooms:</strong> <?php echo $room_count; ?> room<?php echo $room_count !== 1 ? 's' : ''; ?>
                                        </div>
                                        <div class="mb-2">
                                            <strong>Room Rates:</strong>
                                            <?php foreach ($room_details as $index => $room): ?>
                                                <div>Room <?php echo htmlspecialchars($room['number']); ?>: ₱<?php echo number_format($room['rate'], 2); ?>/night (<?php echo $room['nights']; ?> night<?php echo $room['nights'] !== 1 ? 's' : ''; ?>)<?php echo $index < count($room_details) - 1 ? ',' : ''; ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="mb-2">
                                            <strong>Total Guests:</strong> <?php echo intval($booking['total_guests'] ?? 1); ?> person(s)
                                        </div>
                                        <div class="mb-2">
                                            <strong>Total Cost:</strong> ₱<?php echo number_format($total_cost, 2); ?>
                                        </div>
                                        <?php if (!empty($booking['tenant_info_address'])): ?>
                                            <div class="mb-2">
                                                <strong>Address:</strong> <?php echo htmlspecialchars($booking['tenant_info_address']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <strong>Check-in:</strong> <?php echo !empty($booking['checkin_date']) && $booking['checkin_date'] !== '0000-00-00' ? date('M d, Y', strtotime($booking['checkin_date'])) : 'Not set'; ?>
                                            <?php if (!empty($booking['checkin_time']) && $booking['checkin_time'] !== '00:00:00'): ?>
                                                <small class="d-block text-muted"><?php echo date('g:i A', strtotime($booking['checkin_time'])); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mb-2">
                                            <strong>Check-out:</strong> <?php echo !empty($booking['checkout_date']) && $booking['checkout_date'] !== '0000-00-00' ? date('M d, Y', strtotime($booking['checkout_date'])) : 'Not set'; ?>
                                            <?php if (!empty($booking['checkout_time']) && $booking['checkout_time'] !== '00:00:00'): ?>
                                                <small class="d-block text-muted"><?php echo date('g:i A', strtotime($booking['checkout_time'])); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mb-2">
                                            <strong>Booking Period:</strong> <?php echo intval($nights); ?> night<?php echo $nights !== 1 ? 's' : ''; ?> (<?php echo date('M d, Y', strtotime($booking['checkin_date'])); ?> - <?php echo date('M d, Y', strtotime($booking['checkout_date'])); ?>)
                                            <?php if ($room_count > 1): ?>
                                                <br><small class="text-muted">Individual room durations may vary</small>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($booking['all_notes'])): ?>
                                            <div class="mb-2">
                                                <strong>Notes:</strong> <?php echo htmlspecialchars($booking['all_notes']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="mt-3 d-flex gap-2">
                                    <a href="bills.php?view=active" class="btn btn-outline-secondary">
                                        <i class="bi bi-eye"></i> View Bill & Approve Payment
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function approveAllBookings(bookingIds, billIds) {
    if (!confirm('Are you sure you want to approve all ' + bookingIds.split(',').length + ' bookings for this customer?')) {
        return;
    }

    const bookingIdArray = bookingIds.split(',');
    const billIdArray = billIds.split(',');
    
    // Show loading state
    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
    button.disabled = true;

    // Process each booking sequentially
    let processed = 0;
    let errors = [];

    function processNextBooking() {
        if (processed >= bookingIdArray.length) {
            // All done
            if (errors.length === 0) {
                alert('All bookings approved successfully!');
                location.reload();
            } else {
                alert('Some bookings failed to approve:\n' + errors.join('\n'));
                button.innerHTML = originalText;
                button.disabled = false;
            }
            return;
        }

        const bookingId = bookingIdArray[processed].trim();
        const billId = billIdArray[processed] ? billIdArray[processed].trim() : null;

        // Make AJAX call to approve this booking
        fetch('api_approve_booking.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                booking_id: bookingId,
                bill_id: billId,
                action: 'approve_booking'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                errors.push('Booking #' + bookingId + ': ' + (data.message || 'Unknown error'));
            }
            processed++;
            processNextBooking();
        })
        .catch(error => {
            errors.push('Booking #' + bookingId + ': Network error');
            processed++;
            processNextBooking();
        });
    }

    // Start processing
    processNextBooking();
}
</script>

</body>
</html>
