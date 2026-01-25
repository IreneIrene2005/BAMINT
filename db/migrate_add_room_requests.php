<?php
require_once "database.php";

try {
    // Check if room_requests table already exists
    $result = $conn->query("SHOW TABLES LIKE 'room_requests'");
    
    if ($result->rowCount() > 0) {
        echo "✓ room_requests table already exists.<br>";
    } else {
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
        echo "✓ room_requests table created successfully!<br>";
    }
    
    echo "<br><strong>Migration completed successfully!</strong>";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
