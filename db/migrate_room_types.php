<?php
/**
 * Migration: Update room types from "Suite" to "Bedspace"
 * Run this once to update existing data
 */

require_once "database.php";

try {
    // Update Suite to Bedspace
    $result = $conn->exec("UPDATE rooms SET room_type = 'Bedspace' WHERE LOWER(room_type) = 'suite'");
    echo "✓ Updated $result room(s) from 'Suite' to 'Bedspace'<br>";
    
    // Standardize room type capitalization
    $conn->exec("UPDATE rooms SET room_type = 'Single' WHERE LOWER(room_type) = 'single'");
    $conn->exec("UPDATE rooms SET room_type = 'Shared' WHERE LOWER(room_type) = 'shared'");
    $conn->exec("UPDATE rooms SET room_type = 'Bedspace' WHERE LOWER(room_type) = 'bedspace'");
    
    echo "✓ Standardized room type formatting<br>";
    echo "<br><strong>Migration completed successfully!</strong>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
