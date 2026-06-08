/**
 * Balitech Chat WebSocket Gateway
 * - Authenticated WSS connections (token from PHP)
 * - Redis pub/sub when enabled (PM2 cluster / production)
 * - HTTP /internal/publish for local XAMPP (no Redis required)
 */
const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const WebSocket = require('ws');
const Redis = require('ioredis');

const configPath = path.join(__dirname, 'config.json');
if (!fs.existsSync(configPath)) {
    console.error('Missing config.json — copy config.example.json');
    process.exit(1);
}

const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
const PORT = config.port || 8765;
const SECRET = config.secret || '';
const REDIS_CFG = config.redis || {};
const REDIS_ENABLED = REDIS_CFG.enabled !== false && config.redisEnabled !== false;
const CHANNEL = REDIS_CFG.channel || 'chat:events';
const KEY_PREFIX = REDIS_CFG.keyPrefix || 'balitech:chat:';
const ONLINE_TTL = config.onlineTtlSeconds || 120;
const MAX_PER_USER = config.limits?.maxSocketsPerUser || 5;
const WS_RATE = config.limits?.wsMessagesPerMinute || 180;
const MAX_PAYLOAD = config.limits?.maxPayloadBytes || 65536;

/** @type {Map<number, Set<WebSocket>>} */
const socketsByUser = new Map();
/** @type {Map<WebSocket, { count: number, reset: number }>} */
const wsRateBuckets = new Map();

let redisPub = null;
let redisSub = null;
let redisActive = false;

function redisOpts() {
    return {
        host: REDIS_CFG.host || '127.0.0.1',
        port: REDIS_CFG.port || 6379,
        password: REDIS_CFG.password || undefined,
        db: REDIS_CFG.db ?? 0,
        lazyConnect: true,
        enableOfflineQueue: false,
        maxRetriesPerRequest: 0,
        retryStrategy: () => null,
        reconnectOnError: false,
        connectTimeout: 2000,
    };
}

function destroyRedisClient(client) {
    if (!client) return;
    try {
        client.removeAllListeners();
        client.disconnect(false);
    } catch { /* ignore */ }
}

async function connectRedis() {
    if (!REDIS_ENABLED) {
        console.log('Redis disabled in config — using HTTP publish only (fine for local dev).');
        return;
    }

    const pub = new Redis(redisOpts());
    const sub = new Redis(redisOpts());

    try {
        await Promise.all([pub.connect(), sub.connect()]);
        await sub.subscribe(CHANNEL);
        sub.on('message', (_ch, message) => {
            try {
                deliverEvent(JSON.parse(message));
            } catch (e) {
                console.warn('Bad pub/sub payload:', e.message);
            }
        });
        redisPub = pub;
        redisSub = sub;
        redisActive = true;
        console.log(`Redis connected — subscribed to ${CHANNEL}`);
    } catch (e) {
        destroyRedisClient(pub);
        destroyRedisClient(sub);
        redisPub = null;
        redisSub = null;
        redisActive = false;
        console.log('Redis not running — chat uses HTTP publish to this server (OK for XAMPP).');
        console.log('  To enable Redis later: start redis-server and set redis.enabled true in config.json');
    }
}

function onlineKey(userId) {
    return `${KEY_PREFIX}online:${userId}`;
}

async function setUserOnline(userId) {
    if (!redisActive || !redisPub || !userId) return;
    await redisPub.setex(onlineKey(userId), ONLINE_TTL, String(Math.floor(Date.now() / 1000))).catch(() => {});
}

async function touchUserOnline(userId) {
    if (!redisActive || !redisPub || !userId) return;
    await redisPub.expire(onlineKey(userId), ONLINE_TTL).catch(() => {});
}

function verifyToken(token) {
    if (!token || !SECRET) return null;
    try {
        const raw = Buffer.from(token.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8');
        const dot = raw.lastIndexOf('.');
        if (dot < 1) return null;
        const payload = raw.slice(0, dot);
        const sig = raw.slice(dot + 1);
        const expected = crypto.createHmac('sha256', SECRET).update(payload).digest('hex');
        if (sig.length !== expected.length) return null;
        if (!crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected))) return null;
        const parts = payload.split('.');
        const userId = parseInt(parts[0], 10);
        const exp = parseInt(parts[1], 10);
        if (!userId || !exp || exp < Math.floor(Date.now() / 1000)) return null;
        return userId;
    } catch {
        return null;
    }
}

function addSocket(userId, ws) {
    if (!socketsByUser.has(userId)) socketsByUser.set(userId, new Set());
    const set = socketsByUser.get(userId);
    if (set.size >= MAX_PER_USER) {
        const oldest = set.values().next().value;
        if (oldest) oldest.close(4002, 'too many sessions');
    }
    set.add(ws);
    ws.userId = userId;
    ws.isAlive = true;
    ws.subscribedCid = null;
    setUserOnline(userId);
}

