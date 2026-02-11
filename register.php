<?php
session_start();

// If user is already logged in, redirect to dashboard
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    if ($_SESSION["role"] === "admin") {
        header("location: dashboard.php");
    } else {
        header("location: tenant_dashboard.php");
    }
    exit;
}

require_once "db/database.php";
require_once "db/notifications.php";

$username = $email = $name = $password = $confirm_password = $phone = $address = "";
$username_err = $email_err = $name_err = $password_err = $confirm_password_err = $phone_err = "";
$role = isset($_GET['role']) ? $_GET['role'] : 'tenant';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $role = isset($_POST['role']) ? $_POST['role'] : 'admin';

    if ($role === 'admin') {
        // ===== ADMIN REGISTRATION =====
        
        // Validate username
        if (empty(trim($_POST["username"]))) {
            $username_err = "Please enter a username.";
        } else {
            $username = trim($_POST["username"]);
            
            // Check if username already exists
            $sql = "SELECT id FROM admins WHERE username = :username";
            
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bindParam(":username", $param_username, PDO::PARAM_STR);
                $param_username = $username;
                
                if ($stmt->execute()) {
                    if ($stmt->rowCount() > 0) {
                        $username_err = "This username is already taken.";
                    }
                } else {
                    echo "Oops! Something went wrong. Please try again later.";
                }
                unset($stmt);
            }
        }

        // Validate password
        if (empty(trim($_POST["password"]))) {
            $password_err = "Please enter a password.";
        } else {
            $password = trim($_POST["password"]);
            
            if (strlen($password) < 6) {
                $password_err = "Password must have at least 6 characters.";
            }
        }

        // Validate confirm password
        if (empty(trim($_POST["confirm_password"]))) {
            $confirm_password_err = "Please confirm your password.";
        } else {
            $confirm_password = trim($_POST["confirm_password"]);
            
            if ($password != $confirm_password) {
                $confirm_password_err = "Password did not match.";
            }
        }

        // Check for errors before inserting in database
        if (empty($username_err) && empty($password_err) && empty($confirm_password_err)) {
            
            // Prepare an insert statement
            $sql = "INSERT INTO admins (username, password) VALUES (:username, :password)";
             
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bindParam(":username", $param_username, PDO::PARAM_STR);
                $stmt->bindParam(":password", $param_password, PDO::PARAM_STR);
                
                $param_username = $username;
                $param_password = password_hash($password, PASSWORD_DEFAULT);
                
                if ($stmt->execute()) {
                    header("location: index.php?success=Admin account created successfully. Please login.");
                    exit();
                } else {
                    echo "Oops! Something went wrong. Please try again later.";
                }
                
                unset($stmt);
            }
        }

    } else {
        // ===== TENANT REGISTRATION =====
        
        // Validate name
        if (empty(trim($_POST["name"]))) {
            $name_err = "Please enter your full name.";
        } else {
            $name = trim($_POST["name"]);
        }

        // Optional address
        $address = isset($_POST['address']) ? trim($_POST['address']) : null;

        // Validate phone
        if (empty(trim($_POST["phone"]))) {
            $phone_err = "Please enter your phone number.";
        } else {
            $phone = trim($_POST["phone"]);
            // Normalize phone: keep digits only
            $phone = preg_replace('/\D/', '', $phone);
            if (strlen($phone) < 7) {
                $phone_err = "Please enter a valid phone number.";
            }
        }

        // Validate email
        if (empty(trim($_POST["email"]))) {
            $email_err = "Please enter your email.";
        } else {
            $email = trim($_POST["email"]);
            
            // Check if email already exists
            $sql = "SELECT id FROM tenant_accounts WHERE email = :email";
            
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bindParam(":email", $param_email, PDO::PARAM_STR);
                $param_email = $email;
                
                if ($stmt->execute()) {
                    if ($stmt->rowCount() > 0) {
                        $email_err = "This email is already registered.";
                    }
                } else {
                    echo "Oops! Something went wrong. Please try again later.";
                }
                unset($stmt);
            }
        }

        // Validate password
        if (empty(trim($_POST["password"]))) {
            $password_err = "Please enter a password.";
        } else {
            $password = trim($_POST["password"]);
            
            if (strlen($password) < 6) {
                $password_err = "Password must have at least 6 characters.";
            }
        }

        // Validate confirm password
        if (empty(trim($_POST["confirm_password"]))) {
            $confirm_password_err = "Please confirm your password.";
        } else {
            $confirm_password = trim($_POST["confirm_password"]);
            
            if ($password != $confirm_password) {
                $confirm_password_err = "Password did not match.";
            }
        }

        // Check for errors before inserting in database
        if (empty($name_err) && empty($email_err) && empty($password_err) && empty($confirm_password_err) && empty($phone_err)) {
            
            // First create a tenant record
                $sql = "INSERT INTO tenants (name, email, phone, address, id_number, room_id, start_date, status) 
                    VALUES (:name, :email, :phone, :address, 'PENDING', NULL, CURDATE(), 'active')";
            
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bindParam(":name", $param_name, PDO::PARAM_STR);
                $stmt->bindParam(":email", $param_email, PDO::PARAM_STR);
                $stmt->bindParam(":phone", $param_phone, PDO::PARAM_STR);
                $stmt->bindParam(":address", $param_address, PDO::PARAM_STR);
                
                $param_name = $name;
                $param_email = $email;
                $param_phone = $phone;
                $param_address = $address;
                
                if ($stmt->execute()) {
                    $tenant_id = $conn->lastInsertId();
                    
                    // Now create the account (store name in account as well)
                        $sql = "INSERT INTO tenant_accounts (tenant_id, name, email, password, address) 
                            VALUES (:tenant_id, :name, :email, :password, :address)";
                    
                    if ($stmt = $conn->prepare($sql)) {
                        $stmt->bindParam(":tenant_id", $param_tenant_id, PDO::PARAM_INT);
                        $stmt->bindParam(":name", $param_name, PDO::PARAM_STR);
                        $stmt->bindParam(":email", $param_email, PDO::PARAM_STR);
                        $stmt->bindParam(":password", $param_password, PDO::PARAM_STR);
                        $stmt->bindParam(":address", $param_address, PDO::PARAM_STR);
                        
                        $param_tenant_id = $tenant_id;
                        $param_name = $name;
                        $param_email = $email;
                        $param_password = password_hash($password, PASSWORD_DEFAULT);
                        $param_address = $address;
                        
                        if ($stmt->execute()) {
                            // Notify all admins and front desk staff about new customer account
                            try {
                                notifyAdminsNewAccount($conn, $tenant_id, $name);
                            } catch (Exception $e) {
                                error_log("Error creating notification: " . $e->getMessage());
                            }
                            
                            header("location: index.php?role=tenant&success=Customer account created successfully. Please login.");
                            exit();
                        } else {
                            echo "Oops! Something went wrong. Please try again later.";
                        }
                        unset($stmt);
                    }
                } else {
                    echo "Oops! Something went wrong. Please try again later.";
                }
                unset($stmt);
            }
        }
    }
    
    unset($conn);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BAMINT - Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }
        .register-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 450px;
            padding: 2rem;
        }
        .register-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .register-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        .register-header p {
            color: #6c757d;
            margin-bottom: 0;
        }
        .role-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid #e9ecef;
        }
        .role-tab {
            flex: 1;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            border: none;
            background: none;
            font-size: 1rem;
            font-weight: 500;
            color: #6c757d;
            transition: all 0.3s ease;
            position: relative;
        }
        .role-tab.active {
            color: #667eea;
        }
        .role-tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background: #667eea;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-label {
            font-weight: 500;
            color: #333;
            margin-bottom: 0.5rem;
        }
        .form-control {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 0.75rem;
            font-size: 1rem;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-register {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 0.75rem;
            font-weight: 600;
            width: 100%;
            border-radius: 5px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
            color: white;
        }
        .text-danger {
            color: #dc3545;
            font-size: 0.9rem;
            margin-top: 0.25rem;
            display: block;
        }
        .link-login {
            text-align: center;
            margin-top: 1.5rem;
        }
        .link-login a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        .link-login a:hover {
            text-decoration: underline;
        }
        .form-section {
            display: none;
        }
        .form-section.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <h1><i class="bi bi-door-open"></i> BAMINT</h1>
            <p>Create your account</p>
        </div>

        <!-- Customer Registration (admin account creation removed) -->

        <!-- Tenant Registration Form -->
        <form id="tenantForm" class="form-section <?php echo $role === 'tenant' ? 'active' : ''; ?>" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <input type="hidden" name="role" value="tenant">

            <div class="form-group <?php echo (!empty($name_err)) ? 'has-error' : ''; ?>">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($name); ?>" placeholder="Enter your full name">
                <?php if (!empty($name_err)): ?>
                    <span class="text-danger"><?php echo htmlspecialchars($name_err); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group <?php echo (!empty($email_err)) ? 'has-error' : ''; ?>">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" placeholder="Enter your email">
                <?php if (!empty($email_err)): ?>
                    <span class="text-danger"><?php echo htmlspecialchars($email_err); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group <?php echo (!empty($phone_err)) ? 'has-error' : ''; ?>">
                <label class="form-label">Phone Number</label>
                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($phone); ?>" placeholder="Enter your phone number">
                <?php if (!empty($phone_err)): ?>
                    <span class="text-danger"><?php echo htmlspecialchars($phone_err); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Address</label>
                <textarea name="address" class="form-control" rows="2" placeholder="Street address, city, region"><?php echo htmlspecialchars($address); ?></textarea>
            </div>

            <div class="form-group <?php echo (!empty($password_err)) ? 'has-error' : ''; ?>">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="At least 6 characters">
                <?php if (!empty($password_err)): ?>
                    <span class="text-danger"><?php echo htmlspecialchars($password_err); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group <?php echo (!empty($confirm_password_err)) ? 'has-error' : ''; ?>">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password">
                <?php if (!empty($confirm_password_err)): ?>
                    <span class="text-danger"><?php echo htmlspecialchars($confirm_password_err); ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-register">Register as Customer</button>

            <div class="link-login">
                <p>Already have an account? <a href="index.php?role=tenant">Customer login</a> or <a href="index.php?role=admin">Admin login</a></p>
            </div>
        </form>
    </div>
</body>
</html>
</body>
</html>