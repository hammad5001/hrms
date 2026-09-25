<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['portal_role'] ?? '') !== 'super_admin') {
    header('Location: index.html');
    exit;
}
require_once 'config.php';
require_once 'includes/ip_security.php';

$super_name = $_SESSION['full_name'] ?? 'Super Admin';
$client_ip = get_client_ip();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP Perimeter Security | Super Admin Command Center</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #f97316;
            --primary-rgb: 249, 115, 22;
            --primary-dark: #ea580c;
            --primary-light: rgba(249, 115, 22, 0.12);
            --primary-glow: rgba(249, 115, 22, 0.35);
            --secondary: #10b981;
            --secondary-rgb: 16, 185, 129;
            --danger: #ef4444;
            --danger-rgb: 239, 68, 68;
            --warning: #f59e0b;
            --info: #3b82f6;
            --bg-dark: #080a12;
            --bg-card: rgba(18, 22, 38, 0.82);
            --bg-card-hover: rgba(24, 30, 52, 0.95);
            --text-main: #ffffff;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
            --border-light: rgba(255, 255, 255, 0.08);
            --border-hover: rgba(249, 115, 22, 0.35);
            --radius-xl: 24px;
            --radius-lg: 16px;
            --radius-md: 12px;
            --radius-sm: 8px;
            --shadow-card: 0 20px 40px -15px rgba(0, 0, 0, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.06);
            --shadow-glow: 0 0 25px rgba(249, 115, 22, 0.3);
            --shadow-danger-glow: 0 0 25px rgba(239, 68, 68, 0.3);
            --shadow-success-glow: 0 0 25px rgba(16, 185, 129, 0.3);
        }

        * { margin:0; padding:0; box-sizing:border-box; }
        
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-dark);
            min-height: 100vh;
            color: var(--text-main);
            position: relative;
            overflow-x: hidden;
            padding-bottom: 50px;
        }

        /* High-tech Canvas Background */
        .bg-mesh {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            z-index: -2;
            background: 
                radial-gradient(circle at 10% 20%, rgba(249, 115, 22, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(59, 130, 246, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(139, 92, 246, 0.04) 0%, transparent 60%),
                linear-gradient(180deg, #090b14 0%, #05060b 100%);
        }

        .cyber-grid {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            z-index: -1;
            background-image: 
                linear-gradient(to right, rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            background-size: 40px 40px;
            mask-image: radial-gradient(circle at 50% 50%, black 40%, transparent 80%);
            -webkit-mask-image: radial-gradient(circle at 50% 50%, black 40%, transparent 80%);
        }

        .container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 30px 24px;
        }

        /* Modern Top Bar */
        .top-navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(15, 20, 35, 0.7);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            padding: 16px 28px;
            box-shadow: var(--shadow-card);
            margin-bottom: 30px;
            transition: all 0.3s ease;
        }

        .top-navbar:hover {
            border-color: rgba(255, 255, 255, 0.12);
        }

        .brand-section {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .security-badge-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(249, 115, 22, 0.25));
            border: 1px solid rgba(249, 115, 22, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #f97316;
            box-shadow: 0 0 25px rgba(249, 115, 22, 0.25);
            position: relative;
        }

        .security-badge-icon::after {
            content: '';
            position: absolute;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 8px #10b981;
            top: 4px;
            right: 4px;
            animation: radarPulse 2s infinite ease-in-out;
        }

        @keyframes radarPulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.4); opacity: 0.6; }
        }

        .brand-text h1 {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #ffffff 30%, #f97316 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .super-tag {
            background: linear-gradient(135deg, #facc15, #ca8a04);
            color: #000;
            font-size: 10px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 20px;
            letter-spacing: 0.5px;
            -webkit-text-fill-color: initial;
        }

        .brand-text p {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid var(--border-light);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-main);
        }

        .btn:hover {
            background: rgba(255, 255, 255, 0.09);
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-color: transparent;
            color: white;
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.25);
        }

        .btn-primary:hover {
            box-shadow: var(--shadow-glow);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            border-color: transparent;
            color: white;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.25);
        }

        .btn-success:hover {
            box-shadow: var(--shadow-success-glow);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #b91c1c);
            border-color: transparent;
            color: white;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.25);
        }

        /* Key Metrics / Status Grid */
        .hero-stats-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
        }

        .hero-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-xl);
            border: 1px solid var(--border-light);
            padding: 26px;
            box-shadow: var(--shadow-card);
            position: relative;
            overflow: hidden;
            transition: all 0.35s ease;
        }

        .hero-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, transparent, var(--border-hover), transparent);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .hero-card:hover {
            transform: translateY(-4px);
            border-color: var(--border-hover);
            background: var(--bg-card-hover);
        }

        .hero-card:hover::before {
            opacity: 1;
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .card-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-icon-pill {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        /* Master Toggle Controller */
        .master-toggle-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 10px;
            padding: 16px;
            border-radius: var(--radius-lg);
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.04);
        }

        .status-badge-live {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 13px;
            letter-spacing: 0.3px;
            transition: all 0.3s ease;
        }

        .status-badge-live.active {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: #10b981;
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.25);
        }

        .status-badge-live.inactive {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #f87171;
            box-shadow: 0 0 15px rgba(239, 68, 68, 0.15);
        }

        .switch-custom {
            position: relative;
            display: inline-block;
            width: 64px;
            height: 34px;
        }

        .switch-custom input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .switch-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(255, 255, 255, 0.12);
            transition: .35s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 34px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .switch-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 3px;
            background-color: white;
            transition: .35s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.4);
        }

        input:checked + .switch-slider {
            background: linear-gradient(135deg, #10b981, #059669);
            border-color: #10b981;
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.4);
        }

        input:checked + .switch-slider:before {
            transform: translateX(30px);
        }

        /* Current IP Widget */
        .detected-ip-val {
            font-family: 'JetBrains Mono', monospace;
            font-size: 22px;
            font-weight: 700;
            color: #facc15;
            letter-spacing: 0.5px;
            margin: 6px 0 14px;
            text-shadow: 0 0 15px rgba(250, 204, 21, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quick-whitelist-btn {
            width: 100%;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
            justify-content: center;
            border: none;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(5, 150, 105, 0.3));
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.4);
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .quick-whitelist-btn:hover {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: var(--shadow-success-glow);
            transform: translateY(-2px);
        }

        /* Big Counter */
        .big-stat-val {
            font-size: 40px;
            font-weight: 800;
            color: #fff;
            letter-spacing: -1px;
            line-height: 1;
            margin: 8px 0;
            font-feature-settings: "tnum";
            font-variant-numeric: tabular-nums;
        }

        /* Main Whitelist Management Card */
        .management-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-xl);
            border: 1px solid var(--border-light);
            padding: 30px;
            box-shadow: var(--shadow-card);
            position: relative;
        }

        .section-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .section-bar-title h2 {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-bar-title p {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .controls-group {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .search-container {
            position: relative;
            width: 320px;
        }

        .search-container i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
        }

        .search-container input {
            width: 100%;
            padding: 12px 18px 12px 46px;
            border-radius: 50px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-light);
            color: white;
            font-size: 13px;
            outline: none;
            transition: all 0.25s ease;
        }

        .search-container input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 15px rgba(249, 115, 22, 0.25);
            background: rgba(0, 0, 0, 0.45);
        }

        /* Glass Table */
        .glass-table-wrapper {
            overflow-x: auto;
            border-radius: var(--radius-lg);
            border: 1px solid rgba(255, 255, 255, 0.06);
            background: rgba(10, 14, 26, 0.6);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }

        thead tr {
            background: rgba(255, 255, 255, 0.03);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        th {
            padding: 16px 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
        }

        td {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            vertical-align: middle;
        }

        tbody tr {
            transition: background 0.2s ease;
        }

        tbody tr:hover {
            background: rgba(249, 115, 22, 0.04);
        }

        .ip-badge-text {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            font-weight: 700;
            color: #60a5fa;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 10px;
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.25);
            border-radius: 8px;
        }

        .cidr-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            background: rgba(249, 115, 22, 0.2);
            color: #f97316;
            font-weight: 700;
        }

        .table-pill {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .table-pill.active {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .table-pill.inactive {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .action-cell {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
        }

        .table-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border-light);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 14px;
        }

        .table-btn:hover {
            color: white;
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .table-btn.edit-btn:hover {
            color: #38bdf8;
            border-color: rgba(56, 189, 248, 0.4);
            background: rgba(56, 189, 248, 0.15);
        }

        .table-btn.del-btn:hover {
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.4);
            background: rgba(239, 68, 68, 0.15);
        }

        /* Modal Dialog */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(5, 7, 15, 0.85);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: rgba(20, 26, 44, 0.98);
            border: 1px solid rgba(249, 115, 22, 0.35);
            border-radius: var(--radius-xl);
            padding: 34px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.8), 0 0 35px rgba(249, 115, 22, 0.2);
            animation: modalZoomIn 0.28s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        @keyframes modalZoomIn {
            from { transform: scale(0.92) translateY(10px); opacity: 0; }
            to { transform: scale(1) translateY(0); opacity: 1; }
        }

        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-light);
        }

        .modal-head h3 {
            font-size: 19px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-close-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-light);
            width: 34px;
            height: 34px;
            border-radius: 50%;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .modal-close-btn:hover {
            color: white;
            background: rgba(239, 68, 68, 0.2);
            border-color: #ef4444;
        }

        .form-field {
            margin-bottom: 18px;
        }

        .form-field label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .form-field input, .form-field select {
            width: 100%;
            padding: 14px 18px;
            border-radius: var(--radius-md);
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid var(--border-light);
            color: white;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: all 0.25s ease;
        }

        .form-field input[type="text"] {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
        }

        .form-field input:focus, .form-field select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 15px rgba(249, 115, 22, 0.25);
            background: rgba(0, 0, 0, 0.5);
        }

        .form-hint {
            color: var(--text-dim);
            font-size: 11px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .modal-foot {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 28px;
            padding-top: 18px;
            border-top: 1px solid var(--border-light);
        }

        /* Toast Popup */
        .toast-box {
            position: fixed;
            bottom: 28px;
            right: 28px;
            padding: 16px 24px;
            border-radius: var(--radius-md);
            background: rgba(18, 24, 42, 0.96);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.6);
            z-index: 2000;
            transform: translateY(120px);
            opacity: 0;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .toast-box.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast-box.success { border-left: 4px solid #10b981; }
        .toast-box.error { border-left: 4px solid #ef4444; }

        @media (max-width: 980px) {
            .hero-stats-grid {
                grid-template-columns: 1fr;
            }
            .top-navbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            .nav-actions {
                width: 100%;
                justify-content: space-between;
            }
            .section-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            .controls-group {
                width: 100%;
                flex-direction: column;
            }
            .search-container {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>
    <div class="cyber-grid"></div>

    <div class="container">
        <!-- Top Navbar -->
        <nav class="top-navbar">
            <div class="brand-section">
                <div class="security-badge-icon"><i class="fas fa-shield-halved"></i></div>
                <div class="brand-text">
                    <h1>IP Perimeter Security <span class="super-tag">SUPER ADMIN</span></h1>
                    <p>Restrict HRMS portal access exclusively to authorized network IP addresses</p>
                </div>
            </div>
            <div class="nav-actions">
                <a href="admin.php" class="btn"><i class="fas fa-users-gear"></i> User Directory</a>
                <a href="admin-dashboard.html" class="btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
            </div>
        </nav>

        <!-- Status Overview Grid -->
        <div class="hero-stats-grid">
            <!-- Master Toggle Card -->
            <div class="hero-card" style="border-color: rgba(239, 68, 68, 0.25);">
                <div class="card-top">
                    <span class="card-label"><i class="fas fa-power-off" style="color: #ef4444;"></i> Master Restriction</span>
                    <div class="card-icon-pill" style="background: rgba(239, 68, 68, 0.15); color: #ef4444;"><i class="fas fa-lock"></i></div>
                </div>
                <div class="master-toggle-container">
                    <div>
                        <div id="masterStatusBadge" class="status-badge-live inactive">
                            <i class="fas fa-circle-xmark"></i> Restriction Disabled
                        </div>
                        <p style="font-size: 11px; color: var(--text-dim); margin-top: 6px;">
                            When enabled, non-whitelisted IPs are blocked globally.
                        </p>
                    </div>
                    <label class="switch-custom">
                        <input type="checkbox" id="masterToggle" onchange="toggleMasterRestriction(this.checked)">
                        <span class="switch-slider"></span>
                    </label>
                </div>
            </div>

            <!-- Current IP Widget -->
            <div class="hero-card">
                <div class="card-top">
                    <span class="card-label"><i class="fas fa-network-wired" style="color: #facc15;"></i> Current Connection</span>
                    <span id="currentIpStatusBadge" class="table-pill active"><i class="fas fa-check-circle"></i> Whitelisted</span>
                </div>
                <div class="detected-ip-val" id="myCurrentIpDisplay">
                    <i class="fas fa-laptop" style="font-size: 16px; color: rgba(255,255,255,0.4);"></i>
                    <?php echo htmlspecialchars($client_ip); ?>
                </div>
                <button class="quick-whitelist-btn" onclick="whitelistCurrentIp()">
                    <i class="fas fa-shield-plus"></i> Whitelist My Current IP
                </button>
            </div>

            <!-- Total Rules Stat -->
            <div class="hero-card">
                <div class="card-top">
                    <span class="card-label"><i class="fas fa-list-check" style="color: var(--primary);"></i> Active IP Rules</span>
                    <div class="card-icon-pill" style="background: rgba(249, 115, 22, 0.15); color: var(--primary);"><i class="fas fa-shield"></i></div>
                </div>
                <div class="big-stat-val" id="totalIpsCount">0</div>
                <p style="font-size: 12px; color: var(--text-muted);">
                    Configured allowed addresses &amp; subnets
                </p>
            </div>
        </div>

        <!-- Allowed IP Management Section -->
        <div class="management-card">
            <div class="section-bar">
                <div class="section-bar-title">
                    <h2><i class="fas fa-shield-virus" style="color: var(--primary);"></i> Allowed Network Whitelist</h2>
                    <p>IP addresses and CIDR subnets allowed to bypass perimeter restriction</p>
                </div>
                <div class="controls-group">
                    <div class="search-container">
                        <i class="fas fa-magnifying-glass"></i>
                        <input type="text" id="ipSearchInput" placeholder="Search by IP, subnet or label..." oninput="filterIpTable()">
                    </div>
                    <button class="btn btn-primary" onclick="openAddIpModal()">
                        <i class="fas fa-plus"></i> Add New Rule
                    </button>
                </div>
            </div>

            <div class="glass-table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Network Address / CIDR</th>
                            <th>Description / Branch Label</th>
                            <th>Status</th>
                            <th>Whitelisted By</th>
                            <th>Created Date</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="ipTableBody">
                        <tr>
                            <td colspan="6" style="text-align:center; padding: 40px; color: var(--text-muted);">
                                <i class="fas fa-spinner fa-spin fa-2x" style="color: var(--primary); margin-bottom: 10px; display:block;"></i>
                                Loading perimeter security rules...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Form -->
    <div class="modal-overlay" id="ipModal">
        <div class="modal-box">
            <div class="modal-head">
                <h3 id="modalTitle"><i class="fas fa-plus-circle" style="color: var(--primary);"></i> Add Allowed IP</h3>
                <button class="modal-close-btn" onclick="closeIpModal()"><i class="fas fa-times"></i></button>
            </div>
            <input type="hidden" id="editIpId" value="">
            <div class="form-field">
                <label><i class="fas fa-network-wired" style="color: var(--primary);"></i> IP Address / CIDR Range <span style="color:#ef4444;">*</span></label>
                <input type="text" id="modalIpAddress" placeholder="e.g. 202.142.10.45 or 192.168.1.0/24">
                <div class="form-hint"><i class="fas fa-circle-info"></i> Individual IPv4/IPv6 or subnet CIDR notation supported.</div>
            </div>
            <div class="form-field">
                <label><i class="fas fa-tag" style="color: var(--primary);"></i> Rule Label / Branch Name <span style="color:#ef4444;">*</span></label>
                <input type="text" id="modalLabel" placeholder="e.g. Head Office Rawalpindi Fiber Line">
            </div>
            <div class="form-field">
                <label><i class="fas fa-toggle-on" style="color: var(--primary);"></i> Enforcement Status</label>
                <select id="modalStatus">
                    <option value="1">Active (Allow Access Immediately)</option>
                    <option value="0">Disabled (Temporarily Inactive)</option>
                </select>
            </div>
            <div class="modal-foot">
                <button class="btn" onclick="closeIpModal()">Cancel</button>
                <button class="btn btn-primary" id="saveIpBtn" onclick="saveIp()"><i class="fas fa-save"></i> Save Rule</button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast-box" id="toast">
        <i class="fas fa-circle-check" id="toastIcon" style="color:#10b981; font-size: 18px;"></i>
        <span id="toastMessage">Action completed successfully</span>
    </div>

    <script>
        let allIps = [];

        async function loadSecurityData() {
            try {
                const res = await fetch('api/security_api.php?action=get_security_status', { credentials: 'include' });
                const text = await res.text();
                let json;
                try {
                    json = JSON.parse(text);
                } catch (pe) {
                    console.error('Non-JSON response received:', text);
                    showToast('Server returned invalid response (Status: ' + res.status + ')', 'error');
                    return;
                }

                if (json.success) {
                    const data = json.data;
                    allIps = data.allowed_ips || [];
                    
                    // Master toggle state
                    const toggle = document.getElementById('masterToggle');
                    const badge = document.getElementById('masterStatusBadge');
                    toggle.checked = data.restriction_enabled;
                    
                    if (data.restriction_enabled) {
                        badge.className = 'status-badge-live active';
                        badge.innerHTML = '<i class="fas fa-shield-check"></i> Restriction Active';
                    } else {
                        badge.className = 'status-badge-live inactive';
                        badge.innerHTML = '<i class="fas fa-circle-xmark"></i> Restriction Disabled';
                    }

                    // Client IP display
                    document.getElementById('myCurrentIpDisplay').innerHTML = `<i class="fas fa-laptop" style="font-size: 16px; color: rgba(255,255,255,0.4);"></i> ${data.client_ip}`;
                    const ipBadge = document.getElementById('currentIpStatusBadge');
                    if (data.is_current_ip_whitelisted) {
                        ipBadge.className = 'table-pill active';
                        ipBadge.innerHTML = '<i class="fas fa-check-circle"></i> Whitelisted';
                    } else {
                        ipBadge.className = 'table-pill inactive';
                        ipBadge.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Not Whitelisted';
                    }

                    // Stats
                    document.getElementById('totalIpsCount').textContent = allIps.length;

                    renderTable(allIps);
                } else {
                    showToast(json.message || 'Failed to load security status', 'error');
                }
            } catch (e) {
                console.error('Error loading security data:', e);
                showToast('Network error: ' + (e.message || 'Cannot reach security API'), 'error');
            }
        }

        function renderTable(ips) {
            const tbody = document.getElementById('ipTableBody');
            if (!ips || ips.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-shield-slash fa-2x" style="color: var(--text-dim); margin-bottom: 12px; display:block;"></i>
                    No allowed IPs configured yet. Click "Add New Rule" or whitelist your current connection above.
                </td></tr>`;
                return;
            }

            tbody.innerHTML = ips.map(item => {
                const isActive = item.is_active == 1;
                const statusBadge = isActive
                    ? '<span class="table-pill active"><i class="fas fa-circle-check"></i> Active</span>'
                    : '<span class="table-pill inactive"><i class="fas fa-circle-pause"></i> Disabled</span>';

                const isCidr = item.ip_address.includes('/');
                const cidrTag = isCidr ? '<span class="cidr-badge">CIDR</span>' : '';

                const safeIp = (item.ip_address || '').replace(/'/g, "\\'");
                const safeLabel = (item.label || '').replace(/'/g, "\\'");

                return `<tr>
                    <td>
                        <span class="ip-badge-text">${item.ip_address}</span>
                        ${cidrTag}
                    </td>
                    <td style="font-weight: 600; color: #fff;">${item.label || '—'}</td>
                    <td>${statusBadge}</td>
                    <td style="color: var(--text-muted); font-size: 12px;"><i class="fas fa-user-shield" style="margin-right:4px; opacity:0.6;"></i> ${item.created_by || 'System'}</td>
                    <td style="color: var(--text-muted); font-size: 12px;"><i class="fas fa-calendar-day" style="margin-right:4px; opacity:0.6;"></i> ${(item.created_at || '').substring(0, 10)}</td>
                    <td style="text-align: right;">
                        <div class="action-cell">
                            <button class="table-btn" title="Toggle Status" onclick="toggleSingleIp(${item.id}, ${isActive ? 0 : 1})">
                                <i class="fas ${isActive ? 'fa-pause' : 'fa-play'}" style="color: ${isActive ? '#f59e0b' : '#10b981'};"></i>
                            </button>
                            <button class="table-btn edit-btn" title="Edit Rule" onclick="openEditIpModal(${item.id}, '${safeIp}', '${safeLabel}', ${item.is_active})">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button class="table-btn del-btn" title="Delete Rule" onclick="deleteIp(${item.id}, '${safeIp}')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        function filterIpTable() {
            const query = document.getElementById('ipSearchInput').value.toLowerCase().trim();
            if (!query) {
                renderTable(allIps);
                return;
            }
            const filtered = allIps.filter(item => 
                (item.ip_address && item.ip_address.toLowerCase().includes(query)) ||
                (item.label && item.label.toLowerCase().includes(query))
            );
            renderTable(filtered);
        }

        async function toggleMasterRestriction(enabled) {
            try {
                const res = await fetch('api/security_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ action: 'toggle_restriction', enable: enabled })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    loadSecurityData();
                } else {
                    showToast(data.message || 'Failed to toggle restriction', 'error');
                    document.getElementById('masterToggle').checked = !enabled;
                }
            } catch (e) {
                showToast('Network error: ' + (e.message || 'Could not update restriction'), 'error');
                document.getElementById('masterToggle').checked = !enabled;
            }
        }

        async function whitelistCurrentIp() {
            try {
                const res = await fetch('api/security_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ action: 'allow_current_ip' })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    loadSecurityData();
                } else {
                    showToast(data.message || 'Failed to whitelist current IP', 'error');
                }
            } catch (e) {
                showToast('Network error: ' + (e.message || 'Failed to whitelist current IP'), 'error');
            }
        }

        function openAddIpModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Add Allowed Network Rule';
            document.getElementById('editIpId').value = '';
            document.getElementById('modalIpAddress').value = '';
            document.getElementById('modalLabel').value = '';
            document.getElementById('modalStatus').value = '1';
            document.getElementById('ipModal').classList.add('active');
        }

        function openEditIpModal(id, ip, label, status) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen-to-square" style="color: var(--primary);"></i> Edit Allowed Network Rule';
            document.getElementById('editIpId').value = id;
            document.getElementById('modalIpAddress').value = ip;
            document.getElementById('modalLabel').value = label;
            document.getElementById('modalStatus').value = status;
            document.getElementById('ipModal').classList.add('active');
        }

        function closeIpModal() {
            document.getElementById('ipModal').classList.remove('active');
        }

        async function saveIp() {
            const id = document.getElementById('editIpId').value;
            const ip = document.getElementById('modalIpAddress').value.trim();
            const label = document.getElementById('modalLabel').value.trim();
            const is_active = parseInt(document.getElementById('modalStatus').value, 10);

            if (!ip) {
                showToast('Please enter a valid IP address or CIDR range', 'error');
                return;
            }

            const payload = id ? {
                action: 'update_ip',
                id: parseInt(id, 10),
                ip_address: ip,
                label: label,
                is_active: is_active
            } : {
                action: 'add_ip',
                ip_address: ip,
                label: label,
                is_active: is_active
            };

            const saveBtn = document.getElementById('saveIpBtn');
            const origHtml = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            try {
                const res = await fetch('api/security_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify(payload)
                });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (pe) {
                    console.error('Non-JSON response:', text);
                    showToast('Server returned an unexpected error (Status: ' + res.status + ')', 'error');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    return;
                }

                if (data.success) {
                    showToast(data.message, 'success');
                    closeIpModal();
                    loadSecurityData();
                } else {
                    showToast(data.message || 'Failed to save rule', 'error');
                }
            } catch (e) {
                showToast('Network error: ' + (e.message || 'Could not save rule'), 'error');
            } finally {
                saveBtn.disabled = false;
                saveBtn.innerHTML = origHtml;
            }
        }

        async function toggleSingleIp(id, newStatus) {
            try {
                const res = await fetch('api/security_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ action: 'toggle_ip_status', id: id, is_active: newStatus })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    loadSecurityData();
                } else {
                    showToast(data.message || 'Failed to update status', 'error');
                }
            } catch (e) {
                showToast('Network error: ' + (e.message || 'Failed to update status'), 'error');
            }
        }

        async function deleteIp(id, ip) {
            if (!confirm(`Are you sure you want to remove network rule "${ip}" from the whitelist?`)) {
                return;
            }

            try {
                const res = await fetch('api/security_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ action: 'delete_ip', id: id })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    loadSecurityData();
                } else {
                    showToast(data.message || 'Failed to delete rule', 'error');
                }
            } catch (e) {
                showToast('Network error: ' + (e.message || 'Failed to delete rule'), 'error');
            }
        }

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            const icon = document.getElementById('toastIcon');
            const text = document.getElementById('toastMessage');

            text.textContent = msg;
            toast.className = `toast-box ${type} show`;
            icon.className = type === 'success' ? 'fas fa-circle-check' : 'fas fa-circle-exclamation';
            icon.style.color = type === 'success' ? '#10b981' : '#ef4444';

            setTimeout(() => {
                toast.classList.remove('show');
            }, 3500);
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadSecurityData();
        });
    </script>
</body>
</html>
