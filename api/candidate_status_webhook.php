<?php
// api/candidate_status_webhook.php - Two-way sync & Query API for Website / External Systems
require_once __DIR__ . '/config.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

$req_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = ($req_method === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: $_POST) : $_GET;

$action = trim((string)($input['action'] ?? 'get_status'));
$cnic_raw = trim((string)($input['cnic'] ?? ''));
$phone_raw = trim((string)($input['phone'] ?? ''));
$external_id = trim((string)($input['external_id'] ?? $input['lead_id'] ?? ''));

$cnic_digits = preg_replace('/[^0-9]/', '', $cnic_raw);
$phone_digits = preg_replace('/[^0-9]/', '', $phone_raw);

if (empty($cnic_digits) && empty($phone_digits) && empty($external_id)) {
    respond(false, null, 'Please provide CNIC, Phone, or External Lead ID');
}

// 1. Find Candidate Record
$where_clauses = [];
$params = [];
$types = '';

if (!empty($external_id)) {
    $where_clauses[] = "(l.external_lead_id = ? OR l.id = ?)";
    $types .= 'ss';
    $params[] = $external_id;
    $params[] = $external_id;
}

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
        l.id, l.external_lead_id, l.full_name, l.father_name, l.phone, l.email, l.cnic,
        l.city, l.education, l.position_applied, l.referred_by, l.source,
        l.company_branch, l.current_stage, l.rejection_reason,
        l.interview_date, l.hired_date, l.created_at, l.updated_at,
        u.full_name AS recruiter_name
    FROM leads l
    LEFT JOIN users u ON l.assigned_recruiter_id = u.id
    WHERE ($where_sql)
    ORDER BY l.id DESC
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(false, null, 'Database query failed: ' . $conn->error);
}

if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$lead = $stmt->get_result()->fetch_assoc();

if (!$lead) {
    respond(false, [
        'found' => false,
        'status' => 'not_found',
        'status_label' => 'No application found with this CNIC/Phone',
        'is_rejected' => false,
        'rejection_reason' => null
    ], 'Candidate record not found');
}

$lead_id = (int)$lead['id'];
$stage = canonical_stage((string)$lead['current_stage']);
$branch_label = company_branch_label(normalize_company_branch($lead['company_branch'] ?: 'main'));

// Fetch HR & GM Interview Results
$hr_interview = null;
$hr_st = $conn->prepare("SELECT result, rejection_reason, remarks, interview_date, completed_at FROM hr_interviews WHERE lead_id = ? ORDER BY id DESC LIMIT 1");
if ($hr_st) {
    $hr_st->bind_param('i', $lead_id);
    $hr_st->execute();
    $hr_interview = $hr_st->get_result()->fetch_assoc();
}

$gm_interview = null;
$gm_st = $conn->prepare("SELECT result, rejection_reason, remarks, interview_date, completed_at FROM gm_interviews WHERE lead_id = ? ORDER BY id DESC LIMIT 1");
if ($gm_st) {
    $gm_st->bind_param('i', $lead_id);
    $gm_st->execute();
    $gm_interview = $gm_st->get_result()->fetch_assoc();
}

// Fetch Latest Remarks
$remarks = [];
$rem_st = $conn->prepare("SELECT added_by_name, added_by_role, remark, created_at FROM lead_remarks WHERE lead_id = ? ORDER BY created_at DESC LIMIT 5");
if ($rem_st) {
    $rem_st->bind_param('i', $lead_id);
    $rem_st->execute();
    $rem_res = $rem_st->get_result();
    while ($r = $rem_res->fetch_assoc()) {
        $remarks[] = $r;
    }
}

// Determine clear status & rejection explanation
$is_rejected = in_array($stage, ['hr_rejected', 'gm_rejected', 'rejected', 'mock_rejected', 'not_appeared'], true) || !empty($lead['rejection_reason']);
$rejection_reason = $lead['rejection_reason'] ?: ($hr_interview['rejection_reason'] ?? ($gm_interview['rejection_reason'] ?? ''));

