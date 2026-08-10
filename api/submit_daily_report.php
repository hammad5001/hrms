<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config.php';
require_once '../includes/db_schema.php';

// Ensure tables exist
ensure_app_schema($conn);

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$customer_number    = trim($input['customer_number']    ?? '');
$customer_zip       = trim($input['customer_zip']       ?? '');
$customer_first_name = trim($input['customer_first_name'] ?? '');
$customer_last_name  = trim($input['customer_last_name']  ?? '');
$customer_name      = trim($input['customer_name']      ?? '');
if (empty($customer_name) && (!empty($customer_first_name) || !empty($customer_last_name))) {
    $customer_name = trim($customer_first_name . ' ' . $customer_last_name);
}
$customer_state     = trim($input['customer_state']     ?? '');
$customer_age       = trim($input['customer_age']       ?? '');
$transfer_on        = trim($input['transfer_on']        ?? 'D1');
$verifier_real_name = trim($input['verifier_real_name'] ?? '');
$agent_pseudo       = trim($input['agent_pseudo']       ?? '');
$team_name          = trim($input['team_name']          ?? '');
$call_notes         = trim($input['call_notes']         ?? '');
$call_duration_mins = max(0, (int)($input['call_duration_mins'] ?? 0));
$offline_uuid       = trim($input['offline_uuid']       ?? '');
$is_offline_sync    = !empty($offline_uuid) ? 1 : 0;

if (empty($customer_number) || empty($transfer_on)) {
    echo json_encode(['success' => false, 'message' => 'Customer Number and Transfer Line/Option are required fields.']);
    exit;
}

$user_id      = (int)$_SESSION['user_id'];
$biometric_id = $_SESSION['employee_code'] ?? '';
$company_branch = $_SESSION['company_branch'] ?? 'main';

// Auto-detect Real Name from HRMS user account
$user_real_name = $_SESSION['full_name'] ?? '';
if (empty($user_real_name)) {
    $uStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
    $uStmt->bind_param("i", $user_id);
    $uStmt->execute();
    if ($uRow = $uStmt->get_result()->fetch_assoc()) {
        $user_real_name = $uRow['full_name'];
    }
    $uStmt->close();
}
$verifier_real_name = !empty($user_real_name) ? $user_real_name : trim($input['verifier_real_name'] ?? '');

if (empty($biometric_id)) {
    echo json_encode(['success' => false, 'message' => 'Error: Your account does not have a Biometric ID (DID) assigned. Please contact HR.']);
    exit;
}

// If offline_uuid provided, check for duplicate (idempotent sync)
if (!empty($offline_uuid)) {
    $checkStmt = $conn->prepare("SELECT id FROM agent_daily_transfers WHERE offline_uuid = ? LIMIT 1");
    $checkStmt->bind_param('s', $offline_uuid);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows > 0) {
        $checkStmt->close();
        echo json_encode(['success' => true, 'message' => 'Already synced (duplicate skipped).', 'duplicate' => true]);
        exit;
    }
    $checkStmt->close();
}

$offline_uuid_val = !empty($offline_uuid) ? $offline_uuid : null;

$stmt = $conn->prepare(
    "INSERT INTO agent_daily_transfers
     (user_id, biometric_id, customer_number, customer_zip, customer_name, customer_first_name, customer_last_name,
      customer_state, customer_age, verifier_real_name, agent_pseudo, team_name, transfer_on, call_notes,
      call_duration_mins, is_offline_sync, offline_uuid, company_branch)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    "isssssssssssssisis",
    $user_id,
    $biometric_id,
    $customer_number,
    $customer_zip,
    $customer_name,
    $customer_first_name,
    $customer_last_name,
    $customer_state,
    $customer_age,
    $verifier_real_name,
    $agent_pseudo,
    $team_name,
    $transfer_on,
    $call_notes,
    $call_duration_mins,
    $is_offline_sync,
    $offline_uuid_val,
    $company_branch
);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Transfer reported successfully!', 'id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
}

$stmt->close();
?>
