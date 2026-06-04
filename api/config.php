<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'balitech');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/../includes/company_branches.php';
require_once __DIR__ . '/../includes/db_schema.php';
ensure_company_branch_schema($conn);
ensure_app_schema($conn);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]));
}

function isAuthenticated() {
    return isset($_SESSION['user_id']);
}

function isSuperRecruiter() {
    return isset($_SESSION['recruiter_type']) && $_SESSION['recruiter_type'] === 'super';
}

function isRegularRecruiter() {
    return isset($_SESSION['recruiter_type']) && $_SESSION['recruiter_type'] === 'regular';
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? 0;
}

function getCurrentUserName() {
    return $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System';
}

function respond($success, $data = null, $error = null) {
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit;
}

function bindParams(&$stmt, $types, &$params) {
    if (empty($types) || empty($params)) return;
    $bind_names = [$types];
    for ($i=0; $i<count($params); $i++) {
        $bind_name = 'bind_' . $i;
        $$bind_name = $params[$i];
        $bind_names[] = &$$bind_name;
    }
    call_user_func_array(array($stmt, 'bind_param'), $bind_names);
}

/**
 * Canonical candidate stages used across all portals.
 */
function canonical_stage(string $stage): string {
    $s = strtolower(trim($stage));
    $map = [
        'new' => 'new',
        'assigned' => 'assigned',
        'contacted' => 'contacted',
        'interested' => 'interested',
        'callback' => 'callback',
        'outreach_phone' => 'outreach_phone',
        'outreach_whatsapp_call' => 'outreach_whatsapp_call',
        'outreach_whatsapp_msg' => 'outreach_whatsapp_msg',
        'interview_scheduled' => 'interview_scheduled',
        'scheduled' => 'interview_scheduled',
        'receptionist' => 'receptionist',
        'agent_checkin' => 'receptionist',
        'reception_checked_in' => 'receptionist',
        'pending' => 'pending',
        'hr_queue' => 'pending',
        'interview_completed' => 'interview_conducted',
        'interview_conducted' => 'interview_conducted',
        'not_appeared' => 'not_appeared',
        'hr_passed' => 'hr_passed',
        'hr-passed' => 'hr_passed',
        'hr_rejected' => 'hr_rejected',
        'selected' => 'selected',
        'gm_interview' => 'hr_passed',
        'management_queue' => 'hr_passed',
        'gm_passed' => 'gm_passed',
        'gm_rejected' => 'gm_rejected',
        'training' => 'training',
        'hired' => 'hired',
        'deployed' => 'deployed',
        'rejected' => 'rejected',
        'mock_rejected' => 'mock_rejected',
        'left' => 'left',
    ];
    return $map[$s] ?? $s;
}

function stage_group(string $stage): string {
    $s = canonical_stage($stage);
    if (in_array($s, ['new', 'assigned', 'contacted', 'interested', 'callback', 'outreach_phone', 'outreach_whatsapp_call', 'outreach_whatsapp_msg'], true)) {
        return 'recruiting';
    }
    if ($s === 'interview_scheduled') return 'scheduled';
    if (in_array($s, ['receptionist', 'not_appeared'], true)) return 'reception';
    if (in_array($s, ['interview_conducted', 'hr_passed', 'hr_rejected', 'selected', 'pending', 'rejected'], true)) return 'hr';
    if (in_array($s, ['gm_passed', 'gm_rejected'], true)) return 'management';
    if (in_array($s, ['hired', 'training', 'deployed', 'mock_rejected', 'left'], true)) return 'training';
    return 'other';
}

/**
 * Recruitment pipeline transition guard (see pipeline diagram).
 */
function stage_transition_allowed(string $from, string $to): bool {
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
?>