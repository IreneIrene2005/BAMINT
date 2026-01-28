<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "tenant") {
    header("location: index.php?role=tenant");
    exit;
}

require_once "db/database.php";

$tenant_id = $_SESSION["tenant_id"];
$success_msg = "";
$error_msg = "";

try {
    // Get tenant information
    $stmt = $conn->prepare("
        SELECT t.*, r.room_number, r.room_type, r.rate
        FROM tenants t
        LEFT JOIN rooms r ON t.room_id = r.id
        WHERE t.id = :tenant_id
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get account info
    $stmt = $conn->prepare("
        SELECT email FROM tenant_accounts
        WHERE tenant_id = :tenant_id
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error_msg = "Error loading profile: " . $e->getMessage();
}

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // Validation
    $validation_errors = [];

    if (empty($name)) {
        $validation_errors[] = "Name cannot be empty.";
    }

    if (empty($phone)) {
        $validation_errors[] = "Phone number cannot be empty.";
    } elseif (!preg_match('/^[0-9\s\-\+\(\)]{10,}$/', $phone)) {
        $validation_errors[] = "Please enter a valid phone number.";
    }

    if (empty($email)) {
        $validation_errors[] = "Email cannot be empty.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validation_errors[] = "Please enter a valid email address.";
    } else {
        // Check if email is already taken by another account
        $stmt = $conn->prepare("
            SELECT id FROM tenant_accounts 
            WHERE email = :email AND tenant_id != :tenant_id
        ");
        $stmt->execute(['email' => $email, 'tenant_id' => $tenant_id]);
        if ($stmt->rowCount() > 0) {
            $validation_errors[] = "This email is already registered to another account.";
        }
    }

    if (empty($validation_errors)) {
        try {
            // Update tenant info
            $stmt = $conn->prepare("
                UPDATE tenants 
                SET name = :name, phone = :phone
                WHERE id = :tenant_id
            ");
            $stmt->execute([
                'name' => $name,
                'phone' => $phone,
                'tenant_id' => $tenant_id
            ]);

            // Update email in tenant_accounts
            $stmt = $conn->prepare("
                UPDATE tenant_accounts 
                SET email = :email
                WHERE tenant_id = :tenant_id
            ");
            
            $stmt->execute([
                'email' => $email,
                'tenant_id' => $tenant_id
            ]);

            // Update session
            $_SESSION["name"] = $name;
            $_SESSION["email"] = $email;

            $success_msg = "Profile updated successfully!";

            // Refresh tenant data
            $stmt = $conn->prepare("
                SELECT t.*, r.room_number, r.room_type, r.rate
                FROM tenants t
                LEFT JOIN rooms r ON t.room_id = r.id
                WHERE t.id = :tenant_id
            ");
            $stmt->execute(['tenant_id' => $tenant_id]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare("
                SELECT email FROM tenant_accounts
                WHERE tenant_id = :tenant_id
            ");
            $stmt->execute(['tenant_id' => $tenant_id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $error_msg = "Error updating profile: " . $e->getMessage();
        }
    } else {
        $error_msg = implode("<br>", $validation_errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - BAMINT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar {
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
            color: white;
            min-height: 100vh;
            padding: 2rem 0;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.7);
            padding: 1rem 1.5rem;
            border-left: 3px solid transparent;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
            border-left-color: white;
        }
        .user-info {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 1rem;
        }
        .user-info h5 { margin-bottom: 0.25rem; }
        .user-info p { font-size: 0.9rem; opacity: 0.8; margin-bottom: 0; }
        .header-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .btn-logout {
            background: #dc3545;
            color: white;
            border: none;
            margin-top: 1rem;
            width: 100%;
        }
        .btn-logout:hover {
            background: #c82333;
            color: white;
        }
        .profile-card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .info-section {
            padding: 1.5rem;
            border-bottom: 1px solid #e9ecef;
        }
        .info-section:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        .info-value {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 0;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
    </style>
</head>
<body>
    <?php include 'templates/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 sidebar">
                <div class="position-sticky pt-3">
                    <div class="user-info">
                        <h5><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION["name"]); ?></h5>
                        <p><?php echo htmlspecialchars($_SESSION["email"]); ?></p>
                    </div>

                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_dashboard.php">
                                <i class="bi bi-house-door"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_bills.php">
                                <i class="bi bi-receipt"></i> My Bills
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_payments.php">
                                <i class="bi bi-coin"></i> Payments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="tenant_maintenance.php">
                                <i class="bi bi-tools"></i> Maintenance
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="tenant_profile.php">
                                <i class="bi bi-person"></i> My Profile
                            </a>
                        </li>
                    </ul>

                    <form action="logout.php" method="post">
                        <button type="submit" class="btn btn-logout">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </button>
                    </form>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="header-banner">
                    <h1><i class="bi bi-person"></i> My Profile</h1>
                    <p class="mb-0">Manage your personal information and account details</p>
                </div>

                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-circle"></i> <?php echo $error_msg; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Personal Information -->
                    <div class="col-lg-6">
                        <div class="card profile-card mb-4">
                            <div class="card-header bg-primary bg-opacity-10">
                                <h6 class="mb-0"><i class="bi bi-person-fill"></i> Personal Information</h6>
                            </div>
                            <form method="POST">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Full Name</label>
                                        <input type="text" class="form-control" id="name" name="name" 
                                               value="<?php echo htmlspecialchars($tenant['name'] ?? ''); ?>" required>
                                        <small class="text-muted">Your full legal name</small>
                                    </div>

                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="tel" class="form-control" id="phone" name="phone" 
                                               value="<?php echo htmlspecialchars($tenant['phone'] ?? ''); ?>" required>
                                        <small class="text-muted">Format: 10+ digits (e.g., +63 912 345 6789)</small>
                                    </div>

                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo htmlspecialchars($account['email'] ?? ''); ?>" required>
                                        <small class="text-muted">Used for login and notifications</small>
                                    </div>

                                    <button type="submit" name="update_profile" class="btn btn-primary w-100">
                                        <i class="bi bi-save"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Room & Lease Information -->
                    <div class="col-lg-6">
                        <div class="card profile-card mb-4">
                            <div class="card-header bg-success bg-opacity-10">
                                <h6 class="mb-0"><i class="bi bi-door-open"></i> Room & Lease Information</h6>
                            </div>
                            <div class="card-body">
                                <?php if ($tenant['room_id']): ?>
                                    <div class="info-section">
                                        <div class="info-label">Room Number</div>
                                        <div class="info-value"><?php echo htmlspecialchars($tenant['room_number']); ?></div>
                                    </div>

                                    <div class="info-section">
                                        <div class="info-label">Room Type</div>
                                        <div class="info-value"><?php echo htmlspecialchars($tenant['room_type'] ?? 'N/A'); ?></div>
                                    </div>

                                    <div class="info-section">
                                        <div class="info-label">Monthly Rent</div>
                                        <div class="info-value text-success">₱<?php echo number_format($tenant['rate'] ?? 0, 2); ?></div>
                                    </div>

                                    <div class="info-section">
                                        <div class="info-label">Move-in Date</div>
                                        <div class="info-value"><?php echo date('F d, Y', strtotime($tenant['start_date'])); ?></div>
                                    </div>

                                    <div class="info-section">
                                        <div class="info-label">Status</div>
                                        <div class="info-value">
                                            <span class="badge bg-<?php echo $tenant['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo ucfirst($tenant['status']); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if ($tenant['end_date']): ?>
                                        <div class="info-section">
                                            <div class="info-label">Move-out Date</div>
                                            <div class="info-value"><?php echo date('F d, Y', strtotime($tenant['end_date'])); ?></div>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="bi bi-info-circle"></i> No room assigned yet. Contact the admin to request room assignment.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account Information -->
                <div class="card profile-card">
                    <div class="card-header bg-info bg-opacity-10">
                        <h6 class="mb-0"><i class="bi bi-shield-check"></i> Account Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-section">
                                    <div class="info-label">Account Status</div>
                                    <div class="info-value">
                                        <span class="badge bg-<?php echo $tenant['status'] === 'active' ? 'success' : 'warning'; ?>">
                                            <?php echo $tenant['status'] === 'active' ? 'Active' : 'Pending Admin Approval'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="info-section">
                                    <div class="info-label">Account Created</div>
                                    <div class="info-value">
                                        <?php echo date('F d, Y'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-light mb-0 mt-3">
                            <small class="text-muted">
                                <i class="bi bi-shield-lock"></i> 
                                Your password can be changed by contacting the administrative office. For security reasons, we do not allow online password changes.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Contact Admin -->
                <div class="alert alert-warning mt-4">
                    <i class="bi bi-exclamation-triangle"></i> 
                    <strong>Need help?</strong> If you need to change your password or have other account questions, 
                    please contact the administrative office directly.
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
