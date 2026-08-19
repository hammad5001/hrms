<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config.php';
require_once '../includes/db_schema.php';


function getGoogleSheetEnvValue($key) {
    $paths = [
        __DIR__ . '/../.env',
        __DIR__ . '/../backend/.env'
    ];

    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim(str_replace('\\_', '_', $line));

            if (strpos($line, $key . '=') === 0) {
                $value = trim(substr($line, strlen($key) + 1));
                $value = trim($value, "\"'");

                if ($key === 'GOOGLE_SHEET_WEBHOOK_URL') {
                    if (preg_match('/https:\/\/script\.google\.com\/macros\/s\/[^)\]\s]+\/exec/', $value, $m)) {
                        return $m[0];
                    }
                }

                return str_replace('\\_', '_', $value);
            }
        }
    }

    return '';
}

function syncTransferToGoogleSheet($transfer) {
    $url = getenv('GOOGLE_SHEET_WEBHOOK_URL') ?: getGoogleSheetEnvValue('GOOGLE_SHEET_WEBHOOK_URL');
    $secret = getenv('GOOGLE_SHEET_SECRET') ?: getGoogleSheetEnvValue('GOOGLE_SHEET_SECRET');

    $url = trim(str_replace('\\_', '_', $url));
    $secret = trim(str_replace('\\_', '_', $secret));

    if (preg_match('/https:\/\/script\.google\.com\/macros\/s\/[^)\]\s]+\/exec/', $url, $m)) {
        $url = $m[0];
    }

    if (empty($url) || empty($secret)) {
        return [
            'success' => false,
            'message' => 'Google Sheet URL or secret missing'
        ];
    }

    $payload = [
        'secret' => $secret,
        'action' => 'create_transfer',
        'transfer_id' => 'HRMS-' . $transfer['id'],
        'phone_number' => (string)($transfer['customer_number'] ?? ''),
        'line' => (string)($transfer['transfer_on'] ?? ''),
        'team' => (string)($transfer['team_name'] ?? ''),
        'hrms_real_name' => (string)($transfer['verifier_real_name'] ?? ''),
        'pseudo' => (string)($transfer['agent_pseudo'] ?? ''),
        'state' => (string)($transfer['customer_state'] ?? ''),
        'customer_first_name' => (string)($transfer['customer_first_name'] ?? ''),
        'customer_last_name' => (string)($transfer['customer_last_name'] ?? ''),
        'zipcode' => (string)($transfer['customer_zip'] ?? ''),
        'age' => (string)($transfer['customer_age'] ?? ''),
        'qa_status' => 'pending'
    ];

    $json = json_encode($payload);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 15
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        error_log('Google Sheet sync failed curl error: ' . $error);
        return [
            'success' => false,
            'message' => $error,
            'raw' => $response
        ];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 400) {
        error_log('Google Sheet sync HTTP error: ' . $httpCode . ' Response: ' . $response);
        return [
            'success' => false,
            'message' => 'HTTP ' . $httpCode,
            'raw' => $response
        ];
    }

    if (is_array($decoded) && !empty($decoded['success'])) {
        return [
            'success' => true,
            'message' => $decoded['message'] ?? 'synced',
            'raw' => $response
        ];
    }

    error_log('Google Sheet sync bad response: ' . $response);

    return [
        'success' => false,
        'message' => 'Bad Google Sheet response',
        'raw' => $response
    ];
}


// Ensure tables exist
// Production: schema migrations are run manually during deployment.

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
    $inserted_id = (int)$conn->insert_id;
    $google_sheet_transfer_id = 'HRMS-' . $inserted_id;

    $syncResult = syncTransferToGoogleSheet([
        'id' => $inserted_id,
        'customer_number' => $customer_number,
        'customer_zip' => $customer_zip,
        'customer_first_name' => $customer_first_name,
        'customer_last_name' => $customer_last_name,
        'customer_state' => $customer_state,
        'customer_age' => $customer_age,
        'transfer_on' => $transfer_on,
        'verifier_real_name' => $verifier_real_name,
        'agent_pseudo' => $agent_pseudo,
        'team_name' => $team_name
    ]);

    $qa_sync_status = !empty($syncResult['success']) ? 'synced' : 'failed';

    $updateStmt = $conn->prepare(
        "UPDATE agent_daily_transfers
         SET google_sheet_transfer_id = ?, qa_sync_status = ?
         WHERE id = ?"
    );

    if ($updateStmt) {
        $updateStmt->bind_param("ssi", $google_sheet_transfer_id, $qa_sync_status, $inserted_id);
        $updateStmt->execute();
        $updateStmt->close();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Transfer reported successfully!',
        'id' => $inserted_id,
        'google_sheet_transfer_id' => $google_sheet_transfer_id,
        'google_sheet_sync' => $qa_sync_status
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
}

$stmt->close();
?>
