<?php
// db_pdo.php
// PDO connection for BAMINT (for notifications)

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bamint";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("PDO Connection failed: " . $e->getMessage());
}
?>
