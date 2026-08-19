<?php
session_start();
header('Content-Type: application/json');

require_once '../config.php';
require_once '../includes/db_schema.php';
require_once '../includes/dialer_report_timezone.php';

global $conn;

function send_dialer_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!isset($conn) || !$conn) {
    send_dialer_json(['success' => false, 'message' => 'Database connection not available'], 500);
}

$secret = $_GET['secret'] ?? $_POST['secret'] ?? '';
$validSecret = hash_equals('Balitech_QA_Transfer_Secret_2026_987', $secret);

if ($validSecret && isset($_GET['user_id'])) {
    $userId = (int)$_GET['user_id'];
} elseif (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
} else {
    send_dialer_json(['success' => false, 'message' => 'Unauthorized'], 401);
}

$period = $_GET['period'] ?? 'today';
$range = get_dialer_usa_range($period);
$from = $range['from'];
$to = $range['to'];

$summary = [
    'total_transfers' => 0,
    'approved' => 0,
    'rejected' => 0,
    'pending' => 0
];

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_transfers,
        SUM(qa_status = 'approved') AS approved,
        SUM(qa_status = 'rejected') AS rejected,
        SUM(qa_status = 'pending') AS pending
    FROM dialer_daily_transfers
    WHERE hrms_user_id = ?
      AND last_call_at BETWEEN ? AND ?
");
$stmt->bind_param("iss", $userId, $from, $to);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $summary = [
        'total_transfers' => (int)$row['total_transfers'],
        'approved' => (int)$row['approved'],
        'rejected' => (int)$row['rejected'],
        'pending' => (int)$row['pending']
    ];
}
$stmt->close();

$dispositions = [];
$stmt = $conn->prepare("
    SELECT disposition, COUNT(*) AS total
    FROM dialer_daily_transfers
    WHERE hrms_user_id = ?
      AND last_call_at BETWEEN ? AND ?
    GROUP BY disposition
    ORDER BY disposition
");
$stmt->bind_param("iss", $userId, $from, $to);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $dispositions[] = [
        'disposition' => $row['disposition'],
        'total' => (int)$row['total']
    ];
}
$stmt->close();

$rows = [];
$stmt = $conn->prepare("
    SELECT id, lead_id, phone_number, customer_name, team, disposition, qa_status, qa_notes, last_call_at
    FROM dialer_daily_transfers
    WHERE hrms_user_id = ?
      AND last_call_at BETWEEN ? AND ?
    ORDER BY last_call_at DESC, id DESC
");
$stmt->bind_param("iss", $userId, $from, $to);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

send_dialer_json([
    'success' => true,
    'period' => $period,
    'from' => $from,
    'to' => $to,
    'dialer_timezone' => $range['timezone'],
    'usa_today' => $range['usa_today'],
    'user_id' => $userId,
    'summary' => $summary,
    'dispositions' => $dispositions,
    'rows' => $rows
]);
