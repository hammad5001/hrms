<?php
require_once __DIR__ . '/../config.php';

function getGoogleSheetEnvValueForQaSync($key) {
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

$url = getenv('GOOGLE_SHEET_WEBHOOK_URL') ?: getGoogleSheetEnvValueForQaSync('GOOGLE_SHEET_WEBHOOK_URL');
$secret = getenv('GOOGLE_SHEET_SECRET') ?: getGoogleSheetEnvValueForQaSync('GOOGLE_SHEET_SECRET');

$url = trim(str_replace('\\_', '_', $url));
$secret = trim(str_replace('\\_', '_', $secret));

if (preg_match('/https:\/\/script\.google\.com\/macros\/s\/[^)\]\s]+\/exec/', $url, $m)) {
    $url = $m[0];
}

if (!$url || !$secret) {
    echo "Google Sheet URL or secret missing\n";
    exit(1);
}

$reviewedUrl = $url . '?action=reviewed&secret=' . urlencode($secret);

$ch = curl_init($reviewedUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($error) {
    echo "Curl error: {$error}\n";
    exit(1);
}

if ($httpCode >= 400) {
    echo "HTTP error: {$httpCode}\n";
    echo $response . "\n";
    exit(1);
}

$decoded = json_decode($response, true);

if (!is_array($decoded) || empty($decoded['success'])) {
    echo "Invalid response:\n";
    echo $response . "\n";
    exit(1);
}

$rows = $decoded['data'] ?? [];
$updated = 0;

foreach ($rows as $row) {
    $transferId = trim((string)($row['transfer_id'] ?? ''));
    if ($transferId === '') {
        continue;
    }

    $rawQaStatus = trim((string)($row['qa_status'] ?? 'pending'));
    $statusKey = strtolower($rawQaStatus);

    $statusMap = [
        'accepted' => 'approved',
        'approve' => 'approved',
        'approved' => 'approved',
        'rejected' => 'rejected',
        'reject' => 'rejected',
        'pending' => 'pending',
        'coaching_required' => 'coaching_required',
        'coaching required' => 'coaching_required',
        'invalid' => 'rejected'
    ];

    $qaStatus = $statusMap[$statusKey] ?? $statusKey;
    $qaScore = trim((string)($row['qa_score'] ?? ''));
    $qaNotes = trim((string)($row['qa_notes'] ?? ''));
    $evaluatedBy = trim((string)($row['evaluated_by'] ?? ''));
    $evaluatedAt = trim((string)($row['evaluated_at'] ?? ''));

    if ($evaluatedAt === '') {
        $evaluatedAt = date('Y-m-d H:i:s');
    } else {
        $ts = strtotime($evaluatedAt);
        $evaluatedAt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }

    $numericId = 0;
    if (preg_match('/^HRMS-(\d+)$/', $transferId, $m)) {
        $numericId = (int)$m[1];
    }

    $stmt = $conn->prepare(
        "UPDATE agent_daily_transfers
         SET qa_status = ?,
             qa_score = NULLIF(?, ''),
             qa_notes = ?,
             qa_evaluated_by = ?,
             qa_evaluated_at = ?,
             google_sheet_transfer_id = ?,
             qa_sync_status = 'synced'
         WHERE google_sheet_transfer_id = ?
            OR id = ?"
    );

    if (!$stmt) {
        echo "Prepare failed: " . $conn->error . "\n";
        continue;
    }

    $stmt->bind_param(
        "sssssssi",
        $qaStatus,
        $qaScore,
        $qaNotes,
        $evaluatedBy,
        $evaluatedAt,
        $transferId,
        $transferId,
        $numericId
    );

    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $updated++;
    }

    $stmt->close();
}

echo "Reviewed rows from sheet: " . count($rows) . "\n";
echo "HRMS rows updated: " . $updated . "\n";
