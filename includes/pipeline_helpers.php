<?php

require_once __DIR__ . '/../api/config.php';

/**
 * Pipeline stages aligned to Recruitment Pipeline diagram.
 */
function pipeline_stage_labels(): array {
    return [
        'new' => 'New Lead',
        'assigned' => 'Assigned to Recruiter',
        'contacted' => 'Contacted',
        'interested' => 'Interested',
        'callback' => 'Callback',
        'outreach_phone' => 'Phone Outreach',
        'outreach_whatsapp_call' => 'WhatsApp Call',
        'outreach_whatsapp_msg' => 'WhatsApp Message',
        'interview_scheduled' => 'Interview Scheduled',
        'not_appeared' => 'Not Appeared (Reception)',
        'receptionist' => 'At Reception (Slot Assigned)',
        'interview_conducted' => 'Interview Conducted',
        'selected' => 'Selected',
        'pending' => 'Pending (HR)',
        'rejected' => 'Rejected',
        'hr_passed' => 'HR Passed → Final Interview',
        'hr_rejected' => 'HR Rejected',
        'gm_passed' => 'Final Interview Passed',
        'gm_rejected' => 'Final Interview Rejected',
        'hired' => 'Selected for Training',
        'training' => 'In Training',
        'deployed' => 'Deployed',
        'mock_rejected' => 'Mock Rejected',
        'left' => 'Left',
    ];
}

function pipeline_stage_badge_class(string $stage): string {
    $s = canonical_stage($stage);
    if (in_array($s, ['deployed', 'selected', 'hr_passed', 'gm_passed', 'hired', 'training', 'interview_scheduled', 'receptionist'], true)) {
        return 'positive';
    }
    if (in_array($s, ['rejected', 'hr_rejected', 'gm_rejected', 'not_appeared', 'mock_rejected', 'left'], true)) {
        return 'negative';
    }
    if (in_array($s, ['pending', 'callback', 'interested'], true)) {
        return 'warning';
    }
    return 'neutral';
}

function pipeline_stage_label(string $stage): string {
    $labels = pipeline_stage_labels();
    $s = canonical_stage($stage);
    return $labels[$s] ?? ucfirst(str_replace('_', ' ', $s));
}

/**
 * Transition graph from recruitment pipeline diagram.
 */
function pipeline_transition_allowed(string $from, string $to): bool {
    $from = canonical_stage($from);
    $to = canonical_stage($to);
    if ($from === $to || $from === '' || $to === '') {
        return true;
    }

    $allowed = [
        'new' => ['assigned', 'contacted', 'interested', 'callback', 'outreach_phone', 'outreach_whatsapp_call', 'outreach_whatsapp_msg', 'interview_scheduled', 'rejected'],
        'assigned' => ['contacted', 'interested', 'callback', 'outreach_phone', 'outreach_whatsapp_call', 'outreach_whatsapp_msg', 'interview_scheduled', 'rejected'],
        'contacted' => ['interested', 'callback', 'outreach_phone', 'outreach_whatsapp_call', 'outreach_whatsapp_msg', 'interview_scheduled', 'rejected'],
        'interested' => ['interview_scheduled', 'rejected'],
        'callback' => ['interview_scheduled', 'rejected'],
        'outreach_phone' => ['interview_scheduled', 'rejected'],
        'outreach_whatsapp_call' => ['interview_scheduled', 'rejected'],
        'outreach_whatsapp_msg' => ['interview_scheduled', 'rejected'],
        'interview_scheduled' => ['receptionist', 'not_appeared', 'interview_conducted', 'rejected'],
        'receptionist' => ['interview_conducted', 'not_appeared', 'rejected'],
        'not_appeared' => ['interview_scheduled', 'rejected'],
        'interview_conducted' => ['selected', 'pending', 'hr_rejected', 'rejected'],
        'selected' => ['hr_passed', 'hired', 'training', 'rejected'],
        'pending' => ['selected', 'hr_passed', 'hired', 'training', 'hr_rejected', 'rejected'],
        'hr_passed' => ['gm_passed', 'gm_rejected', 'hired', 'training', 'rejected'],
        'hr_rejected' => ['rejected'],
        'gm_passed' => ['hired', 'training', 'rejected'],
        'gm_rejected' => ['rejected'],
        'hired' => ['training', 'not_appeared', 'rejected'],
        'training' => ['deployed', 'mock_rejected', 'left', 'rejected'],
        'deployed' => [],
        'mock_rejected' => [],
        'left' => [],
        'rejected' => [],
    ];

    if (!isset($allowed[$from])) {
        return true;
    }
    return in_array($to, $allowed[$from], true);
}

