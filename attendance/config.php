<?php
// =====================================================
// ATTENDANCE SYSTEM CONFIGURATION
// SHIFT: 7:00 PM to 5:00 AM
// =====================================================

// Database settings (XAMPP default)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'balitech');  // ← CHANGED from 'balitech_attendance' to 'balitech'

// Connect to database
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'error' => 'Database connection failed: ' . $conn->connect_error
    ]));
}

// Set charset
$conn->set_charset('utf8mb4');

// Dynamic Branch Configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$active_branch = $_SESSION['company_branch'] ?? 'main';

if ($active_branch === 'commercial') {
    define('TABLE_ATTENDANCE', 'attendance_commercial_raw');
    define('TABLE_EMPLOYEES', 'employees_commercial');
    define('CSV_ALL_USERS', 'all_users_commercial.xlsx');
    define('CSV_MASTER', 'attendance_master_commercial.csv');
    define('PYTHON_SCRIPT', 'attendance_collector_commercial.py');
    define('LOG_FILE', 'auto_fetch_commercial_log.txt');
    define('BRANCH_LABEL', 'Commercial Branch');
} else {
    define('TABLE_ATTENDANCE', 'attendance_raw');
    define('TABLE_EMPLOYEES', 'employees');
    define('CSV_ALL_USERS', 'all_users.xlsx');
    define('CSV_MASTER', 'attendance_master.csv');
    define('PYTHON_SCRIPT', 'attendance_collector.py');
    define('LOG_FILE', 'auto_fetch_log.txt');
    define('BRANCH_LABEL', 'Main Branch');
}

// Company settings
define('COMPANY_NAME', 'Balitech Pvt Ltd');
define('SHIFT_START', '19:00:00'); // 7:00 PM
define('SHIFT_END', '05:00:00');   // 5:00 AM
define('GRACE_MINUTES', 15);        // 15 minutes grace period

// =====================================================
// HELPER FUNCTIONS
// =====================================================

// Note: sendJSON is now defined in attendance-api.php to avoid duplication
// Only define it here if it doesn't exist
if (!function_exists('sendJSON')) {
    function sendJSON($success, $data = null, $message = '') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'data' => $data,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
}

function sanitize($input) {
    global $conn;
    return $conn->real_escape_string(trim($input));
}

function getEmployeeName($employee_id) {
    global $conn;
    $result = $conn->query("SELECT full_name FROM employees WHERE id = $employee_id");
    if ($result && $row = $result->fetch_assoc()) {
        return $row['full_name'];
    }
    return 'Unknown';
}

function formatTime($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return sprintf("%02d:%02d", $hours, $minutes);
}

function isNightShiftPunch($punch_time) {
    $hour = date('H', strtotime($punch_time));
    // Night shift: 19:00 (7 PM) to 05:00 (5 AM)
    return ($hour >= 19 || $hour < 5);
}
?>