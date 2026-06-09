<?php
// config.php - Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'balitech');  // ← CHANGED HERE

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

date_default_timezone_set('Asia/Karachi');
$conn->query("SET time_zone = '+05:00'");

require_once __DIR__ . '/includes/company_branches.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/portal_roles.php';
ensure_company_branch_schema($conn);
ensure_app_schema($conn);
ensure_receptionist_portal_role($conn);
require_once __DIR__ . '/includes/chat_schema.php';
ensure_chat_schema($conn);
?>