function pipeline_update_lead_stage(mysqli $conn, int $lead_id, string $new_stage, string $action, ?string $remark = null, ?string $user_role = null): array {
    $new_stage = canonical_stage($new_stage);
    $stmt = $conn->prepare('SELECT id, current_stage FROM leads WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $lead_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return ['ok' => false, 'error' => 'Lead not found'];
    }

    $old_stage = canonical_stage((string)$row['current_stage']);
    if (!pipeline_transition_allowed($old_stage, $new_stage)) {
        return ['ok' => false, 'error' => "Invalid transition: {$old_stage} -> {$new_stage}"];
    }

    $user_id = function_exists('getCurrentUserId') ? (int)getCurrentUserId() : 0;
    $user_name = function_exists('getCurrentUserName') ? getCurrentUserName() : 'System';
    $role = $user_role ?: 'System';
    $branch = function_exists('get_active_company_branch') ? get_active_company_branch() : 'main';

    $upd = $conn->prepare('UPDATE leads SET current_stage = ?, company_branch = ?, updated_at = NOW() WHERE id = ?');
    $upd->bind_param('ssi', $new_stage, $branch, $lead_id);
    $upd->execute();

    $audit = $conn->prepare('INSERT INTO lead_audit (lead_id, user_id, user_name, action, old_value, new_value, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $notes = $remark ?: 'Stage updated';
    $audit->bind_param('iisssss', $lead_id, $user_id, $user_name, $action, $old_stage, $new_stage, $notes);
    $audit->execute();

    if ($remark) {
        $rem = $conn->prepare('INSERT INTO lead_remarks (lead_id, added_by, added_by_name, added_by_role, remark, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $rem->bind_param('iisss', $lead_id, $user_id, $user_name, $role, $remark);
        $rem->execute();
    }

    return ['ok' => true, 'old_stage' => $old_stage, 'new_stage' => $new_stage, 'lead_id' => $lead_id];
}

function pipeline_get_lead_timeline(mysqli $conn, int $lead_id): array {
    $events = [];

    $audit = $conn->prepare("
        SELECT user_name, action, old_value, new_value, notes, created_at
        FROM lead_audit
        WHERE lead_id = ?
        ORDER BY created_at ASC
    ");
    $audit->bind_param('i', $lead_id);
    $audit->execute();
    $res = $audit->get_result();
    while ($row = $res->fetch_assoc()) {
        $label = pipeline_stage_label($row['new_value'] ?: $row['old_value']);
        $events[] = [
            'type' => 'audit',
            'title' => $row['action'] . ($label ? " ({$label})" : ''),
            'detail' => $row['notes'] ?: '',
            'by' => $row['user_name'] ?: 'System',
            'at' => $row['created_at'],
        ];
    }

    $remarks = $conn->prepare("
        SELECT added_by_name, added_by_role, remark, created_at
        FROM lead_remarks
        WHERE lead_id = ?
        ORDER BY created_at ASC
    ");
    $remarks->bind_param('i', $lead_id);
    $remarks->execute();
    $rres = $remarks->get_result();
    while ($row = $rres->fetch_assoc()) {
        $events[] = [
            'type' => 'remark',
            'title' => 'Remark',
            'detail' => $row['remark'],
            'by' => ($row['added_by_name'] ?: 'System') . ' (' . ($row['added_by_role'] ?: '') . ')',
            'at' => $row['created_at'],
        ];
    }

    $intv = $conn->prepare("
        SELECT scheduled_date, scheduled_time, location, interviewer_name, status, created_at
        FROM interviews
        WHERE lead_id = ?
        ORDER BY created_at DESC
    ");
    $intv->bind_param('i', $lead_id);
    $intv->execute();
    $ires = $intv->get_result();
    while ($row = $ires->fetch_assoc()) {
        $events[] = [
            'type' => 'interview',
            'title' => 'Interview scheduled',
            'detail' => trim(($row['scheduled_date'] ?? '') . ' ' . ($row['scheduled_time'] ?? '') . ' @ ' . ($row['location'] ?? 'Main Office')),
            'by' => $row['interviewer_name'] ?? 'Recruiter',
            'at' => $row['created_at'],
        ];
    }

    usort($events, fn($a, $b) => strtotime($a['at']) <=> strtotime($b['at']));
    return $events;
}

function pipeline_normalize_phone(?string $phone): string {
    return preg_replace('/\D+/', '', (string)$phone);
}
