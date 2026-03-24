<?php
session_start();

// If user is already logged in, redirect to appropriate dashboard
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    if ($_SESSION["role"] === "admin") {
        header("location: dashboard.php");
    } else if ($_SESSION["role"] === "front_desk") {
        header("location: front_desk_dashboard.php");
    } else {
        header("location: tenant_dashboard.php");
    }
    exit;
}

require_once "db_pdo.php";

// Alias $pdo as $conn for compatibility
$conn = $pdo;

$email = $username = $identifier = $password = "";
$email_err = $username_err = $identifier_err = $password_err = $login_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // unified login: admin/front_desk/tenant
    if (empty(trim($_POST["identifier"]))) {
        $identifier_err = "Please enter username or email.";
    } else {
        $identifier = trim($_POST["identifier"]);
    }

    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }

    if (empty($identifier_err) && empty($password_err)) {
        // First try admin/front_desk by username
        $sql = "SELECT id, username, password, role FROM admins WHERE username = :identifier LIMIT 1";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bindParam(":identifier", $identifier, PDO::PARAM_STR);
            if ($stmt->execute()) {
                if ($stmt->rowCount() == 1 && ($row = $stmt->fetch())) {
                    $id = $row["id"];
                    $hashed_password = $row["password"];
                    $user_role = $row["role"] ?? 'admin';

                    if (password_verify($password, $hashed_password)) {
                        $_SESSION["loggedin"] = true;
                        $_SESSION["admin_id"] = $id;
                        $_SESSION["username"] = $identifier;
                        $_SESSION["role"] = $user_role;

                        if ($user_role === 'admin') {
                            header("location: dashboard.php");
                        } else if ($user_role === 'front_desk') {
                            header("location: front_desk_dashboard.php");
                        } else {
                            // fallback
                            header("location: index.php");
                        }
                        exit;
                    } else {
                        $password_err = "The password you entered was not valid.";
                    }
                } else {
                    // not found in admins, try tenant accounts by email
                    // No PDOStatement::close() in PDO. Just move to tenant query.
                    $sqlTenant = "SELECT ta.id, ta.tenant_id, ta.password, COALESCE(ta.name, t.name) AS name FROM tenant_accounts ta 
                        LEFT JOIN tenants t ON ta.tenant_id = t.id 
                        WHERE ta.email = :identifier LIMIT 1";
                    if ($stmtTenant = $conn->prepare($sqlTenant)) {
                        $stmtTenant->bindParam(":identifier", $identifier, PDO::PARAM_STR);
                        if ($stmtTenant->execute()) {
                            if ($stmtTenant->rowCount() == 1 && ($rowTenant = $stmtTenant->fetch())) {
                                $id = $rowTenant["id"];
                                $tenant_id = $rowTenant["tenant_id"];
                                $name = $rowTenant["name"];
                                $hashed_password = $rowTenant["password"];

                                if (password_verify($password, $hashed_password)) {
                                    $_SESSION["loggedin"] = true;
                                    $_SESSION["id"] = $id;
                                    $_SESSION["tenant_id"] = $tenant_id;
                                    $_SESSION["name"] = $name;
                                    $_SESSION["email"] = $identifier;
                                    $_SESSION["role"] = "tenant";

                                    header("location: tenant_dashboard.php");
                                    exit;
                                } else {
                                    $password_err = "The password you entered was not valid.";
                                }
                            } else {
                                $login_err = "No account found with that username/email.";
                            }
                        } else {
                            $login_err = "Oops! Something went wrong. Please try again later.";
                        }
                        unset($stmtTenant);
                    }
                }
            } else {
                $login_err = "Oops! Something went wrong. Please try again later.";
            }
            unset($stmt);
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
    <title>BAMINT - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 450px;
            padding: 2rem;
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        .login-header p {
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
        .btn-login {
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
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
            color: white;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 0.75rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border: 1px solid #f5c6cb;
        }
        .text-danger {
            color: #dc3545;
            font-size: 0.9rem;
            margin-top: 0.25rem;
            display: block;
        }
        .link-register {
            text-align: center;
            margin-top: 1.5rem;
        }
        .link-register a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        .link-register a:hover {
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
    <div class="login-container">
        <div class="login-header">
            <h1><i class="bi bi-door-open"></i> BAMINT</h1>
            <p>Hotel Online Booking System</p>
        </div>

        <?php if (!empty($login_err)): ?>
            <div class="error-message"><?php echo htmlspecialchars($login_err); ?></div>
        <?php endif; ?>

        <!-- Unified Login Form -->
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group <?php echo (!empty($identifier_err)) ? 'has-error' : ''; ?>">
                <label class="form-label">Username or Email</label>
                <input type="text" name="identifier" class="form-control" value="<?php echo htmlspecialchars($identifier); ?>" placeholder="Enter your username or email">
                <?php if (!empty($identifier_err)): ?>
                    <span class="text-danger"><?php echo htmlspecialchars($identifier_err); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group <?php echo (!empty($password_err)) ? 'has-error' : ''; ?>">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Enter your password">
                <?php if (!empty($password_err)): ?>
                    <span class="text-danger"><?php echo htmlspecialchars($password_err); ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-login">Login</button>

            <div class="link-register">
                <p>Don't have an account? <a href="register.php?role=tenant">Create customer account</a></p>
            </div>
        </form>
    </div>

    <script>
        function switchRole(role) {
            // Update tab styles
            document.querySelectorAll('.role-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector(`[data-role="${role}"]`).classList.add('active');

            // Show/hide forms
            document.querySelectorAll('.form-section').forEach(form => {
                form.classList.remove('active');
            });
            // Handle front_desk form id which uses underscore
            const formId = role === 'front_desk' ? 'front_deskForm' : role + 'Form';
            document.getElementById(formId).classList.add('active');
        }
    </script>
</body>
</html>