<?php
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'front_desk'])) {
    header('Location: index.php');
    exit;
}

require_once 'db_connect.php';

// Room status filter from query string (optional)
$room_status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Prepare metrics
$total_rooms = (int)$conn->query("SELECT COUNT(*) FROM rooms")->fetch_row()[0];
$occupied_rooms = (int)$conn->query("SELECT COUNT(*) FROM rooms WHERE status = 'occupied'")->fetch_row()[0];
$vacant_rooms = (int)$conn->query("SELECT COUNT(*) FROM rooms WHERE status = 'available'")->fetch_row()[0];
$maintenance_rooms = (int)$conn->query("SELECT COUNT(*) FROM rooms WHERE status = 'under maintenance'")->fetch_row()[0];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rooms - BAMINT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="public/css/style.css">
  <style>
    .metric-card { border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
    .metric-value { font-size:1.6rem; font-weight:700; }
    .last-updated { font-size:0.85rem; color:#666; text-align:right; }
  </style>
</head>
<body>

<?php include 'templates/header.php'; ?>

<div class="container-fluid">
  <div class="row">
    <?php include 'templates/sidebar.php'; ?>

    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
      <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-building"></i> Room Management</h1>
        <div>
          <button class="btn btn-outline-secondary btn-sm" onclick="location.reload();"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
      </div>

      <!-- Metrics -->
      <div class="row mb-4">
        <div class="col-md-3 mb-3">
          <div class="card metric-card bg-success text-white h-100">
            <div class="card-body text-center">
              <div class="metric-icon"><i class="bi bi-building"></i></div>
              <div class="metric-label">Total Rooms</div>
              <div id="totalRoomsValue" class="metric-value"><?= $total_rooms ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card metric-card bg-info text-white h-100">
            <div class="card-body text-center">
              <div class="metric-icon"><i class="bi bi-door-open"></i></div>
              <div class="metric-label">Occupied</div>
              <div id="occupiedRoomsValue" class="metric-value"><?= $occupied_rooms ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card metric-card bg-primary text-white h-100">
            <div class="card-body text-center">
              <div class="metric-icon"><i class="bi bi-door-closed"></i></div>
              <div class="metric-label">Vacant</div>
              <div id="vacantRoomsValue" class="metric-value"><?= $vacant_rooms ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-3 mb-3">
          <div class="card metric-card bg-warning text-white h-100">
            <div class="card-body text-center">
              <div class="metric-icon"><i class="bi bi-tools"></i></div>
              <div class="metric-label">Under Maintenance</div>
              <div id="maintenanceRoomsValue" class="metric-value"><?= $maintenance_rooms ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Rooms table -->
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
              <span class="fw-semibold"><i class="bi bi-table"></i> Room List</span>
              <div>
                <label for="roomStatusFilter" class="form-label mb-0 small">Filter</label>
                <select id="roomStatusFilter" class="form-select form-select-sm">
                  <option value="">All</option>
                  <option value="available" <?php echo $room_status_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                  <option value="booked" <?php echo $room_status_filter === 'booked' ? 'selected' : ''; ?>>Booked</option>
                  <option value="occupied" <?php echo $room_status_filter === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                  <option value="under maintenance" <?php echo $room_status_filter === 'under maintenance' ? 'selected' : ''; ?>>Under Maintenance</option>
                </select>
              </div>
            </div>
            <div>
              <button class="btn btn-primary btn-sm me-2" id="applyRoomFilter">Apply</button>
              <?php if ($_SESSION['role'] === 'admin'): ?>
              <button class="btn btn-primary" onclick="openAddModal()"><i class="bi bi-plus-circle"></i> Add Room</button>
              <?php endif; ?>
            </div>
          </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>Room Number</th>
                  <th>Category</th>
                  <th>Status</th>
                  <th>Rate/Night</th>
                  <th>Description</th>
                  <th>Image</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="roomTableBody">
                <?php
                // Fetch rooms, optionally filter by status
                if ($room_status_filter) {
                    $status_esc = $conn->real_escape_string($room_status_filter);
                    $res = $conn->query("SELECT * FROM rooms WHERE status = '".$status_esc."' ORDER BY id DESC");
                } else {
                    $res = $conn->query("SELECT * FROM rooms ORDER BY id DESC");
                }
                while ($r = $res->fetch_assoc()):
                  $status = htmlspecialchars(ucfirst($r['status']));
                  $badge = ($r['status'] === 'available') ? 'success' : (($r['status'] === 'booked') ? 'warning text-dark' : 'secondary');
                ?>
                <tr>
                  <td class="fw-bold text-secondary"><?= htmlspecialchars($r['id']) ?></td>
                  <td><?= htmlspecialchars($r['room_number']) ?></td>
                  <td><?= htmlspecialchars(ucfirst($r['room_type'])) ?></td>
                  <td><span class="badge bg-<?= $badge ?>"><?= $status ?></span></td>
                  <td>₱<?= htmlspecialchars(number_format($r['rate'], 2)) ?></td>
                  <td><?= htmlspecialchars($r['description']) ?></td>
                  <td><?= $r['image'] ? "<img src='".htmlspecialchars($r['image'])."' width='60' class='rounded border'>" : '<span class="text-muted">No image</span>' ?></td>
                  <td>
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                    <button class="btn btn-sm btn-warning me-1" onclick='openEditModal(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i> Edit</button>
                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= (int)$r['id'] ?>)"><i class="bi bi-trash"></i> Delete</button>
                    <?php else: ?>
                    <span class="text-muted small">View only</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="last-updated mb-4">Last updated: <span id="updateTime"><?= date('M d, Y H:i:s') ?></span></div>

    </main>
  </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="addRoomModal" tabindex="-1" aria-labelledby="addRoomModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="addRoomForm" enctype="multipart/form-data">
        <input type="hidden" name="id" id="roomId">
        <div class="modal-header">
          <h5 class="modal-title" id="addRoomModalLabel">Add Room</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="roomNumber" class="form-label">Room Number</label>
            <input type="text" class="form-control" id="roomNumber" name="room_number" required>
          </div>
          <div class="mb-3">
            <label for="roomCategory" class="form-label">Category</label>
            <select class="form-select" id="roomCategory" name="category" required>
              <option value="Single">Single</option>
              <option value="Double">Double</option>
              <option value="Family">Family</option>
            </select>
          </div>
          <div class="mb-3">
            <label for="roomStatus" class="form-label">Status</label>
            <select class="form-select" id="roomStatus" name="status" required>
              <option value="available">Available</option>
              <option value="booked">Booked</option>
              <option value="under maintenance">Under Maintenance</option>
            </select>
          </div>
          <div class="mb-3">
            <label for="roomRate" class="form-label">Rate per Night</label>
            <input type="number" step="0.01" class="form-control" id="roomRate" name="rate_per_night" required>
          </div>
          <div class="mb-3">
            <label for="roomDescription" class="form-label">Description</label>
            <textarea class="form-control" id="roomDescription" name="description"></textarea>
          </div>
          <div class="mb-3">
            <label for="roomImage" class="form-label">Image</label>
            <input type="file" class="form-control" id="roomImage" name="image">
            <input type="hidden" id="existingImage" name="existing_image">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function loadRooms() {
  const statusEl = document.getElementById('roomStatusFilter');
  const status = statusEl ? encodeURIComponent(statusEl.value) : '';
  const url = 'rooms_api.php?action=list' + (status ? ('&status=' + status) : '');
  fetch(url)
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        const tbody = document.getElementById('roomTableBody');
        tbody.innerHTML = '';
        data.rooms.forEach(r => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td class="fw-bold text-secondary">${r.id}</td>
            <td>${r.room_number}</td>
            <td>${r.room_type || ''}</td>
            <td><span class="badge bg-${r.status === 'available' ? 'success' : (r.status === 'booked' ? 'warning text-dark' : 'secondary')}">${r.status}</span></td>
            <td>₱${Number(r.rate).toFixed(2)}</td>
            <td>${r.description || ''}</td>
            <td>${r.image ? `<img src='${r.image}' width='60' class='rounded border'>` : '<span class="text-muted">No image</span>'}</td>
            <td>
              <button class="btn btn-sm btn-warning me-1" onclick='openEditModal(${JSON.stringify(r)})'>Edit</button>
              <button class="btn btn-sm btn-danger" onclick="confirmDelete(${r.id})">Delete</button>
            </td>
          `;
          tbody.appendChild(tr);
        });
      }
    });
}

function confirmDelete(id) {
  if (!confirm('Delete this room?')) return;
  const fd = new FormData(); fd.append('id', id);
  fetch('rooms_api.php?action=delete', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(d => {
      if (d.success) {
        loadRooms();
        alert('Room deleted successfully');
      } else {
        alert('⚠️ ' + (d.message || 'Delete failed'));
      }
    })
    .catch(err => alert('Error: ' + err));
}

function openAddModal() {
  const modalEl = document.getElementById('addRoomModal');
  const modal = new bootstrap.Modal(modalEl);
  document.getElementById('addRoomModalLabel').textContent = 'Add Room';
  document.getElementById('roomId').value = '';
  document.getElementById('roomNumber').value = '';
  document.getElementById('roomCategory').value = 'Single';
  document.getElementById('roomStatus').value = 'available';
  document.getElementById('roomRate').value = '1500';
  document.getElementById('roomDescription').value = '';
  document.getElementById('roomImage').value = '';
  document.getElementById('existingImage').value = '';
  modal.show();
}

function openEditModal(room) {
  const modalEl = document.getElementById('addRoomModal');
  const modal = new bootstrap.Modal(modalEl);
  document.getElementById('addRoomModalLabel').textContent = 'Edit Room';
  document.getElementById('roomId').value = room.id;
  document.getElementById('roomNumber').value = room.room_number;
  document.getElementById('roomCategory').value = room.room_type || '';
  document.getElementById('roomStatus').value = room.status;
  document.getElementById('roomRate').value = room.rate;
  document.getElementById('roomDescription').value = room.description || '';
  document.getElementById('existingImage').value = room.image || '';
  modal.show();
}

// Form submit handles both add and edit depending on id
document.getElementById('addRoomForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const form = e.target;
  const fd = new FormData(form);
  const id = document.getElementById('roomId').value;
  const action = id ? 'edit' : 'add';
  fetch('rooms_api.php?action=' + action, { method: 'POST', body: fd })
    .then(res => res.json()).then(d => {
      if (d.success) {
        form.reset();
        var m = bootstrap.Modal.getOrCreateInstance(document.getElementById('addRoomModal'));
        m.hide();
        loadRooms();
      } else {
        alert('Save failed');
      }
    });
});

window.addEventListener('load', function() {
  // Handle category change to auto-populate rate
  const categorySelect = document.getElementById('roomCategory');
  const rateInput = document.getElementById('roomRate');
  
  if (categorySelect && rateInput) {
    categorySelect.addEventListener('change', function() {
      const category = this.value;
      if (category) {
        fetch('rooms_api.php?action=get_rate_by_category&category=' + encodeURIComponent(category))
          .then(res => res.json())
          .then(data => {
            if (data.success && data.rate > 0) {
              rateInput.value = parseFloat(data.rate).toFixed(2);
            }
          })
          .catch(err => console.error('Error fetching rate:', err));
      }
    });
  }

  // Wire filter apply button and load initial rooms with optional filter
  const applyBtn = document.getElementById('applyRoomFilter');
  const statusEl = document.getElementById('roomStatusFilter');
  if (applyBtn && statusEl) {
    applyBtn.addEventListener('click', function(){
      // If user clicks apply, reload server-rendered view so GET param is set
      const val = statusEl.value ? ('?status=' + encodeURIComponent(statusEl.value)) : '';
      location.href = 'admin_rooms.php' + val;
    });
  }
  loadRooms();
  // Start realtime metrics refresh
  refreshMetrics();
  setInterval(refreshMetrics, 10000); // every 10s
  
  // Real-time room updates for front desk users
  const isFrontDesk = '<?php echo $_SESSION['role']; ?>' === 'front_desk';
  if (isFrontDesk) {
    let lastRoomsChecksum = null;
    
    // Calculate checksum for rooms to detect changes
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
    
    // Fetch rooms from API and check for changes
    async function checkRoomsUpdates() {
      try {
        const response = await fetch('api_get_rooms.php');
        const result = await response.json();
        
        if (result.success) {
          const currentChecksum = calculateChecksum(result.data);
          
          // If rooms changed, reload the page
          if (lastRoomsChecksum !== null && lastRoomsChecksum !== currentChecksum) {
            console.log('Rooms updated by admin, refreshing page...');
            location.reload();
          }
          
          lastRoomsChecksum = currentChecksum;
        }
      } catch (error) {
        console.error('Error checking rooms updates:', error);
      }
    }
    
    // Initial check and then poll every 5 seconds
    checkRoomsUpdates();
    setInterval(checkRoomsUpdates, 5000);
    console.log('Real-time room updates enabled for front desk (5 second interval)');
  }
});

function refreshMetrics() {
  fetch('rooms_api.php?action=metrics')
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        document.getElementById('totalRoomsValue').textContent = data.total;
        document.getElementById('occupiedRoomsValue').textContent = data.occupied;
        document.getElementById('vacantRoomsValue').textContent = data.vacant;
        document.getElementById('maintenanceRoomsValue').textContent = data.maintenance;
        document.getElementById('updateTime').textContent = new Date().toLocaleString();
      }
    }).catch(err => console.error('metrics fetch error', err));
}
</script>
