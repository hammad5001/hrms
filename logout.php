<?php
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 86400 * 7,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

$redirect = 'index.html?logout=true';

if (
    (isset($_SESSION['company_branch']) && $_SESSION['company_branch'] === 'workfromhome') ||
    (isset($_GET['wfh']) && $_GET['wfh'] === 'true')
) {
    $redirect = 'workfromhome/index.html?logout=true';
}

if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    require_once 'config.php';

    $user_id = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare("UPDATE users SET last_seen = NULL WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        '/', $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Location: ' . $redirect);
exit;
