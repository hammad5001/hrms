<?php
session_start();
header('Content-Type: application/json');

require_once '../config.php';
require_once '../includes/db_schema.php';

$SECRET = 'Balitech_QA_Transfer_Secret_2026_987';

function send_json($success, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success], $data));
    exit;
}

function normalize_datetime($value) {
    $value = trim((string)$value);
    if ($value === '') return date('Y-m-d H:i:s');

    $ts = strtotime($value);
    if (!$ts) return date('Y-m-d H:i:s');

    return date('Y-m-d H:i:s', $ts);
}

function normalize_qa_status($value) {
    $value = strtolower(trim((string)$value));

    if (in_array($value, ['accepted', 'approved', 'approve', 'pass'])) {
        return 'approved';
    }

    if (in_array($value, ['rejected', 'reject', 'failed', 'invalid'])) {
        return 'rejected';
    }

    return 'pending';
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload) {
    send_json(false, ['message' => 'Invalid JSON'], 400);
}

if (($payload['secret'] ?? '') !== $SECRET) {
    send_json(false, ['message' => 'Invalid secret'], 403);
}

$records = $payload['records'] ?? [];

if (!is_array($records) || count($records) === 0) {
    send_json(false, ['message' => 'No records provided'], 400);
}

global $conn;
if (!isset($conn) || !$conn) {
    send_json(false, ['message' => 'Database connection not available'], 500);
}

$inserted = 0;
$updated = 0;
$skipped = 0;
$unmapped = [];

foreach ($records as $r) {
    $lead_id = trim((string)($r['lead_id'] ?? $r['id'] ?? ''));

    if ($lead_id === '') {
        $skipped++;
        continue;
    }

    $agent_raw = trim((string)($r['dialer_agent_name'] ?? $r['agent'] ?? $r['agent_name'] ?? ''));
    $agent_code = trim((string)($r['dialer_agent_code'] ?? $r['agent_code'] ?? ''));

    if ($agent_code === '' && preg_match('/\((\d+)\)/', $agent_raw, $m)) {
        $agent_code = $m[1];
    }

    $hrms_user_id = null;
    $employee_code = null;

    if ($agent_code !== '') {
        $mapStmt = $conn->prepare("
            SELECT hrms_user_id, employee_code
            FROM dialer_agent_map
            WHERE dialer_agent_code = ?
              AND is_active = 1
            LIMIT 1
        ");
        $mapStmt->bind_param("s", $agent_code);
        $mapStmt->execute();
        $mapRes = $mapStmt->get_result();
        $map = $mapRes->fetch_assoc();
        $mapStmt->close();

        if ($map) {
            $hrms_user_id = $map['hrms_user_id'];
            $employee_code = $map['employee_code'];
        }
    }

    if (!$hrms_user_id) {
        $unmapped[] = [
            'lead_id' => $lead_id,
            'agent_code' => $agent_code,
            'agent_name' => $agent_raw
        ];
    }

    $phone_number = trim((string)($r['phone_number'] ?? $r['phone'] ?? ''));
    $customer_name = trim((string)($r['customer_name'] ?? $r['name'] ?? ''));
    $team = trim((string)($r['team'] ?? ''));

    $disposition = strtoupper(trim((string)($r['disposition'] ?? $r['status'] ?? '')));
    $qa_status = normalize_qa_status($r['qa_status'] ?? 'pending');
    $qa_notes = trim((string)($r['qa_notes'] ?? $r['notes'] ?? ''));

    $last_call_at = normalize_datetime($r['last_call_at'] ?? $r['last_call'] ?? '');
    $raw_payload = json_encode($r, JSON_UNESCAPED_UNICODE);

    $checkStmt = $conn->prepare("SELECT id FROM dialer_daily_transfers WHERE lead_id = ? LIMIT 1");
    $checkStmt->bind_param("s", $lead_id);
    $checkStmt->execute();
    $checkRes = $checkStmt->get_result();
    $exists = $checkRes->fetch_assoc();
    $checkStmt->close();

    $stmt = $conn->prepare("
        INSERT INTO dialer_daily_transfers
        (
            lead_id,
            hrms_user_id,
            employee_code,
            dialer_agent_code,
            dialer_agent_name,
            phone_number,
            customer_name,
            team,
            disposition,
            qa_status,
            qa_notes,
            last_call_at,
            raw_payload
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            hrms_user_id = VALUES(hrms_user_id),
            employee_code = VALUES(employee_code),
            dialer_agent_code = VALUES(dialer_agent_code),
            dialer_agent_name = VALUES(dialer_agent_name),
            phone_number = VALUES(phone_number),
            customer_name = VALUES(customer_name),
            team = VALUES(team),
            disposition = VALUES(disposition),
            qa_status = VALUES(qa_status),
            qa_notes = VALUES(qa_notes),
            last_call_at = VALUES(last_call_at),
            raw_payload = VALUES(raw_payload),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->bind_param(
        "sssssssssssss",
        $lead_id,
        $hrms_user_id,
        $employee_code,
        $agent_code,
        $agent_raw,
        $phone_number,
        $customer_name,
        $team,
        $disposition,
        $qa_status,
        $qa_notes,
        $last_call_at,
        $raw_payload
    );

    $stmt->execute();
    $stmt->close();

    if ($exists) {
        $updated++;
    } else {
        $inserted++;
    }
}

send_json(true, [
    'message' => 'Dialer transfers synced successfully',
    'inserted' => $inserted,
    'updated' => $updated,
    'skipped' => $skipped,
    'unmapped_count' => count($unmapped),
    'unmapped' => $unmapped
]);
