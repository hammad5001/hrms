<?php
// includes/sheets_mirror_helper.php - Production & Local Two-Way Sync Engine for Candidate Status & Remarks
require_once __DIR__ . '/../api/config.php';

function sheets_mirror_targets(): array {
    return [
        'https://script.google.com/macros/s/AKfycbzCQLaK7VTxKcPGGpY3TeNBmfks-YjWQR-mJyZRYjLZAMZVs9y0CmvzI-JJKP-XaflRgg/exec',
        'https://script.google.com/macros/s/AKfycbwwrodkRzdbcCTy1G8zRwGl5lSTBXJ5Ar-MmWMvLuXgAZOcJ2snOVdn3IMc4xKnhSMk1w/exec',
    ];
}

function get_website_api_config(): array {
    return [
        'endpoint' => 'https://balitech.org/api/leads/status-update',
        'token'    => 'btk_crm_mWl9wKuxqfd5YwRgfd1ws6FvwbjVvRi3rtx7wdTm5Po'
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
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

/**
 * Pushes live status, remarks, and rejection reason directly to balitech.org website intake system
 */
function sync_status_to_website(array $candidate, string $event = 'update'): void {
    if (!function_exists('curl_init')) {
        return;
    }
    
    $cfg = get_website_api_config();
    $stage = canonical_stage((string)($candidate['status'] ?? $candidate['current_stage'] ?? ''));
    $is_rejected = in_array($stage, ['hr_rejected', 'gm_rejected', 'rejected', 'mock_rejected', 'not_appeared'], true) || !empty($candidate['rejection_reason']);
    
    $payload = [
        'event' => $event,
        'external_id' => $candidate['external_lead_id'] ?? $candidate['externalId'] ?? null,
        'candidate_id' => $candidate['id'] ?? $candidate['lead_id'] ?? null,
        'cnic' => $candidate['cnic'] ?? '',
        'phone' => preg_replace('/\D+/', '', (string)($candidate['phone'] ?? '')),
        'full_name' => $candidate['fullName'] ?? $candidate['full_name'] ?? '',
        'position' => $candidate['position'] ?? $candidate['position_applied'] ?? '',
        'current_stage' => $stage,
        'is_rejected' => $is_rejected,
        'rejection_reason' => $candidate['rejection_reason'] ?? '',
        'remarks' => $candidate['remark'] ?? $candidate['remarks'] ?? '',
        'updated_by' => getCurrentUserName() ?: 'HR Manager',
        'branch' => $candidate['company_branch'] ?? ($_SESSION['company_branch'] ?? 'main'),
        'updated_at' => date('c')
    ];

    $ch = curl_init($cfg['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['token']
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function mirror_candidate_update_to_sheets(array $candidate, string $event = 'update'): void {
    $stage = canonical_stage((string)($candidate['status'] ?? $candidate['current_stage'] ?? 'pending'));
    $rejection_reason = $candidate['rejection_reason'] ?? '';
    
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
        'status' => $stage,
        'rejectionReason' => $rejection_reason,
        'remarks' => $candidate['remark'] ?? $candidate['remarks'] ?? '',
        'updatedAt' => date('c'),
        'branch' => $candidate['company_branch'] ?? ($_SESSION['company_branch'] ?? 'main'),
    ];
    
    // 1. Sync to Google Sheets Engine
    sheets_mirror_post($payload);
    
    // 2. Direct Sync to Website Live Intake API
    sync_status_to_website($candidate, $event);
}
