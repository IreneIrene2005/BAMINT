<?php
require_once "database.php";

try {
    // Create room_requests table
    $sql = "
        CREATE TABLE IF NOT EXISTS `room_requests` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `tenant_id` int(11) NOT NULL,
          `room_id` int(11) NOT NULL,
          `request_date` timestamp DEFAULT CURRENT_TIMESTAMP,
          `status` varchar(50) NOT NULL DEFAULT 'pending',
          `notes` text,
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `tenant_id` (`tenant_id`),
          KEY `room_id` (`room_id`),
          KEY `status` (`status`),
          KEY `request_date` (`request_date`),
          CONSTRAINT `room_requests_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
          CONSTRAINT `room_requests_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    
    $conn->exec($sql);
    echo "<h2 style='color: green;'>✓ Table 'room_requests' created successfully!</h2>";
    echo "<br><h3>Table Structure:</h3>";
    echo "<pre>";
    echo htmlspecialchars($sql);
    echo "</pre>";
    
} catch(PDOException $e) {
    echo "<h2 style='color: red;'>Error: " . $e->getMessage() . "</h2>";
}
?>
