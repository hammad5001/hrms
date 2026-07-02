<?php
session_start();
header("Content-Type: application/json");
require_once __DIR__ . "/../config.php";

// Only load attendance config constants if not already defined
if (file_exists(__DIR__ . "/../attendance/config.php")) {
    @require_once __DIR__ . "/../attendance/config.php";
}

if (empty($_SESSION["user_id"])) { echo json_encode(["success" => false]); exit; }
$employee_code = $_SESSION["employee_code"] ?? "";
$date = date("Y-m-d");

$status = false;
if (defined("TABLE_ATTENDANCE")) {
    $stmt = $conn->prepare("SELECT count(*) as cnt FROM " . TABLE_ATTENDANCE . " WHERE user_id = ? AND date = ? AND sync_status = 'wfh_web'");
    $stmt->bind_param("ss", $employee_code, $date);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $status = ($res["cnt"] % 2 !== 0);
}
echo json_encode(["success" => true, "checked_in" => $status]);
?>
