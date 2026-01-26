<?php
/**
 * Migration: Add payment method system with online proof and verification
 * - Add payment_type: 'online' or 'cash'
 * - Add payment_status: 'pending', 'verified', 'approved'
 * - Add proof_of_payment: stores file path for online payments
 * - Add verified_by: admin ID who verified
 * - Add verification_date: when payment was verified
 */

require_once "database.php";

try {
    // Check if payment_type column exists
    $result = $conn->query("SHOW COLUMNS FROM payment_transactions LIKE 'payment_type'");
    $column_exists = $result->rowCount() > 0;

    if (!$column_exists) {
        // Add payment system columns
        $conn->exec("ALTER TABLE payment_transactions ADD COLUMN payment_type VARCHAR(50) DEFAULT 'cash' AFTER payment_method");
        $conn->exec("ALTER TABLE payment_transactions ADD COLUMN payment_status VARCHAR(50) DEFAULT 'approved' AFTER payment_type");
        $conn->exec("ALTER TABLE payment_transactions ADD COLUMN proof_of_payment VARCHAR(255) AFTER payment_status");
        $conn->exec("ALTER TABLE payment_transactions ADD COLUMN verified_by INT AFTER proof_of_payment");
        $conn->exec("ALTER TABLE payment_transactions ADD COLUMN verification_date DATETIME AFTER verified_by");
        echo "✓ Added payment system columns (payment_type, payment_status, proof_of_payment, verified_by, verification_date)<br>";
    } else {
        echo "✓ Payment system columns already exist<br>";
    }

    // Add foreign key for verified_by if not exists
    $result = $conn->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME='payment_transactions' AND COLUMN_NAME='verified_by'");
    if ($result->rowCount() == 0) {
        try {
            $conn->exec("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_ibfk_3 FOREIGN KEY (verified_by) REFERENCES admins (id) ON SET NULL");
            echo "✓ Added foreign key for verified_by<br>";
        } catch (Exception $e) {
            echo "⚠️ Foreign key may already exist<br>";
        }
    }

    // Create payment_proofs directory if it doesn't exist
    $upload_dir = __DIR__ . "/../public/payment_proofs";
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
        echo "✓ Created payment proofs directory<br>";
    }

    echo "<br><strong>Payment system migration completed successfully!</strong>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
