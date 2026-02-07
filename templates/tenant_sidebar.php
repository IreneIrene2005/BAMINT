<?php
// Get current page name from PHP_SELF
$current_page = basename($_SERVER['PHP_SELF']);
?>

<nav class="col-md-3 col-lg-2 sidebar">
    <div class="position-sticky pt-3">
        <!-- User Info -->
        <div class="user-info">
            <h5><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION["name"]); ?></h5>
            <p><?php echo htmlspecialchars($_SESSION["email"]); ?></p>
        </div>

        <!-- Navigation -->
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'tenant_dashboard.php') ? 'active' : ''; ?>" href="tenant_dashboard.php">
                    <i class="bi bi-house-door"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'tenant_bills.php') ? 'active' : ''; ?>" href="tenant_bills.php">
                    <i class="bi bi-receipt"></i> My Bills
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'tenant_payments.php') ? 'active' : ''; ?>" href="tenant_payments.php">
                    <i class="bi bi-coin"></i> Payments
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'tenant_maintenance.php') ? 'active' : ''; ?>" href="tenant_maintenance.php">
                    <i class="bi bi-tools"></i> Maintenance
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'tenant_messages.php') ? 'active' : ''; ?>" href="tenant_messages.php">
                    <i class="bi bi-envelope"></i> Messages
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'tenant_add_room.php') ? 'active' : ''; ?>" href="tenant_add_room.php">
                    <i class="bi bi-plus-square"></i> Add Room
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'tenant_profile.php') ? 'active' : ''; ?>" href="tenant_profile.php">
                    <i class="bi bi-person"></i> My Profile
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page === 'tenant_archives.php') ? 'active' : ''; ?>" href="tenant_archives.php">
                    <i class="bi bi-archive"></i> Archives
                </a>
            </li>
        </ul>

        <!-- Logout Button -->
        <form action="logout.php" method="post">
            <button type="submit" class="btn btn-logout">
                <i class="bi bi-box-arrow-right"></i> Logout
            </button>
        </form>
    </div>
</nav>