function removeSocket(ws) {
    const uid = ws.userId;
    if (!uid) return;
    const set = socketsByUser.get(uid);
    if (!set) return;
    set.delete(ws);
    if (set.size === 0) {
        socketsByUser.delete(uid);
    }
}

function sendJson(ws, obj) {
    if (ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify(obj));
    }
}

function deliverEvent(envelope) {
    const userIds = envelope.user_ids || [];
    const event = envelope.event || envelope.type;
    const data = envelope.data || {};
    if (!event || !Array.isArray(userIds)) return;

    const payload = { type: event, ...data };
    const sent = new Set();
    for (const uid of userIds) {
        const set = socketsByUser.get(parseInt(uid, 10));
        if (!set) continue;
        for (const ws of set) {
            if (sent.has(ws)) continue;
            sent.add(ws);
            sendJson(ws, payload);
        }
    }
}

function wsRateOk(ws) {
    const now = Date.now();
    let b = wsRateBuckets.get(ws);
    if (!b || now > b.reset) {
        b = { count: 0, reset: now + 60000 };
        wsRateBuckets.set(ws, b);
    }
    b.count++;
    return b.count <= WS_RATE;
}

function readBody(req) {
    return new Promise((resolve, reject) => {
        let data = '';
        req.on('data', (chunk) => {
            data += chunk;
            if (data.length > MAX_PAYLOAD) reject(new Error('payload too large'));
        });
        req.on('end', () => resolve(data));
        req.on('error', reject);
    });
}

const server = http.createServer(async (req, res) => {
    if (req.method === 'POST' && req.url === '/internal/publish') {
        const hdr = req.headers['x-chat-ws-secret'] || '';
        if (hdr !== SECRET) {
            res.writeHead(403);
            res.end('forbidden');
            return;
        }
        try {
            const raw = await readBody(req);
            const body = JSON.parse(raw);
            deliverEvent(body);
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: true, mode: redisActive ? 'local+redis' : 'local' }));
        } catch (e) {
            res.writeHead(500);
            res.end(String(e.message));
        }
        return;
    }
    if (req.method === 'GET' && req.url === '/health') {
        const payload = {
            ok: true,
            users: socketsByUser.size,
            connections: [...socketsByUser.values()].reduce((n, s) => n + s.size, 0),
            redis: redisActive,
            mode: redisActive ? 'redis+http' : 'http-only',
        };
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(payload));
        return;
    }
    res.writeHead(404);
    res.end();
});

const wss = new WebSocket.Server({ server, maxPayload: MAX_PAYLOAD });

wss.on('connection', (ws) => {
    ws.authed = false;

    ws.on('message', async (raw) => {
        if (raw.length > MAX_PAYLOAD) return;
        if (!wsRateOk(ws)) {
            sendJson(ws, { type: 'error', error: 'Rate limit exceeded' });
            return;
        }
        let msg;
        try {
            msg = JSON.parse(raw.toString());
        } catch {
            return;
        }

        if (msg.type === 'auth') {
            const userId = verifyToken(msg.token);
            if (!userId) {
                sendJson(ws, { type: 'auth_error', error: 'Invalid or expired token' });
                ws.close(4001, 'unauthorized');
                return;
            }
            ws.authed = true;
            addSocket(userId, ws);
            sendJson(ws, { type: 'auth_ok', user_id: userId });
            return;
        }

        if (!ws.authed) {
            sendJson(ws, { type: 'error', error: 'Authenticate first' });
            return;
        }

        if (msg.type === 'subscribe') {
            ws.subscribedCid = parseInt(msg.conversation_id, 10) || null;
            sendJson(ws, { type: 'subscribed', conversation_id: ws.subscribedCid });
            touchUserOnline(ws.userId);
            return;
        }

        if (msg.type === 'unsubscribe') {
            ws.subscribedCid = null;
            return;
        }

        if (msg.type === 'ping') {
            touchUserOnline(ws.userId);
            sendJson(ws, { type: 'pong', ts: Date.now() });
        }
    });

    ws.on('close', () => {
        wsRateBuckets.delete(ws);
        removeSocket(ws);
    });
    ws.on('error', () => removeSocket(ws));
});

const heartbeat = setInterval(() => {
    for (const [uid, set] of socketsByUser) {
        for (const ws of set) {
            if (!ws.isAlive) {
                ws.terminate();
                continue;
            }
            ws.isAlive = false;
            sendJson(ws, { type: 'ping' });
        }
        touchUserOnline(uid);
    }
}, 30000);

wss.on('close', () => clearInterval(heartbeat));

async function start() {
    await connectRedis();
    server.listen(PORT, () => {
        console.log(`Chat WebSocket gateway on port ${PORT} (pid ${process.pid})`);
        console.log(`  Real-time path: PHP -> HTTP /internal/publish -> this process`);
        if (!redisActive) {
            console.log('  Tip: Install Redis only for production / PM2 cluster scaling.');
        }
    });
}

start().catch((e) => {
    console.error(e);
    process.exit(1);
});
