<?php

require_once __DIR__ . '/../config/chat_ws.php';
require_once __DIR__ . '/chat_redis.php';

function chat_ws_enabled(): bool {
    return defined('CHAT_WS_ENABLED') && CHAT_WS_ENABLED;
}

function chat_ws_secret(): string {
    return defined('CHAT_WS_SECRET') ? (string)CHAT_WS_SECRET : '';
}

function chat_ws_internal_url(): string {
    $host = defined('CHAT_WS_HOST') ? CHAT_WS_HOST : '127.0.0.1';
    $port = defined('CHAT_WS_PORT') ? (int)CHAT_WS_PORT : 8765;
    return 'http://' . $host . ':' . $port;
}

/** WebSocket URL for browsers (Nginx path or host:port). */
function chat_ws_client_url(): string {
    $path = defined('CHAT_WS_PUBLIC_PATH') ? trim((string)CHAT_WS_PUBLIC_PATH) : '';
    $host = defined('CHAT_WS_PUBLIC_HOST') && CHAT_WS_PUBLIC_HOST !== ''
        ? CHAT_WS_PUBLIC_HOST
        : ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (strpos($host, ':') !== false) {
        $host = preg_replace('/:\d+$/', '', $host);
    }
    $tls = (defined('CHAT_WS_USE_TLS') && CHAT_WS_USE_TLS)
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $tls ? 'wss' : 'ws';

    if ($path !== '') {
        $path = '/' . trim($path, '/');
        return $scheme . '://' . $host . $path;
    }

    $port = defined('CHAT_WS_PORT') ? (int)CHAT_WS_PORT : 8765;
    return $scheme . '://' . $host . ':' . $port;
}

function chat_ws_issue_token(int $user_id): string {
    $exp = time() + 7200;
    $payload = $user_id . '.' . $exp;
    $sig = hash_hmac('sha256', $payload, chat_ws_secret());
    return rtrim(strtr(base64_encode($payload . '.' . $sig), '+/', '-_'), '=');
}

/**
 * @param int[] $user_ids
 */
function chat_ws_publish(array $user_ids, string $event, array $data): void {
    if (!chat_ws_enabled() || empty($user_ids)) {
        return;
    }
    $user_ids = array_values(array_unique(array_map('intval', $user_ids)));
    $user_ids = array_filter($user_ids, fn($id) => $id > 0);
    if (empty($user_ids)) {
        return;
    }

    $envelope = ['user_ids' => $user_ids, 'event' => $event, 'data' => $data];
    $published = chat_redis_publish_event($user_ids, $event, $data);

    if (!$published && defined('CHAT_WS_HTTP_FALLBACK') && CHAT_WS_HTTP_FALLBACK) {
        chat_ws_publish_http($envelope);
    }
}

function chat_ws_publish_http(array $envelope): void {
    $body = json_encode($envelope, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return;
    }
    $url = chat_ws_internal_url() . '/internal/publish';
    $secret = chat_ws_secret();
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Chat-Ws-Secret: ' . $secret,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        @curl_exec($ch);
        curl_close($ch);
        return;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Chat-Ws-Secret: {$secret}\r\n",
            'content' => $body,
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($url, false, $ctx);
}

function chat_ws_notify_conversation(mysqli $conn, int $conversation_id, string $event, array $data): void {
    $ids = chat_participant_user_ids($conn, $conversation_id);
    $payload = array_merge(['conversation_id' => $conversation_id], $data);
    chat_ws_publish($ids, $event, $payload);
}

function chat_ws_notify_inbox(mysqli $conn, int $conversation_id): void {
    chat_ws_notify_conversation($conn, $conversation_id, 'inbox', []);
}

function chat_ws_notify_presence(mysqli $conn, int $user_id, bool $online): void {
    $stmt = $conn->prepare('SELECT DISTINCT conversation_id FROM chat_participants WHERE user_id = ? LIMIT 200');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $targets = [];
    while ($row = $res->fetch_assoc()) {
        foreach (chat_participant_user_ids($conn, (int)$row['conversation_id']) as $uid) {
            if ($uid !== $user_id) {
                $targets[$uid] = true;
            }
        }
    }
    if (empty($targets)) {
        return;
    }
    chat_ws_publish(array_keys($targets), 'presence', [
        'user_id' => $user_id,
        'online' => $online,
    ]);
}
