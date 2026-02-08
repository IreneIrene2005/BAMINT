<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: index.php?role=admin");
    exit;
}

require_once "db/database.php";

// Check if role column exists, if not create it
try {
    $check_role = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'admins' AND COLUMN_NAME = 'role'");
    $check_role->execute();
    if ($check_role->rowCount() === 0) {
        $conn->exec("ALTER TABLE `admins` ADD COLUMN `role` VARCHAR(50) DEFAULT 'admin'");
    }
    
    $check_created = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'admins' AND COLUMN_NAME = 'created_at'");
    $check_created->execute();
    if ($check_created->rowCount() === 0) {
        $conn->exec("ALTER TABLE `admins` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
} catch (Exception $e) {
    // Silently fail
}

$message = "";
$message_type = "";
$users = [];
$roles = ['admin', 'front_desk', 'staff'];

// Handle add user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $password_confirm = trim($_POST['password_confirm'] ?? '');
        $role = trim($_POST['role'] ?? 'staff');
        
        $errors = [];
        
        if (empty($username)) $errors[] = "Username is required.";
        if (strlen($username) < 3) $errors[] = "Username must be at least 3 characters.";
        if (empty($password)) $errors[] = "Password is required.";
        if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters.";
        if ($password !== $password_confirm) $errors[] = "Passwords do not match.";
        if (!in_array($role, $roles)) $errors[] = "Invalid role selected.";
        
        if (empty($errors)) {
            try {
                // Check if username already exists
                $check_stmt = $conn->prepare("SELECT id FROM admins WHERE username = :username");
                $check_stmt->execute(['username' => $username]);
                
                if ($check_stmt->rowCount() > 0) {
                    $message = "Username already exists.";
                    $message_type = "danger";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO admins (username, password, role) VALUES (:username, :password, :role)");
                    $stmt->execute([
                        'username' => $username,
                        'password' => $hashed_password,
                        'role' => $role
                    ]);
                    $message = "User created successfully.";
                    $message_type = "success";
                }
            } catch (Exception $e) {
                $message = "Error creating user: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = implode(" ", $errors);
            $message_type = "danger";
        }
    } elseif ($action === 'edit_user') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $role = trim($_POST['role'] ?? 'staff');
        
        if ($user_id > 0 && in_array($role, $roles)) {
            try {
                $stmt = $conn->prepare("UPDATE admins SET role = :role WHERE id = :id");
                $stmt->execute(['role' => $role, 'id' => $user_id]);
                $message = "User role updated successfully.";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Error updating user: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    } elseif ($action === 'delete_user') {
        $user_id = intval($_POST['user_id'] ?? 0);
        
        if ($user_id > 0 && $user_id != $_SESSION['id']) {
            try {
                $stmt = $conn->prepare("DELETE FROM admins WHERE id = :id");
                $stmt->execute(['id' => $user_id]);
                $message = "User deleted successfully.";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Error deleting user: " . $e->getMessage();
                $message_type = "danger";
            }
        } else if ($user_id == $_SESSION['id']) {
            $message = "You cannot delete your own account.";
            $message_type = "danger";
        }
    }
}

// Handle add customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_customer') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (!$name || !$email || !$phone) {
            $message = "Please fill in all required fields.";
            $message_type = "danger";
        } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
            $message_type = "danger";
        } else {
            try {
                // Check if email already exists
                $check_stmt = $conn->prepare("SELECT id FROM tenants WHERE email = :email");
                $check_stmt->execute(['email' => $email]);
                if ($check_stmt->rowCount() > 0) {
                    $message = "Email already exists in the system.";
                    $message_type = "warning";
                } else {
                    $stmt = $conn->prepare("INSERT INTO tenants (name, email, phone, status) VALUES (:name, :email, :phone, 'active')");
                    $stmt->execute([
                        'name' => $name,
                        'email' => $email,
                        'phone' => $phone,
                    ]);
                    $message = "Customer account created successfully.";
                    $message_type = "success";
                }
            } catch (Exception $e) {
                $message = "Error creating customer: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// Fetch all users
try {
    $stmt = $conn->query("SELECT id, username, role, created_at FROM admins ORDER BY created_at DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = "Error loading users: " . $e->getMessage();
    $message_type = "danger";
}

try {
    $sql = "SELECT 
        t.id,
        t.name,
        t.email,
        COALESCE(
            NULLIF(t.phone, ''),
            (SELECT rr.tenant_info_phone FROM room_requests rr WHERE rr.tenant_id = t.id ORDER BY rr.id DESC LIMIT 1),
            (SELECT ct.phone FROM co_tenants ct WHERE ct.primary_tenant_id = t.id ORDER BY ct.id DESC LIMIT 1)
        ) AS phone,
        t.status
    FROM tenants t
    ORDER BY t.id DESC
    LIMIT 50";

    $stmt = $conn->query($sql);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = "Error loading customers: " . $e->getMessage();
    $message_type = "danger";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management - BAMINT Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="public/css/style.css">
    <style>
        .user-card {
            border-left: 4px solid #667eea;
            transition: all 0.3s;
        }
        .user-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .role-badge {
            font-weight: 600;
            padding: 0.4rem 0.8rem;
        }
        .role-admin {
            background: #dc3545;
        }
        .role-front_desk {
            background: #0d6efd;
        }
        .role-staff {
            background: #6c757d;
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
                <h1 class="h2"><i class="bi bi-shield-lock"></i> User & Customer Management</h1>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <ul class="nav nav-tabs mb-4" id="mgmtTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">
                        <i class="bi bi-people"></i> System Users
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="customers-tab" data-bs-toggle="tab" data-bs-target="#customers" type="button" role="tab">
                        <i class="bi bi-person-vcard"></i> Customers
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content">
                <!-- Users Tab -->
                <div class="tab-pane fade show active" id="users" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5>System Users (Admin, Front Desk, Staff)</h5>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            <i class="bi bi-plus-circle"></i> Add New User
                        </button>
                    </div>

                    <!-- Users Grid -->
                    <div class="row g-4">
                        <?php if (empty($users)): ?>
                            <div class="col-12">
                                <div class="alert alert-info">No users found.</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card user-card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="card-title"><?php echo htmlspecialchars($user['username']); ?></h6>
                                                <span class="badge role-badge role-<?php echo $user['role']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                                </span>
                                            </div>
                                            <p class="text-muted small">
                                                Created: <?php echo $user['created_at'] ? date('M d, Y', strtotime($user['created_at'])) : 'N/A'; ?>
                                            </p>
                                            
                                            <div class="d-grid gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUserModal" onclick="setEditUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', '<?php echo $user['role']; ?>')">
                                                    <i class="bi bi-pencil-square"></i> Edit Role
                                                </button>
                                                <?php if ($user['id'] != $_SESSION['id']): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="if(confirm('Delete this user?')) { document.getElementById('deleteForm<?php echo $user['id']; ?>').submit(); }">
                                                        <i class="bi bi-trash"></i> Delete
                                                    </button>
                                                    <form id="deleteForm<?php echo $user['id']; ?>" method="POST" style="display: none;">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    </form>
                                                <?php else: ?>
                                                    <small class="text-muted d-block text-center mt-2">(Your account)</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Customers Tab -->
                <div class="tab-pane fade" id="customers" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5>Customer Accounts</h5>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                            <i class="bi bi-person-plus"></i> Add New Customer
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($customers)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No customers found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($customers as $customer): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($customer['name']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                            <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                                            <td>
                                                <span class="badge <?php echo $customer['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo ucfirst($customer['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-plus"></i> Add New System User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_user">
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" required minlength="3">
                        <small class="text-muted">At least 3 characters</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required minlength="6">
                        <small class="text-muted">At least 6 characters</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password_confirm" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" required minlength="6">
                    </div>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label">Role</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="staff">Staff</option>
                            <option value="front_desk">Front Desk</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-person-plus"></i> Add New Customer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_customer">
                    
                    <div class="mb-3">
                        <label for="cust_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="cust_name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="cust_email" class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="cust_email" name="email" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="cust_phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control" id="cust_phone" name="phone" required>
                    </div>
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Create Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Edit User Role</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" id="editUserId" name="user_id" value="">
                    
                    <p class="mb-3">
                        <strong>Username:</strong> <span id="editUsername"></span>
                    </p>
                    
                    <div class="mb-3">
                        <label for="editRole" class="form-label">Role</label>
                        <select class="form-select" id="editRole" name="role" required>
                            <option value="staff">Staff</option>
                            <option value="front_desk">Front Desk</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">Update Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function setEditUser(userId, username, role) {
    document.getElementById('editUserId').value = userId;
    document.getElementById('editUsername').textContent = username;
    document.getElementById('editRole').value = role;
}
</script>
</body>
</html>
