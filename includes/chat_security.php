<?php

require_once __DIR__ . '/chat_redis.php';

/** Per-action rate limits: [max requests, window seconds] */
function chat_rate_limit_rules(): array {
    return [
        'sendMessage' => [40, 60],
        'upload' => [20, 60],
        'searchUsers' => [40, 60],
        'createGroup' => [10, 300],
        'createDirect' => [20, 60],
        'editMessage' => [30, 60],
        'deleteMessage' => [30, 60],
        'blockUser' => [20, 300],
        'unblockUser' => [30, 60],
        'listBlockedUsers' => [40, 60],
        'acceptRequest' => [30, 60],
        'declineRequest' => [30, 60],
        'setTyping' => [120, 60],
        'getMessages' => [120, 60],
        'listConversations' => [60, 60],
        'markRead' => [120, 60],
        'heartbeat' => [30, 60],
        'wsConfig' => [20, 60],
        'default' => [200, 60],
    ];
}

function chat_rate_limit_check(int $user_id, string $action): bool {
    $rules = chat_rate_limit_rules();
    $rule = $rules[$action] ?? $rules['default'];
    [$max, $window] = $rule;
    if (chat_redis_available()) {
        return chat_redis_rate_limit($user_id, $action, $max, $window);
    }
    return chat_rate_limit_session($user_id, $action, $max, $window);
}

/** Fallback when Redis is unavailable */
function chat_rate_limit_session(int $user_id, string $action, int $max, int $window): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }
    $key = 'chat_rl_' . $user_id;
    $now = time();
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }
    $bucket = &$_SESSION[$key][$action];
    if (!is_array($bucket) || ($bucket['reset'] ?? 0) < $now) {
        $bucket = ['count' => 0, 'reset' => $now + $window];
    }
    $bucket['count']++;
    return $bucket['count'] <= $max;
}

function chat_sanitize_message_body(string $body): string {
    $body = strip_tags($body);
    $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? $body;
    return trim($body);
}

function chat_validate_conversation_access(mysqli $conn, int $conversation_id, int $user_id): bool {
    return chat_user_is_participant($conn, $conversation_id, $user_id);
}
