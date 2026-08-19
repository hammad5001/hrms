<?php
header('Content-Type: application/json');

require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$apiSecret = 'Balitech_QA_Transfer_Secret_2026_987';

if (trim((string)($input['secret'] ?? '')) !== $apiSecret) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$transferId = trim((string)($input['transfer_id'] ?? ''));

if ($transferId === '') {
    echo json_encode(['success' => false, 'message' => 'transfer_id is required']);
    exit;
}

$rawStatus = strtolower(trim((string)($input['qa_status'] ?? 'pending')));
$rawStatus = str_replace(' ', '_', $rawStatus);

$statusMap = [
    'accepted' => 'approved',
    'accept' => 'approved',
    'approved' => 'approved',
    'approve' => 'approved',

    'rejected' => 'rejected',
    'reject' => 'rejected',
    'invalid' => 'rejected',

    'pending' => 'pending',
    '' => 'pending',

    'coaching' => 'coaching_required',
    'coaching_required' => 'coaching_required'
];

$qaStatus = $statusMap[$rawStatus] ?? $rawStatus;

$qaScore = trim((string)($input['qa_score'] ?? ''));
$qaNotes = trim((string)($input['qa_notes'] ?? ''));
$evaluatedBy = trim((string)($input['evaluated_by'] ?? ''));

$evaluatedAtRaw = trim((string)($input['evaluated_at'] ?? ''));
if ($evaluatedAtRaw === '') {
    $evaluatedAt = date('Y-m-d H:i:s');
} else {
    $ts = strtotime($evaluatedAtRaw);
    $evaluatedAt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

$numericId = 0;

if (preg_match('/^HRMS-(\d+)$/', $transferId, $m)) {
    $numericId = (int)$m[1];
} elseif (ctype_digit($transferId)) {
    $numericId = (int)$transferId;
}

$findStmt = $conn->prepare(
    "SELECT id
     FROM agent_daily_transfers
     WHERE google_sheet_transfer_id = ?
        OR id = ?
     LIMIT 1"
);

if (!$findStmt) {
    echo json_encode(['success' => false, 'message' => 'Find prepare failed: ' . $conn->error]);
    exit;
}

$findStmt->bind_param("si", $transferId, $numericId);
$findStmt->execute();
$res = $findStmt->get_result();
$row = $res->fetch_assoc();
$findStmt->close();

if (!$row) {
    echo json_encode([
        'success' => false,
        'message' => 'Transfer not found in HRMS',
        'transfer_id' => $transferId
    ]);
    exit;
}

$dbId = (int)$row['id'];

$updateStmt = $conn->prepare(
    "UPDATE agent_daily_transfers
     SET qa_status = ?,
         qa_score = NULLIF(?, ''),
         qa_notes = ?,
         qa_evaluated_by = ?,
         qa_evaluated_at = ?,
         google_sheet_transfer_id = ?,
         qa_sync_status = 'synced'
     WHERE id = ?"
);

if (!$updateStmt) {
    echo json_encode(['success' => false, 'message' => 'Update prepare failed: ' . $conn->error]);
    exit;
}

$updateStmt->bind_param(
    "ssssssi",
    $qaStatus,
    $qaScore,
    $qaNotes,
    $evaluatedBy,
    $evaluatedAt,
    $transferId,
    $dbId
);

$ok = $updateStmt->execute();
$error = $updateStmt->error;
$updateStmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $error]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'HRMS QA status updated',
    'transfer_id' => $transferId,
    'hrms_id' => $dbId,
    'qa_status' => $qaStatus
]);
