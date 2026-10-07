<?php
// api/candidate_audit_report.php - Full Candidate Lifecycle & Audit Trail Report by CNIC / Phone / ID
require_once __DIR__ . '/config.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

$req_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = ($req_method === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: $_POST) : $_GET;

$cnic_raw = trim((string)($input['cnic'] ?? ''));
$phone_raw = trim((string)($input['phone'] ?? ''));
$lead_id = intval($input['lead_id'] ?? 0);

$cnic_digits = preg_replace('/[^0-9]/', '', $cnic_raw);
$phone_digits = preg_replace('/[^0-9]/', '', $phone_raw);

if (empty($cnic_digits) && empty($phone_digits) && $lead_id <= 0) {
    respond(false, null, 'Please provide CNIC number, Phone, or Candidate ID');
}

// 1. Locate all matching lead records across all branches
$where_clauses = [];
$params = [];
$types = '';

if ($lead_id > 0) {
    $where_clauses[] = "l.id = ?";
    $types .= 'i';
    $params[] = $lead_id;
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
        l.id, l.full_name, l.father_name, l.phone, l.email, l.cnic, l.city, l.dob,
        l.education, l.position_applied, l.referred_by, l.source,
        l.company_branch, l.current_stage, l.call_count, l.rejection_reason,
        l.interview_date, l.hired_date, l.created_at, l.updated_at,
        u.full_name AS recruiter_name, u.email AS recruiter_email,
        i.scheduled_date, i.scheduled_time, i.interviewer_name, i.location AS interview_location
    FROM leads l
    LEFT JOIN users u ON l.assigned_recruiter_id = u.id
    LEFT JOIN interviews i ON i.lead_id = l.id
    WHERE ($where_sql)
    ORDER BY l.created_at DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(false, null, 'Database query failed: ' . $conn->error);
}

if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$leads_res = $stmt->get_result();

$applications = [];
$all_lead_ids = [];

while ($row = $leads_res->fetch_assoc()) {
    $lid = (int)$row['id'];
    $all_lead_ids[] = $lid;
    
    $branch_key = normalize_company_branch($row['company_branch'] ?: 'main');
    $branch_label = company_branch_label($branch_key);
    $stage = canonical_stage((string)$row['current_stage']);
    
    $applications[] = [
        'lead_id' => $lid,
        'full_name' => $row['full_name'],
        'father_name' => $row['father_name'] ?: 'N/A',
        'phone' => $row['phone'],
        'email' => $row['email'] ?: 'N/A',
        'cnic' => $row['cnic'] ?: 'N/A',
        'city' => $row['city'] ?: 'N/A',
        'dob' => $row['dob'] ?: 'N/A',
        'education' => $row['education'] ?: 'N/A',
        'position' => $row['position_applied'],
        'referred_by' => $row['referred_by'] ?: 'Walk-in',
        'source' => $row['source'] ?: 'manual',
        'branch_key' => $branch_key,
        'branch_label' => $branch_label,
        'current_stage' => $stage,
        'rejection_reason' => $row['rejection_reason'] ?: '',
        'call_count' => (int)$row['call_count'],
        'recruiter_name' => $row['recruiter_name'] ?: 'Not Assigned',
        'scheduled_date' => $row['scheduled_date'] ?: '',
        'scheduled_time' => $row['scheduled_time'] ?: '',
        'interviewer_name' => $row['interviewer_name'] ?: '',
        'interview_location' => $row['interview_location'] ?: '',
        'applied_at' => $row['created_at'],
        'updated_at' => $row['updated_at']
    ];
}

if (empty($applications)) {
    respond(false, null, 'No candidate record found with this CNIC or Phone number');
}

