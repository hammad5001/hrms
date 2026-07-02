<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config.php';
require_once '../includes/db_schema.php';
ensure_app_schema($conn);

$action = $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$biometric_id = $_SESSION['employee_code'] ?? '';

if ($action === 'load_dashboard') {
    // 1. Get QA Stats for this month
    $qa_sales = 0;
    $qa_rejected = 0;
    $qa_transfers = 0;

    if (!empty($biometric_id)) {
        // We sum up the QA stats for the current month
        $stmt = $conn->prepare("SELECT SUM(sales) as s, SUM(rejected) as r, SUM(transfers) as t FROM qa_performance_stats WHERE biometric_id = ? AND MONTH(report_date) = MONTH(CURRENT_DATE()) AND YEAR(report_date) = YEAR(CURRENT_DATE())");
        $stmt->bind_param("s", $biometric_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $qa_sales = (int)$row['s'];
            $qa_rejected = (int)$row['r'];
            $qa_transfers = (int)$row['t'];
        }
        $stmt->close();
    }

    // 2. Get today's logged transfers
    $today_transfers = [];
    $stmt = $conn->prepare("SELECT customer_number, customer_name, transfer_on, created_at FROM agent_daily_transfers WHERE user_id = ? AND DATE(created_at) = CURRENT_DATE() ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $today_transfers[] = $row;
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'data' => [
            'biometric_id' => $biometric_id,
            'qa_stats' => [
                'sales' => $qa_sales,
                'rejected' => $qa_rejected,
                'transfers' => $qa_transfers
            ],
            'today_transfers' => $today_transfers
        ]
    ]);
    exit;
}

if ($action === 'load_analytics') {
    $qa_sales = 0; $qa_rejected = 0; $qa_transfers = 0;
    if (!empty($biometric_id)) {
        $stmt = $conn->prepare("SELECT SUM(sales) as s, SUM(rejected) as r, SUM(transfers) as t FROM qa_performance_stats WHERE biometric_id = ? AND MONTH(report_date) = MONTH(CURRENT_DATE()) AND YEAR(report_date) = YEAR(CURRENT_DATE())");
        $stmt->bind_param("s", $biometric_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $qa_sales = (int)$row['s'];
            $qa_rejected = (int)$row['r'];
            $qa_transfers = (int)$row['t'];
        }
        $stmt->close();
    }

    $history = [];
    if (!empty($biometric_id)) {
        $stmt = $conn->prepare("SELECT report_date as date, sales, rejected, transfers FROM qa_performance_stats WHERE biometric_id = ? AND report_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY) ORDER BY report_date ASC");
        $stmt->bind_param("s", $biometric_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $history[] = $row;
        }
        $stmt->close();
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'qa_stats' => [
                'sales' => $qa_sales,
                'rejected' => $qa_rejected,
                'transfers' => $qa_transfers
            ],
            'history' => $history
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
