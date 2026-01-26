<?php
require_once "database.php";

try {
    // Create co_tenants table
    $sql = "CREATE TABLE IF NOT EXISTS `co_tenants` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `primary_tenant_id` int(11) NOT NULL,
      `room_id` int(11) NOT NULL,
      `name` varchar(255) NOT NULL,
      `email` varchar(255),
      `phone` varchar(20),
      `id_number` varchar(255),
      `address` text,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `primary_tenant_id` (`primary_tenant_id`),
      KEY `room_id` (`room_id`),
      CONSTRAINT `co_tenants_ibfk_1` FOREIGN KEY (`primary_tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
      CONSTRAINT `co_tenants_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $conn->exec($sql);
    echo "✅ co_tenants table created successfully!";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
