<?php

function ensure_php_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/** Load full user row for current session (by id or email). */
function resolve_logged_in_user(mysqli $conn): ?array {
    ensure_php_session();

    if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
        $id = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare("
            SELECT id, full_name, email, portal_role, employee_code, phone, department, designation,
                   branch, team, joined_date, status, chat_avatar,
                   COALESCE(NULLIF(company_branch, ''), 'main') AS company_branch
            FROM users WHERE id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            return $row;
        }
    }

    if (!empty($_SESSION['email'])) {
        $email = $_SESSION['email'];
        $stmt = $conn->prepare("
            SELECT id, full_name, email, portal_role, employee_code, phone, department, designation,
                   branch, team, joined_date, status, chat_avatar,
                   COALESCE(NULLIF(company_branch, ''), 'main') AS company_branch
            FROM users WHERE email = ? LIMIT 1
        ");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $_SESSION['user_id'] = (int)$row['id'];
            $_SESSION['full_name'] = $row['full_name'];
            $_SESSION['portal_role'] = $row['portal_role'];
            return $row;
        }
    }

    return null;
}
