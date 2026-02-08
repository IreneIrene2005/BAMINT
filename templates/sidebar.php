<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
    <div class="position-sticky pt-3">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php">
                    <i class="bi bi-house-door"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'admin_reports.php') ? 'active' : ''; ?>" href="admin_reports.php">
                    <i class="bi bi-bar-chart"></i>
                    Reports & Analytics
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'admin_rooms.php') ? 'active' : ''; ?>" href="admin_rooms.php">
                    <i class="bi bi-building"></i>
                    Rooms
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'tenants.php') ? 'active' : ''; ?>" href="tenants.php">
                    <i class="bi bi-people"></i>
                    Tenants
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'admin_bookings.php') ? 'active' : ''; ?>" href="admin_bookings.php">
                    <i class="bi bi-x-circle"></i>
                    Cancellations
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'admin_resident_list.php') ? 'active' : ''; ?>" href="admin_resident_list.php">
                    <i class="bi bi-people-fill"></i>
                    Resident List
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'bills.php') ? 'active' : ''; ?>" href="bills.php">
                    <i class="bi bi-receipt"></i>
                    Bills & Billing
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'payment_history.php') ? 'active' : ''; ?>" href="payment_history.php">
                    <i class="bi bi-coin"></i>
                    Payment History
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'admin_maintenance_queue.php') ? 'active' : ''; ?>" href="admin_maintenance_queue.php">
                    <i class="bi bi-tools"></i>
                    Maintenance Queue
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'admin_additional_charges.php') ? 'active' : ''; ?>" href="admin_additional_charges.php">
                    <i class="bi bi-cart-plus"></i>
                    Additional Charges
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'room_requests_queue.php') ? 'active' : ''; ?>" href="room_requests_queue.php">
                    <i class="bi bi-door-closed"></i>
                    Room Requests Queue
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'admin_checkin_checkout.php') ? 'active' : ''; ?>" href="admin_checkin_checkout.php">
                    <i class="bi bi-door-open"></i>
                    Check-in & Check-out
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'maintenance_history.php') ? 'active' : ''; ?>" href="maintenance_history.php">
                    <i class="bi bi-clock-history"></i>
                    Maintenance History
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'admin_tenants.php') ? 'active' : ''; ?>" href="admin_tenants.php">
                    <i class="bi bi-person-vcard"></i>
                    Tenant Management
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'admin_user_management.php') ? 'active' : ''; ?>" href="admin_user_management.php">
                    <i class="bi bi-shield-lock"></i>
                    User Management
                </a>
            </li>
            <!-- Removed: Overdue Reminders, Payment verification, Record Cash Payment, secondary Reports & Analytics, and Check-in/Check-out per admin UI update -->
            <li class="nav-item border-top mt-3 pt-3">
                <a class="nav-link text-danger" href="logout.php">
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>
            </li>
        </ul>
    </div>
</nav>