<?php
session_start();
header("Content-Type: application/json");

require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../../includes/session_user.php";

$user = null;
if (!empty($_SESSION["user_id"])) {
    $user = resolve_logged_in_user($conn);
}
$role = $user ? sync_user_portal_role($conn, $user) : trim((string)($_SESSION["portal_role"] ?? $_SESSION["role"] ?? ""));

// Check if user is admin or super admin
if ($role !== "admin" && $role !== "super_admin" && $role !== "finance") {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$date = date("Y-m-d"); // Get todays locations

if (!defined("TABLE_ATTENDANCE")) {
    echo json_encode(["success" => false, "message" => "Attendance table not configured"]);
    exit;
}

$sql = "SELECT user_id, name, time, latitude, longitude FROM " . TABLE_ATTENDANCE . " WHERE date = ? AND sync_status = 'wfh_web' AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY time DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $date);
$stmt->execute();
$result = $stmt->get_result();

$locations = [];
while ($row = $result->fetch_assoc()) {
    $locations[] = $row;
}

echo json_encode(["success" => true, "data" => $locations]);
?>
