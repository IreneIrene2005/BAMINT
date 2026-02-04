
<?php
// Room Management Page for Admin UI (replaces old content)
// ...existing code for authentication/session...
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Room Management</title>
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
        <h1 class="h2 mb-0"><i class="bi bi-building"></i> Rooms</h1>
        <p class="mb-0">Manage all rooms, categories, rates, and statuses here.</p>
      </div>
      <div class="d-flex justify-content-end mb-3">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoomModal">
          <i class="bi bi-plus-circle"></i> Add Room
        </button>
      </div>
      <div class="card mb-4">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Category</th>
                  <th>Status</th>
                  <th>Rate/Night</th>
                  <th>Description</th>
                  <th>Image</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="roomTableBody">
                <!-- Room rows will be loaded here via PHP or AJAX -->
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>
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
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save Room</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Load rooms on page load
function loadRooms() {
    fetch('rooms_api.php?action=list')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const tbody = document.getElementById('roomTableBody');
                tbody.innerHTML = '';
                data.rooms.forEach(room => {
                    tbody.innerHTML += `
                        <tr>
                            <td>${room.id}</td>
                            <td>${room.name}</td>
                            <td>${room.category}</td>
                            <td>${room.status}</td>
                            <td>${room.rate_per_night}</td>
                            <td>${room.description || ''}</td>
                            <td>${room.image ? `<img src='${room.image}' width='60'>` : ''}</td>
                            <td>
                                <button class='btn btn-sm btn-warning' onclick='editRoom(${JSON.stringify(room)})'>Edit</button>
                                <button class='btn btn-sm btn-danger' onclick='deleteRoom(${room.id})'>Delete</button>
                            </td>
                        </tr>
                    `;
                });
            }
        });
}

// Add room
document.getElementById('addRoomForm').onsubmit = function(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    fetch('rooms_api.php?action=add', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            form.reset();
            var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('addRoomModal'));
            modal.hide();
            loadRooms();
        } else {
            alert('Failed to add room');
        }
    });
};

// Delete room
function deleteRoom(id) {
    if (!confirm('Delete this room?')) return;
    const formData = new FormData();
    formData.append('id', id);
    fetch('rooms_api.php?action=delete', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) loadRooms();
        else alert('Failed to delete room');
    });
}

// Edit room (simple version: prefill add modal, then submit as edit)
function editRoom(room) {
    document.getElementById('roomName').value = room.name;
    document.getElementById('roomCategory').value = room.category;
    document.getElementById('roomStatus').value = room.status;
    document.getElementById('roomRate').value = room.rate_per_night;
    document.getElementById('roomDescription').value = room.description;
    // For image, skip prefill
    document.getElementById('addRoomModalLabel').innerText = 'Edit Room';
    document.getElementById('addRoomForm').onsubmit = function(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        formData.append('id', room.id);
        formData.append('existing_image', room.image || '');
        fetch('rooms_api.php?action=edit', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                form.reset();
                var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('addRoomModal'));
                modal.hide();
                loadRooms();
                document.getElementById('addRoomModalLabel').innerText = 'Add Room';
                document.getElementById('addRoomForm').onsubmit = addRoomHandler;
            } else {
                alert('Failed to update room');
            }
        });
    };
    var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('addRoomModal'));
    modal.show();
}

// Restore add handler after edit
const addRoomHandler = document.getElementById('addRoomForm').onsubmit;

window.onload = loadRooms;
</script>
</body>
</html>