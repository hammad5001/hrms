<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once '../config.php';

$user_id = (int)$_SESSION['user_id'];
$emp_code = $_SESSION['employee_code'] ?? '';

// If employee code is missing, get it from DB
if (empty($emp_code)) {
    $stmt = $conn->prepare("SELECT employee_code FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $emp_code = $row['employee_code'];
        $_SESSION['employee_code'] = $emp_code;
    }
    $stmt->close();
}

if (empty($emp_code)) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

$stmt = $conn->prepare("SELECT id, month, year, gross_salary, net_salary, slip_data_json, created_at FROM employee_salary_slips WHERE employee_code = ? ORDER BY year DESC, created_at DESC");
$stmt->bind_param("s", $emp_code);
$stmt->execute();
$res = $stmt->get_result();

$slips = [];
while ($row = $res->fetch_assoc()) {
    $row['slip_data'] = json_decode($row['slip_data_json'], true);
    unset($row['slip_data_json']); // don't send raw string if not needed
    $slips[] = $row;
}

$stmt->close();

echo json_encode(['success' => true, 'data' => $slips]);
?>
