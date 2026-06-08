# Balitech Chat — Production Architecture

Real-time workplace chat scoped to this module only. **MySQL** is the source of truth; **Redis** reduces load and enables scale; **Node.js** delivers WebSocket events; **PHP** handles auth, business rules, and APIs.

## Stack

| Layer | Role |
|--------|------|
| **PHP** (`api/chat_api.php`, `includes/chat_*.php`) | Session auth, CRUD, receipts, uploads, rate limits |
| **MySQL** | Messages, conversations, participants, receipts |
| **Redis** | Online status, typing, unread cache, rate limits, **pub/sub** to all WS nodes |
| **Node** (`chat-ws/server.js`) | WebSocket connections, fan-out, PM2 cluster |
| **Nginx** | TLS termination, `wss://` proxy to Node |
| **PM2** | Auto-restart, multi-instance processes |

## Data flow

```
Browser ──wss──► Nginx ──► Node (WS)
                │
                ├──► PHP API ──► MySQL (persist)
                │         │
                │         └──► Redis PUBLISH chat:events
                │
Node ◄── Redis SUBSCRIBE ── (all PM2 instances receive event)
Node ──► deliver to connected user sockets
```

No HTTP polling for messages. A light **heartbeat** (30s) updates DB/Redis presence only.

## Horizontal scale (1000+ users)

1. Run **multiple** Node instances: `pm2 start ecosystem.config.js` (`instances: 2` or `max`).
2. All instances **subscribe** to the same Redis channel `chat:events`.
3. PHP publishes once; every node pushes to its local sockets.
4. Sticky sessions are **not** required for pub/sub delivery.

## Configuration

| File | Purpose |
|------|---------|
| `config/chat_redis.php` | Redis host, prefix, TTLs, channel |
| `config/chat_ws.php` | WS secret, port, public path (`/chat-ws`), TLS |
| `chat-ws/config.json` | Node port, secret, Redis (must match PHP) |

## Security

- PHP session required for all API actions.
- WebSocket **HMAC token** from `wsConfig` (2h TTL).
- Per-user **rate limits** (Redis or session fallback).
- `chat_sanitize_message_body()` strips HTML/control chars.
- `chat_validate_conversation_access()` on sensitive actions.
- Internal publish: `X-Chat-Ws-Secret` + localhost Nginx ACL.
- Upload type/size checks unchanged in `chat_helpers.php`.

## Features

| Feature | Implementation |
|---------|----------------|
| Messages | MySQL insert + `message.new` event |
| Delivery / read | `chat_message_receipts` + `read` WS event |
| Typing | Redis TTL keys + `typing` event |
| Online/offline | Redis `online:{userId}` + `presence` event |
| Unread badges | Redis hash per user, invalidated on read |
| Files | `api/chat_upload.php` + `message.new` |
| Notifications | Browser API when tab hidden (client) |
| History | `getMessages` cursor: `before_id`, `after_id`, `has_more` |

## Operations

```bash
# Redis (required for production scale)
redis-server

# WebSocket gateway
cd chat-ws
npm install
pm2 start ecosystem.config.js
pm2 logs balitech-chat-ws

# Health
curl http://127.0.0.1:8765/health
```

Enable PHP **redis** extension (`phpredis`). Without Redis: chat still works with MySQL + HTTP WS publish fallback; no cross-node fan-out.

## Nginx + SSL

Include `chat/deploy/nginx/chat-ws.conf` in your HTTPS `server` block. Set:

```php
define('CHAT_WS_PUBLIC_PATH', '/chat-ws');
define('CHAT_WS_USE_TLS', true);
```

Clients connect to `wss://your-domain/chat-ws`.

## Indexes

Applied via `ensure_chat_schema()`:

- `chat_messages (conversation_id, id)`
- `chat_messages (conversation_id, is_deleted, id)`
- `chat_participants (user_id, conversation_id)`

## Future

- Dedicated push service (FCM) using same Redis events.
- Read replicas for `getMessages` history.
- Separate `chat-ws` hosts behind Nginx `upstream` with more servers.
