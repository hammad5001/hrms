<?php
/**
 * WebSocket gateway (Node + Nginx). Match chat-ws/config.json.
 */
define('CHAT_WS_ENABLED', true);
define('CHAT_WS_HOST', '127.0.0.1');
define('CHAT_WS_PORT', 8765);
define('CHAT_WS_SECRET', 'balitech-chat-ws-change-me');
/** Nginx path e.g. /chat-ws — leave empty to use host:port directly */
define('CHAT_WS_PUBLIC_PATH', '');
define('CHAT_WS_PUBLIC_HOST', '');
define('CHAT_WS_USE_TLS', false);
/** Prefer Redis pub/sub; HTTP publish is fallback when Redis unavailable */
define('CHAT_WS_HTTP_FALLBACK', true);
