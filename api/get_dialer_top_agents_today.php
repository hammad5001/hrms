<?php
session_start();
header('Content-Type: application/json');

require_once '../config.php';
require_once '../includes/db_schema.php';
require_once '../includes/dialer_report_timezone.php';

global $conn;

function send_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!isset($conn) || !$conn) {
    send_json(['success' => false, 'message' => 'Database connection not available'], 500);
}

$secret = $_GET['secret'] ?? '';
$validSecret = hash_equals('Balitech_QA_Transfer_Secret_2026_987', $secret);

if (!$validSecret && !isset($_SESSION['user_id'])) {
    send_json(['success' => false, 'message' => 'Unauthorized'], 401);
}

$range = get_dialer_usa_range('today');
$from = $range['from'];
$to = $range['to'];

$agents = [];

$stmt = $conn->prepare("
    SELECT
        MAX(d.hrms_user_id) AS hrms_user_id,
        COALESCE(MAX(u.employee_code), MAX(d.employee_code), MAX(d.dialer_agent_code)) AS employee_code,
        COALESCE(MAX(u.full_name), MAX(d.dialer_agent_name), 'Unknown Agent') AS agent_name,
        COUNT(*) AS total_transfers,
        SUM(d.disposition = 'D4') AS d4_total,
        SUM(d.disposition = 'D5') AS d5_total
    FROM dialer_daily_transfers d
    LEFT JOIN users u ON u.id = d.hrms_user_id
    WHERE d.last_call_at BETWEEN ? AND ?
      AND d.dialer_agent_code IS NOT NULL
      AND TRIM(d.dialer_agent_code) <> ''
    GROUP BY d.dialer_agent_code
    ORDER BY total_transfers DESC, agent_name ASC
    LIMIT 5
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $agents[] = [
        'hrms_user_id' => (int)$row['hrms_user_id'],
        'employee_code' => $row['employee_code'],
        'agent_name' => $row['agent_name'],
        'total_transfers' => (int)$row['total_transfers'],
        'd4_total' => (int)$row['d4_total'],
        'd5_total' => (int)$row['d5_total']
    ];
}
$stmt->close();

send_json([
    'success' => true,
    'dialer_timezone' => $range['timezone'],
    'usa_today' => $range['usa_today'],
    'from' => $from,
    'to' => $to,
    'agents' => $agents
]);
