<?php
/**
 * IP Security Management and Access Control Guard
 * 
 * Provides functions for:
 * - Detecting client IP (with proxy/Cloudflare header support)
 * - CIDR & exact IP matching
 * - System-wide IP restriction checking
 * - Super Admin security enforcement
 */

if (!function_exists('get_client_ip')) {
    function get_client_ip(): string {
        $ip = '';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($list[0]);
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        $ip = trim($ip);
        if (strpos($ip, ':') !== false && strpos($ip, '.') !== false) {
            // IPv4-mapped IPv6 like ::ffff:192.168.1.1
            $parts = explode(':', $ip);
            $ip = end($parts);
        }

        return $ip ?: '127.0.0.1';
    }
}

if (!function_exists('is_local_ip')) {
    function is_local_ip(string $ip): bool {
        if ($ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
            return true;
        }
        return false;
    }
}

if (!function_exists('ip_in_cidr')) {
    function ip_in_cidr(string $ip, string $cidr): bool {
        if (strpos($cidr, '/') === false) {
            return strcasecmp(trim($ip), trim($cidr)) === 0;
        }

        [$subnet, $mask] = explode('/', trim($cidr), 2);
        $mask = (int)$mask;

        // IPv4 CIDR check
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($mask < 0 || $mask > 32) return false;
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask_long = ~((1 << (32 - $mask)) - 1);
            return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
        }

        // IPv6 CIDR check
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($mask < 0 || $mask > 128) return false;
            $ip_bin = inet_pton($ip);
            $subnet_bin = inet_pton($subnet);
            if ($ip_bin === false || $subnet_bin === false) return false;

            $bytes = intdiv($mask, 8);
            $bits = $mask % 8;

            if ($bytes > 0 && substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) {
                return false;
            }

            if ($bits > 0) {
                $mask_byte = chr(0xFF << (8 - $bits) & 0xFF);
                if (($ip_bin[$bytes] & $mask_byte) !== ($subnet_bin[$bytes] & $mask_byte)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }
}

if (!function_exists('is_ip_restriction_enabled')) {
    function is_ip_restriction_enabled(mysqli $conn): bool {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ip_restriction_enabled' LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            $cached = ($row['setting_value'] === '1' || $row['setting_value'] === 'true');
        } else {
            $cached = false;
        }
        return $cached;
    }
}

if (!function_exists('is_ip_allowed')) {
    function is_ip_allowed(mysqli $conn, ?string $client_ip = null): bool {
        $ip = $client_ip ?: get_client_ip();

        // Localhost / Loopback is always allowed for local development
        if (is_local_ip($ip)) {
            return true;
        }

        if (!is_ip_restriction_enabled($conn)) {
            return true;
        }

        // Fetch active allowed IPs from database
        $res = $conn->query("SELECT ip_address FROM allowed_ips WHERE is_active = 1");
        if (!$res || $res->num_rows === 0) {
            // If restriction is enabled but no IPs exist, keep safe fallback (allow) to avoid locking out completely
            return true;
        }

        while ($row = $res->fetch_assoc()) {
            $allowed = trim($row['ip_address']);
            if (ip_in_cidr($ip, $allowed)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('render_ip_blocked_screen')) {
    function render_ip_blocked_screen(string $client_ip): void {
        http_response_code(403);
        $safe_ip = htmlspecialchars($client_ip, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Access Restricted | HRMS Security</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Plus Jakarta Sans',sans-serif; }
        body {
            min-height: 100vh;
            background: #080a12;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        .bg-glow {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: 
                radial-gradient(circle at 50% 30%, rgba(239, 68, 68, 0.12) 0%, transparent 60%),
                radial-gradient(circle at 20% 80%, rgba(249, 115, 22, 0.08) 0%, transparent 50%);
            z-index: -1;
        }
        .security-card {
            background: rgba(18, 22, 38, 0.88);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
            border: 1px solid rgba(239, 68, 68, 0.35);
            border-radius: 28px;
            padding: 44px;
            max-width: 520px;
            width: 100%;
            text-align: center;
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.8), 0 0 40px rgba(239, 68, 68, 0.18);
            position: relative;
        }
        .shield-icon {
            width: 84px;
            height: 84px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(185, 28, 28, 0.35));
            border: 2px solid rgba(239, 68, 68, 0.5);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            color: #ef4444;
            box-shadow: 0 0 30px rgba(239, 68, 68, 0.35);
            animation: pulseShield 2.5s infinite ease-in-out;
        }
        @keyframes pulseShield {
            0%, 100% { transform: scale(1); box-shadow: 0 0 25px rgba(239,68,68,0.3); }
            50% { transform: scale(1.05); box-shadow: 0 0 45px rgba(239,68,68,0.55); }
        }
        h1 { font-size: 24px; font-weight: 800; margin-bottom: 8px; color: #fff; letter-spacing: -0.5px; }
        p.subtitle { font-size: 14px; color: #94a3b8; margin-bottom: 24px; line-height: 1.6; }
        .ip-badge {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 14px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
        }
        .ip-val { font-family: 'JetBrains Mono', monospace; font-size: 16px; font-weight: 700; color: #f87171; letter-spacing: 0.5px; }
        .info-box {
            font-size: 12px;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 28px;
            background: rgba(255, 255, 255, 0.02);
            padding: 14px 18px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.04);
        }
        .btn-retry {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 30px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.25s ease;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 18px rgba(249, 115, 22, 0.35);
        }
        .btn-retry:hover { transform: translateY(-2px); box-shadow: 0 0 25px rgba(249, 115, 22, 0.5); }
    </style>
</head>
<body>
    <div class="bg-glow"></div>
    <div class="security-card">
        <div class="shield-icon"><i class="fas fa-shield-halved"></i></div>
        <h1>Perimeter Access Restricted</h1>
        <p class="subtitle">This HRMS instance is protected by Network Perimeter Whitelisting. Your network address is currently not permitted.</p>
        <div class="ip-badge">
            <span style="color:#94a3b8;"><i class="fas fa-network-wired" style="color:#f97316; margin-right:6px;"></i> Detected IP:</span>
            <span class="ip-val">{$safe_ip}</span>
        </div>
        <div class="info-box">
            If you are connecting from a new office line or authorized VPN, contact your Super Administrator to whitelist this IP.
        </div>
        <div>
            <button class="btn-retry" onclick="window.location.reload()"><i class="fas fa-rotate-right"></i> Refresh Connection</button>
        </div>
    </div>
</body>
</html>
HTML;
        exit;
    }
}

if (!function_exists('enforce_ip_security')) {
    function enforce_ip_security(mysqli $conn, bool $is_api = false): bool {
        $ip = get_client_ip();
        if (is_ip_allowed($conn, $ip)) {
            return true;
        }

        if ($is_api) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'ip_blocked' => true,
                'client_ip' => $ip,
                'message' => 'Access denied: Your IP address (' . $ip . ') is not authorized to access the HRMS system.'
            ]);
            exit;
        }

        render_ip_blocked_screen($ip);
        return false;
    }
}
