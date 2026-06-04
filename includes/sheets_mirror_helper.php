<?php

function sheets_mirror_targets(): array {
    return [
        'https://script.google.com/macros/s/AKfycbzCQLaK7VTxKcPGGpY3TeNBmfks-YjWQR-mJyZRYjLZAMZVs9y0CmvzI-JJKP-XaflRgg/exec',
        'https://script.google.com/macros/s/AKfycbwwrodkRzdbcCTy1G8zRwGl5lSTBXJ5Ar-MmWMvLuXgAZOcJ2snOVdn3IMc4xKnhSMk1w/exec',
    ];
}

function sheets_mirror_post(array $payload): void {
    if (!function_exists('curl_init')) {
        return;
    }
    foreach (sheets_mirror_targets() as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

function mirror_candidate_update_to_sheets(array $candidate, string $event = 'update'): void {
    $payload = [
        'action' => 'upsertCandidate',
        'event' => $event,
        'fullName' => $candidate['fullName'] ?? $candidate['full_name'] ?? '',
        'fatherName' => $candidate['fatherName'] ?? $candidate['father_name'] ?? '',
        'phone' => preg_replace('/\D+/', '', (string)($candidate['phone'] ?? '')),
        'email' => $candidate['email'] ?? '',
        'cnic' => $candidate['cnic'] ?? '',
        'city' => $candidate['city'] ?? '',
        'dob' => $candidate['dob'] ?? '',
        'graduation' => $candidate['graduation'] ?? $candidate['education'] ?? '',
        'position' => $candidate['position'] ?? $candidate['position_applied'] ?? '',
        'referredBy' => $candidate['referredBy'] ?? $candidate['referred_by'] ?? '',
        'status' => $candidate['status'] ?? $candidate['current_stage'] ?? 'pending',
        'updatedAt' => date('c'),
        'branch' => $candidate['company_branch'] ?? ($_SESSION['company_branch'] ?? 'main'),
    ];
    sheets_mirror_post($payload);
}

