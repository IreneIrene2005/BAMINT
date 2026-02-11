<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
    <div class="position-sticky pt-3">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'tenant_dashboard.php') ? 'active' : ''; ?>" href="tenant_dashboard.php">
                    <i class="bi bi-house-door"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'tenant_bills.php') ? 'active' : ''; ?>" href="tenant_bills.php">
                    <i class="bi bi-receipt"></i>
                    My Bills
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'tenant_payments.php') ? 'active' : ''; ?>" href="tenant_payments.php">
                    <i class="bi bi-coin"></i>
                    Payments
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'tenant_maintenance.php') ? 'active' : ''; ?>" href="tenant_maintenance.php">
                    <i class="bi bi-lightbulb"></i>
                    Amenities
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'tenant_add_room.php') ? 'active' : ''; ?>" href="tenant_add_room.php">
                    <i class="bi bi-plus-square"></i>
                    Add Room
                </a>
            </li>
            <li class="nav-item border-top mt-3 pt-3">
                <a class="nav-link text-danger" href="logout.php">
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>
            </li>
        </ul>
    </div>
</nav>
