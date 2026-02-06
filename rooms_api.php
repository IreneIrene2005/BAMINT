<?php
// rooms_api.php
// Backend API for Room CRUD operations

require_once 'db_connect.php'; // Assumes you have a DB connection file

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        // optional status filter
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        if ($status) {
            $status_esc = $conn->real_escape_string($status);
            $result = $conn->query("SELECT * FROM rooms WHERE status = '" . $status_esc . "'");
        } else {
            $result = $conn->query("SELECT * FROM rooms");
        }
        $rooms = [];
        while ($row = $result->fetch_assoc()) {
            $rooms[] = $row;
        }
        echo json_encode(['success' => true, 'rooms' => $rooms]);
        break;
    case 'guests':
        $room_id = $_GET['room_id'] ?? 0;
        $guests = [];
        if ($room_id) {
            // Get tenants assigned directly to this room who are currently active
            $stmt = $conn->prepare("SELECT t.name, t.email, t.phone, t.address, t.status, t.start_date, t.end_date FROM tenants t WHERE t.room_id = ? AND t.status = 'active'");
            $stmt->bind_param('i', $room_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $row['type'] = 'Primary';
                if (!empty($row['start_date']) && !empty($row['end_date'])) {
                    $row['stay_duration'] = date('M d, Y', strtotime($row['start_date'])) . ' - ' . date('M d, Y', strtotime($row['end_date']));
                } else if (!empty($row['start_date'])) {
                    $row['stay_duration'] = date('M d, Y', strtotime($row['start_date'])) . ' - Present';
                } else {
                    $row['stay_duration'] = 'N/A';
                }
                $guests[] = $row;
            }

            // Also get tenants from room_requests (approved/occupied) who are currently in the room
            $stmt_req = $conn->prepare("SELECT rr.tenant_id, rr.checkin_date, rr.checkout_date, t.name, t.email, t.phone, t.address, t.status FROM room_requests rr JOIN tenants t ON rr.tenant_id = t.id WHERE rr.room_id = ? AND rr.status IN ('approved','occupied')");
            $stmt_req->bind_param('i', $room_id);
            $stmt_req->execute();
            $result_req = $stmt_req->get_result();
            while ($row = $result_req->fetch_assoc()) {
                $row['type'] = 'Primary (Request)';
                if (!empty($row['checkin_date']) && !empty($row['checkout_date'])) {
                    $row['stay_duration'] = date('M d, Y', strtotime($row['checkin_date'])) . ' - ' . date('M d, Y', strtotime($row['checkout_date']));
                } else if (!empty($row['checkin_date'])) {
                    $row['stay_duration'] = date('M d, Y', strtotime($row['checkin_date'])) . ' - Present';
                } else {
                    $row['stay_duration'] = 'N/A';
                }
                $guests[] = $row;
            }

            // Get co-tenants (guests) for this room
            $stmt2 = $conn->prepare("SELECT name, email, phone, address FROM co_tenants WHERE room_id = ?");
            $stmt2->bind_param('i', $room_id);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            while ($row2 = $result2->fetch_assoc()) {
                $row2['type'] = 'Co-Guest';
                $row2['stay_duration'] = '';
                $guests[] = $row2;
            }
        }
        echo json_encode(['success' => true, 'guests' => $guests]);
        break;
    case 'add':
        $room_number = $_POST['room_number'];
        $room_type = $_POST['category'];
        $status = $_POST['status'];
        $rate = $_POST['rate_per_night'] ?? $_POST['rate'];
        $description = $_POST['description'];
        $image = '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $target = 'uploads/' . basename($_FILES['image']['name']);
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                $image = $target;
            }
        }
        $stmt = $conn->prepare("INSERT INTO rooms (room_number, room_type, status, rate, description, image) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssss', $room_number, $room_type, $status, $rate, $description, $image);
        $success = $stmt->execute();
        echo json_encode(['success' => $success]);
        break;
    case 'delete':
        $id = $_POST['id'];
        $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->bind_param('i', $id);
        $success = $stmt->execute();
        echo json_encode(['success' => $success]);
        break;
    case 'edit':
        $id = $_POST['id'];
        $room_number = $_POST['room_number'];
        $room_type = $_POST['category'];
        $status = $_POST['status'];
        $rate = $_POST['rate_per_night'] ?? $_POST['rate'];
        $description = $_POST['description'];
        $image = $_POST['existing_image'] ?? '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $target = 'uploads/' . basename($_FILES['image']['name']);
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                $image = $target;
            }
        }
        $stmt = $conn->prepare("UPDATE rooms SET room_number=?, room_type=?, status=?, rate=?, description=?, image=? WHERE id=?");
        $stmt->bind_param('ssssssi', $room_number, $room_type, $status, $rate, $description, $image, $id);
        $success = $stmt->execute();
        echo json_encode(['success' => $success]);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
