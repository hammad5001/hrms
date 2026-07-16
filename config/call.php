<?php
// config/call.php - WebRTC Calling Configuration

define('CALL_FEATURE_ENABLED', true);
define('CALL_TIMEOUT_SECONDS', 30);
define('CALL_HEARTBEAT_TIMEOUT_SECONDS', 15);

// LiveKit Media Server Configuration
define('LIVEKIT_HOST', 'call.example.com');
define('LIVEKIT_WS_URL', 'wss://' . LIVEKIT_HOST);

// Local Token Service
define('CALL_TOKEN_SERVICE_URL', 'http://127.0.0.1:8870');
define('CALL_TOKEN_SERVICE_SECRET', 'balitech_webrtc_super_secret_key');
?>
