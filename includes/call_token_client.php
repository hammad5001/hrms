<?php
// includes/call_token_client.php - Issues short-lived LiveKit access tokens from the local Node.js service

require_once __DIR__ . '/../config/call.php';

function call_token_client_generate(string $room_name, string $identity, string $display_name): ?string {
    $url = CALL_TOKEN_SERVICE_URL . '/token';
    $payload = [
        'room' => $room_name,
        'identity' => $identity,
        'name' => $display_name,
        'secret' => CALL_TOKEN_SERVICE_SECRET
    ];
    $body = json_encode($payload);
    if ($body === false) {
        return null;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code === 200 && $response) {
            $data = json_decode($response, true);
            return $data['token'] ?? null;
        }
        return null;
    }

    // Fallback stream context
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 3,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response) {
        $data = json_decode($response, true);
        return $data['token'] ?? null;
    }
    return null;
}
?>
