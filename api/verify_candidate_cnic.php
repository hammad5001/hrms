<?php
// api/verify_candidate_cnic.php - Cross-branch candidate CNIC verification & past interview audit history
require_once __DIR__ . '/config.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

$req_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($req_method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;
} else {
    $input = $_GET;
}

$cnic_raw = trim((string)($input['cnic'] ?? ''));
$phone_raw = trim((string)($input['phone'] ?? ''));
$current_lead_id = intval($input['lead_id'] ?? 0);
$current_branch = get_active_company_branch();

$cnic_digits = preg_replace('/[^0-9]/', '', $cnic_raw);
$phone_digits = preg_replace('/[^0-9]/', '', $phone_raw);

if (empty($cnic_digits) && empty($phone_digits) && $current_lead_id <= 0) {
    respond(false, null, 'CNIC, Phone or Lead ID is required');
}

// If lead_id provided without CNIC, fetch CNIC first
if (empty($cnic_digits) && $current_lead_id > 0) {
    $st = $conn->prepare("SELECT cnic, phone FROM leads WHERE id = ? LIMIT 1");
    if ($st) {
        $st->bind_param('i', $current_lead_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row) {
            $cnic_digits = preg_replace('/[^0-9]/', '', (string)$row['cnic']);
            if (empty($phone_digits)) {
                $phone_digits = preg_replace('/[^0-9]/', '', (string)$row['phone']);
            }
        }
    }
}

if (empty($cnic_digits) && empty($phone_digits)) {
    respond(true, [
        'has_history' => false,
        'has_rejection' => false,
        'records_count' => 0,
        'summary' => 'No prior records found',
        'history' => []
    ]);
}

// Build query to search cross-branch across all leads
$where_clauses = [];
$params = [];
$types = '';

if (!empty($cnic_digits)) {
    $where_clauses[] = "(REPLACE(REPLACE(l.cnic, '-', ''), ' ', '') = ? OR l.cnic = ?)";
    $types .= 'ss';
    $params[] = $cnic_digits;
    $params[] = $cnic_raw;
}

if (!empty($phone_digits) && strlen($phone_digits) >= 10) {
    $where_clauses[] = "(REPLACE(REPLACE(l.phone, '-', ''), ' ', '') = ? OR l.phone LIKE ?)";
    $types .= 'ss';
    $params[] = $phone_digits;
    $params[] = '%' . substr($phone_digits, -10);
}

$where_sql = implode(' OR ', $where_clauses);
$sql = "
    SELECT 
        l.id, l.full_name, l.father_name, l.phone, l.email, l.cnic, l.city, l.dob,
        l.education, l.position_applied, l.referred_by, l.source,
        l.current_stage, l.rejection_reason AS lead_rejection_reason,
        l.company_branch, l.interview_date, l.created_at, l.updated_at,
        u.full_name AS recruiter_name,
        i.scheduled_date, i.scheduled_time, i.interviewer_name, i.location AS interview_location
    FROM leads l
    LEFT JOIN users u ON l.assigned_recruiter_id = u.id
    LEFT JOIN interviews i ON i.lead_id = l.id
    WHERE ($where_sql)
    ORDER BY l.created_at DESC
    LIMIT 20
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(false, null, 'Database query preparation failed: ' . $conn->error);
}

