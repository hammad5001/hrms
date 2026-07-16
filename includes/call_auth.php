<?php
// includes/call_auth.php - Authentication and authorization helper for RTC calls

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/call.php';
require_once __DIR__ . '/session_user.php';
require_once __DIR__ . '/chat_helpers.php';

function call_authenticate(mysqli $conn): array {
    if (!CALL_FEATURE_ENABLED) {
        call_error_response(403, 'Calling feature is disabled');
    }

    $me = resolve_logged_in_user($conn);
    if (!$me || ($me['status'] ?? 'active') !== 'active') {
        call_error_response(401, 'Unauthorized');
    }

    return $me;
}

function call_validate_conversation_membership(mysqli $conn, int $conversation_id, int $user_id): void {
    if (!chat_user_is_participant($conn, $conversation_id, $user_id)) {
        call_error_response(403, 'You do not belong to this conversation');
    }
}

function call_validate_participant_blocking(mysqli $conn, int $user_id, int $target_id): void {
    if (chat_is_blocked($conn, $user_id, $target_id)) {
        call_error_response(403, 'Call blocked due to block restrictions');
    }
}

function call_error_response(int $status_code, string $message): void {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
