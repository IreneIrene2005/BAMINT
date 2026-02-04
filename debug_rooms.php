<?php
require_once 'db_connect.php';
$result = $conn->query("SELECT * FROM rooms");
echo "<h2>Rooms Table Debug</h2><table border='1'><tr><th>ID</th><th>Room Number</th><th>Room Type</th><th>Status</th><th>Rate</th><th>Description</th><th>Image</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['room_number']) . "</td>";
    echo "<td>" . htmlspecialchars($row['room_type']) . "</td>";
    echo "<td>" . htmlspecialchars($row['status']) . "</td>";
    echo "<td>" . htmlspecialchars($row['rate']) . "</td>";
    echo "<td>" . htmlspecialchars($row['description']) . "</td>";
    echo "<td>" . htmlspecialchars($row['image']) . "</td>";
    echo "</tr>";
}
echo "</table>";
?>