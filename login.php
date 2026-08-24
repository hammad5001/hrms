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
require_once 'config.php';

$email          = trim($_POST['email'] ?? '');
$password       = $_POST['password'] ?? '';
$branch_input   = isset($_POST['company_branch']) ? normalize_company_branch($_POST['company_branch']) : '';

if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Email and password are required']);
    exit;
}

$stmt = $conn->prepare("
    SELECT u.id, u.full_name, u.email, u.portal_role, u.password_hash,
           u.employee_code, u.phone, u.department, u.designation, u.status,
           u.branch, u.team, u.joined_date,
           COALESCE(NULLIF(u.company_branch, ''), 'main') AS company_branch,
           r.recruiter_type
    FROM users u
    LEFT JOIN recruiters r ON u.id = r.user_id
    WHERE u.email = ?
    LIMIT 1
");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();

    if ($user['status'] === 'inactive') {
        echo json_encode(['success' => false, 'message' => 'Your account has been deactivated. Contact admin.']);
        exit;
    }

    if (password_verify($password, $user['password_hash'])) {
        $portal_role = sync_user_portal_role($conn, $user);
        $account_branch = normalize_company_branch($user['company_branch'] ?? 'main');
        $session_branch = ($portal_role === 'super_admin' && !empty($branch_input) && is_valid_company_branch($branch_input)) ? $branch_input : $account_branch;

        $_SESSION['user_id']         = $user['id'];
        $_SESSION['full_name']       = $user['full_name'];
        $_SESSION['portal_role']     = $portal_role;
        $_SESSION['email']           = $user['email'];
        $_SESSION['employee_code']   = $user['employee_code'] ?? '';
        $_SESSION['recruiter_type']  = $user['recruiter_type'] ?? 'regular';
        $_SESSION['company_branch']  = $session_branch;
        $_SESSION['user_branch']     = $account_branch;

        if ($password === 'Balitech@123' && $portal_role !== 'super_admin') {
            $_SESSION['requires_password_change'] = true;
        } else {
            unset($_SESSION['requires_password_change']);
        }

        if ($portal_role === 'super_admin') {
            $_SESSION['recruiter_type'] = 'super';
        }

        // Update last_seen timestamp and IP
        $ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ip, ',') !== false) {
            $ip = explode(',', $ip)[0];
        }
        $ip = substr(trim($ip), 0, 45);
        $update_ls = $conn->prepare("UPDATE users SET last_seen = NOW(), ip_address = ? WHERE id = ?");
        $update_ls->bind_param("si", $ip, $user['id']);
        $update_ls->execute();

        $work_redirect = work_portal_url_for_role($portal_role);
        $redirect = $work_redirect ?? employee_self_service_url();

        if ($portal_role === 'super_admin') {
            $work_redirect = work_portal_url_for_role('super_admin');
            $redirect = $work_redirect ?? employee_self_service_url();
        }

        echo json_encode([
            'success' => true,
            'redirect' => $redirect,
            'work_redirect' => $work_redirect,
            'ess_redirect' => employee_self_service_url(),
            'can_access_work_portal' => user_can_access_work_portal($portal_role),
            'is_super_admin' => ($portal_role === 'super_admin'),
            'requires_password_change' => ($password === 'Balitech@123' && $portal_role !== 'super_admin'),
            'user' => [
                'id'              => $user['id'],
                'full_name'       => $user['full_name'],
                'email'           => $user['email'],
                'portal_role'     => $portal_role,
                'recruiter_type'  => $_SESSION['recruiter_type'],
                'employee_code'   => $user['employee_code'] ?? '',
                'phone'           => $user['phone'] ?? '',
                'department'      => $user['department'] ?? '',
                'designation'     => $user['designation'] ?? '',
                'team'            => $user['team'] ?? '',
                'branch'          => $user['branch'] ?? '',
                'company_branch'  => $session_branch,
                'company_branch_label' => company_branch_label($session_branch),
                'account_branch'  => $account_branch,
                'account_branch_label' => company_branch_label($account_branch),
                'joined_date'     => $user['joined_date'] ?? '',
            ]
        ]);
        exit;
    }
}

$hardcoded = [
    'hr@balitech.com'         => ['password' => 'balitech@123', 'role' => 'hr',         'name' => 'HR Manager',        'branch' => 'main'],
    'management@balitech.com' => ['password' => 'balitech@123', 'role' => 'management', 'name' => 'Management User', 'branch' => 'main'],
    'training@balitech.com'   => ['password' => 'balitech@123', 'role' => 'training',   'name' => 'Training User',   'branch' => 'main'],
    'reception@balitech.com'  => ['password' => '0000',         'role' => 'receptionist', 'name' => 'Reception User',  'branch' => 'main'],
    'agent@balitech.com'      => ['password' => '0000',         'role' => 'receptionist', 'name' => 'Reception User',  'branch' => 'main'],
    'analytics@balitech.com'  => ['password' => 'analytics@123','role' => 'analytics',  'name' => 'Analytics User',  'branch' => 'main'],
    'attendance@balitech.com' => ['password' => '0000',         'role' => 'attendance',  'name' => 'Attendance User', 'branch' => 'main'],
];

if (isset($hardcoded[$email]) && $password === $hardcoded[$email]['password']) {
    $hc_branch = normalize_company_branch($hardcoded[$email]['branch']);
    $session_branch = $hc_branch;

    $db_uid = 0;
    $db_user = null;
    $lookup = $conn->prepare("SELECT id, full_name, employee_code, department, designation, team, branch FROM users WHERE email = ? LIMIT 1");
    $lookup->bind_param('s', $email);
    $lookup->execute();
    $db_user = $lookup->get_result()->fetch_assoc();
    if ($db_user) {
        $db_uid = (int)$db_user['id'];
        $ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ip, ',') !== false) {
            $ip = explode(',', $ip)[0];
        }
        $ip = substr(trim($ip), 0, 45);
        $update_ls = $conn->prepare("UPDATE users SET last_seen = NOW(), ip_address = ? WHERE id = ?");
        $update_ls->bind_param("si", $ip, $db_uid);
        $update_ls->execute();
    }

    $_SESSION['user_id']        = $db_uid;
    $_SESSION['full_name']      = $db_user['full_name'] ?? $hardcoded[$email]['name'];
    $_SESSION['portal_role']    = $hardcoded[$email]['role'];
    $_SESSION['email']          = $email;
    $_SESSION['employee_code']  = $db_user['employee_code'] ?? '';
    $_SESSION['recruiter_type'] = 'regular';
    $_SESSION['company_branch'] = $branch_input;
    $_SESSION['user_branch']    = $hardcoded[$email]['branch'];

    $hc_role = $hardcoded[$email]['role'];
    $work_redirect = work_portal_url_for_role($hc_role);
    $redirect = $work_redirect ?? employee_self_service_url();

    echo json_encode([
        'success' => true,
        'redirect' => $redirect,
        'work_redirect' => $work_redirect,
        'ess_redirect' => employee_self_service_url(),
        'can_access_work_portal' => user_can_access_work_portal($hc_role),
        'user' => [
            'id'          => $db_uid,
            'full_name'   => $_SESSION['full_name'],
            'email'       => $email,
            'portal_role' => $hc_role,
            'recruiter_type' => 'regular',
            'employee_code' => $db_user['employee_code'] ?? '',
            'department' => $db_user['department'] ?? '',
            'designation' => $db_user['designation'] ?? '',
            'company_branch' => $branch_input,
            'company_branch_label' => company_branch_label($branch_input),
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