if (!empty($types) && !empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$history = [];
$has_rejection = false;
$rejection_summaries = [];
$branches_encountered = [];

$rejection_stages = [
    'hr_rejected', 'gm_rejected', 'rejected', 'mock_rejected', 'left', 'not_appeared'
];

while ($lead = $result->fetch_assoc()) {
    $lead_id = (int)$lead['id'];
    $is_current = ($current_lead_id > 0 && $lead_id === $current_lead_id);
    
    $stage = canonical_stage((string)$lead['current_stage']);
    $branch_key = normalize_company_branch($lead['company_branch'] ?: 'main');
    $branch_label = company_branch_label($branch_key);
    $branches_encountered[$branch_label] = true;
    
    $is_lead_rejected = in_array($stage, $rejection_stages, true) || !empty($lead['lead_rejection_reason']);
    if ($is_lead_rejected && !$is_current) {
        $has_rejection = true;
    }
    
    // Fetch Remarks for this lead
    $remarks_list = [];
    $rem_stmt = $conn->prepare("
        SELECT id, added_by_name, added_by_role, remark, created_at
        FROM lead_remarks
        WHERE lead_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    if ($rem_stmt) {
        $rem_stmt->bind_param('i', $lead_id);
        $rem_stmt->execute();
        $rem_res = $rem_stmt->get_result();
        while ($r = $rem_res->fetch_assoc()) {
            $remarks_list[] = [
                'text' => $r['remark'],
                'by' => $r['added_by_name'] ?: 'System',
                'role' => $r['added_by_role'] ?: '',
                'date' => $r['created_at']
            ];
        }
    }
    
    // Fetch HR Interview record if exists
    $hr_record = null;
    $hr_stmt = $conn->prepare("
        SELECT h.result, h.rejection_reason, h.remarks, h.interview_date, h.completed_at, u.full_name AS interviewer
        FROM hr_interviews h
        LEFT JOIN users u ON h.interviewer_id = u.id
        WHERE h.lead_id = ?
        ORDER BY h.id DESC
        LIMIT 1
    ");
    if ($hr_stmt) {
        $hr_stmt->bind_param('i', $lead_id);
        $hr_stmt->execute();
        $hr_r = $hr_stmt->get_result()->fetch_assoc();
        if ($hr_r) {
            $hr_record = [
                'result' => $hr_r['result'],
                'rejection_reason' => $hr_r['rejection_reason'],
                'remarks' => $hr_r['remarks'],
                'interview_date' => $hr_r['interview_date'],
                'interviewer' => $hr_r['interviewer'] ?: 'HR'
            ];
            if ($hr_r['result'] === 'rejected' && !$is_current) {
                $has_rejection = true;
            }
        }
    }
    
    // Fetch GM Interview record if exists
    $gm_record = null;
    $gm_stmt = $conn->prepare("
        SELECT g.result, g.rejection_reason, g.remarks, g.interview_date, g.completed_at, u.full_name AS interviewer
        FROM gm_interviews g
        LEFT JOIN users u ON g.interviewer_id = u.id
        WHERE g.lead_id = ?
        ORDER BY g.id DESC
        LIMIT 1
    ");
    if ($gm_stmt) {
        $gm_stmt->bind_param('i', $lead_id);
        $gm_stmt->execute();
        $gm_r = $gm_stmt->get_result()->fetch_assoc();
        if ($gm_r) {
            $gm_record = [
                'result' => $gm_r['result'],
                'rejection_reason' => $gm_r['rejection_reason'],
                'remarks' => $gm_r['remarks'],
                'interview_date' => $gm_r['interview_date'],
                'interviewer' => $gm_r['interviewer'] ?: 'Management'
            ];
            if ($gm_r['result'] === 'rejected' && !$is_current) {
                $has_rejection = true;
            }
        }
    }
    
    $rejection_reason_text = $lead['lead_rejection_reason'] ?: ($hr_record['rejection_reason'] ?? ($gm_record['rejection_reason'] ?? ''));
    
    // Friendly status display
    $status_title = match($stage) {
        'hr_rejected' => 'HR Rejected',
        'gm_rejected' => 'Management (GM) Rejected',
        'rejected' => 'Rejected',
        'mock_rejected' => 'Training Mock Rejected',
        'hr_passed' => 'HR Passed',
        'gm_passed' => 'GM Passed',
        'selected' => 'Selected / Training',
        'training' => 'In Training',
        'hired' => 'Hired',
        'deployed' => 'Deployed',
        'not_appeared' => 'No Show / Not Appeared',
        'interview_scheduled' => 'Interview Scheduled',
        'receptionist' => 'Checked In at Reception',
        'interview_conducted' => 'Interview Conducted',
        default => ucfirst(str_replace('_', ' ', $stage))
    };
    
    $applied_date = $lead['created_at'] ? date('d-M-Y', strtotime($lead['created_at'])) : 'N/A';
    
    if ($is_lead_rejected && !$is_current) {
        $reason_snippet = $rejection_reason_text ? " (Reason: $rejection_reason_text)" : '';
        $rejection_summaries[] = "$status_title at $branch_label on $applied_date$reason_snippet";
    }
    
    $history[] = [
        'lead_id' => $lead_id,
        'is_current_record' => $is_current,
        'full_name' => $lead['full_name'],
        'father_name' => $lead['father_name'],
        'phone' => $lead['phone'],
        'email' => $lead['email'],
        'cnic' => $lead['cnic'],
        'position' => $lead['position_applied'],
        'branch_key' => $branch_key,
        'branch_label' => $branch_label,
        'is_different_branch' => ($branch_key !== normalize_company_branch($current_branch)),
        'stage' => $stage,
        'status_title' => $status_title,
        'is_rejected' => $is_lead_rejected,
        'rejection_reason' => $rejection_reason_text,
        'applied_date' => $applied_date,
        'created_at' => $lead['created_at'],
        'recruiter_name' => $lead['recruiter_name'] ?: 'N/A',
        'hr_interview' => $hr_record,
        'gm_interview' => $gm_record,
        'remarks' => $remarks_list
    ];
}

$total_records = count($history);
$other_records_count = 0;
foreach ($history as $h) {
    if (!$h['is_current_record']) {
        $other_records_count++;
    }
}

$has_history = ($other_records_count > 0);
$summary_text = 'No prior history across other branches';
$alert_level = 'none'; // 'danger', 'warning', 'info', 'none'

if ($has_rejection) {
    $alert_level = 'danger';
    $summary_text = '⚠️ Previously Rejected: ' . implode(' | ', array_slice($rejection_summaries, 0, 2));
} elseif ($has_history) {
    $alert_level = 'warning';
    $summary_text = "ℹ️ Candidate applied previously in " . count($branches_encountered) . " branch(es)";
}

respond(true, [
    'has_history' => $has_history,
    'has_rejection' => $has_rejection,
    'alert_level' => $alert_level,
    'total_records' => $total_records,
    'other_records_count' => $other_records_count,
    'summary' => $summary_text,
    'branches' => array_keys($branches_encountered),
    'history' => $history
]);
