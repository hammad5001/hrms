<?php
require_once __DIR__ . '/config.php';

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if (!$user_id) {
    respond(false, null, 'Not logged in');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Invalid request method');
}

$new_password = $_POST['new_password'] ?? '';
if (empty($new_password) || strlen($new_password) < 4) {
    respond(false, null, 'Password must be at least 4 characters');
}

$hash = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE users SET password_hash = ?, plain_password = ? WHERE id = ?");
$stmt->bind_param("ssi", $hash, $new_password, $user_id);

if ($stmt->execute()) {
    unset($_SESSION['requires_password_change']);
    respond(true, null, 'Password updated successfully');
} else {
    respond(false, null, 'Database error updating password');
}
$stmt->close();
?>
