<?php
session_start();
header('Content-Type: application/json');
require_once '../config.php';

$data = json_decode(file_get_contents('php://input'), true);
$user_id = intval($data['user_id'] ?? 0);
$user_name = $conn->real_escape_string($data['user_name'] ?? '');
$user_email = $conn->real_escape_string($data['user_email'] ?? '');

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit;
}

// Check if user already exists in recruiters table
$check = $conn->prepare("SELECT id FROM recruiters WHERE user_id = ?");
$check->bind_param("i", $user_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {
    echo json_encode(['success' => true, 'message' => 'Recruiter record already exists']);
    exit;
}

// Insert into recruiters table
$stmt = $conn->prepare("
    INSERT INTO recruiters (user_id, recruiter_type, total_leads, total_calls, total_hired, created_at) 
    VALUES (?, 'regular', 0, 0, 0, NOW())
");
$stmt->bind_param("i", $user_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Recruiter record created successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}

$conn->close();
?>