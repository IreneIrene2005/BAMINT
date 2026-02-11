<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
    <div class="position-sticky pt-3">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'dashboard.php' || basename($_SERVER['PHP_SELF']) === 'front_desk_dashboard.php') ? 'active' : ''; ?>" href="<?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'front_desk') ? 'front_desk_dashboard.php' : 'dashboard.php'; ?>">
                    <i class="bi bi-house-door"></i>
                    Dashboard
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
                    Customers
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
                    <i class="bi bi-gift"></i>
                    <?php echo (isset($_SESSION['role']) && ( $_SESSION['role'] === 'front_desk' || $_SESSION['role'] === 'admin')) ? 'Extra Amenities' : 'Resident List'; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'bills.php') ? 'active' : ''; ?>" href="bills.php">
                    <i class="bi bi-receipt"></i>
                    Bills & Billing
                </a>
            </li>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'payment_history.php') ? 'active' : ''; ?>" href="payment_history.php">
                    <i class="bi bi-coin"></i>
                    Payment History
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'admin_maintenance_queue.php') ? 'active' : ''; ?>" href="admin_maintenance_queue.php">
                    <i class="bi bi-tools"></i>
                    <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'front_desk') ? 'Amenities Queue' : 'Amenities Request Queue'; ?>
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
                    <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'front_desk') ? 'Front Desk Check-in & Check-out' : 'Check-in & Check-out'; ?>
                </a>
            </li>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'maintenance_history.php') ? 'active' : ''; ?>" href="maintenance_history.php">
                    <i class="bi bi-clock-history"></i>
                    Extra Amenities History
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'admin_user_management.php') ? 'active' : ''; ?>" href="admin_user_management.php">
                    <i class="bi bi-shield-lock"></i>
                    User Management
                </a>
            </li>
            <?php endif; ?>
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