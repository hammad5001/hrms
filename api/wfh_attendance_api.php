<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
// Need attendance config for TABLE_ATTENDANCE
if (file_exists(__DIR__ . '/../attendance/config.php')) {
    @require_once __DIR__ . '/../attendance/config.php';
}

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$lat = $input['lat'] ?? null;
$lng = $input['lng'] ?? null;

if ($lat === null || $lng === null) {
    echo json_encode(['success' => false, 'message' => 'Location coordinates are required.']);
    exit;
}

$employee_code = $_SESSION['employee_code'] ?? '';
$full_name = $_SESSION['full_name'] ?? $_SESSION['userFullName'] ?? '';
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

// Current Date & Time
$timestamp = date('Y-m-d H:i:s');
$date = date('Y-m-d');
$time = date('H:i:s');

$conn->begin_transaction();

try {
    // 2. Insert into the main attendance table
    if (defined('TABLE_ATTENDANCE')) {
        $stmt2 = $conn->prepare("INSERT INTO " . TABLE_ATTENDANCE . " (user_id, name, timestamp, date, time, sync_status, latitude, longitude) VALUES (?, ?, ?, ?, ?, 'wfh_web', ?, ?)");
        $stmt2->bind_param("sssssdd", $employee_code, $full_name, $timestamp, $date, $time, $lat, $lng);
        $stmt2->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'timestamp' => $timestamp, 'message' => 'Attendance recorded successfully']);
} catch (Exception $e) {
    $conn->rollback();
    error_log("WFH Check-in Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to record attendance. Please try again.']);
}
?>
