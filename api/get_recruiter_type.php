<?php
session_start();
header('Content-Type: application/json');
require_once '../config.php';

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if (!$user_id && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
}

if (!$user_id) {
    echo json_encode(['success' => false, 'recruiter_type' => 'regular']);
    exit;
}

$query = $conn->prepare("SELECT recruiter_type FROM recruiters WHERE user_id = ?");
$query->bind_param("i", $user_id);
$query->execute();
$result = $query->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode(['success' => true, 'recruiter_type' => $row['recruiter_type']]);
} else {
    echo json_encode(['success' => false, 'recruiter_type' => 'regular']);
}
?>