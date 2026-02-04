<?php
// db_connect.php
// Simple MySQLi connection for BAMINT

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bamint";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
