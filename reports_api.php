<?php
// reports_api.php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
require_once 'db/database.php';

$reportType = $_GET['reportType'] ?? 'bookings';
$period = $_GET['period'] ?? 'monthly';
$date = $_GET['date'] ?? date('Y-m-d');

try {
    $pdo = $conn; // db/database.php defines $conn as PDO

    // Helper to get month start/end
    function month_range($year, $month) {
        $start = date('Y-m-01', strtotime("$year-$month-01"));
        $end = date('Y-m-t', strtotime($start));
        return [$start, $end];
    }

    $labels = [];
    $data = [];

    if ($period === 'monthly' || $period === 'daily' || $period === 'yearly') {
        // determine labels based on period
        if ($period === 'monthly') {
            // last 6 months including current
            $dt = new DateTime($date);
            for ($i = 5; $i >= 0; $i--) {
                $m = clone $dt;
                $m->modify("-{$i} months");
                $labels[] = $m->format('M Y');
            }
        } elseif ($period === 'daily') {
            // last 7 days including today
            $dt = new DateTime($date);
            for ($i = 6; $i >= 0; $i--) {
                $d = clone $dt;
                $d->modify("-{$i} days");
                $labels[] = $d->format('Y-m-d');
            }
        } else { // yearly
            $dt = new DateTime($date);
            for ($i = 5; $i >= 0; $i--) {
                $y = (int)$dt->format('Y') - $i;
                $labels[] = (string)$y;
            }
        }

        foreach ($labels as $lab) {
            if ($period === 'monthly') {
                $d = DateTime::createFromFormat('M Y', $lab);
                $start = $d->format('Y-m-01');
                $end = $d->format('Y-m-t');
            } elseif ($period === 'daily') {
                $start = $lab;
                $end = $lab;
            } else { // yearly
                $start = $lab . '-01-01';
                $end = $lab . '-12-31';
            }

            if ($reportType === 'bookings') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM room_requests WHERE DATE(checkin_date) BETWEEN :start AND :end AND status IN ('approved','occupied')");
                $stmt->execute(['start' => $start, 'end' => $end]);
                $data[] = (int)$stmt->fetchColumn();
            } elseif ($reportType === 'revenue') {
                // overall revenue for the period
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM payment_transactions WHERE DATE(payment_date) BETWEEN :start AND :end");
                $stmt->execute(['start' => $start, 'end' => $end]);
                $data[] = (float)$stmt->fetchColumn();
            } elseif ($reportType === 'occupancy') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE (start_date <= :end) AND (end_date IS NULL OR end_date >= :start) AND status IN ('active','inactive')");
                $stmt->execute(['start' => $start, 'end' => $end]);
                $active = (int)$stmt->fetchColumn();
                $total = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
                $rate = $total > 0 ? round(($active / $total) * 100, 2) : 0;
                $data[] = $rate;
            } elseif ($reportType === 'payments') {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM payment_transactions WHERE DATE(payment_date) BETWEEN :start AND :end");
                $stmt->execute(['start' => $start, 'end' => $end]);
                $data[] = (float)$stmt->fetchColumn();
            }
        }

        echo json_encode(['success' => true, 'labels' => $labels, 'data' => $data]);
        exit;
    }

    // daily or yearly could be added similarly
    echo json_encode(['success' => false, 'message' => 'Unsupported period']);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
