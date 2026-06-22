<?php
session_start();
require_once '../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT portal_role FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$role = $stmt->get_result()->fetch_assoc()['portal_role'] ?? '';

if ($role !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Super Admin only.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, full_name, portal_role, company_branch, ip_address, last_seen 
    FROM users 
    WHERE status = 'active'
    ORDER BY last_seen DESC, full_name ASC
");

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$result = $stmt->get_result();
$users = [];
$activeCount = 0;
$offlineCount = 0;

$now = time();

while ($row = $result->fetch_assoc()) {
    $is_online = false;
    if (!empty($row['last_seen'])) {
        $last_seen_time = strtotime($row['last_seen']);
        if (($now - $last_seen_time) <= 120) {
            $is_online = true;
        }
    }
    
    if ($is_online) {
        $activeCount++;
    } else {
        $offlineCount++;
    }
    
    $users[] = [
        'id' => $row['id'],
        'name' => $row['full_name'],
        'role' => $row['portal_role'],
        'branch' => $row['company_branch'],
        'ip' => $row['ip_address'],
        'is_online' => $is_online,
        'last_seen' => $row['last_seen']
    ];
}

echo json_encode([
    'success' => true,
    'data' => [
        'activeCount' => $activeCount,
        'offlineCount' => $offlineCount,
        'users' => $users
    ]
]);