// 2. Fetch all lead_audit logs for all matching leads
$timeline = [];
if (!empty($all_lead_ids)) {
    $in_clause = implode(',', array_map('intval', $all_lead_ids));
    
    // Fetch Audit Trail
    $audit_query = "
        SELECT 
            a.id, a.lead_id, a.user_id, a.user_name, a.action,
            a.old_value, a.new_value, a.notes, a.created_at
        FROM lead_audit a
        WHERE a.lead_id IN ($in_clause)
        ORDER BY a.created_at ASC
    ";
    $audit_res = $conn->query($audit_query);
    if ($audit_res) {
        while ($a = $audit_res->fetch_assoc()) {
            $timeline[] = [
                'type' => 'audit',
                'lead_id' => (int)$a['lead_id'],
                'user_name' => $a['user_name'] ?: 'System',
                'action' => $a['action'],
                'old_value' => $a['old_value'],
                'new_value' => $a['new_value'],
                'notes' => $a['notes'],
                'created_at' => $a['created_at']
            ];
        }
    }
    
    // Fetch Remarks / Interviewer Notes
    $rem_query = "
        SELECT 
            r.id, r.lead_id, r.added_by, r.added_by_name, r.added_by_role,
            r.remark, r.created_at
        FROM lead_remarks r
        WHERE r.lead_id IN ($in_clause)
        ORDER BY r.created_at ASC
    ";
    $rem_res = $conn->query($rem_query);
    if ($rem_res) {
        while ($r = $rem_res->fetch_assoc()) {
            $timeline[] = [
                'type' => 'remark',
                'lead_id' => (int)$r['lead_id'],
                'user_name' => $r['added_by_name'] ?: 'HR / Interviewer',
                'role' => $r['added_by_role'] ?: 'HR',
                'action' => 'interviewer_remark',
                'notes' => $r['remark'],
                'created_at' => $r['created_at']
            ];
        }
    }
    
    // Fetch HR Interviews Table
    $hr_query = "
        SELECT 
            h.id, h.lead_id, h.result, h.rejection_reason, h.remarks,
            h.interview_date, h.completed_at, u.full_name AS interviewer
        FROM hr_interviews h
        LEFT JOIN users u ON h.interviewer_id = u.id
        WHERE h.lead_id IN ($in_clause)
        ORDER BY h.id ASC
    ";
    $hr_res = $conn->query($hr_query);
    if ($hr_res) {
        while ($h = $hr_res->fetch_assoc()) {
            $timeline[] = [
                'type' => 'hr_interview',
                'lead_id' => (int)$h['lead_id'],
                'user_name' => $h['interviewer'] ?: 'HR Manager',
                'role' => 'HR Manager',
                'action' => 'hr_interview_' . ($h['result'] ?: 'completed'),
                'result' => $h['result'],
                'rejection_reason' => $h['rejection_reason'],
                'notes' => $h['remarks'] ?: ($h['rejection_reason'] ? 'Reason: ' . $h['rejection_reason'] : 'HR Interview Outcome: ' . $h['result']),
                'created_at' => $h['completed_at'] ?: ($h['interview_date'] ?: date('Y-m-d H:i:s'))
            ];
        }
    }
    
    // Fetch GM Interviews Table
    $gm_query = "
        SELECT 
            g.id, g.lead_id, g.result, g.rejection_reason, g.remarks,
            g.interview_date, g.completed_at, u.full_name AS interviewer
        FROM gm_interviews g
        LEFT JOIN users u ON g.interviewer_id = u.id
        WHERE g.lead_id IN ($in_clause)
        ORDER BY g.id ASC
    ";
    $gm_res = $conn->query($gm_query);
    if ($gm_res) {
        while ($g = $gm_res->fetch_assoc()) {
            $timeline[] = [
                'type' => 'gm_interview',
                'lead_id' => (int)$g['lead_id'],
                'user_name' => $g['interviewer'] ?: 'General Manager',
                'role' => 'Management (GM)',
                'action' => 'gm_interview_' . ($g['result'] ?: 'completed'),
                'result' => $g['result'],
                'rejection_reason' => $g['rejection_reason'],
                'notes' => $g['remarks'] ?: ($g['rejection_reason'] ? 'Reason: ' . $g['rejection_reason'] : 'Final Interview Outcome: ' . $g['result']),
                'created_at' => $g['completed_at'] ?: ($g['interview_date'] ?: date('Y-m-d H:i:s'))
            ];
        }
    }
}

