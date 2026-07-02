<?php
session_start();
header('Content-Type: application/json');

// Ensure Super Admin or Finance access
if (!isset($_SESSION['user_id']) || ($_SESSION['portal_role'] !== 'super_admin' && $_SESSION['portal_role'] !== 'finance')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Elevated privileges required']);
    exit;
}

require_once '../config.php';
// Include the mailer script
require_once '../includes/mailer.php'; 

// Get JSON input
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (!$input || !isset($input['salaryData'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data payload']);
    exit;
}

$month = $conn->real_escape_string($input['month'] ?? date('F'));
$year = (int)($input['year'] ?? date('Y'));
$sendEmail = !empty($input['sendEmail']);
$sendPortal = !empty($input['sendPortal']);
$salaryData = $input['salaryData'];

$processed = 0;
$emails_sent = 0;
$failed = 0;

foreach ($salaryData as $row) {
    $empCode = '';
    $empName = '';

    foreach ($row as $k => $v) {
        $kLower = strtolower(trim($k));
        $val = trim((string)$v);
        
        // Match IDs like 'B ID', 'Biometric ID', 'Emp ID', 'Sr ID'
        if (empty($empCode) && (strpos($kLower, 'id') !== false || strpos($kLower, 'code') !== false || strpos($kLower, 'sr') !== false)) {
            // Avoid matching 'Account ID' or similar if they exist, stick to expected variants
            if (in_array($kLower, ['b id', 'biometric id', 'employee code', 'emp id', 'sr. no', 'sr id'])) {
                $empCode = $val;
            }
        }
        
        // Match names like 'Employee Name', 'Name', 'Sudo Name'
        if (strpos($kLower, 'name') !== false && strpos($kLower, 'bank') === false) {
            // Prioritize primary name over others, or set if empty
            if (empty($empName) || $kLower === 'employee name') {
                $empName = $val;
            }
        }
    }

    if (empty($empCode) && empty($empName)) {
        $failed++;
        continue;
    }

    // Try to find employee email in DB
    $user_email = '';
    $db_emp_code = $empCode;

    if (!empty($empCode)) {
        $stmt = $conn->prepare("SELECT id, email, employee_code, full_name FROM users WHERE employee_code = ? LIMIT 1");
        $stmt->bind_param('s', $empCode);
    } else {
        $stmt = $conn->prepare("SELECT id, email, employee_code, full_name FROM users WHERE full_name LIKE ? LIMIT 1");
        $searchTerm = "%$empName%";
        $stmt->bind_param('s', $searchTerm);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    if ($user = $res->fetch_assoc()) {
        $user_email = $user['email'];
        if (empty($db_emp_code)) {
            $db_emp_code = $user['employee_code']; // Fallback if matched by name
        }
        $empName = $user['full_name']; // Use DB name for email consistency
    }
    $stmt->close();

    // Identify Gross and Net Salary for DB
    $grossCol = '';
    $netCol = '';
    foreach (array_keys($row) as $col) {
        if (stripos($col, 'gross') !== false) $grossCol = $col;
        if (stripos($col, 'net payable') !== false || stripos($col, 'net salary') !== false) $netCol = $col;
    }

    $gross_salary = $grossCol && isset($row[$grossCol]) ? (float)str_replace(',', '', $row[$grossCol]) : 0.00;
    $net_salary = $netCol && isset($row[$netCol]) ? (float)str_replace(',', '', $row[$netCol]) : 0.00;

    $json_data = json_encode($row);

    // Save to DB
    if ($sendPortal || $sendEmail) {
        $status = 'pending';
        
        $insertStmt = $conn->prepare("INSERT INTO employee_salary_slips (employee_code, month, year, gross_salary, net_salary, slip_data_json, email_sent_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->bind_param('ssiddss', $db_emp_code, $month, $year, $gross_salary, $net_salary, $json_data, $status);
        $insertStmt->execute();
        $slip_id = $insertStmt->insert_id;
        $insertStmt->close();

        $processed++;

        // Send Email Feature
        if ($sendEmail && !empty($user_email)) {
            // Function send_salary_slip_email is defined in mailer.php
            $emailSuccess = send_salary_slip_email($user_email, $empName, $month, $year, $row);

            if ($emailSuccess) {
                $emails_sent++;
                $conn->query("UPDATE employee_salary_slips SET email_sent_status = 'sent', sent_at = NOW() WHERE id = $slip_id");
            } else {
                $failed++;
                $conn->query("UPDATE employee_salary_slips SET email_sent_status = 'failed' WHERE id = $slip_id");
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'processed' => $processed,
    'emails_sent' => $emails_sent,
    'failed' => $failed
]);
?>
