<?php
session_start();
header('Content-Type: application/json');
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if user is a recruiter
$check = $conn->prepare("SELECT recruiter_type FROM recruiters WHERE user_id = ?");
$check->bind_param("i", $user_id);
$check->execute();
$recruiter_result = $check->get_result();

// If not in recruiters table, auto-add them
if ($recruiter_result->num_rows === 0) {
    $insert = $conn->prepare("INSERT INTO recruiters (user_id, recruiter_type) VALUES (?, 'regular')");
    $insert->bind_param("i", $user_id);
    $insert->execute();
    $recruiter_type = 'regular';
} else {
    $recruiter_data = $recruiter_result->fetch_assoc();
    $recruiter_type = $recruiter_data['recruiter_type'];
}

if ($recruiter_type === 'super') {
    $query = "SELECT l.*, u.full_name as assigned_recruiter_name FROM leads l LEFT JOIN users u ON l.assigned_recruiter_id = u.id ORDER BY l.created_at DESC";
    $result = $conn->query($query);
} else {
    $query = "SELECT l.* FROM leads l WHERE l.assigned_recruiter_id = ? ORDER BY l.created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
}

$leads = [];
while ($row = $result->fetch_assoc()) {
    $leads[] = $row;
}

echo json_encode(['success' => true, 'data' => $leads]);
?>