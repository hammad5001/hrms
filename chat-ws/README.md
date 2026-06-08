# Balitech Chat WebSocket Gateway

Production real-time layer for chat. Works with **Redis pub/sub** (required for PM2 cluster / 1000+ users), **PHP** APIs, and **Nginx** `wss://` proxy.

Full architecture: [../chat/ARCHITECTURE.md](../chat/ARCHITECTURE.md)

## Quick start (development)

1. Start **Redis** and **MySQL** (XAMPP).
2. Enable PHP **redis** extension in `php.ini`: `extension=redis`
3. Align secrets in `config.json` and `../config/chat_ws.php`
4. Install and run:

```powershell
cd C:\xampp\htdocs\interview-forms\chat-ws
npm install
npm start
```

5. Open chat in browser; green dot in header = connected.

## Production (PM2)

```bash
cd chat-ws
npm install
mkdir -p logs
pm2 start ecosystem.config.js
pm2 save
```

Set `CHAT_WS_INSTANCES=max` for all CPU cores.

## Nginx + SSL

Include `../chat/deploy/nginx/chat-ws.conf` in your HTTPS server. In `config/chat_ws.php`:

```php
define('CHAT_WS_PUBLIC_PATH', '/chat-ws');
define('CHAT_WS_USE_TLS', true);
```

## Health

`GET http://127.0.0.1:8765/health` → `{ ok, users, connections, redis }`

## Config (`config.json`)

| Field | Description |
|--------|-------------|
| `port` | Listen port (default 8765) |
| `secret` | Must match `CHAT_WS_SECRET` in PHP |
| `redis.channel` | Pub/sub channel (default `chat:events`) |
| `redis.keyPrefix` | Must match `CHAT_REDIS_PREFIX` in PHP |
| `limits.maxSocketsPerUser` | Tab/device limit per user |

## Without Redis (default on XAMPP)

`config.json` has `"redis": { "enabled": false }` — no Redis install needed.

PHP sends events via HTTP to `http://127.0.0.1:8765/internal/publish`. Keep `CHAT_WS_HTTP_FALLBACK` true in `config/chat_ws.php`.

For production / PM2 cluster: install Redis, set `redis.enabled` to `true` in both `chat-ws/config.json` and `config/chat_redis.php` (`CHAT_REDIS_ENABLED`).
