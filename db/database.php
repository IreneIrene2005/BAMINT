<?php
/**
 * Database Connection File - db/database.php
 * Provides $conn variable for database operations
 * Uses PDO for compatibility with modern PHP practices
 */

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bamint";

try {
    // Create PDO connection
    $conn = new PDO(
        "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    
    // Set error handling mode
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
