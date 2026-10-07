<?php
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 86400 * 7,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
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
define('DB_USER', 'balitech_user');
define('DB_PASS', '12344321');
define('DB_NAME', 'balitech');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]));
}

$conn->set_charset('utf8mb4');

require_once __DIR__ . '/../includes/company_branches.php';
require_once __DIR__ . '/../includes/db_schema.php';
// Disabled on web requests: run schema migration manually during deployment.
// Disabled on web requests: run schema migration manually during deployment.

function isAuthenticated() {
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

function isSuperRecruiter() {
    $type = $_SESSION['recruiter_type'] ?? '';
    $role = $_SESSION['portal_role'] ?? '';
    return $type === 'super' || $role === 'super_admin' || $role === 'admin' || $role === 'hr';
}

function isGlobalSuperAdmin() {
    $role = strtolower(trim((string)($_SESSION['portal_role'] ?? '')));
    return $role === 'super_admin';
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
        'not_answered' => 'not_answered',
        'no_answer' => 'not_answered',
        'outreach_phone' => 'outreach_phone',
        'outreach_whatsapp_call' => 'outreach_whatsapp_call',
        'outreach_whatsapp_msg' => 'outreach_whatsapp_msg',
        'message_dropped' => 'outreach_whatsapp_msg',
        'referred' => 'referred_branch',
        'referred_branch' => 'referred_branch',
        'transfer_branch' => 'referred_branch',
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
    if (in_array($s, ['new', 'assigned', 'contacted', 'interested', 'callback', 'not_answered', 'outreach_phone', 'outreach_whatsapp_call', 'outreach_whatsapp_msg', 'referred_branch'], true)) {
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
    // If super admin / admin / HR is managing, allow any stage adjustment
    if (isset($_SESSION['recruiter_type']) && $_SESSION['recruiter_type'] === 'super') return true;
    if (isset($_SESSION['portal_role']) && in_array($_SESSION['portal_role'], ['admin', 'super_admin', 'hr', 'management'], true)) return true;

    $from = canonical_stage($from);
    $to = canonical_stage($to);
    if ($from === $to || $from === '' || $to === '') {
        return true;
    }

    $outreach_set = ['assigned', 'contacted', 'interested', 'callback', 'not_answered', 'outreach_phone', 'outreach_whatsapp_call', 'outreach_whatsapp_msg', 'interview_scheduled', 'pending', 'selected', 'rejected', 'referred_branch'];

    $allowed = [
        'new' => $outreach_set,
        'assigned' => $outreach_set,
        'contacted' => $outreach_set,
        'interested' => $outreach_set,
        'callback' => $outreach_set,
        'not_answered' => $outreach_set,
        'outreach_phone' => $outreach_set,
        'outreach_whatsapp_call' => $outreach_set,
        'outreach_whatsapp_msg' => $outreach_set,
        'referred_branch' => $outreach_set,
        'interview_scheduled' => ['receptionist', 'not_appeared', 'left', 'interview_conducted', 'interview_scheduled', 'callback', 'outreach_phone', 'rejected', 'referred_branch'],
        'receptionist' => ['interview_conducted', 'not_appeared', 'left', 'rejected', 'interview_scheduled'],
        'not_appeared' => ['interview_scheduled', 'receptionist', 'callback', 'outreach_phone', 'outreach_whatsapp_msg', 'not_answered', 'rejected', 'referred_branch'],
        'interview_conducted' => ['selected', 'pending', 'hr_passed', 'hr_rejected', 'gm_passed', 'gm_rejected', 'hired', 'training', 'rejected', 'referred_branch'],
        'selected' => ['hr_passed', 'hired', 'training', 'rejected', 'referred_branch', 'pending'],
        'pending' => ['selected', 'hr_passed', 'hired', 'training', 'hr_rejected', 'rejected', 'interview_scheduled', 'referred_branch', 'callback'],
        'hr_passed' => ['gm_passed', 'gm_rejected', 'hired', 'training', 'rejected', 'referred_branch'],
        'hr_rejected' => ['rejected', 'referred_branch', 'interview_scheduled'],
        'gm_passed' => ['hired', 'training', 'rejected', 'referred_branch'],
        'gm_rejected' => ['rejected', 'referred_branch', 'interview_scheduled'],
        'hired' => ['training', 'not_appeared', 'rejected'],
        'training' => ['deployed', 'mock_rejected', 'left', 'rejected'],
        'deployed' => [],
        'mock_rejected' => ['training', 'rejected'],
        'left' => ['interview_scheduled', 'receptionist', 'callback', 'rejected'],
        'rejected' => ['assigned', 'outreach_phone', 'callback', 'interview_scheduled', 'referred_branch'],
    ];
    if (!isset($allowed[$from])) {
        return true;
    }
    return in_array($to, $allowed[$from], true);
}
?>