// Sort unified timeline chronologically
usort($timeline, function($a, $b) {
    return strtotime($a['created_at']) <=> strtotime($b['created_at']);
});

// Primary candidate info summary
$primary = $applications[0];

// Format human-friendly timeline stages
$human_timeline = [];
foreach ($timeline as $t) {
    $title = 'System Event';
    $desc = $t['notes'] ?: '';
    $icon = 'fa-circle-info';
    $badge_color = '#3b82f6';
    
    $act = strtolower($t['action'] ?? '');
    
    if (str_contains($act, 'create') || str_contains($act, 'registration') || str_contains($act, 'apply')) {
        $title = 'Registration / Lead Entry';
        $desc = $t['notes'] ?: 'Candidate registered into the system';
        $icon = 'fa-user-plus';
        $badge_color = '#10b981';
    } elseif (str_contains($act, 'assign')) {
        $title = 'Assigned to Recruiter';
        $icon = 'fa-user-tag';
        $badge_color = '#8b5cf6';
    } elseif (str_contains($act, 'schedule') || str_contains($act, 'interview_scheduled')) {
        $title = 'Interview Scheduled';
        $icon = 'fa-calendar-check';
        $badge_color = '#f59e0b';
    } elseif (str_contains($act, 'reception_appeared') || str_contains($act, 'receptionist')) {
        $title = 'Arrived & Checked In at Reception';
        $icon = 'fa-door-open';
        $badge_color = '#10b981';
    } elseif (str_contains($act, 'no_show') || str_contains($act, 'not_appeared')) {
        $title = 'Candidate No-Show / Not Appeared';
        $icon = 'fa-user-slash';
        $badge_color = '#ef4444';
    } elseif (str_contains($act, 'left')) {
        $title = 'Candidate Left Reception';
        $icon = 'fa-sign-out-alt';
        $badge_color = '#64748b';
    } elseif (str_contains($act, 'hr_pass') || str_contains($act, 'passed')) {
        $title = 'Passed HR Interview';
        $icon = 'fa-check-double';
        $badge_color = '#10b981';
    } elseif (str_contains($act, 'reject') || str_contains($act, 'hr_reject') || str_contains($act, 'gm_reject')) {
        $title = 'Rejected in Interview';
        $icon = 'fa-times-circle';
        $badge_color = '#ef4444';
    } elseif (str_contains($act, 'selected') || str_contains($act, 'training')) {
        $title = 'Selected for Training';
        $icon = 'fa-graduation-cap';
        $badge_color = '#10b981';
    } elseif (str_contains($act, 'hired') || str_contains($act, 'deployed')) {
        $title = 'Hired & Deployed';
        $icon = 'fa-briefcase';
        $badge_color = '#059669';
    } elseif ($t['type'] === 'remark') {
        $title = 'Interviewer Remark Added (' . ($t['role'] ?? 'HR') . ')';
        $icon = 'fa-comment-alt';
        $badge_color = '#f97316';
    }

    $human_timeline[] = [
        'lead_id' => $t['lead_id'],
        'title' => $title,
        'description' => $desc,
        'actor' => $t['user_name'] ?? 'System',
        'role' => $t['role'] ?? '',
        'icon' => $icon,
        'badge_color' => $badge_color,
        'date' => date('d-M-Y h:i A', strtotime($t['created_at'])),
        'raw_date' => $t['created_at']
    ];
}

respond(true, [
    'candidate' => [
        'full_name' => $primary['full_name'],
        'father_name' => $primary['father_name'],
        'phone' => $primary['phone'],
        'email' => $primary['email'],
        'cnic' => $primary['cnic'],
        'city' => $primary['city'],
        'dob' => $primary['dob'],
        'education' => $primary['education'],
        'position' => $primary['position'],
        'referred_by' => $primary['referred_by'],
        'current_stage' => $primary['current_stage'],
        'branch_label' => $primary['branch_label'],
        'total_applications' => count($applications)
    ],
    'applications' => $applications,
    'timeline' => $human_timeline
], 'Candidate audit trail generated successfully');
