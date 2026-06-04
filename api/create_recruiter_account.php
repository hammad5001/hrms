<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/db_schema.php';
ensure_app_schema($conn);

// Check if user is logged in and has super admin privileges
if (!isAuthenticated() || !isSuperRecruiter()) {
    respond(false, null, 'Only Super Admin can create recruiter accounts');
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

$full_name = trim($data['full_name'] ?? '');
$email = trim($data['email'] ?? '');
$username = trim($data['username'] ?? '');
$phone = trim($data['phone'] ?? '');
$password = $data['password'] ?? 'Recruiter@123';
$employee_code = trim($data['employee_code'] ?? $data['bid'] ?? '');

if (!$full_name || !$email || !$username) {
    respond(false, null, 'Full name, email, and username are required');
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, null, 'Invalid email format');
}

// Check if email already exists
$check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
$check_email->bind_param("s", $email);
$check_email->execute();
$email_result = $check_email->get_result();

if ($email_result->num_rows > 0) {
    respond(false, null, 'Email already exists');
}

// Check if username already exists
$check_user = $conn->prepare("SELECT id FROM users WHERE username = ?");
$check_user->bind_param("s", $username);
$check_user->execute();
$user_result = $check_user->get_result();

if ($user_result->num_rows > 0) {
    respond(false, null, 'Username already exists');
}

// Hash password
$password_hash = password_hash($password, PASSWORD_DEFAULT);

// Use real biometric BID when provided; otherwise temporary REC code
if ($employee_code === '') {
    $employee_code = 'REC' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
}

// Insert into users table
$stmt = $conn->prepare("
    INSERT INTO users (
        full_name, email, username, password_hash, portal_role, 
        employee_code, phone, company_branch, status, created_at
    ) VALUES (?, ?, ?, ?, 'recruiter', ?, ?, ?, 'active', NOW())
");
$rec_branch = get_active_company_branch();
$stmt->bind_param("sssssss", $full_name, $email, $username, $password_hash, $employee_code, $phone, $rec_branch);

if ($stmt->execute()) {
    $user_id = $conn->insert_id;
    
    // Insert into recruiters table
    $recruiter_stmt = $conn->prepare("
        INSERT INTO recruiters (user_id, recruiter_type, total_leads, total_calls, total_hired, created_at) 
        VALUES (?, 'regular', 0, 0, 0, NOW())
    ");
    $recruiter_stmt->bind_param("i", $user_id);
    $recruiter_stmt->execute();
    
    respond(true, [
        'id' => $user_id,
        'name' => $full_name,
        'email' => $email,
        'username' => $username,
        'employee_code' => $employee_code
    ], "Recruiter account created for $full_name");
} else {
    respond(false, null, 'Database error: ' . $conn->error);
}

$conn->close();
?>