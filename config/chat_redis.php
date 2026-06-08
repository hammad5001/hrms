<?php
/**
 * Redis for chat (online status, typing, unread cache, pub/sub).
 * Requires PHP redis extension (phpredis) in production.
 */
/** Set true when Redis server is running (optional on XAMPP; chat works without it) */
define('CHAT_REDIS_ENABLED', false);
define('CHAT_REDIS_HOST', '127.0.0.1');
define('CHAT_REDIS_PORT', 6379);
define('CHAT_REDIS_PASSWORD', '');
define('CHAT_REDIS_DB', 0);
/** Pub/sub channel for horizontal WebSocket scaling */
define('CHAT_REDIS_CHANNEL', 'chat:events');
define('CHAT_REDIS_PREFIX', 'balitech:chat:');
/** TTL seconds */
define('CHAT_REDIS_ONLINE_TTL', 120);
define('CHAT_REDIS_TYPING_TTL', 8);
define('CHAT_REDIS_UNREAD_TTL', 300);
define('CHAT_REDIS_CONV_MEMBERS_TTL', 3600);
