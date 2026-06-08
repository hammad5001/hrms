<?php

require_once __DIR__ . '/../config/chat_redis.php';

function chat_redis_prefix(): string {
    return defined('CHAT_REDIS_PREFIX') ? CHAT_REDIS_PREFIX : 'chat:';
}

/** @return object|null phpredis Redis instance when available */
function chat_redis_client() {
    static $client = null;
    static $failed = false;
    if ($failed) {
        return null;
    }
    if ($client !== null && is_object($client)) {
        return $client;
    }
    if (!defined('CHAT_REDIS_ENABLED') || !CHAT_REDIS_ENABLED || !extension_loaded('redis') || !class_exists('Redis')) {
        $failed = true;
        return null;
    }
    try {
        $r = new Redis();
        $host = CHAT_REDIS_HOST;
        $port = (int)CHAT_REDIS_PORT;
        if (!$r->connect($host, $port, 1.5)) {
            $failed = true;
            return null;
        }
        if (defined('CHAT_REDIS_PASSWORD') && CHAT_REDIS_PASSWORD !== '') {
            $r->auth(CHAT_REDIS_PASSWORD);
        }
        if (defined('CHAT_REDIS_DB')) {
            $r->select((int)CHAT_REDIS_DB);
        }
        $client = $r;
        return $client;
    } catch (Throwable $e) {
        $failed = true;
        return null;
    }
}

function chat_redis_available(): bool {
    return chat_redis_client() !== null;
}

function chat_redis_key(string $suffix): string {
    return chat_redis_prefix() . $suffix;
}

function chat_redis_online_touch(int $user_id): void {
    $r = chat_redis_client();
    if (!$r || $user_id <= 0) {
        return;
    }
    $ttl = (int)(defined('CHAT_REDIS_ONLINE_TTL') ? CHAT_REDIS_ONLINE_TTL : 120);
    $r->setex(chat_redis_key('online:' . $user_id), $ttl, (string)time());
}

function chat_redis_is_online(int $user_id): bool {
    $r = chat_redis_client();
    if (!$r || $user_id <= 0) {
        return false;
    }
    return (bool)$r->exists(chat_redis_key('online:' . $user_id));
}

function chat_redis_set_typing(int $conversation_id, int $user_id, bool $typing): void {
    $r = chat_redis_client();
    if (!$r || $conversation_id <= 0 || $user_id <= 0) {
        return;
    }
    $key = chat_redis_key("typing:{$conversation_id}:{$user_id}");
    if ($typing) {
        $ttl = (int)(defined('CHAT_REDIS_TYPING_TTL') ? CHAT_REDIS_TYPING_TTL : 8);
        $r->setex($key, $ttl, '1');
    } else {
        $r->del($key);
    }
}

function chat_redis_is_typing(int $conversation_id, int $user_id): bool {
    $r = chat_redis_client();
    if (!$r) {
        return false;
    }
    return (bool)$r->exists(chat_redis_key("typing:{$conversation_id}:{$user_id}"));
}

function chat_redis_group_any_typing(int $conversation_id, int $exclude_user_id): bool {
    $r = chat_redis_client();
    if (!$r) {
        return false;
    }
    $pattern = chat_redis_prefix() . "typing:{$conversation_id}:*";
    $keys = $r->keys($pattern);
    if (!is_array($keys)) {
        return false;
    }
    foreach ($keys as $key) {
        if (preg_match('/typing:' . $conversation_id . ':(\d+)$/', $key, $m)) {
            if ((int)$m[1] !== $exclude_user_id) {
                return true;
            }
        }
    }
    return false;
}

function chat_redis_sync_conv_members(mysqli $conn, int $conversation_id): void {
    $r = chat_redis_client();
    if (!$r || $conversation_id <= 0) {
        return;
    }
    $stmt = $conn->prepare('SELECT user_id FROM chat_participants WHERE conversation_id = ?');
    $stmt->bind_param('i', $conversation_id);
    $stmt->execute();
    $ids = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['user_id'];
    }
    $key = chat_redis_key('conv:' . $conversation_id . ':members');
    $r->del($key);
    if (!empty($ids)) {
        foreach ($ids as $id) {
            $r->sAdd($key, (string)$id);
        }
        $ttl = (int)(defined('CHAT_REDIS_CONV_MEMBERS_TTL') ? CHAT_REDIS_CONV_MEMBERS_TTL : 3600);
        $r->expire($key, $ttl);
    }
}

function chat_redis_invalidate_unread(int $user_id, ?int $conversation_id = null): void {
    $r = chat_redis_client();
    if (!$r || $user_id <= 0) {
        return;
    }
    $key = chat_redis_key('unread:' . $user_id);
    if ($conversation_id === null) {
        $r->del($key);
        return;
    }
    $r->hDel($key, (string)$conversation_id);
}

function chat_redis_set_unread(int $user_id, int $conversation_id, int $count): void {
    $r = chat_redis_client();
    if (!$r) {
        return;
    }
    $key = chat_redis_key('unread:' . $user_id);
    $r->hSet($key, (string)$conversation_id, (string)max(0, $count));
    $ttl = (int)(defined('CHAT_REDIS_UNREAD_TTL') ? CHAT_REDIS_UNREAD_TTL : 300);
    $r->expire($key, $ttl);
}

function chat_redis_get_unread(int $user_id, int $conversation_id, ?int $db_fallback = null): int {
    $r = chat_redis_client();
    if ($r) {
        $v = $r->hGet(chat_redis_key('unread:' . $user_id), (string)$conversation_id);
        if ($v !== false) {
            return (int)$v;
        }
    }
    if ($db_fallback !== null) {
        return $db_fallback;
    }
    return -1;
}

/**
 * @param int[] $user_ids
 */
function chat_redis_publish_event(array $user_ids, string $event, array $data): bool {
    $r = chat_redis_client();
    if (!$r || empty($user_ids)) {
        return false;
    }
    $channel = defined('CHAT_REDIS_CHANNEL') ? CHAT_REDIS_CHANNEL : 'chat:events';
    $payload = json_encode([
        'user_ids' => array_values(array_unique(array_map('intval', $user_ids))),
        'event' => $event,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return false;
    }
    return $r->publish($channel, $payload) !== false;
}

function chat_redis_rate_limit(int $user_id, string $action, int $max, int $window_sec): bool {
    $r = chat_redis_client();
    if (!$r || $user_id <= 0) {
        return true;
    }
    $key = chat_redis_key('rl:' . $user_id . ':' . $action);
    $n = (int)$r->incr($key);
    if ($n === 1) {
        $r->expire($key, $window_sec);
    }
    return $n <= $max;
}
