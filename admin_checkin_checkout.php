

<!DOCTYPE html>
<html lang="en">
<head>
        <meta charset="UTF-8">
        <title>Check-in / Check-out</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container-fluid">
    <div class="row">
        <?php include 'templates/sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="header-banner mb-4">
                <h1 class="h2 mb-0"><i class="bi bi-door-open"></i> Check-in / Check-out</h1>
                <p class="mb-0">Manage guest arrivals, departures, and generate reports for housekeeping and front desk.</p>
            </div>
            <?php include 'db_connect.php'; ?>
            <?php
            $bookings = $conn->query("SELECT b.id, c.name AS customer_name, r.room_number, b.status, b.checkin_date, b.checkout_date, b.amount_due, b.amount_paid FROM bookings b JOIN customers c ON b.user_id = c.id JOIN rooms r ON b.room_id = r.id ORDER BY b.checkin_date DESC");
            ?>
            <div class="card mb-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Booking ID</th>
                                    <th>Guest Name</th>
                                    <th>Room</th>
                                    <th>Status</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Stay Duration</th>
                                    <th>Amount Due</th>
                                    <th>Amount Paid</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php while($row = $bookings->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $row['id'] ?></td>
                                    <td><?= htmlspecialchars($row['customer_name']) ?></td>
                                    <td><?= htmlspecialchars($row['room_number']) ?></td>
                                    <td><?= htmlspecialchars($row['status']) ?></td>
                                    <td><?= htmlspecialchars($row['checkin_date']) ?></td>
                                    <td><?= htmlspecialchars($row['checkout_date']) ?></td>
                                    <td>
                                        <?php
                                        if ($row['checkin_date'] && $row['checkout_date']) {
                                            $start = new DateTime($row['checkin_date']);
                                            $end = new DateTime($row['checkout_date']);
                                            echo $start->diff($end)->days . ' nights';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td><?= number_format($row['amount_due'], 2) ?></td>
                                    <td><?= number_format($row['amount_paid'], 2) ?></td>
                                    <td>
                                        <?php if ($row['status'] == 'booked'): ?>
                                            <a href="admin_checkin_checkout.php?action=checkin&id=<?= $row['id'] ?>" class="btn btn-success btn-sm">Check-in</a>
                                        <?php elseif ($row['status'] == 'checked_in'): ?>
                                            <a href="admin_checkin_checkout.php?action=checkout&id=<?= $row['id'] ?>" class="btn btn-warning btn-sm">Check-out</a>
                                        <?php else: ?>
                                            <span class="text-muted">Completed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header">Reports</div>
                <div class="card-body">
                    <a href="admin_checkin_checkout.php?report=housekeeping" class="btn btn-info">Housekeeping Report</a>
                    <a href="admin_checkin_checkout.php?report=frontdesk" class="btn btn-info">Front Desk Report</a>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
