<?php
session_start();

$redirect = '/interview-forms/index.html?logout=true';
if ((isset($_SESSION['company_branch']) && $_SESSION['company_branch'] === 'workfromhome') || (isset($_GET['wfh']) && $_GET['wfh'] === 'true')) {
    $redirect = '/interview-forms/workfromhome/index.html?logout=true';
}

if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    require_once 'config.php';
    $user_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("UPDATE users SET last_seen = NULL WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
}

session_destroy();
header('Location: ' . $redirect);
exit;
?>