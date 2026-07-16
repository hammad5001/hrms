/**
 * Balitech HRMS WebRTC Calling Token Service
 * - Restricted locally to localhost / 127.0.0.1
 * - Securely signs tokens using LiveKit SDK credentials
 */
const express = require('express');
const { AccessToken } = require('livekit-server-sdk');
require('dotenv').config();

const app = express();
app.use(express.json());

const PORT = process.env.PORT || 8870;
const LIVEKIT_API_KEY = process.env.LIVEKIT_API_KEY || 'devkey';
const LIVEKIT_API_SECRET = process.env.LIVEKIT_API_SECRET || 'secret';
const SERVICE_SECRET = process.env.SERVICE_SECRET || 'balitech_webrtc_super_secret_key';

app.post('/token', async (req, res) => {
    try {
        const { room, identity, name, secret } = req.body;

        if (!room || !identity || !name || !secret) {
            return res.status(400).json({ error: 'Missing required parameters' });
        }

        if (secret !== SERVICE_SECRET) {
            return res.status(403).json({ error: 'Forbidden' });
        }

        const at = new AccessToken(LIVEKIT_API_KEY, LIVEKIT_API_SECRET, {
            identity: String(identity),
            name: String(name),
            ttl: '1h'
        });

        at.addGrant({
            roomJoin: true,
            room: String(room),
            canPublish: true,
            canSubscribe: true,
            canPublishData: true
        });

        const token = await at.toJwt();
        return res.json({ token });
    } catch (err) {
        console.error('Error generating token:', err.message);
        return res.status(500).json({ error: 'Internal server error' });
    }
});

app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        service: 'balitech-call-token-service'
    });
});

// Bind explicitly to 127.0.0.1 to prevent external exposure
const server = app.listen(PORT, '127.0.0.1', () => {
    console.log(`LiveKit Token Service running on http://127.0.0.1:${PORT}`);
});
