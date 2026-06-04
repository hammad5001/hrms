<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balitech · Finance Command Center</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/dropdown-fix.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --primary-glow: rgba(249,115,22,0.5);
            --secondary: #10b981;
            --secondary-glow: rgba(16,185,129,0.3);
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --dark: #0a0c15;
            --darker: #05070f;
            --glass: rgba(255, 255, 255, 0.07);
            --glass-border: rgba(255, 255, 255, 0.1);
            --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: radial-gradient(circle at 20% 30%, #0f0c29, #302b63, #24243e);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        .animated-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            background: linear-gradient(125deg, #0f0c29 0%, #302b63 50%, #24243e 100%);
        }

        .animated-bg::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            top: -50%;
            left: -50%;
            background: radial-gradient(circle, rgba(249,115,22,0.15) 0%, transparent 70%);
            animation: slowRotate 30s linear infinite;
        }

        @keyframes slowRotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            background: rgba(249,115,22,0.3);
            border-radius: 50%;
            animation: float linear infinite;
        }

        @keyframes float {
            0% { transform: translateY(100vh) rotate(0deg); opacity: 0; }
            10% { opacity: 0.5; }
            90% { opacity: 0.5; }
            100% { transform: translateY(-100vh) rotate(720deg); opacity: 0; }
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 10px; }

        .app-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 24px;
            position: relative;
            z-index: 1;
        }

        .header {
            background: rgba(10, 12, 21, 0.6);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 20px 32px;
            margin-bottom: 28px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: var(--shadow);
            animation: slideDown 0.5s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .logo-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: white;
            box-shadow: 0 0 30px var(--primary-glow);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 var(--primary-glow); }
            50% { box-shadow: 0 0 30px 10px var(--primary-glow); }
        }

        .logo-text h1 {
            font-size: 26px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .finance-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(249,115,22,0.15);
            padding: 8px 20px;
            border-radius: 50px;
            border: 1px solid rgba(249,115,22,0.3);
        }

        .nav-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.03);
            padding: 6px;
            border-radius: 60px;
            border: 1px solid rgba(255,255,255,0.05);
        }

        .nav-btn {
            padding: 10px 22px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.3s;
            background: transparent;
            color: rgba(255,255,255,0.7);
            border: none;
            text-decoration: none;
        }

        .nav-btn:hover {
            color: white;
            transform: translateY(-2px);
            background: rgba(255,255,255,0.1);
        }

        .nav-btn.primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 15px rgba(249,115,22,0.4);
        }

        .nav-btn.success {
            background: linear-gradient(135deg, var(--secondary), #059669);
            color: white;
            box-shadow: 0 4px 15px rgba(16,185,129,0.4);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .date-time {
            background: rgba(255,255,255,0.05);
            padding: 10px 22px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 500;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
            backdrop-filter: blur(10px);
        }

        .hero-banner {
            background: linear-gradient(135deg, rgba(102,126,234,0.4), rgba(118,75,162,0.4));
            backdrop-filter: blur(20px);
            border-radius: 32px;
            padding: 40px;
            margin-bottom: 32px;
            border: 1px solid rgba(255,255,255,0.15);
            position: relative;
            overflow: hidden;
        }

        .hero-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(249,115,22,0.2), transparent);
            animation: rotateGlow 20s linear infinite;
        }

        @keyframes rotateGlow {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .hero-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 30px;
        }

        .hero-text h1 {
            font-size: 42px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 12px;
        }

        .hero-text p {
            color: rgba(255,255,255,0.7);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
        }

        .hero-stats {
            display: flex;
            gap: 40px;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(10px);
            padding: 20px 40px;
            border-radius: 40px;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .hero-stat {
            text-align: center;
        }

        .hero-stat h3 {
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
        }

        .hero-stat p {
            font-size: 12px;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .info-banners {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }

        .info-banner {
            background: linear-gradient(135deg, rgba(59,130,246,0.2), rgba(139,92,246,0.1));
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            border: 1px solid rgba(59,130,246,0.2);
        }

        .info-banner.shift {
            background: linear-gradient(135deg, rgba(249,115,22,0.2), rgba(139,92,246,0.1));
            border-color: rgba(249,115,22,0.2);
        }

        .info-badge {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }

        .info-badge.blue { background: rgba(59,130,246,0.2); color: #60a5fa; }
        .info-badge.orange { background: rgba(245,158,11,0.2); color: #f59e0b; }
        .info-badge.green { background: rgba(16,185,129,0.2); color: #10b981; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.05);
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: rgba(249,115,22,0.4);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: rgba(249,115,22,0.15);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: var(--primary);
            margin-bottom: 16px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: white;
        }

        .stat-label {
            font-size: 12px;
            color: rgba(255,255,255,0.6);
        }

        .control-panel {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 24px;
            margin-bottom: 28px;
        }

        .filter-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
        }

        .month-selector {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.05);
            padding: 10px 20px;
            border-radius: 50px;
        }

        .month-selector input {
            background: transparent;
            border: none;
            color: white;
            font-size: 14px;
            cursor: pointer;
        }

        .month-selector input::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.5);
        }

        .search-box input {
            width: 100%;
            padding: 12px 20px 12px 48px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 50px;
            font-size: 14px;
            color: white;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .filter-select {
            padding: 12px 36px 12px 20px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 50px;
            font-size: 14px;
            color: white;
            cursor: pointer;
            min-width: 160px;
        }

        .filter-select option {
            background: #1a1c2c;
            color: #fff;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-secondary {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.8);
        }

        .btn-primary:hover, .btn-secondary:hover {
            transform: translateY(-2px);
        }

        .table-container {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 24px;
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .table-header h2 {
            color: white;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .month-info {
            background: rgba(249,115,22,0.2);
            padding: 6px 16px;
            border-radius: 30px;
            color: var(--primary);
            font-size: 13px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th {
            text-align: left;
            padding: 12px 10px;
            background: rgba(255,255,255,0.05);
            color: rgba(255,255,255,0.7);
            font-weight: 600;
            font-size: 11px;
            position: sticky;
            top: 0;
        }

        td {
            padding: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            color: rgba(255,255,255,0.9);
            font-size: 11px;
        }

        tr:hover td {
            background: rgba(255,255,255,0.03);
            cursor: pointer;
        }

        .checkin-time { font-family: monospace; font-weight: 600; color: var(--primary); }
        .weekend-checkin { font-family: monospace; font-weight: 600; color: #a78bfa; }
        .absent-cell { color: rgba(255,255,255,0.4); font-style: italic; }

        .summary-badge {
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 10px;
            display: inline-block;
        }

        .summary-present { background: rgba(16,185,129,0.2); color: #10b981; }
        .summary-late { background: rgba(245,158,11,0.2); color: #f59e0b; }
        .summary-absent { background: rgba(239,68,68,0.2); color: #ef4444; }
        .summary-leave { background: rgba(139,92,246,0.2); color: #a78bfa; }

        .view-btn {
            background: rgba(59,130,246,0.2);
            color: #60a5fa;
            border: none;
            padding: 5px 10px;
            border-radius: 16px;
            font-size: 10px;
            cursor: pointer;
        }

        .view-btn:hover {
            background: var(--primary);
            color: white;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            backdrop-filter: blur(15px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active { display: flex; animation: modalFadeIn 0.3s ease; }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-content {
            background: linear-gradient(135deg, #1a1c2c, #0f1119);
            border-radius: 32px;
            width: 95%;
            max-width: 900px;
            max-height: 85vh;
            overflow: visible;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .modal-content .modal-body {
            max-height: 70vh;
            overflow-y: auto;
            overflow-x: visible;
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 32px 32px 0 0;
            position: sticky;
            top: 0;
        }

        .modal-header h2 {
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background: rgba(255,255,255,0.2);
            color: white;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: var(--danger);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 24px;
        }

        .employee-profile-card {
            background: rgba(255,255,255,0.05);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .profile-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .profile-info h3 {
            color: white;
            font-size: 18px;
            margin-bottom: 5px;
        }

        .profile-info p {
            color: rgba(255,255,255,0.6);
            font-size: 12px;
        }

        .profile-details-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .profile-detail-item {
            background: rgba(255,255,255,0.03);
            padding: 10px;
            border-radius: 12px;
        }

        .profile-detail-item .label {
            font-size: 10px;
            color: rgba(255,255,255,0.5);
        }

        .profile-detail-item .value {
            font-size: 14px;
            font-weight: 600;
            color: white;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat-card-small {
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            padding: 12px;
            text-align: center;
        }

        .stat-card-small .number {
            font-size: 20px;
            font-weight: 700;
            color: white;
        }

        .stat-card-small .stat-label {
            font-size: 10px;
            color: rgba(255,255,255,0.5);
        }

        .leave-section {
            background: rgba(255,255,255,0.03);
            border-radius: 20px;
            padding: 20px;
            margin-top: 20px;
        }

        .leave-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .leave-header h4 {
            color: white;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .add-leave-btn {
            background: rgba(16,185,129,0.2);
            color: #10b981;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
        }

        .add-leave-form {
            display: none;
            background: rgba(0,0,0,0.3);
            padding: 16px;
            border-radius: 16px;
            margin-bottom: 16px;
        }

        .add-leave-form.show {
            display: block;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .form-group label {
            display: block;
            color: rgba(255,255,255,0.7);
            font-size: 11px;
            margin-bottom: 5px;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            color: white;
            font-size: 12px;
        }

        .submit-leave {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            width: 100%;
        }

        .leave-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .leave-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            margin-bottom: 8px;
        }

        .leave-info {
            flex: 1;
        }

        .leave-date {
            font-size: 12px;
            font-weight: 600;
            color: white;
        }

        .leave-reason {
            font-size: 10px;
            color: rgba(255,255,255,0.5);
        }

        .leave-type {
            background: rgba(139,92,246,0.2);
            color: #a78bfa;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
        }

        .delete-leave {
            background: rgba(239,68,68,0.2);
            color: #ef4444;
            border: none;
            padding: 5px 10px;
            border-radius: 10px;
            cursor: pointer;
            margin-left: 10px;
        }

        .attendance-detail-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .attendance-detail-table th {
            background: rgba(255,255,255,0.05);
            padding: 10px;
            font-size: 11px;
        }

        .attendance-detail-table td {
            padding: 8px;
            font-size: 11px;
        }

        .toast-container {
            position: fixed;
            top: 100px;
            right: 24px;
            z-index: 2000;
        }

        .toast {
            background: rgba(10,12,21,0.9);
            backdrop-filter: blur(20px);
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            transform: translateX(450px);
            transition: transform 0.3s;
            border-left: 3px solid var(--primary);
            font-size: 12px;
        }

        .toast.show {
            transform: translateX(0);
        }

        .footer {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 16px 24px;
            margin-top: 28px;
            text-align: center;
            color: rgba(255,255,255,0.5);
            font-size: 12px;
        }

        .loading-state {
            text-align: center;
            padding: 60px;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(249,115,22,0.2);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 1200px) {
            .stats-cards {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid, .info-banners {
                grid-template-columns: 1fr;
            }
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            .profile-details-grid {
                grid-template-columns: 1fr;
            }
            .filter-row {
                flex-direction: column;
            }
            .hero-stats {
                flex-wrap: wrap;
                justify-content: center;
                gap: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="animated-bg"></div>
    <div class="particles" id="particles"></div>

    <div class="app-container">
        <header class="header">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-chart-line"></i></div>
                <div class="logo-text"><h1>BALITECH · FINANCE</h1></div>
                <div class="finance-badge"><i class="fas fa-coins"></i><span>Payroll Command Center</span></div>
            </div>
            <div class="nav-bar">
                <a href="../admin-dashboard.html" class="nav-btn"><i class="fas fa-chart-pie"></i> Dashboard</a>
                <a href="../profile.php" class="nav-btn"><i class="fas fa-user"></i> Profile</a>
                <a href="attendance-dashboard.html" class="nav-btn"><i class="fas fa-clock"></i> Attendance</a>
                <a href="../logout.php" class="nav-btn primary"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            <div class="header-right">
                <div class="date-time" id="currentDate"><i class="fas fa-calendar-alt"></i><span>Loading...</span></div>
            </div>
        </header>

        <div class="hero-banner">
            <div class="hero-content">
                <div class="hero-text">
                    <h1>Payroll Analytics</h1>
                    <p><i class="fas fa-calculator"></i> Working Days Only (Monday-Friday)</p>
                </div>
                <div class="hero-stats">
                    <div class="hero-stat"><h3 id="totalEmployees">0</h3><p>Total Employees</p></div>
                    <div class="hero-stat"><h3 id="totalPresent">0</h3><p>Present Days</p></div>
                    <div class="hero-stat"><h3 id="totalLate">0</h3><p>Late Arrivals</p></div>
                </div>
            </div>
        </div>

        <div class="info-banners">
            <div class="info-banner">
                <div><i class="fas fa-calendar-week"></i> <strong>📊 Salary Calculation Basis</strong></div>
                <div>
                    <span class="info-badge blue">✅ Present: Working Days Only (Mon-Fri)</span>
                    <span class="info-badge orange">⚠️ Late: Working Days Only (Mon-Fri)</span>
                    <span class="info-badge green">🌿 Leave: Working Days Only (Mon-Fri)</span>
                </div>
            </div>
            <div class="info-banner shift">
                <div><i class="fas fa-clock"></i> <strong>⚠️ Shift Timing Rule</strong></div>
                <div>
                    <span class="info-badge orange">📅 Late arrival: After 6:10 PM (18:10)</span>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-value" id="statTotal">0</div><div class="stat-label">Active Personnel</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div class="stat-value" id="statPresent">0</div><div class="stat-label">Present Days (Working Days)</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-clock"></i></div><div class="stat-value" id="statLate">0</div><div class="stat-label">Late Arrivals</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-chart-line"></i></div><div class="stat-value" id="statRate">0%</div><div class="stat-label">Avg Attendance Rate</div></div>
        </div>

        <div class="control-panel">
            <div class="filter-row">
                <div class="month-selector"><i class="fas fa-calendar-alt"></i><input type="month" id="monthPicker" value="2026-04" onchange="loadAttendanceData()"></div>
                <div class="search-box"><i class="fas fa-search"></i><input type="text" id="searchInput" placeholder="Search by name or ID..."></div>
                <select id="departmentFilter" class="filter-select"><option value="">All Departments</option></select>
                <button class="btn btn-primary" onclick="loadAttendanceData()"><i class="fas fa-sync-alt"></i> Load Data</button>
                <button class="btn btn-secondary" onclick="exportToCSV()"><i class="fas fa-download"></i> Export CSV</button>
            </div>
        </div>

        <div class="table-container">
            <div class="table-header">
                <h2><i class="fas fa-table"></i> Monthly Attendance - Check-in Times</h2>
                <div class="month-info" id="monthInfo">Loading...</div>
            </div>
            <div class="table-wrapper">
                <table id="attendanceTable">
                    <thead id="tableHeader"></thead>
                    <tbody id="tableBody">
                        <tr><td colspan="10"><div class="loading-state"><div class="loading-spinner"></div><p>Loading attendance data...</p></div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <footer class="footer">
            <div><span style="color: var(--primary);">⚡ BALITECH NEXUS</span> · Finance Command Center</div>
            <div><i class="fas fa-shield-alt"></i> Working Days Only (Mon-Fri) for Salary Calculation</div>
        </footer>
    </div>

    <!-- Employee Detail Modal -->
    <div class="modal" id="employeeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-clock"></i> <span id="modalEmployeeName">Employee</span> - Attendance Details</h2>
                <div class="modal-close" onclick="closeModal()">&times;</div>
            </div>
            <div class="modal-body" id="modalBody"><div class="loading-state"><div class="loading-spinner"></div><p>Loading...</p></div></div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        // Global variables
        let allData = [];
        let currentYear = 2026;
        let currentMonth = 4;
        let daysInMonth = 30;
        let workingDaysCount = 0;
        let monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        
        // Leave storage
        let leaves = {};
        const PAYROLL_API = '../api/payroll_api.php';

        async function loadLeavesFromDb() {
            try {
                const res = await fetch(`${PAYROLL_API}?action=getLeaves`, { credentials: 'include' });
                const data = await res.json();
                if (data.success) {
                    leaves = data.data || {};
                }
            } catch (e) {
                console.error('Leave load error', e);
            }
        }

        async function saveLeaves() {
            try {
                await fetch(`${PAYROLL_API}?action=saveLeaves`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ leaves })
                });
            } catch (e) {
                console.error('Leave save error', e);
            }
        }

        function isWeekend(year, month, day) {
            const date = new Date(year, month - 1, day);
            const dayOfWeek = date.getDay();
            return dayOfWeek === 0 || dayOfWeek === 6;
        }

        function getWorkingDaysCount(year, month) {
            let count = 0;
            const days = new Date(year, month, 0).getDate();
            for (let day = 1; day <= days; day++) {
                if (!isWeekend(year, month, day)) count++;
            }
            return count;
        }

        function isCheckinLate(checkinTime) {
            if (!checkinTime || checkinTime === '--:--') return false;
            const hour = parseInt(checkinTime.split(':')[0]);
            const minute = parseInt(checkinTime.split(':')[1]);
            return (hour > 18 || (hour === 18 && minute > 10));
        }

        async function loadEmployeeList() {
            try {
                const response = await fetch('attendance-api.php?action=getFilterOptions');
                const data = await response.json();
                if (data.success && data.data && data.data.departments) {
                    const deptSelect = document.getElementById('departmentFilter');
                    deptSelect.innerHTML = '<option value="">All Departments</option>';
                    data.data.departments.forEach(dept => {
                        deptSelect.innerHTML += `<option value="${dept}">${dept}</option>`;
                    });
                }
            } catch(e) { console.log('Using fallback'); }
        }

        async function loadAttendanceData() {
            const monthPicker = document.getElementById('monthPicker').value;
            const [year, month] = monthPicker.split('-');
            currentYear = parseInt(year);
            currentMonth = parseInt(month);
            daysInMonth = new Date(currentYear, currentMonth, 0).getDate();
            workingDaysCount = getWorkingDaysCount(currentYear, currentMonth);
            await loadLeavesFromDb();

            document.getElementById('monthInfo').innerHTML = `${monthNames[currentMonth - 1]} ${currentYear} (${workingDaysCount} Working Days)`;
            document.getElementById('tableBody').innerHTML = '<tr><td colspan="10"><div class="loading-state"><div class="loading-spinner"></div><p>Fetching attendance records...</p></div></td></tr>';
            
            const startDate = `${year}-${String(month).padStart(2, '0')}-01`;
            const endDate = `${year}-${String(month).padStart(2, '0')}-${daysInMonth}`;
            
            try {
                const response = await fetch(`attendance-api.php?action=getDateRange&start_date=${startDate}&end_date=${endDate}`);
                const result = await response.json();
                
                if (!result.success || !result.data || !result.data.report) {
                    showToast('No data found', 'warning');
                    return;
                }
                
                const rawData = result.data.report;
                allData = [];
                
                for (const emp of rawData) {
                    const empLeaves = leaves[emp.code] || [];
                    const dailyTimes = {};
                    let presentCount = 0;
                    let lateCount = 0;
                    let leaveCount = 0;
                    
                    for (let day = 1; day <= daysInMonth; day++) {
                        const dateStr = `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                        const isWeekendDay = isWeekend(currentYear, currentMonth, day);
                        const hasLeave = empLeaves.some(l => l.date === dateStr);
                        
                        let checkin = '--:--';
                        if (emp.attendance_data && emp.attendance_data[dateStr]) {
                            const punches = emp.attendance_data[dateStr];
                            punches.sort();
                            if (punches.length > 0) {
                                const inTime = new Date(punches[0]);
                                checkin = inTime.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                            }
                        }
                        
                        dailyTimes[day] = checkin;
                        
                        if (isWeekendDay) {
                            // Weekend days are not counted
                        } else if (hasLeave) {
                            leaveCount++;
                        } else if (checkin !== '--:--') {
                            presentCount++;
                            if (isCheckinLate(checkin)) {
                                lateCount++;
                            }
                        }
                    }
                    
                    const absentCount = workingDaysCount - presentCount - leaveCount;
                    const attendanceRate = workingDaysCount > 0 ? Math.round(((presentCount) / workingDaysCount) * 100) : 0;
                    
                    allData.push({
                        id: emp.code,
                        name: emp.name,
                        department: emp.department,
                        designation: emp.designation,
                        branch: emp.branch,
                        team: emp.team,
                        present: presentCount,
                        late: lateCount,
                        absent: absentCount,
                        leave: leaveCount,
                        working_days: workingDaysCount,
                        attendance_rate: attendanceRate,
                        attendance: dailyTimes,
                        leaves: empLeaves
                    });
                }
                
                calculateStats();
                renderTable();
                showToast(`✅ Loaded ${allData.length} employees for ${monthNames[currentMonth - 1]} ${currentYear} (${workingDaysCount} Working Days)`, 'success');
            } catch (error) {
                console.error('Error:', error);
                showToast('Error loading data', 'error');
            }
        }

        function calculateStats() {
            const totalEmployees = allData.length;
            const totalPresent = allData.reduce((sum, emp) => sum + emp.present, 0);
            const totalLate = allData.reduce((sum, emp) => sum + emp.late, 0);
            const avgRate = totalEmployees > 0 ? Math.round(allData.reduce((sum, emp) => sum + emp.attendance_rate, 0) / totalEmployees) : 0;
            
            document.getElementById('totalEmployees').textContent = totalEmployees;
            document.getElementById('totalPresent').textContent = totalPresent.toLocaleString();
            document.getElementById('totalLate').textContent = totalLate.toLocaleString();
            document.getElementById('statTotal').textContent = totalEmployees;
            document.getElementById('statPresent').textContent = totalPresent.toLocaleString();
            document.getElementById('statLate').textContent = totalLate.toLocaleString();
            document.getElementById('statRate').textContent = `${avgRate}%`;
        }

        function renderTable() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const departmentFilter = document.getElementById('departmentFilter').value;
            
            let filtered = allData.filter(emp => {
                const matchSearch = emp.name.toLowerCase().includes(searchTerm) || emp.id.toLowerCase().includes(searchTerm);
                const matchDept = !departmentFilter || emp.department === departmentFilter;
                return matchSearch && matchDept;
            });
            
            let headerHtml = `<tr><th>ID</th><th>Personnel</th><th>Department</th><th>Designation</th><th>Branch</th><th>Team</th>`;
            for (let day = 1; day <= daysInMonth; day++) {
                const isWeekendDay = isWeekend(currentYear, currentMonth, day);
                headerHtml += `<th title="${isWeekendDay ? 'Weekend - Not counted' : 'Working Day'}" style="${isWeekendDay ? 'color: #a78bfa;' : ''}">${day}</th>`;
            }
            headerHtml += `<th>Present</th><th>Absent</th><th>Late</th><th>Leave</th><th>Actions</th></tr>`;
            document.getElementById('tableHeader').innerHTML = headerHtml;
            
            const tbody = document.getElementById('tableBody');
            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="${6 + daysInMonth + 5}" style="text-align:center;padding:40px;">No employees found</td></tr>`;
                return;
            }
            
            tbody.innerHTML = filtered.map(emp => {
                let rowHtml = `<tr onclick="viewEmployeeDetails('${emp.id}', '${emp.name.replace(/'/g, "\\'")}')">`;
                rowHtml += `<td><strong>${emp.id}</strong></td>`;
                rowHtml += `<td><strong>${emp.name}</strong></td>`;
                rowHtml += `<td>${emp.department}</td>`;
                rowHtml += `<td>${emp.designation}</td>`;
                rowHtml += `<td>${emp.branch}</td>`;
                rowHtml += `<td>${emp.team}</td>`;
                
                for (let day = 1; day <= daysInMonth; day++) {
                    const checkin = emp.attendance[day];
                    const isPresent = checkin !== '--:--';
                    const isWeekendDay = isWeekend(currentYear, currentMonth, day);
                    const hasLeave = emp.leaves.some(l => {
                        const leaveDate = l.date;
                        return leaveDate === `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                    });
                    
                    let cellClass = 'absent-cell';
                    let displayText = checkin;
                    
                    if (hasLeave && !isWeekendDay) {
                        cellClass = 'weekend-checkin';
                        displayText = '🌿 Leave';
                    } else if (isPresent) {
                        cellClass = isWeekendDay ? 'weekend-checkin' : 'checkin-time';
                    }
                    
                    rowHtml += `<td class="${cellClass}">${displayText}</td>`;
                }
                
                rowHtml += `<td><span class="summary-badge summary-present">${emp.present}</span></td>`;
                rowHtml += `<td><span class="summary-badge summary-absent">${emp.absent}</span></td>`;
                rowHtml += `<td><span class="summary-badge summary-late">${emp.late}</span></td>`;
                rowHtml += `<td><span class="summary-badge summary-leave">${emp.leave}</span></td>`;
                rowHtml += `<td><button class="view-btn" onclick="event.stopPropagation();viewEmployeeDetails('${emp.id}', '${emp.name.replace(/'/g, "\\'")}')"><i class="fas fa-eye"></i> View</button></td>`;
                rowHtml += `</tr>`;
                return rowHtml;
            }).join('');
        }

        async function viewEmployeeDetails(employeeId, employeeName) {
            const modal = document.getElementById('employeeModal');
            const modalBody = document.getElementById('modalBody');
            const modalName = document.getElementById('modalEmployeeName');
            
            const employee = allData.find(e => e.id === employeeId);
            if (!employee) {
                modalBody.innerHTML = '<div style="text-align:center;padding:40px;color:#f87171;">Employee not found</div>';
                modal.classList.add('active');
                return;
            }
            
            modalName.textContent = employeeName;
            modal.classList.add('active');
            
            let leavesHtml = '';
            if (employee.leaves && employee.leaves.length > 0) {
                leavesHtml = employee.leaves.map(leave => `
                    <div class="leave-item">
                        <div class="leave-info">
                            <div class="leave-date">📅 ${new Date(leave.date).toLocaleDateString()}</div>
                            <div class="leave-reason">${leave.reason || 'No reason provided'}</div>
                        </div>
                        <span class="leave-type">${leave.type || 'Casual Leave'}</span>
                        <button class="delete-leave" onclick="deleteLeave('${employeeId}', '${leave.date}', event)"><i class="fas fa-trash"></i></button>
                    </div>
                `).join('');
            } else {
                leavesHtml = '<div style="text-align:center;padding:20px;color:rgba(255,255,255,0.5);">No leaves recorded</div>';
            }
            
            let tableHtml = `
                <div class="employee-profile-card">
                    <div class="profile-header">
                        <div class="profile-avatar"><i class="fas fa-user"></i></div>
                        <div class="profile-info">
                            <h3>${employee.name}</h3>
                            <p><i class="fas fa-id-card"></i> Employee ID: ${employee.id}</p>
                            <p><i class="fas fa-building"></i> ${employee.department} · ${employee.designation}</p>
                        </div>
                    </div>
                    
                    <div class="profile-details-grid">
                        <div class="profile-detail-item"><div class="label">Branch</div><div class="value">${employee.branch}</div></div>
                        <div class="profile-detail-item"><div class="label">Team</div><div class="value">${employee.team}</div></div>
                        <div class="profile-detail-item"><div class="label">Working Days</div><div class="value">${employee.working_days}</div></div>
                        <div class="profile-detail-item"><div class="label">Attendance Rate</div><div class="value">${employee.attendance_rate}%</div></div>
                    </div>
                    
                    <div class="stats-cards">
                        <div class="stat-card-small"><div class="number" style="color:#10b981;">${employee.present}</div><div class="stat-label">Present Days</div></div>
                        <div class="stat-card-small"><div class="number" style="color:#ef4444;">${employee.absent}</div><div class="stat-label">Absent Days</div></div>
                        <div class="stat-card-small"><div class="number" style="color:#f59e0b;">${employee.late}</div><div class="stat-label">Late Days</div></div>
                        <div class="stat-card-small"><div class="number" style="color:#a78bfa;">${employee.leave}</div><div class="stat-label">Leave Days</div></div>
                        <div class="stat-card-small"><div class="number" style="color:#3b82f6;">${employee.working_days}</div><div class="stat-label">Total Working Days</div></div>
                    </div>
                </div>
                
                <div class="leave-section">
                    <div class="leave-header">
                        <h4><i class="fas fa-umbrella-beach"></i> Leave Management</h4>
                        <button class="add-leave-btn" onclick="toggleLeaveForm('${employeeId}')"><i class="fas fa-plus"></i> Add Leave</button>
                    </div>
                    
                    <div class="add-leave-form" id="leaveForm-${employeeId}">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Leave Date</label>
                                <input type="date" id="leaveDate-${employeeId}" value="${new Date().toISOString().split('T')[0]}">
                            </div>
                            <div class="form-group">
                                <label>Leave Type</label>
                                <select id="leaveType-${employeeId}">
                                    <option value="Casual Leave">Casual Leave</option>
                                    <option value="Sick Leave">Sick Leave</option>
                                    <option value="Annual Leave">Annual Leave</option>
                                    <option value="Unpaid Leave">Unpaid Leave</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Reason (Optional)</label>
                            <input type="text" id="leaveReason-${employeeId}" placeholder="Enter reason for leave...">
                        </div>
                        <button class="submit-leave" onclick="addLeave('${employeeId}')"><i class="fas fa-save"></i> Save Leave</button>
                    </div>
                    
                    <div class="leave-list" id="leaveList-${employeeId}">
                        ${leavesHtml}
                    </div>
                </div>
                
                <div style="overflow-x:auto; margin-top: 20px;">
                    <table class="attendance-detail-table">
                        <thead>
                            <tr><th>Date</th><th>Day</th><th>Check In Time</th><th>Type</th><th>Status</th></tr>
                        </thead>
                        <tbody>
            `;
            
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(currentYear, currentMonth - 1, day);
                const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
                const isWeekendDay = isWeekend(currentYear, currentMonth, day);
                const checkin = employee.attendance[day];
                const isPresent = checkin !== '--:--';
                const isLate = isPresent && isCheckinLate(checkin);
                const hasLeave = employee.leaves.some(l => l.date === `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`);
                
                let type = 'Working Day';
                let status = 'Absent';
                let statusColor = '#ef4444';
                let statusBg = 'rgba(239,68,68,0.2)';
                
                if (isWeekendDay) {
                    type = 'Weekend';
                    if (isPresent) {
                        status = 'Present (Weekend)';
                        statusColor = '#a78bfa';
                        statusBg = 'rgba(139,92,246,0.2)';
                    } else {
                        status = 'Weekend';
                        statusColor = '#6b7280';
                        statusBg = 'rgba(107,114,128,0.2)';
                    }
                } else if (hasLeave) {
                    status = 'On Leave';
                    statusColor = '#a78bfa';
                    statusBg = 'rgba(139,92,246,0.2)';
                } else if (isPresent) {
                    if (isLate) {
                        status = 'Late';
                        statusColor = '#f59e0b';
                        statusBg = 'rgba(245,158,11,0.2)';
                    } else {
                        status = 'Present';
                        statusColor = '#10b981';
                        statusBg = 'rgba(16,185,129,0.2)';
                    }
                }
                
                tableHtml += `
                    <tr>
                        <td>${day} ${monthNames[currentMonth - 1]}</td>
                        <td>${dayName}</td>
                        <td class="${isPresent ? 'checkin-time' : 'absent-cell'}">${checkin}</td>
                        <td style="font-size:11px;${isWeekendDay ? 'color:#a78bfa;' : ''}">${type}</td>
                        <td><span style="background:${statusBg}; color:${statusColor}; padding:4px 10px; border-radius:20px; font-size:11px;">${status}</span></td>
                    </tr>
                `;
            }
            
            tableHtml += `</tbody></table></div><div style="margin-top: 20px; text-align: right;"><button class="btn btn-secondary" onclick="exportEmployeeAttendance('${employeeId}', '${employee.name.replace(/'/g, "\\'")}')"><i class="fas fa-download"></i> Download Report</button></div>`;
            
            modalBody.innerHTML = tableHtml;
        }

        function toggleLeaveForm(employeeId) {
            const form = document.getElementById(`leaveForm-${employeeId}`);
            if (form) form.classList.toggle('show');
        }

        function addLeave(employeeId) {
            const dateInput = document.getElementById(`leaveDate-${employeeId}`);
            const typeSelect = document.getElementById(`leaveType-${employeeId}`);
            const reasonInput = document.getElementById(`leaveReason-${employeeId}`);
            
            if (!dateInput.value) {
                showToast('Please select a date', 'warning');
                return;
            }
            
            const leaveDate = dateInput.value;
            const [year, month, day] = leaveDate.split('-');
            
            if (isWeekend(parseInt(year), parseInt(month), parseInt(day))) {
                showToast('Cannot add leave on weekends (Saturday/Sunday)', 'warning');
                return;
            }
            
            if (!leaves[employeeId]) leaves[employeeId] = [];
            
            const existingLeave = leaves[employeeId].find(l => l.date === leaveDate);
            if (existingLeave) {
                showToast('Leave already exists for this date', 'warning');
                return;
            }
            
            leaves[employeeId].push({
                date: leaveDate,
                type: typeSelect.value,
                reason: reasonInput.value || 'No reason provided',
                addedAt: new Date().toISOString()
            });
            
            leaves[employeeId].sort((a, b) => new Date(a.date) - new Date(b.date));
            saveLeaves();
            
            showToast('✅ Leave added successfully', 'success');
            toggleLeaveForm(employeeId);
            loadAttendanceData();
            closeModal();
            setTimeout(() => viewEmployeeDetails(employeeId, document.getElementById('modalEmployeeName').textContent), 500);
        }

        function deleteLeave(employeeId, leaveDate, event) {
            event.stopPropagation();
            if (confirm('Are you sure you want to delete this leave record?')) {
                if (leaves[employeeId]) {
                    leaves[employeeId] = leaves[employeeId].filter(l => l.date !== leaveDate);
                    if (leaves[employeeId].length === 0) delete leaves[employeeId];
                    saveLeaves();
                    showToast('✅ Leave deleted successfully', 'success');
                    loadAttendanceData();
                    closeModal();
                    setTimeout(() => viewEmployeeDetails(employeeId, document.getElementById('modalEmployeeName').textContent), 500);
                }
            }
        }

        function exportToCSV() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const departmentFilter = document.getElementById('departmentFilter').value;
            
            let filtered = allData.filter(emp => {
                const matchSearch = emp.name.toLowerCase().includes(searchTerm) || emp.id.toLowerCase().includes(searchTerm);
                const matchDept = !departmentFilter || emp.department === departmentFilter;
                return matchSearch && matchDept;
            });
            
            let headers = ['ID', 'Personnel', 'Department', 'Designation', 'Branch', 'Team'];
            for (let day = 1; day <= daysInMonth; day++) {
                headers.push(`${day}`);
            }
            headers.push('Present Days', 'Absent Days', 'Late Days', 'Leave Days', `Working Days (${workingDaysCount})`);
            
            let csvContent = headers.map(h => `"${h}"`).join(',') + '\n';
            
            filtered.forEach(emp => {
                let row = [emp.id, emp.name, emp.department, emp.designation, emp.branch, emp.team];
                for (let day = 1; day <= daysInMonth; day++) {
                    let val = emp.attendance[day];
                    const hasLeave = emp.leaves.some(l => {
                        const leaveDate = l.date;
                        return leaveDate === `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                    });
                    if (hasLeave && !isWeekend(currentYear, currentMonth, day)) val = 'LEAVE';
                    row.push(val);
                }
                row.push(emp.present, emp.absent, emp.late, emp.leave, emp.working_days);
                csvContent += row.map(cell => `"${cell}"`).join(',') + '\n';
            });
            
            downloadCSV(csvContent, `attendance_${currentYear}_${currentMonth}.csv`);
            showToast(`✅ Exported ${filtered.length} employees`, 'success');
        }

        function exportEmployeeAttendance(employeeId, employeeName) {
            const employee = allData.find(e => e.id === employeeId);
            if (!employee) return;
            
            let csvContent = `"Employee Attendance Report"\n`;
            csvContent += `"Employee ID","${employee.id}"\n`;
            csvContent += `"Employee Name","${employee.name}"\n`;
            csvContent += `"Department","${employee.department}"\n`;
            csvContent += `"Designation","${employee.designation}"\n`;
            csvContent += `"Branch","${employee.branch}"\n`;
            csvContent += `"Team","${employee.team}"\n`;
            csvContent += `"Period","${monthNames[currentMonth - 1]} ${currentYear}"\n`;
            csvContent += `"Working Days in Month","${employee.working_days}"\n`;
            csvContent += `"Present Days","${employee.present}"\n`;
            csvContent += `"Late Days","${employee.late}"\n`;
            csvContent += `"Absent Days","${employee.absent}"\n`;
            csvContent += `"Leave Days","${employee.leave}"\n`;
            csvContent += `"Attendance Rate","${employee.attendance_rate}%"\n\n`;
            csvContent += `"Date","Day","Type","Check In Time","Status","Counted for Salary"\n`;
            
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(currentYear, currentMonth - 1, day);
                const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
                const isWeekendDay = isWeekend(currentYear, currentMonth, day);
                const checkin = employee.attendance[day];
                const isPresent = checkin !== '--:--';
                const isLate = isPresent && isCheckinLate(checkin);
                const hasLeave = employee.leaves.some(l => l.date === `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`);
                
                let status = 'Absent';
                let counted = 'Yes';
                let type = 'Working Day';
                
                if (isWeekendDay) {
                    type = 'Weekend';
                    counted = 'No';
                    status = isPresent ? 'Present (Weekend)' : 'Weekend';
                } else if (hasLeave) {
                    status = 'On Leave';
                    counted = 'Yes (Leave)';
                } else if (isPresent) {
                    status = isLate ? 'Late' : 'Present';
                }
                
                csvContent += `"${day} ${monthNames[currentMonth - 1]}","${dayName}","${type}","${checkin}","${status}","${counted}"\n`;
            }
            
            downloadCSV(csvContent, `employee_${employeeId}_${currentYear}_${currentMonth}.csv`);
            showToast(`✅ Downloaded ${employeeName}`, 'success');
        }

        function downloadCSV(content, filename) {
            const blob = new Blob(["\uFEFF" + content], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            a.click();
            URL.revokeObjectURL(url);
        }

        function showToast(message, type) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast show';
            toast.style.borderLeftColor = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#f59e0b';
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i> ${message}`;
            container.appendChild(toast);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        function filterTable() { renderTable(); }
        function closeModal() { document.getElementById('employeeModal').classList.remove('active'); }

        document.getElementById('searchInput').addEventListener('keyup', filterTable);
        document.getElementById('departmentFilter').addEventListener('change', filterTable);
        
        function createParticles() {
            const container = document.getElementById('particles');
            for (let i = 0; i < 50; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                const size = Math.random() * 4 + 2;
                particle.style.width = size + 'px';
                particle.style.height = size + 'px';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDuration = Math.random() * 10 + 10 + 's';
                particle.style.animationDelay = Math.random() * 5 + 's';
                container.appendChild(particle);
            }
        }
        createParticles();
        
        function updateDateTime() {
            const now = new Date();
            document.getElementById('currentDate').innerHTML = `<i class="fas fa-calendar-alt"></i> ${now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true })}`;
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);
        
        // Initialize
        loadEmployeeList();
        loadAttendanceData();
    </script>
</body>
</html>