$status_message = '';
$decision = 'in_progress';

if ($stage === 'hr_rejected' || ($hr_interview && $hr_interview['result'] === 'rejected')) {
    $decision = 'hr_rejected';
    $status_message = "Application was evaluated and rejected by HR" . ($rejection_reason ? ": $rejection_reason" : ".");
} elseif ($stage === 'gm_rejected' || ($gm_interview && $gm_interview['result'] === 'rejected')) {
    $decision = 'management_rejected';
    $status_message = "Application was rejected in Final Management Interview" . ($rejection_reason ? ": $rejection_reason" : ".");
} elseif ($stage === 'rejected') {
    $decision = 'rejected';
    $status_message = "Application was rejected" . ($rejection_reason ? ": $rejection_reason" : ".");
} elseif ($stage === 'not_appeared') {
    $decision = 'no_show';
    $status_message = "Candidate did not appear for the scheduled interview.";
} elseif ($stage === 'hired' || $stage === 'deployed') {
    $decision = 'hired';
    $status_message = "Congratulations! Candidate has been hired & placed at {$branch_label}.";
} elseif ($stage === 'selected' || $stage === 'training') {
    $decision = 'selected';
    $status_message = "Candidate passed interview and is selected for Training Pipeline.";
} elseif ($stage === 'hr_passed' || $stage === 'gm_passed') {
    $decision = 'passed_interview';
    $status_message = "Candidate successfully cleared interview round.";
} elseif ($stage === 'receptionist') {
    $decision = 'at_reception';
    $status_message = "Candidate checked in at Reception Desk.";
} elseif ($stage === 'interview_scheduled') {
    $decision = 'scheduled';
    $status_message = "Interview has been scheduled. Please arrive at reception on time.";
} else {
    $decision = 'under_review';
    $status_message = "Application is currently under review by Recruiter / HR.";
}

// If caller is requesting push callback to website endpoint
if ($action === 'push_to_website' && !empty($input['callback_url'])) {
    $callback_url = $input['callback_url'];
    $payload = [
        'cnic' => $lead['cnic'],
        'phone' => $lead['phone'],
        'full_name' => $lead['full_name'],
        'external_lead_id' => $lead['external_lead_id'],
        'decision' => $decision,
        'current_stage' => $stage,
        'is_rejected' => $is_rejected,
        'rejection_reason' => $rejection_reason,
        'status_message' => $status_message,
        'branch' => $branch_label,
        'updated_at' => $lead['updated_at']
    ];
    
    $ch = curl_init($callback_url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $out = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    respond(true, [
        'pushed' => true,
        'http_code' => $http_code,
        'payload' => $payload
    ], 'Response pushed to website callback URL successfully');
}

respond(true, [
    'found' => true,
    'candidate_id' => $lead_id,
    'external_lead_id' => $lead['external_lead_id'],
    'full_name' => $lead['full_name'],
    'father_name' => $lead['father_name'],
    'cnic' => $lead['cnic'],
    'phone' => $lead['phone'],
    'position' => $lead['position_applied'],
    'branch' => $branch_label,
    'decision' => $decision,
    'current_stage' => $stage,
    'is_rejected' => $is_rejected,
    'rejection_reason' => $rejection_reason,
    'status_message' => $status_message,
    'hr_evaluation' => $hr_interview ? [
        'result' => $hr_interview['result'],
        'rejection_reason' => $hr_interview['rejection_reason'],
        'remarks' => $hr_interview['remarks'],
        'date' => $hr_interview['completed_at'] ?: $hr_interview['interview_date']
    ] : null,
    'latest_remarks' => $remarks,
    'created_at' => $lead['created_at'],
    'updated_at' => $lead['updated_at']
], 'Candidate evaluation status retrieved successfully');
