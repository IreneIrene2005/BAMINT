<?php
require_once 'db/database.php';
try {
    $name = 'irene';
    $stmt = $conn->prepare("SELECT * FROM tenants WHERE name = :name");
    $stmt->execute(['name' => $name]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "TENANT:\n";
    print_r($tenant);

    if ($tenant) {
        $tenant_id = $tenant['id'];
        $bstmt = $conn->prepare("SELECT * FROM bills WHERE tenant_id = :tid ORDER BY id DESC");
        $bstmt->execute(['tid' => $tenant_id]);
        $bills = $bstmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nBILLS:\n";
        print_r($bills);

        $pstmt = $conn->prepare("SELECT * FROM payment_transactions WHERE tenant_id = :tid ORDER BY id DESC");
        $pstmt->execute(['tid' => $tenant_id]);
        $payments = $pstmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nPAYMENTS:\n";
        print_r($payments);

        $rrstmt = $conn->prepare("SELECT * FROM room_requests WHERE tenant_id = :tid ORDER BY id DESC");
        $rrstmt->execute(['tid' => $tenant_id]);
        $rr = $rrstmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nROOM_REQUESTS:\n";
        print_r($rr);
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>