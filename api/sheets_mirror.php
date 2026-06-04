<?php
require_once __DIR__ . '/config.php';

if (!isAuthenticated()) {
    respond(false, null, 'Unauthorized');
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

function sheet_mirror_targets(): array {
    return [
        'https://script.google.com/macros/s/AKfycbzCQLaK7VTxKcPGGpY3TeNBmfks-YjWQR-mJyZRYjLZAMZVs9y0CmvzI-JJKP-XaflRgg/exec',
        'https://script.google.com/macros/s/AKfycbwwrodkRzdbcCTy1G8zRwGl5lSTBXJ5Ar-MmWMvLuXgAZOcJ2snOVdn3IMc4xKnhSMk1w/exec',
    ];
}

function map_candidate_payload(array $candidate, string $event): array {
    return [
        'action' => 'upsertCandidate',
        'event' => $event,
        'fullName' => $candidate['fullName'] ?? $candidate['candidateName'] ?? '',
        'fatherName' => $candidate['fatherName'] ?? '',
        'phone' => preg_replace('/\D+/', '', (string)($candidate['phone'] ?? '')),
        'email' => $candidate['email'] ?? '',
        'cnic' => $candidate['cnic'] ?? '',
        'city' => $candidate['city'] ?? '',
        'dob' => $candidate['dob'] ?? '',
        'graduation' => $candidate['graduation'] ?? '',
        'position' => $candidate['position'] ?? '',
        'referredBy' => $candidate['referredBy'] ?? '',
        'status' => canonical_stage((string)($candidate['status'] ?? $candidate['new_stage'] ?? 'pending')),
        'interviewLevel' => $candidate['interviewLevel'] ?? 'hr',
        'hrStatus' => $candidate['hrStatus'] ?? 'pending',
        'gmStatus' => $candidate['gmStatus'] ?? 'pending',
        'trainingStatus' => $candidate['trainingStatus'] ?? 'pending',
        'timestamp' => date('c'),
        'updatedBy' => getCurrentUserName(),
        'branch' => get_active_company_branch(),
    ];
}

function post_json(string $url, array $payload): bool {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $out = curl_exec($ch);
    $ok = !curl_errno($ch);
    curl_close($ch);
    return $ok;
}

switch ($action) {
    case 'pushCandidate':
        $candidate = $input['candidate'] ?? [];
        $event = trim((string)($input['event'] ?? 'update'));
        if (!is_array($candidate)) {
            respond(false, null, 'Invalid candidate payload');
        }
        $payload = map_candidate_payload($candidate, $event);
        $targets = sheet_mirror_targets();
        $sent = 0;
        foreach ($targets as $url) {
            if (post_json($url, $payload)) {
                $sent++;
            }
        }
        respond(true, ['targets' => count($targets), 'sent' => $sent]);
        break;

    default:
        respond(false, null, 'Invalid action');
}

