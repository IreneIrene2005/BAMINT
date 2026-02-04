<?php
// admin_bookings.php
// Booking Management UI for Admin

// ...existing code for authentication/session...
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking</title>
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
        <h1 class="h2 mb-0"><i class="bi bi-calendar-check"></i> Booking</h1>
        <p class="mb-0">View, search, and manage all bookings.</p>
      </div>
      <form class="row g-3 mb-3">
        <div class="col-md-3">
          <label for="searchDate" class="form-label">Date</label>
          <input type="date" class="form-control" id="searchDate" name="date">
        </div>
        <div class="col-md-3">
          <label for="searchGuest" class="form-label">Guest Name</label>
          <input type="text" class="form-control" id="searchGuest" name="guest">
        </div>
        <div class="col-md-3">
          <label for="searchStatus" class="form-label">Status</label>
          <select class="form-select" id="searchStatus" name="status">
            <option value="">All</option>
            <option value="pending">Pending</option>
            <option value="confirmed">Confirmed</option>
            <option value="checked-in">Checked-in</option>
            <option value="checked-out">Checked-out</option>
            <option value="canceled">Canceled</option>
          </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Search</button>
        </div>
      </form>
      <div class="card mb-4">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>Date</th>
                  <th>Guest Name</th>
                  <th>Room</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="bookingTableBody">
                <!-- Booking rows will be loaded here via JS -->
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <!-- Modals and JS for actions will be added here -->
    </main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Load bookings (room requests) on page load
function loadBookings() {
  fetch('admin_bookings_data.php')
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        const tbody = document.getElementById('bookingTableBody');
        tbody.innerHTML = '';
        data.bookings.forEach(booking => {
          tbody.innerHTML += `
            <tr>
              <td>${booking.id}</td>
              <td>${booking.date ? booking.date : ''}</td>
              <td>${booking.customer_name ? booking.customer_name : ''}</td>
              <td>${booking.room_number ? booking.room_number : ''} ${booking.room_type ? '('+booking.room_type+')' : ''}</td>
              <td>${booking.status.charAt(0).toUpperCase() + booking.status.slice(1)}</td>
              <td>
                <button class='btn btn-sm btn-info' onclick='viewBooking(${booking.id})'>View</button>
                ${booking.status === 'pending' ? `
                  <button class='btn btn-sm btn-success' onclick='approveBooking(${booking.id})'>Approve</button>
                  <button class='btn btn-sm btn-danger' onclick='rejectBooking(${booking.id})'>Reject</button>
                ` : ''}
              </td>
            </tr>
          `;
        });
      }
    });
}

function approveBooking(id) {
  if (!confirm('Approve this booking?')) return;
  updateBookingStatus(id, 'approve');
}
function rejectBooking(id) {
  if (!confirm('Reject this booking?')) return;
  updateBookingStatus(id, 'reject');
}
function updateBookingStatus(id, action) {
  fetch('room_requests_queue.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=${action}&request_id=${id}`
  })
  .then(() => loadBookings());
}
function viewBooking(id) {
  alert('View details for booking #' + id + '. (You can enhance this with a modal.)');
}

window.onload = loadBookings;
</script>
</body>
</html>
