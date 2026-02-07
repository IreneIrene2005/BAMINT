<?php
// rooms_api.php
// Backend API for Room CRUD operations

error_reporting(0);
ini_set('display_errors', 0);

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
    case 'metrics':
        // Return quick room counts for dashboard
        $total = $conn->query("SELECT COUNT(*) as c FROM rooms")->fetch_assoc()['c'] ?? 0;
        $occupied = $conn->query("SELECT COUNT(*) as c FROM rooms WHERE status = 'occupied'")->fetch_assoc()['c'] ?? 0;
        $vacant = $conn->query("SELECT COUNT(*) as c FROM rooms WHERE status = 'available'")->fetch_assoc()['c'] ?? 0;
        $maintenance = $conn->query("SELECT COUNT(*) as c FROM rooms WHERE status = 'under maintenance'")->fetch_assoc()['c'] ?? 0;
        echo json_encode(['success' => true, 'total' => (int)$total, 'occupied' => (int)$occupied, 'vacant' => (int)$vacant, 'maintenance' => (int)$maintenance]);
        break;
    case 'get_rate_by_category':
        $category = $_GET['category'] ?? '';
        
        // Define default rates for each room category
        $rates = [
            'Single' => 1500,
            'Double' => 2500,
            'Family' => 3500
        ];
        
        $rate = isset($rates[$category]) ? $rates[$category] : 0;
        
        if ($category && $rate > 0) {
            echo json_encode(['success' => true, 'rate' => (float)$rate, 'category' => $category]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No rate found for this category']);
        }
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
        try {
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
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                break;
            }
            $stmt->bind_param('ssssss', $room_number, $room_type, $status, $rate, $description, $image);
            $success = $stmt->execute();
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Room added successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to add room: ' . $stmt->error]);
            }
            $stmt->close();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;
    case 'delete':
        try {
            $id = intval($_POST['id']);
            
            // Temporarily disable foreign key constraints to allow deletion
            $conn->query("SET FOREIGN_KEY_CHECKS=0");
            
            // Proceed with deletion - admin has authority to delete rooms
            $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
            if (!$stmt) {
                $conn->query("SET FOREIGN_KEY_CHECKS=1");
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                break;
            }
            
            $stmt->bind_param('i', $id);
            $success = $stmt->execute();
            
            // Re-enable foreign key constraints
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Room deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete room: ' . $stmt->error]);
            }
            $stmt->close();
        } catch (Exception $e) {
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;
    case 'edit':
        try {
            $id = intval($_POST['id']);
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
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                break;
            }
            $stmt->bind_param('ssssssi', $room_number, $room_type, $status, $rate, $description, $image, $id);
            $success = $stmt->execute();
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Room updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update room: ' . $stmt->error]);
            }
            $stmt->close();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
