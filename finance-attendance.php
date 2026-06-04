<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balitech · Finance Intelligence Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/dropdown-fix.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #f97316; --primary-dark: #ea580c;
            --primary-glow: rgba(249,115,22,0.4); --primary-glow-strong: rgba(249,115,22,0.6);
            --secondary: #10b981; --secondary-glow: rgba(16,185,129,0.3);
            --warning: #f59e0b; --danger: #ef4444; --info: #3b82f6;
            --purple: #8b5cf6; --pink: #ec4899; --cyan: #06b6d4;
            --dark: #0a0c15; --darker: #05070f;
            --glass: rgba(255, 255, 255, 0.07); --glass-border: rgba(255, 255, 255, 0.1);
            --glass-border-light: rgba(255, 255, 255, 0.15);
            --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            --shadow-glow: 0 0 30px var(--primary-glow);
            --card-bg: rgba(20, 25, 45, 0.75); --card-bg-solid: #1a1f35;
        }
        body { font-family: 'Inter', sans-serif; background: radial-gradient(circle at 20% 30%, #0f0c29, #302b63, #24243e); min-height: 100vh; position: relative; overflow-x: hidden; }
        .animated-bg { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; background: linear-gradient(125deg, #0f0c29 0%, #302b63 50%, #24243e 100%); }
        .animated-bg::before { content: ''; position: absolute; width: 200%; height: 200%; top: -50%; left: -50%; background: radial-gradient(circle, rgba(249,115,22,0.12) 0%, transparent 70%); animation: slowRotate 30s linear infinite; }
        @keyframes slowRotate { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; overflow: hidden; }
        .particle { position: absolute; background: rgba(249,115,22,0.25); border-radius: 50%; animation: float linear infinite; }
        @keyframes float { 0% { transform: translateY(100vh) rotate(0deg); opacity: 0; } 10% { opacity: 0.6; } 90% { opacity: 0.6; } 100% { transform: translateY(-100vh) rotate(720deg); opacity: 0; } }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 10px; }
        .app-container { max-width: 1600px; margin: 0 auto; padding: 24px; position: relative; z-index: 1; }
        .header { background: var(--card-bg); backdrop-filter: blur(20px); border-radius: 28px; padding: 20px 32px; margin-bottom: 28px; border: 1px solid var(--glass-border); box-shadow: var(--shadow); animation: slideDown 0.5s ease; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; }
        .logo { display: flex; align-items: center; gap: 14px; }
        .logo-icon { width: 52px; height: 52px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 26px; color: white; box-shadow: 0 0 30px var(--primary-glow); animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { box-shadow: 0 0 0 0 var(--primary-glow); } 50% { box-shadow: 0 0 30px 10px var(--primary-glow); } }
        .logo-text h1 { font-size: 26px; font-weight: 800; background: linear-gradient(135deg, #fff, var(--primary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .finance-badge { display: flex; align-items: center; gap: 8px; background: rgba(249,115,22,0.15); padding: 8px 20px; border-radius: 50px; border: 1px solid rgba(249,115,22,0.3); }
        .nav-bar { display: flex; align-items: center; gap: 12px; background: rgba(255,255,255,0.03); padding: 6px; border-radius: 60px; border: 1px solid var(--glass-border); }
        .nav-btn { padding: 10px 22px; border-radius: 50px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 10px; cursor: pointer; transition: all 0.3s; background: transparent; color: rgba(255,255,255,0.7); border: none; text-decoration: none; }
        .nav-btn:hover { color: white; transform: translateY(-2px); background: rgba(255,255,255,0.1); }
        .nav-btn.primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; box-shadow: 0 4px 15px rgba(249,115,22,0.4); }
        .nav-btn.success { background: linear-gradient(135deg, var(--secondary), #059669); color: white; box-shadow: 0 4px 15px rgba(16,185,129,0.4); }
        .header-right { display: flex; align-items: center; gap: 12px; }
        .date-time { background: rgba(255,255,255,0.05); padding: 10px 22px; border-radius: 50px; font-size: 13px; font-weight: 500; color: white; display: flex; align-items: center; gap: 10px; backdrop-filter: blur(10px); }
        .hero-section { background: linear-gradient(135deg, rgba(249,115,22,0.15), rgba(139,92,246,0.15)); backdrop-filter: blur(20px); border-radius: 32px; padding: 40px; margin-bottom: 32px; border: 1px solid var(--glass-border-light); position: relative; overflow: hidden; }
        .hero-section::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(249,115,22,0.15), transparent); animation: rotateGlow 20s linear infinite; }
        @keyframes rotateGlow { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .hero-content { position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 30px; }
        .hero-text h1 { font-size: 42px; font-weight: 800; background: linear-gradient(135deg, #fff, var(--primary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 12px; }
        .hero-text p { color: rgba(255,255,255,0.7); display: flex; align-items: center; gap: 8px; font-size: 15px; }
        .hero-stats { display: flex; gap: 40px; background: rgba(0,0,0,0.4); backdrop-filter: blur(10px); padding: 20px 40px; border-radius: 40px; border: 1px solid var(--glass-border); }
        .hero-stat { text-align: center; }
        .hero-stat h3 { font-size: 36px; font-weight: 800; background: linear-gradient(135deg, #fff, var(--primary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 5px; }
        .hero-stat p { font-size: 12px; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: 1px; }
        .info-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 28px; }
        .info-card { background: var(--card-bg); backdrop-filter: blur(20px); border-radius: 20px; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--glass-border); transition: all 0.3s; }
        .info-card:hover { border-color: rgba(249,115,22,0.4); transform: translateY(-2px); }
        .info-card-left { display: flex; align-items: center; gap: 12px; }
        .info-icon { width: 40px; height: 40px; background: rgba(59,130,246,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #60a5fa; }
        .info-icon.warning { background: rgba(245,158,11,0.2); color: #f59e0b; }
        .info-icon.success { background: rgba(16,185,129,0.2); color: #10b981; }
        .info-text { font-size: 13px; color: rgba(255,255,255,0.7); }
        .info-text strong { color: white; font-size: 14px; }
        .info-badge { padding: 6px 14px; border-radius: 30px; font-size: 12px; font-weight: 600; }
        .info-badge.old { background: rgba(245,158,11,0.2); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3); }
        .info-badge.new { background: rgba(16,185,129,0.2); color: #10b981; border: 1px solid rgba(16,185,129,0.3); }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
        .stat-card { background: var(--card-bg); backdrop-filter: blur(20px); border-radius: 28px; padding: 24px; border: 1px solid var(--glass-border); transition: all 0.4s; position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: linear-gradient(90deg, var(--primary), var(--primary-dark)); transform: translateX(-100%); transition: transform 0.4s; }
        .stat-card:hover::before { transform: translateX(0); }
        .stat-card:hover { transform: translateY(-5px); border-color: rgba(249,115,22,0.3); }
        .stat-icon { width: 50px; height: 50px; background: rgba(249,115,22,0.15); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: var(--primary); margin-bottom: 16px; }
        .stat-value { font-size: 32px; font-weight: 800; color: white; }
        .stat-label { font-size: 12px; color: rgba(255,255,255,0.6); margin-top: 4px; }
        .stat-trend { font-size: 11px; margin-top: 8px; display: flex; align-items: center; gap: 4px; }
        .stat-trend.up { color: var(--secondary); }
        .stat-trend.down { color: var(--danger); }
        .charts-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; margin-bottom: 28px; }
        .chart-card { background: var(--card-bg); backdrop-filter: blur(20px); border-radius: 28px; padding: 24px; border: 1px solid var(--glass-border); transition: all 0.3s; }
        .chart-card:hover { border-color: rgba(249,115,22,0.3); }
        .chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--glass-border); }
        .chart-header h3 { font-size: 16px; font-weight: 600; color: white; display: flex; align-items: center; gap: 8px; }
        .chart-header h3 i { color: var(--primary); }
        .chart-container { height: 260px; position: relative; }
        .control-panel { background: var(--card-bg); backdrop-filter: blur(20px); border-radius: 28px; padding: 24px; margin-bottom: 28px; border: 1px solid var(--glass-border); }
        .filter-row { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }
        .month-selector { display: flex; align-items: center; gap: 10px; background: rgba(255,255,255,0.05); padding: 10px 20px; border-radius: 50px; }
        .month-selector input { background: transparent; border: none; color: white; font-size: 14px; }
        .search-box { flex: 1; min-width: 250px; position: relative; }
        .search-box i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,0.5); }
        .search-box input { width: 100%; padding: 12px 20px 12px 48px; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 50px; font-size: 14px; color: white; }
        .search-box input:focus { outline: none; border-color: var(--primary); }
        .filter-select { padding: 12px 36px 12px 20px; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 50px; font-size: 14px; color: white; cursor: pointer; min-width: 160px; }
        .filter-select option { background: #1a1c2c; color: #fff; }
        .btn { padding: 12px 24px; border: none; border-radius: 50px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; box-shadow: 0 4px 15px rgba(249,115,22,0.3); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(249,115,22,0.5); }
        .btn-secondary { background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: rgba(255,255,255,0.8); }
        .btn-secondary:hover { background: rgba(255,255,255,0.1); transform: translateY(-2px); }
        .btn-success { background: linear-gradient(135deg, var(--secondary), #059669); color: white; box-shadow: 0 4px 15px rgba(16,185,129,0.3); }
        .btn-danger { background: linear-gradient(135deg, var(--danger), #dc2626); color: white; }
        .btn-info { background: linear-gradient(135deg, var(--info), #2563eb); color: white; }
        .btn-purple { background: linear-gradient(135deg, var(--purple), #7c3aed); color: white; }
        .table-container { background: var(--card-bg); backdrop-filter: blur(20px); border-radius: 28px; padding: 24px; overflow: visible; border: 1px solid var(--glass-border); }
        .table-wrapper { overflow-x: auto; border-radius: 16px; }
        .table-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 16px; }
        .table-header h2 { color: white; font-size: 18px; display: flex; align-items: center; gap: 10px; }
        .month-info { background: rgba(249,115,22,0.2); padding: 6px 16px; border-radius: 30px; color: var(--primary); font-size: 13px; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        th { text-align: left; padding: 14px 12px; background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.8); font-weight: 600; font-size: 12px; position: sticky; top: 0; z-index: 10; }
        td { padding: 12px; border-bottom: 1px solid var(--glass-border); color: rgba(255,255,255,0.9); font-size: 12px; }
        tr:hover td { background: rgba(255,255,255,0.05); cursor: pointer; }
        .checkin-time { font-family: monospace; font-weight: 600; color: var(--primary); }
        .weekend-checkin { font-family: monospace; font-weight: 600; color: #a78bfa; }
        .absent-cell { color: rgba(255,255,255,0.4); font-style: italic; }
        .summary-badge { font-weight: 600; padding: 4px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .summary-present { background: rgba(16,185,129,0.2); color: #10b981; border: 1px solid rgba(16,185,129,0.3); }
        .summary-late { background: rgba(245,158,11,0.2); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3); }
        .summary-absent { background: rgba(239,68,68,0.2); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
        .summary-leave { background: rgba(139,92,246,0.2); color: #a78bfa; border: 1px solid rgba(139,92,246,0.3); }
        .view-btn { background: rgba(59,130,246,0.2); color: #60a5fa; border: none; padding: 6px 12px; border-radius: 16px; font-size: 11px; cursor: pointer; transition: all 0.2s; }
        .view-btn:hover { background: var(--primary); color: white; }
        .payroll-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); backdrop-filter: blur(15px); z-index: 2000; justify-content: center; align-items: center; }
        .payroll-modal.active { display: flex; animation: modalFadeIn 0.3s ease; }
        @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .payroll-modal-content { background: linear-gradient(135deg, #1a1c2c, #0f1119); border-radius: 32px; width: 98%; max-width: 1800px; max-height: 95vh; overflow-y: auto; border: 1px solid var(--glass-border); box-shadow: var(--shadow); }
        .payroll-modal-header { padding: 24px 32px; border-bottom: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, var(--secondary), #059669); border-radius: 32px 32px 0 0; position: sticky; top: 0; z-index: 10; }
        .payroll-modal-header h2 { color: white; display: flex; align-items: center; gap: 12px; font-size: 24px; }
        .payroll-modal-close { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; background: rgba(255,255,255,0.2); color: white; font-size: 20px; transition: all 0.3s; }
        .payroll-modal-close:hover { background: var(--danger); transform: rotate(90deg); }
        .payroll-modal-body { padding: 24px 32px; }

        .payroll-tabs { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; background: rgba(255,255,255,0.03); padding: 6px; border-radius: 16px; border: 1px solid var(--glass-border); }
        .payroll-tab { padding: 10px 20px; border-radius: 12px; font-size: 13px; font-weight: 600; cursor: pointer; background: transparent; color: rgba(255,255,255,0.6); border: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s; }
        .payroll-tab:hover { color: white; background: rgba(255,255,255,0.05); }
        .payroll-tab.active { background: linear-gradient(135deg, var(--secondary), #059669); color: white; box-shadow: 0 4px 15px rgba(16,185,129,0.4); }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .payroll-rules { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        .rule-card { background: rgba(255,255,255,0.05); border-radius: 16px; padding: 16px; text-align: center; border: 1px solid var(--glass-border); transition: all 0.3s; }
        .rule-card:hover { transform: translateY(-3px); border-color: var(--secondary); }
        .rule-icon { width: 42px; height: 42px; margin: 0 auto 10px; background: rgba(16,185,129,0.15); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; color: var(--secondary); }
        .rule-icon.danger { background: rgba(239,68,68,0.15); color: var(--danger); }
        .rule-icon.warning { background: rgba(245,158,11,0.15); color: var(--warning); }
        .rule-icon.info { background: rgba(59,130,246,0.15); color: var(--info); }
        .rule-icon.purple { background: rgba(139,92,246,0.15); color: var(--purple); }
        .rule-title { font-size: 12px; font-weight: 700; color: white; margin-bottom: 6px; }
        .rule-amount { font-size: 16px; font-weight: 800; color: var(--secondary); }
        .rule-amount.negative { color: var(--danger); }
        .rule-desc { font-size: 10px; color: rgba(255,255,255,0.5); margin-top: 6px; }
        .payroll-summary { display: grid; grid-template-columns: repeat(6, 1fr); gap: 16px; margin-bottom: 24px; }
        .summary-card { background: rgba(255,255,255,0.05); backdrop-filter: blur(10px); border-radius: 16px; padding: 16px; border: 1px solid var(--glass-border); text-align: center; }
        .summary-card .value { font-size: 22px; font-weight: 800; color: var(--secondary); }
        .summary-card .value.negative { color: var(--danger); }
        .summary-card .value.warning { color: var(--warning); }
        .summary-card .value.info { color: var(--info); }
        .summary-card .label { font-size: 11px; color: rgba(255,255,255,0.6); margin-top: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
        .payroll-table-container { overflow-x: auto; margin-top: 20px; background: rgba(0,0,0,0.2); border-radius: 16px; padding: 16px; }
        .payroll-table { width: 100%; border-collapse: collapse; min-width: 2400px; }
        .payroll-table th { background: rgba(255,255,255,0.08); padding: 12px 10px; font-size: 11px; font-weight: 600; color: rgba(255,255,255,0.85); white-space: nowrap; text-align: center; border-bottom: 2px solid rgba(16,185,129,0.3); }
        .payroll-table td { padding: 10px; border-bottom: 1px solid var(--glass-border); font-size: 11px; color: rgba(255,255,255,0.9); white-space: nowrap; text-align: center; }
        .payroll-table tr:hover td { background: rgba(255,255,255,0.03); }
        .payroll-table .sticky-col { position: sticky; left: 0; background: #161a2c; z-index: 5; }
        .amount-positive { color: var(--secondary); font-weight: 600; }
        .amount-negative { color: var(--danger); font-weight: 600; }
        .amount-neutral { color: rgba(255,255,255,0.4); }
        .badge-perfect { background: rgba(16,185,129,0.2); color: #10b981; padding: 4px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-warning { background: rgba(245,158,11,0.2); color: #f59e0b; padding: 4px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-danger { background: rgba(239,68,68,0.2); color: #ef4444; padding: 4px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .view-slip-btn { background: rgba(59,130,246,0.2); color: #60a5fa; border: none; padding: 5px 10px; border-radius: 16px; font-size: 10px; cursor: pointer; transition: all 0.2s; margin: 2px; }
        .view-slip-btn:hover { background: var(--primary); color: white; }
        .edit-btn { background: rgba(245,158,11,0.2); color: #f59e0b; border: none; padding: 5px 10px; border-radius: 16px; font-size: 10px; cursor: pointer; margin: 2px; }
        .edit-btn:hover { background: var(--warning); color: white; }

        .adj-section { background: rgba(255,255,255,0.04); border-radius: 20px; padding: 24px; margin-bottom: 24px; border: 1px solid var(--glass-border); }
        .adj-section h3 { color: white; font-size: 16px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; padding-bottom: 12px; border-bottom: 1px solid var(--glass-border); }
        .adj-section h3 i { color: var(--secondary); }
        .adj-form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 12px; }
        .adj-form-grid.three { grid-template-columns: repeat(3, 1fr); }
        .adj-form-grid.two { grid-template-columns: repeat(2, 1fr); }
        .adj-input { padding: 10px 14px; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 10px; color: white; font-size: 13px; width: 100%; }
        .adj-input:focus { outline: none; border-color: var(--secondary); }
        .adj-label { color: rgba(255,255,255,0.7); font-size: 11px; margin-bottom: 4px; display: block; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .adj-list { margin-top: 16px; max-height: 220px; overflow-y: auto; }
        .adj-item { display: grid; grid-template-columns: 2fr 1.5fr 2fr 1fr 0.5fr; gap: 12px; padding: 12px; background: rgba(255,255,255,0.03); border-radius: 10px; margin-bottom: 8px; align-items: center; font-size: 12px; }
        .adj-item .name { color: white; font-weight: 600; }
        .adj-item .amt { color: var(--secondary); font-weight: 700; }
        .adj-item .amt.neg { color: var(--danger); }
        .adj-item .reason { color: rgba(255,255,255,0.6); font-size: 11px; }
        .adj-delete { background: rgba(239,68,68,0.2); color: var(--danger); border: none; padding: 6px 10px; border-radius: 8px; cursor: pointer; }
        .adj-delete:hover { background: var(--danger); color: white; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(12px); z-index: 1000; justify-content: center; align-items: center; }
        .modal.active { display: flex; }
        .modal-content { background: linear-gradient(135deg, #1e1b2e, #13112a); border-radius: 28px; width: 90%; max-width: 900px; max-height: 85vh; overflow: visible; border: 1px solid var(--glass-border); box-shadow: var(--shadow); }
        .modal-content > .modal-body { max-height: 70vh; overflow-y: auto; overflow-x: visible; }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 28px 28px 0 0; }
        .modal-header h2 { color: white; display: flex; align-items: center; gap: 10px; font-size: 18px; }
        .modal-close { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; cursor: pointer; background: rgba(255,255,255,0.2); color: white; transition: all 0.3s; }
        .modal-close:hover { background: var(--danger); transform: rotate(90deg); }
        .modal-body { padding: 24px; }
        .loading-state { text-align: center; padding: 60px; }
        .loading-spinner { width: 40px; height: 40px; border: 3px solid rgba(249,115,22,0.2); border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 16px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .toast-container { position: fixed; top: 100px; right: 24px; z-index: 3000; }
        .toast { background: rgba(10,12,21,0.95); backdrop-filter: blur(20px); padding: 12px 20px; border-radius: 12px; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; transform: translateX(450px); transition: transform 0.3s; border-left: 3px solid var(--primary); font-size: 12px; color: white; }
        .toast.show { transform: translateX(0); }
        .footer { background: var(--card-bg); backdrop-filter: blur(20px); border-radius: 24px; padding: 16px 24px; margin-top: 28px; text-align: center; color: rgba(255,255,255,0.5); font-size: 12px; border: 1px solid var(--glass-border); }

        .slip-pro { background: linear-gradient(135deg, #ffffff, #f8fafc); color: #1e293b; border-radius: 20px; padding: 32px; font-family: 'Inter', sans-serif; }
        .slip-pro * { color: inherit; }
        .slip-header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 20px; border-bottom: 3px solid var(--primary); margin-bottom: 24px; }
        .slip-company h1 { color: var(--primary); font-size: 26px; font-weight: 800; margin-bottom: 4px; }
        .slip-company p { color: #64748b; font-size: 12px; }
        .slip-meta { text-align: right; font-size: 12px; color: #475569; }
        .slip-meta strong { color: #1e293b; }
        .slip-emp-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; padding: 16px; background: #f1f5f9; border-radius: 12px; margin-bottom: 20px; }
        .slip-emp-grid div { font-size: 12px; }
        .slip-emp-grid div span { color: #64748b; }
        .slip-emp-grid div strong { color: #0f172a; display: block; font-size: 13px; }
        .slip-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .slip-table th { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; padding: 10px; font-size: 12px; text-align: left; }
        .slip-table td { padding: 10px; border-bottom: 1px solid #e2e8f0; font-size: 12px; color: #1e293b; }
        .slip-table .amt { text-align: right; font-weight: 600; font-family: monospace; }
        .slip-table .pos { color: #059669; }
        .slip-table .neg { color: #dc2626; }
        .slip-totals { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
        .slip-total-card { background: #f8fafc; border-radius: 12px; padding: 16px; border-left: 4px solid var(--secondary); }
        .slip-total-card.deduction { border-left-color: var(--danger); }
        .slip-total-card .lbl { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        .slip-total-card .val { font-size: 22px; font-weight: 800; color: #059669; margin-top: 4px; }
        .slip-total-card.deduction .val { color: #dc2626; }
        .slip-net { background: linear-gradient(135deg, #059669, #10b981); color: white; border-radius: 16px; padding: 20px 24px; margin-top: 16px; display: flex; justify-content: space-between; align-items: center; }
        .slip-net h3 { font-size: 16px; font-weight: 600; }
        .slip-net .amount { font-size: 32px; font-weight: 800; }
        .slip-footer { margin-top: 24px; padding-top: 16px; border-top: 1px dashed #cbd5e1; text-align: center; font-size: 11px; color: #64748b; }
        .slip-actions { display: flex; gap: 10px; justify-content: flex-end; padding: 16px 24px; background: rgba(0,0,0,0.3); border-radius: 0 0 28px 28px; }

        @media (max-width: 1400px) { .payroll-summary { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 1024px) { .payroll-rules { grid-template-columns: repeat(2, 1fr); } .payroll-summary { grid-template-columns: repeat(2, 1fr); } .charts-row { grid-template-columns: 1fr; } .adj-form-grid, .adj-form-grid.three { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) { .stats-grid, .info-cards { grid-template-columns: 1fr; } .payroll-rules, .payroll-summary { grid-template-columns: 1fr; } .hero-stats { flex-wrap: wrap; justify-content: center; gap: 20px; } .filter-row { flex-direction: column; } .adj-form-grid, .adj-form-grid.three, .adj-form-grid.two { grid-template-columns: 1fr; } }

        .payroll-tabs { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 1px solid var(--glass-border); padding-bottom: 10px; overflow-x: auto; }
        .payroll-tab { padding: 10px 20px; background: var(--glass); border: 1px solid var(--glass-border); color: rgba(255,255,255,0.6); border-radius: 12px; cursor: pointer; transition: all 0.3s; white-space: nowrap; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .payroll-tab:hover { background: rgba(255,255,255,0.1); color: white; }
        .payroll-tab.active { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 5px 15px var(--primary-glow); }
        
        .payroll-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .summary-card { background: var(--card-bg); padding: 20px; border-radius: 20px; border: 1px solid var(--glass-border); text-align: center; }
        .summary-card .value { font-size: 22px; font-weight: 800; color: white; margin-bottom: 5px; }
        .summary-card .value.negative { color: var(--danger); }
        .summary-card .value.warning { color: var(--warning); }
        .summary-card .value.info { color: var(--info); }
        .summary-card .label { font-size: 11px; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 1px; }
        
        .payroll-rules { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 30px; }
        .rule-card { background: rgba(255,255,255,0.03); padding: 15px; border-radius: 15px; border: 1px dashed var(--glass-border); display: flex; align-items: center; gap: 12px; }
        .rule-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: rgba(249,115,22,0.1); color: var(--primary); font-size: 14px; }
        .rule-icon.warning { background: rgba(245,158,11,0.1); color: var(--warning); }
        .rule-icon.danger { background: rgba(239,68,68,0.1); color: var(--danger); }
        .rule-icon.info { background: rgba(59,130,246,0.1); color: var(--info); }
        .rule-title { font-size: 13px; font-weight: 600; color: white; }
        .rule-amount { font-size: 12px; font-weight: 700; color: var(--secondary); margin-left: auto; }
        .rule-amount.negative { color: var(--danger); }

        .payroll-table-container { background: var(--card-bg); border-radius: 15px; border: 1px solid var(--glass-border); overflow: hidden; margin-top: 20px; }
        .payroll-table { width: 100%; border-collapse: collapse; }
        .payroll-table th { background: rgba(255,255,255,0.05); padding: 12px 15px; text-align: left; font-size: 12px; color: rgba(255,255,255,0.5); border-bottom: 1px solid var(--glass-border); }
        .payroll-table td { padding: 12px 15px; font-size: 12px; color: white; border-bottom: 1px solid var(--glass-border); }
        .payroll-table tr:hover td { background: rgba(255,255,255,0.02); }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Employee Search Results Modal/List */
        .employee-search-results {
            position: absolute;
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            max-height: 300px;
            overflow-y: auto;
            width: 100%;
            z-index: 100;
            margin-top: 4px;
        }
        .employee-search-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid var(--glass-border);
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .employee-search-item:hover {
            background: rgba(249,115,22,0.15);
        }
        .employee-search-item .emp-name {
            font-weight: 600;
            color: white;
        }
        .employee-search-item .emp-code {
            font-size: 11px;
            color: var(--text-muted, #94a3b8);
        }
        .search-input-wrapper {
            position: relative;
        }

        @media print {
            body * { visibility: hidden; }
            #printArea, #printArea * { visibility: visible; }
            #printArea { position: absolute; left: 0; top: 0; width: 100%; }
            .slip-actions { display: none !important; }
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
                <div class="finance-badge"><i class="fas fa-coins"></i><span>Payroll Intelligence Hub</span></div>
            </div>
            <div class="nav-bar">
                <a href="admin-dashboard.html" class="nav-btn"><i class="fas fa-chart-pie"></i> Dashboard</a>
                <a href="profile.php" class="nav-btn"><i class="fas fa-user"></i> Profile</a>
                <a href="chat-portal.html" class="nav-btn"><i class="fas fa-comments"></i> Chat</a>
                <a href="attendance/attendance-dashboard.html" class="nav-btn"><i class="fas fa-clock"></i> Attendance</a>
                <button class="nav-btn success" onclick="openPayrollDashboard()"><i class="fas fa-file-invoice-dollar"></i> Payroll</button>
                <a href="logout.php" class="nav-btn primary"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
            <div class="header-right">
                <div class="date-time" id="currentDate"><i class="fas fa-calendar-alt"></i><span>Loading...</span></div>
            </div>
        </header>

        <div class="hero-section">
            <div class="hero-content">
                <div class="hero-text">
                    <h1>Payroll Analytics</h1>
                    <p><i class="fas fa-calculator"></i> Working Days Only (Monday-Friday) · Real-time Salary Calculations</p>
                </div>
                <div class="hero-stats">
                    <div class="hero-stat"><h3 id="totalEmployees">0</h3><p>Total Employees</p></div>
                    <div class="hero-stat"><h3 id="totalPresent">0</h3><p>Present Days</p></div>
                    <div class="hero-stat"><h3 id="totalLate">0</h3><p>Late Arrivals</p></div>
                </div>
            </div>
        </div>

        <div class="info-cards">
            <div class="info-card">
                <div class="info-card-left">
                    <div class="info-icon"><i class="fas fa-calendar-week"></i></div>
                    <div class="info-text"><strong>Salary Calculation Basis</strong><br>Working Days Only (Mon-Fri)</div>
                </div>
                <div>
                    <span class="info-badge old">✅ Present </span>
                    <span class="info-badge old">⚠️ Late </span>
                    <span class="info-badge new">🌿 Leave </span>
                </div>
            </div>
            <div class="info-card">
                <div class="info-card-left">
                    <div class="info-icon warning"><i class="fas fa-clock"></i></div>
                    <div class="info-text"><strong>⚠️ Shift Timing Change Alert</strong></div>
                </div>
                <div>
                    <span class="info-badge old">📅 March 1-8: Late after 7:00 PM</span>
                    <span class="info-badge new">📅 March 9-31: Late after 6:10 PM</span>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-value" id="statTotal">0</div><div class="stat-label">Active Personnel</div><div class="stat-trend up"><i class="fas fa-arrow-up"></i> Full Capacity</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div class="stat-value" id="statPresent">0</div><div class="stat-label">Present Days</div><div class="stat-trend up"><i class="fas fa-chart-line"></i> Working Days Only</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-clock"></i></div><div class="stat-value" id="statLate">0</div><div class="stat-label">Late Arrivals</div><div class="stat-trend down"><i class="fas fa-exclamation-triangle"></i> Working Days Only</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-chart-line"></i></div><div class="stat-value" id="statRate">0%</div><div class="stat-label">Attendance Rate</div><div class="stat-trend up"><i class="fas fa-arrow-up"></i> Monthly Average</div></div>
        </div>

        <div class="charts-row">
            <div class="chart-card">
                <div class="chart-header"><h3><i class="fas fa-chart-line"></i> Attendance Trend</h3><span class="stat-trend up">Last 30 days</span></div>
                <div class="chart-container"><canvas id="attendanceTrendChart"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="chart-header"><h3><i class="fas fa-chart-pie"></i> Department Distribution</h3><span class="stat-trend up">By department</span></div>
                <div class="chart-container"><canvas id="departmentChart"></canvas></div>
            </div>
        </div>

        <div class="control-panel">
            <div class="filter-row">
                <div class="month-selector"><i class="fas fa-calendar-alt"></i><input type="month" id="monthPicker" value="2026-03"></div>
                <div class="search-box"><i class="fas fa-search"></i><input type="text" id="searchInput" placeholder="Search by name or ID..."></div>
                <select id="departmentFilter" class="filter-select"><option value="">All Departments</option></select>
                <!-- NEW: Team Lead Filter Dropdown -->
                <select id="teamLeadFilter" class="filter-select" onchange="filterByTeamLead()">
                    <option value="">All Employees</option>
                    <option value="Team Lead">Team Leads Only</option>
                </select>
                <button class="btn btn-primary" onclick="loadAttendanceData()"><i class="fas fa-sync-alt"></i> Load Data</button>
                <button class="btn btn-secondary" onclick="exportToCSV()"><i class="fas fa-download"></i> Export CSV</button>
                <button class="btn btn-secondary" onclick="openPayrollDashboard()"><i class="fas fa-file-invoice-dollar"></i> Payroll Dashboard</button>
            </div>
        </div>

        <div class="table-container">
            <div class="table-header">
                <h2><i class="fas fa-table"></i> Monthly Attendance · Check-in Times</h2>
                <div class="month-info" id="monthInfo">March 2026</div>
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
            <div><span style="color: var(--primary);">⚡ BALITECH NEXUS</span> · Finance Intelligence Hub v5.0</div>
            <div><i class="fas fa-shield-alt"></i> Working Days Only (Mon-Fri) for Salary Calculation</div>
        </footer>
    </div>

    <div class="modal" id="payrollModal">
        <div class="modal-content" style="max-width: 1300px; width: 95%; height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-header">
                <h2><i class="fas fa-file-invoice-dollar"></i> Advanced Payroll System · <span id="payrollMonthLabel">March 2026</span></h2>
                <div style="display:flex;gap:10px;align-items:center;">
                    <button class="btn btn-secondary" onclick="exportPayrollCSV()" style="padding:8px 16px;font-size:12px;"><i class="fas fa-file-csv"></i> Export Payroll</button>
                    <button class="btn btn-secondary" onclick="processFullPayroll()" style="padding:8px 16px;font-size:12px;"><i class="fas fa-play"></i> Re-Calculate All</button>
                    <div class="modal-close" onclick="closePayrollDashboard()">&times;</div>
                </div>
            </div>
            <div class="modal-body" id="payrollModalBody" style="flex:1; overflow-y:auto; padding:30px;">
                <div class="loading-state"><div class="loading-spinner"></div><p>Calculating payroll...</p></div>
            </div>
        </div>
    </div>

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
        // ================== EXISTING ATTENDANCE CODE ==================
        const API_BASE = 'attendance/';
        let allData = [];
        let currentYear = 2026;
        let currentMonth = 3;
        let daysInMonth = 31;
        let workingDaysCount = 0;
        let leaves = {};
        let attendanceTrendChart = null;
        let departmentChart = null;
        const PAYROLL_API = 'api/payroll_api.php';
        let payrollSaveTimer = null;

        function payrollMonthStr() {
            return `${currentYear}-${String(currentMonth).padStart(2, '0')}`;
        }

        // ================== PAYROLL CONSTANTS ==================
        const BASE_SALARY = 50000;
        const PERFECT_ATTENDANCE_BONUS = 5000;
        const LATE_PENALTY = 300;
        const ABSENT_PENALTY = 5000;
        const CONTINUOUS_ABSENCE_PENALTY = 15000;
        const NCNS_PENALTY = 5000;
        const MISSPUNCH_DEDUCTION = 1000;
        const PROBATION_DAYS = 60;
        const TAX_RATE = 0;

        // ================== PAYROLL ADJUSTMENTS (DATABASE) ==================
        let payrollAdj = {
            tada: {}, arrears: {}, bonus: {}, halfDay: {}, ncns: {}, sd: {},
            qaHr: {}, misspunch: {}, advance: {}, manualLate: {}, manualPunctuality: {},
            manualLeaves: {}, tax: {}, appointmentDate: {}, empMeta: {}
        };

        async function loadAllAdj() {
            try {
                const res = await fetch(`${PAYROLL_API}?action=getMonthBundle&month=${payrollMonthStr()}`, { credentials: 'include' });
                const data = await res.json();
                if (data.success && data.data) {
                    const b = data.data.bundle || {};
                    payrollAdj = {
                        tada: b.tada || {}, arrears: b.arrears || {}, bonus: b.bonus || {},
                        halfDay: b.halfDay || {}, ncns: b.ncns || {}, sd: b.sd || {},
                        qaHr: b.qaHr || {}, misspunch: b.misspunch || {}, advance: b.advance || {},
                        manualLate: b.manualLate || {}, manualPunctuality: b.manualPunctuality || {},
                        manualLeaves: b.manualLeaves || {}, tax: b.tax || {},
                        appointmentDate: b.appointmentDate || {}, empMeta: b.empMeta || {}
                    };
                    if (data.data.leaves) {
                        leaves = data.data.leaves;
                    }
                }
            } catch (e) {
                console.error('Failed to load payroll from database', e);
                showToast('Could not load payroll data from server', 'error');
            }
        }

        async function persistAllAdjNow() {
            try {
                const res = await fetch(`${PAYROLL_API}?action=saveMonthBundle`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        month: payrollMonthStr(),
                        bundle: payrollAdj,
                        leaves: leaves
                    })
                });
                const data = await res.json();
                if (!data.success) {
                    showToast(data.error || 'Failed to save payroll', 'error');
                }
            } catch (e) {
                console.error('Payroll save error', e);
                showToast('Payroll save failed', 'error');
            }
        }

        function persistAllAdj() {
            clearTimeout(payrollSaveTimer);
            payrollSaveTimer = setTimeout(() => persistAllAdjNow(), 500);
        }

        function convertTo12Hour(time24h) {
            if (!time24h || time24h === '--:--' || time24h === '---') return '--:--';
            if (time24h.match(/(AM|PM)/i)) return time24h;
            try {
                if (time24h.includes(':')) {
                    const parts = time24h.split(':');
                    let hour = parseInt(parts[0]);
                    const minute = parts[1];
                    const period = hour >= 12 ? 'PM' : 'AM';
                    let hour12 = hour % 12;
                    if (hour12 === 0) hour12 = 12;
                    return `${hour12}:${minute} ${period}`;
                }
                return time24h;
            } catch(e) { return time24h; }
        }

        async function saveLeaves() {
            try {
                await fetch(`${PAYROLL_API}?action=saveLeaves`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ leaves: leaves, month: payrollMonthStr() })
                });
            } catch (e) {
                console.error('Leave save error', e);
            }
        }

        function isWeekend(year, month, day) {
            const date = new Date(year, month - 1, day);
            return date.getDay() === 0 || date.getDay() === 6;
        }

        function getWorkingDaysCount(year, month) {
            let count = 0;
            const days = new Date(year, month, 0).getDate();
            for (let day = 1; day <= days; day++) {
                if (!isWeekend(year, month, day)) count++;
            }
            return count;
        }

        function isCheckinLate(checkinTime, day) {
            if (!checkinTime || checkinTime === '--:--') return false;
            let hour;
            if (checkinTime.match(/(AM|PM)/i)) {
                const match = checkinTime.match(/(\d+):(\d+)\s*(AM|PM)/i);
                if (match) {
                    hour = parseInt(match[1]);
                    const period = match[3].toUpperCase();
                    if (period === 'PM' && hour !== 12) hour += 12;
                    if (period === 'AM' && hour === 12) hour = 0;
                } else { return false; }
            } else { hour = parseInt(checkinTime.split(':')[0]); }
            const minute = parseInt(checkinTime.split(':')[1]) || 0;
            if (day <= 8) { return (hour > 19 || (hour === 19 && minute > 0)); }
            else { return (hour > 18 || (hour === 18 && minute > 10)); }
        }

        async function loadEmployeeList() {
            try {
                const response = await fetch(API_BASE + 'attendance-api.php?action=getFilterOptions');
                const data = await response.json();
                if (data.success && data.data && data.data.departments) {
                    const deptSelect = document.getElementById('departmentFilter');
                    deptSelect.innerHTML = '<option value="">All Departments</option>';
                    data.data.departments.forEach(dept => { deptSelect.innerHTML += `<option value="${dept}">${dept}</option>`; });
                }
            } catch(e) { console.log('Using fallback'); }
        }

        // NEW FUNCTION: Filter by Team Lead
        function filterByTeamLead() {
            renderTable();
        }

        async function loadAttendanceData() {
            const monthPicker = document.getElementById('monthPicker').value;
            const [year, month] = monthPicker.split('-');
            currentYear = parseInt(year);
            currentMonth = parseInt(month);
            daysInMonth = new Date(currentYear, currentMonth, 0).getDate();
            workingDaysCount = getWorkingDaysCount(currentYear, currentMonth);
            await loadAllAdj();

            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            document.getElementById('monthInfo').innerHTML = `${monthNames[currentMonth - 1]} ${currentYear} (${workingDaysCount} Working Days)`;
            document.getElementById('payrollMonthLabel').textContent = `${monthNames[currentMonth - 1]} ${currentYear}`;

            const startDate = `${year}-${month}-01`;
            const endDate = `${year}-${month}-${daysInMonth}`;
            document.getElementById('tableBody').innerHTML = '<tr><td colspan="10"><div class="loading-state"><div class="loading-spinner"></div><p>Fetching attendance records...</p></div></td></tr>';

            try {
                const summaryResponse = await fetch(API_BASE + `attendance-api.php?action=getDateRange&start_date=${startDate}&end_date=${endDate}`);
                const summaryResult = await summaryResponse.json();
                if (!summaryResult.success || !summaryResult.data || !summaryResult.data.report) {
                    showToast('No data found', 'warning');
                    return;
                }

                const dailyCheckins = {};
                const dates = [];
                for (let d = 1; d <= daysInMonth; d++) { dates.push(`${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`); }

                for (const date of dates) {
                    try {
                        const dailyResponse = await fetch(API_BASE + `attendance-api.php?action=getLiveAttendance&date=${date}`);
                        const dailyResult = await dailyResponse.json();
                        if (dailyResult.success && dailyResult.data && dailyResult.data.attendance) {
                            dailyResult.data.attendance.forEach(emp => {
                                const empId = emp.code;
                                let checkinTime = emp.in_time;
                                checkinTime = convertTo12Hour(checkinTime);
                                if (!dailyCheckins[empId]) dailyCheckins[empId] = {};
                                dailyCheckins[empId][date] = checkinTime;
                            });
                        }
                    } catch(e) { console.log(`Error fetching data for ${date}`); }
                    await new Promise(resolve => setTimeout(resolve, 20));
                }

                allData = summaryResult.data.report.map(emp => {
                    const dailyTimes = {};
                    let presentCount = 0, lateCount = 0, leaveCount = 0;
                    const empLeaves = leaves[emp.code] || [];

                    for (let day = 1; day <= daysInMonth; day++) {
                        const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                        let checkin = dailyCheckins[emp.code] ? dailyCheckins[emp.code][dateStr] : null;
                        let formattedTime = checkin || '--:--';
                        dailyTimes[day] = formattedTime;
                        const isLeaveDay = empLeaves.some(l => l.date === dateStr);
                        const isWorkingDay = !isWeekend(currentYear, currentMonth, day);
                        if (isLeaveDay && isWorkingDay) { leaveCount++; }
                        else if (isWorkingDay && !isLeaveDay) {
                            if (formattedTime !== '--:--') {
                                presentCount++;
                                if (isCheckinLate(formattedTime, day)) lateCount++;
                            }
                        }
                    }
                    const absent = workingDaysCount - presentCount - leaveCount;
                    const attendance_rate = workingDaysCount > 0 ? Math.round((presentCount / workingDaysCount) * 100) : 0;

                    return {
                        id: emp.code, name: emp.name, department: emp.department || 'General',
                        designation: emp.designation || 'Employee', branch: emp.branch || 'Main', team: emp.team || 'No Team',
                        cnic: emp.cnic || '', contact: emp.contact || '', accountNo: emp.account_no || '',
                        accountTitle: emp.account_title || '', bankName: emp.bank_name || '',
                        appointmentDate: payrollAdj.appointmentDate[emp.code] || emp.appointment_date || '',
                        present: presentCount, late: lateCount, absent: absent, leave: leaveCount,
                        working_days: workingDaysCount, attendance_rate: attendance_rate,
                        attendance: dailyTimes, leaves: empLeaves
                    };
                });

                calculateStats();
                renderTable();
                updateCharts();
                showToast(`✅ Loaded ${allData.length} employees (Working Days: ${workingDaysCount})`, 'success');
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

        function getMonthAbbr(month) { return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][month - 1]; }
        let currentDepartment = 'all';
        let searchTerm = '';

        function renderTable() {
            let filtered = allData;
            
            // Filter by Department
            if (currentDepartment && currentDepartment !== 'all' && currentDepartment !== '') {
                filtered = filtered.filter(e => e.department && e.department.toLowerCase() === currentDepartment.toLowerCase());
            }
            
            // NEW: Filter by Team Lead (checks if designation contains "Team Lead" or "Lead" or specific team lead teams)
            const teamLeadFilter = document.getElementById('teamLeadFilter').value;
            if (teamLeadFilter === 'Team Lead') {
                filtered = filtered.filter(e => {
                    const designation = (e.designation || '').toLowerCase();
                    const team = (e.team || '').toLowerCase();
                    // Check if designation contains 'team lead' or 'lead'
                    // Or if team is 'Developer Team Lead' or 'Dialer Team Lead' etc.
                    return designation.includes('team lead') || 
                           designation.includes('lead') || 
                           team.includes('team lead') ||
                           (team === 'developer team' && designation === 'lead') ||
                           (team === 'dialer team' && designation === 'lead');
                });
            }
            
            // Filter by Search Term
            if (searchTerm) {
                filtered = filtered.filter(e => e.name?.toLowerCase().includes(searchTerm.toLowerCase()) || e.id?.includes(searchTerm) || e.department?.toLowerCase().includes(searchTerm.toLowerCase()));
            }
            
            if (filtered.length === 0) {
                document.getElementById('tableBody').innerHTML = '<tr><td colspan="11"><div class="loading-state"><i class="fas fa-users-slash"></i><p>No personnel found</p></div></td></tr>';
                return;
            }
            let headerHtml = `<tr><th>ID</th><th>Personnel</th><th>Department</th><th>Designation</th><th>Branch</th><th>Team</th>`;
            for (let day = 1; day <= daysInMonth; day++) {
                let tooltip = day <= 8 ? "Late after 7:00 PM" : "Late after 6:10 PM";
                if (isWeekend(currentYear, currentMonth, day)) tooltip = "Weekend - Not counted in salary";
                headerHtml += `<th title="${tooltip}" style="${isWeekend(currentYear, currentMonth, day) ? 'color: #a78bfa;' : ''}">${day} ${getMonthAbbr(currentMonth)}</th>`;
            }
            headerHtml += `<th>Present</th><th>Absent</th><th>Late</th><th>Leave</th><th>Actions</th></tr>`;
            document.getElementById('tableHeader').innerHTML = headerHtml;
            const tbody = document.getElementById('tableBody');
            let html = '';
            filtered.forEach(emp => {
                const initials = emp.name ? emp.name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2) : '??';
                html += `<tr onclick="viewEmployeeDetails('${emp.id}', '${emp.name.replace(/'/g, "\\'")}')" style="cursor:pointer;">` +
                    `<td><span style="font-weight:600;">${emp.id || '---'}</span></td>` +
                    `<td><div style="display:flex;align-items:center;gap:12px;"><div style="width:36px;height:36px;background:linear-gradient(135deg, var(--primary), var(--primary-dark));border-radius:10px;display:flex;align-items:center;justify-content:center;color:white;font-weight:600;">${initials}</div><div><div style="font-weight:600;">${emp.name || 'Unknown'}</div><div style="font-size:11px;color:rgba(255,255,255,0.5);">${emp.id || '---'}</div></div></div></td>` +
                    `<td><span style="background:linear-gradient(135deg, #667eea, #764ba2);padding:4px 12px;border-radius:20px;font-size:11px;">${emp.department || 'General'}</span></td>` +
                    `<td><span style="background:linear-gradient(135deg, #8b5cf6, #6d28d9);padding:4px 12px;border-radius:20px;font-size:11px;">${emp.designation || 'Employee'}</span></td>` +
                    `<td><span style="background:linear-gradient(135deg, #10b981, #059669);padding:4px 12px;border-radius:20px;font-size:11px;">${emp.branch || 'Head Office'}</span></td>` +
                    `<td><span style="background:linear-gradient(135deg, #f59e0b, #d97706);padding:4px 12px;border-radius:20px;font-size:11px;">${emp.team || 'No Team'}</span></td>`;
                for (let day = 1; day <= daysInMonth; day++) {
                    const checkin = emp.attendance[day];
                    const isPresent = checkin !== '--:--';
                    const isWeekendDay = isWeekend(currentYear, currentMonth, day);
                    const hasLeave = emp.leaves.some(l => l.date === `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`);
                    let cellClass = 'absent-cell';
                    let displayText = checkin;
                    if (hasLeave && !isWeekendDay) { cellClass = 'weekend-checkin'; displayText = '🌿 Leave'; }
                    else if (isPresent) { cellClass = isWeekendDay ? 'weekend-checkin' : 'checkin-time'; }
                    html += `<td class="${cellClass}">${displayText}</td>`;
                }
                html += `<td><span class="summary-badge summary-present">${emp.present}</span></td>` +
                    `<td><span class="summary-badge summary-absent">${emp.absent}</span></td>` +
                    `<td><span class="summary-badge summary-late">${emp.late}</span></td>` +
                    `<td><span class="summary-badge summary-leave">${emp.leave}</span></td>` +
                    `<td><button class="view-btn" onclick="event.stopPropagation();viewEmployeeDetails('${emp.id}', '${emp.name.replace(/'/g, "\\'")}')"><i class="fas fa-eye"></i> View</button></td>` +
                    `</tr>`;
            });
            tbody.innerHTML = html;
        }

        function updateCharts() {
            const last7Days = []; const attendanceData = [];
            for (let i = 6; i >= 0; i--) {
                const date = new Date(); date.setDate(date.getDate() - i);
                last7Days.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                attendanceData.push(Math.floor(Math.random() * 50) + 200);
            }
            if (attendanceTrendChart) attendanceTrendChart.destroy();
            const ctx1 = document.getElementById('attendanceTrendChart').getContext('2d');
            attendanceTrendChart = new Chart(ctx1, { type: 'line', data: { labels: last7Days, datasets: [{ label: 'Present Employees', data: attendanceData, borderColor: '#f97316', backgroundColor: 'rgba(249,115,22,0.1)', fill: true, tension: 0.4, pointBackgroundColor: '#f97316', pointBorderColor: 'white', pointRadius: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#94a3b8' } } }, scales: { y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } }, x: { ticks: { color: '#94a3b8' } } } } });
            const deptMap = new Map();
            allData.forEach(emp => { deptMap.set(emp.department, (deptMap.get(emp.department) || 0) + 1); });
            const sortedDepts = Array.from(deptMap.entries()).sort((a, b) => b[1] - a[1]).slice(0, 6);
            if (departmentChart) departmentChart.destroy();
            const ctx2 = document.getElementById('departmentChart').getContext('2d');
            departmentChart = new Chart(ctx2, { type: 'doughnut', data: { labels: sortedDepts.map(d => d[0]), datasets: [{ data: sortedDepts.map(d => d[1]), backgroundColor: ['#f97316', '#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ec4899'], borderWidth: 0 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#94a3b8', boxWidth: 12, font: { size: 11 } } } }, cutout: '60%' } });
        }

        // ================== ENHANCED PROFESSIONAL PAYROLL CALCULATION ==================
        function getEmpMeta(empId) {
            if (!payrollAdj.empMeta[empId]) {
                payrollAdj.empMeta[empId] = {
                    basicSalary: BASE_SALARY,
                    punctualityEnabled: true,
                    sudoName: '',
                    designation: '',
                    cnic: ''
                };
            }
            return payrollAdj.empMeta[empId];
        }

        function sumAdjustments(empId, adjType) {
            const list = payrollAdj[adjType][empId] || [];
            return list.reduce((s, x) => s + (parseFloat(x.amount) || 0), 0);
        }

        function countAdjustments(empId, adjType) {
            return (payrollAdj[adjType][empId] || []).length;
        }

        function isProbationCompleted(emp) {
            if (!emp.appointmentDate) return true;
            try {
                const apptDate = new Date(emp.appointmentDate);
                const monthEndDate = new Date(currentYear, currentMonth - 1, daysInMonth);
                const diffDays = Math.floor((monthEndDate - apptDate) / (1000 * 60 * 60 * 24));
                return diffDays >= PROBATION_DAYS;
            } catch(e) { return true; }
        }

        function calculatePayrollForEmployee(emp) {
            const meta = getEmpMeta(emp.id);
            const basicSalary = parseFloat(meta.basicSalary) || BASE_SALARY;
            const punctualityBonus = meta.punctualityEnabled ? PERFECT_ATTENDANCE_BONUS : 0;
            const totalSalary = basicSalary + punctualityBonus;
            const perDaySalary = workingDaysCount > 0 ? totalSalary / workingDaysCount : 0;

            let approvedLeaves = parseInt(payrollAdj.manualLeaves[emp.id] || 0);
            let autoLeave = 0;
            if (isProbationCompleted(emp) && emp.absent > 0 && approvedLeaves === 0) {
                autoLeave = 1;
            }
            const totalApprovedLeaves = approvedLeaves + autoLeave;
            const adjustedLeaveCount = Math.min(emp.absent, totalApprovedLeaves);
            const adjustedAbsent = Math.max(0, emp.absent - adjustedLeaveCount);
            const totalWorkingDays = emp.present + adjustedLeaveCount;

            let punctualityQualified = false;
            let punctualityAmount = 0;
            const manualPunc = payrollAdj.manualPunctuality[emp.id];
            if (manualPunc !== undefined) {
                punctualityAmount = parseFloat(manualPunc) || 0;
                punctualityQualified = punctualityAmount > 0;
            } else if (meta.punctualityEnabled) {
                if (totalWorkingDays === workingDaysCount && emp.late < 3) {
                    punctualityQualified = true;
                    punctualityAmount = punctualityBonus;
                }
            }

            let lateDeduction = 0;
            if (emp.late >= 3) {
                if (punctualityQualified) {
                    punctualityAmount = 0;
                    punctualityQualified = false;
                } else {
                    lateDeduction = emp.late * LATE_PENALTY;
                }
            }
            const manualLate = parseFloat(payrollAdj.manualLate[emp.id] || 0);
            if (manualLate > 0) lateDeduction = manualLate;

            const tada = sumAdjustments(emp.id, 'tada');
            const bonus = sumAdjustments(emp.id, 'bonus');
            const arrears = sumAdjustments(emp.id, 'arrears');
            let extraDays = Math.max(0, emp.present - workingDaysCount);
            let extraDayPay = extraDays * perDaySalary;
            const halfDayCount = countAdjustments(emp.id, 'halfDay');
            const halfDayAmount = halfDayCount * (perDaySalary / 2);
            const ncnsCount = countAdjustments(emp.id, 'ncns');
            const ncnsAmount = ncnsCount * NCNS_PENALTY;
            const sdCount = countAdjustments(emp.id, 'sd');
            const sdAmount = sdCount * (perDaySalary * 2);
            const qaHrAmount = sumAdjustments(emp.id, 'qaHr');
            const misspunchCount = countAdjustments(emp.id, 'misspunch');
            const misspunchAmount = misspunchCount * MISSPUNCH_DEDUCTION;
            
            const advanceData = payrollAdj.advance[emp.id];
            let advanceDeduction = 0;
            let advanceRemaining = 0;
            if (advanceData) {
                const remaining = (parseFloat(advanceData.total) || 0) - (parseFloat(advanceData.paid) || 0);
                if (remaining > 0) {
                    const monthKey = `${currentYear}-${String(currentMonth).padStart(2,'0')}`;
                    const skipMonths = advanceData.skipMonths || [];
                    if (!skipMonths.includes(monthKey)) {
                        advanceDeduction = Math.min(parseFloat(advanceData.perMonth) || 0, remaining);
                    }
                }
                advanceRemaining = remaining - advanceDeduction;
            }

            const absentDeduction = adjustedAbsent * perDaySalary;
            const tax = parseFloat(payrollAdj.tax[emp.id] || 0);
            const earningsBase = totalWorkingDays * perDaySalary;
            const totalEarnings = earningsBase + punctualityAmount + bonus + tada + arrears + extraDayPay;
            const totalDeductions = lateDeduction + halfDayAmount + ncnsAmount + sdAmount + qaHrAmount + misspunchAmount + advanceDeduction + tax;
            const grossSalary = totalEarnings - totalDeductions;

            let status = 'Good', statusClass = 'badge-perfect';
            if (punctualityQualified && emp.absent === 0 && emp.late === 0) { status = 'Perfect'; statusClass = 'badge-perfect'; }
            else if (emp.absent > 2) { status = 'Critical'; statusClass = 'badge-danger'; }
            else if (emp.late >= 3 || emp.absent > 0) { status = 'Warning'; statusClass = 'badge-warning'; }

            return {
                ...emp,
                meta, basicSalary, totalSalary, perDaySalary,
                approvedLeaves: totalApprovedLeaves, adjustedLeaveCount, adjustedAbsent, totalWorkingDays,
                punctualityQualified, punctualityAmount,
                lateDeduction, tada, bonus, arrears, extraDays, extraDayPay,
                halfDayCount, halfDayAmount, ncnsCount, ncnsAmount, sdCount, sdAmount,
                qaHrAmount, misspunchCount, misspunchAmount,
                advanceDeduction, advanceRemaining, absentDeduction, tax,
                totalEarnings, totalDeductions, grossSalary: Math.max(grossSalary, 0),
                netSalary: Math.max(grossSalary, 0),
                status, statusClass
            };
        }

        // ================== SEARCH FUNCTION FOR PAYROLL ==================
        let currentPayrollSearchTerm = '';
        let employeeSearchResults = [];
        let activeSearchInputId = null;

        function renderEmployeeSearchResults(inputId, searchValue) {
            const inputWrapper = document.getElementById(inputId)?.closest('.search-input-wrapper');
            if (!inputWrapper) return;
            
            // Remove existing results
            const existingResults = inputWrapper.querySelector('.employee-search-results');
            if (existingResults) existingResults.remove();
            
            if (!searchValue || searchValue.length < 1) return;
            
            const filtered = allData.filter(emp => 
                emp.name.toLowerCase().includes(searchValue.toLowerCase()) || 
                emp.id.toLowerCase().includes(searchValue.toLowerCase())
            ).slice(0, 10);
            
            if (filtered.length === 0) return;
            
            const resultsDiv = document.createElement('div');
            resultsDiv.className = 'employee-search-results';
            
            filtered.forEach(emp => {
                const item = document.createElement('div');
                item.className = 'employee-search-item';
                item.innerHTML = `
                    <div>
                        <div class="emp-name">${emp.name}</div>
                        <div class="emp-code">ID: ${emp.id} | ${emp.department || 'General'}</div>
                    </div>
                    <i class="fas fa-chevron-right" style="color: var(--primary); font-size: 12px;"></i>
                `;
                item.onclick = () => {
                    document.getElementById(inputId).value = emp.id;
                    resultsDiv.remove();
                    // Auto-select the employee in the adjustment
                    if (inputId.includes('-emp-search')) {
                        const type = inputId.replace('-emp-search', '');
                        const selectField = document.getElementById(`adj-emp-${type}`);
                        if (selectField) {
                            selectField.value = emp.id;
                            selectField.dispatchEvent(new Event('change'));
                        }
                    }
                };
                resultsDiv.appendChild(item);
            });
            
            inputWrapper.style.position = 'relative';
            inputWrapper.appendChild(resultsDiv);
        }

        function openPayrollDashboard() {
            const modal = document.getElementById('payrollModal');
            const body = document.getElementById('payrollModalBody');
            modal.classList.add('active');
            body.innerHTML = '<div class="loading-state"><div class="loading-spinner"></div><p>Calculating professional payroll...</p></div>';
            if (allData.length === 0) {
                loadAttendanceData().then(() => renderPayrollDashboard());
            } else {
                renderPayrollDashboard();
            }
        }

        function renderPayrollDashboard() {
            const body = document.getElementById('payrollModalBody');
            loadAllAdj();
            let dataToProcess = allData;
            if (currentPayrollSearchTerm) {
                dataToProcess = allData.filter(emp => 
                    emp.name.toLowerCase().includes(currentPayrollSearchTerm.toLowerCase()) || 
                    emp.id.toLowerCase().includes(currentPayrollSearchTerm.toLowerCase())
                );
            }
            const payrollData = dataToProcess.map(emp => calculatePayrollForEmployee(emp));

            const totalGross = payrollData.reduce((s, e) => s + e.totalSalary, 0);
            const totalEarnings = payrollData.reduce((s, e) => s + e.totalEarnings, 0);
            const totalDeductionsSum = payrollData.reduce((s, e) => s + e.totalDeductions, 0);
            const totalNet = payrollData.reduce((s, e) => s + e.netSalary, 0);
            const totalTada = payrollData.reduce((s, e) => s + e.tada, 0);
            const totalBonusAmt = payrollData.reduce((s, e) => s + e.bonus, 0);
            const totalArrears = payrollData.reduce((s, e) => s + e.arrears, 0);
            const totalAdvance = payrollData.reduce((s, e) => s + e.advanceDeduction, 0);

            body.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; gap:20px; flex-wrap:wrap;">
                    <div class="payroll-tabs" style="margin-bottom:0; border-bottom:none;">
                        <button class="payroll-tab active" data-tab="overview" onclick="switchPayrollTab('overview')"><i class="fas fa-chart-bar"></i> Overview</button>
                        <button class="payroll-tab" data-tab="payroll-table" onclick="switchPayrollTab('payroll-table')"><i class="fas fa-table"></i> Full Payroll Sheet</button>
                        <button class="payroll-tab" data-tab="tada" onclick="switchPayrollTab('tada')"><i class="fas fa-plane"></i> TA/DA</button>
                        <button class="payroll-tab" data-tab="bonus" onclick="switchPayrollTab('bonus')"><i class="fas fa-gift"></i> Bonus</button>
                        <button class="payroll-tab" data-tab="arrears" onclick="switchPayrollTab('arrears')"><i class="fas fa-money-bill-wave"></i> Arrears</button>
                        <button class="payroll-tab" data-tab="halfday" onclick="switchPayrollTab('halfday')"><i class="fas fa-hourglass-half"></i> Half Day</button>
                        <button class="payroll-tab" data-tab="ncns" onclick="switchPayrollTab('ncns')"><i class="fas fa-user-times"></i> NCNS</button>
                        <button class="payroll-tab" data-tab="sd" onclick="switchPayrollTab('sd')"><i class="fas fa-bread-slice"></i> SandWich</button>
                        <button class="payroll-tab" data-tab="qahr" onclick="switchPayrollTab('qahr')"><i class="fas fa-clipboard-check"></i> QA/HR</button>
                        <button class="payroll-tab" data-tab="advance" onclick="switchPayrollTab('advance')"><i class="fas fa-hand-holding-usd"></i> Advance</button>
                        <button class="payroll-tab" data-tab="manual" onclick="switchPayrollTab('manual')"><i class="fas fa-sliders-h"></i> Manual</button>
                        <button class="payroll-tab" data-tab="settings" onclick="switchPayrollTab('settings')"><i class="fas fa-cog"></i> Settings</button>
                    </div>
                    <div class="search-box" style="flex:1; max-width:300px; margin:0; position:relative;">
                        <i class="fas fa-search"></i>
                        <input type="text" id="payrollSearchInput" placeholder="Search by name or employee ID..." value="${currentPayrollSearchTerm}" 
                            onkeyup="handlePayrollSearch(this.value)">
                    </div>
                </div>

                <div class="tab-content active" id="tab-overview">
                    <div class="payroll-rules">
                        <div class="rule-card"><div class="rule-icon"><i class="fas fa-star"></i></div><div class="rule-title">Punctuality Bonus</div><div class="rule-amount">+₨ ${PERFECT_ATTENDANCE_BONUS.toLocaleString()}</div><div class="rule-desc">Full days, <3 lates</div></div>
                        <div class="rule-card"><div class="rule-icon warning"><i class="fas fa-clock"></i></div><div class="rule-title">Late Penalty</div><div class="rule-amount negative">-₨ ${LATE_PENALTY}/late</div><div class="rule-desc">≥3 lates triggers</div></div>
                        <div class="rule-card"><div class="rule-icon danger"><i class="fas fa-user-slash"></i></div><div class="rule-title">NCNS Penalty</div><div class="rule-amount negative">-₨ ${NCNS_PENALTY.toLocaleString()}</div><div class="rule-desc">No Call No Show / day</div></div>
                        <div class="rule-card"><div class="rule-icon info"><i class="fas fa-bread-slice"></i></div><div class="rule-title">SandWich (SD)</div><div class="rule-amount negative">-Per Day × 2</div><div class="rule-desc">Per SD occurrence</div></div>
                        <div class="rule-card"><div class="rule-icon warning"><i class="fas fa-hourglass-half"></i></div><div class="rule-title">Half Day</div><div class="rule-amount negative">-Per Day ÷ 2</div><div class="rule-desc">Approved by admin</div></div>
                        <div class="rule-card"><div class="rule-icon purple"><i class="fas fa-fingerprint"></i></div><div class="rule-title">Misspunch</div><div class="rule-amount negative">-₨ ${MISSPUNCH_DEDUCTION.toLocaleString()}</div><div class="rule-desc">Per missed punch</div></div>
                        <div class="rule-card"><div class="rule-icon"><i class="fas fa-plane"></i></div><div class="rule-title">TA/DA</div><div class="rule-amount">+Variable</div><div class="rule-desc">Travel allowance</div></div>
                        <div class="rule-card"><div class="rule-icon info"><i class="fas fa-hand-holding-usd"></i></div><div class="rule-title">Advance Salary</div><div class="rule-amount negative">-Per Month Plan</div><div class="rule-desc">Auto-deducted</div></div>
                    </div>
                    <div class="payroll-summary">
                        <div class="summary-card"><div class="value info">${payrollData.length}</div><div class="label">Matched Personnel</div></div>
                        <div class="summary-card"><div class="value">₨ ${totalGross.toLocaleString()}</div><div class="label">Total Salary (Base)</div></div>
                        <div class="summary-card"><div class="value">₨ ${totalEarnings.toLocaleString()}</div><div class="label">Total Earnings</div></div>
                        <div class="summary-card"><div class="value negative">₨ ${totalDeductionsSum.toLocaleString()}</div><div class="label">Total Deductions</div></div>
                        <div class="summary-card"><div class="value warning">₨ ${totalAdvance.toLocaleString()}</div><div class="label">Advance Recovery</div></div>
                        <div class="summary-card"><div class="value" style="color:var(--secondary)">₨ ${Math.round(totalNet).toLocaleString()}</div><div class="label">NET PAYROLL</div></div>
                    </div>
                    <div class="adj-section">
                        <h3><i class="fas fa-info-circle"></i> Quick Statistics</h3>
                        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
                            <div style="background:rgba(16,185,129,0.1);padding:14px;border-radius:12px;border:1px solid rgba(16,185,129,0.3);"><div style="color:#10b981;font-weight:700;font-size:20px;">₨ ${totalTada.toLocaleString()}</div><div style="color:rgba(255,255,255,0.6);font-size:11px;margin-top:4px;">Total TA/DA Disbursed</div></div>
                            <div style="background:rgba(139,92,246,0.1);padding:14px;border-radius:12px;border:1px solid rgba(139,92,246,0.3);"><div style="color:#a78bfa;font-weight:700;font-size:20px;">₨ ${totalBonusAmt.toLocaleString()}</div><div style="color:rgba(255,255,255,0.6);font-size:11px;margin-top:4px;">Total Bonuses</div></div>
                            <div style="background:rgba(59,130,246,0.1);padding:14px;border-radius:12px;border:1px solid rgba(59,130,246,0.3);"><div style="color:#60a5fa;font-weight:700;font-size:20px;">₨ ${totalArrears.toLocaleString()}</div><div style="color:rgba(255,255,255,0.6);font-size:11px;margin-top:4px;">Total Arrears Cleared</div></div>
                            <div style="background:rgba(245,158,11,0.1);padding:14px;border-radius:12px;border:1px solid rgba(245,158,11,0.3);"><div style="color:#f59e0b;font-weight:700;font-size:20px;">${payrollData.filter(e=>e.punctualityQualified).length} / ${payrollData.length}</div><div style="color:rgba(255,255,255,0.6);font-size:11px;margin-top:4px;">Punctuality Qualified</div></div>
                        </div>
                    </div>
                </div>

                <div class="tab-content" id="tab-payroll-table">
                    ${renderFullPayrollTable(payrollData)}
                </div>

                <div class="tab-content" id="tab-tada">${renderAdjustmentTab('tada', 'TA/DA (Travel Allowance)', 'fa-plane', 'positive')}</div>
                <div class="tab-content" id="tab-bonus">${renderAdjustmentTab('bonus', 'Bonus', 'fa-gift', 'positive')}</div>
                <div class="tab-content" id="tab-arrears">${renderAdjustmentTab('arrears', 'Arrears', 'fa-money-bill-wave', 'positive')}</div>
                <div class="tab-content" id="tab-halfday">${renderAdjustmentTab('halfDay', 'Half Day', 'fa-hourglass-half', 'negative', true)}</div>
                <div class="tab-content" id="tab-ncns">${renderAdjustmentTab('ncns', 'NCNS (No Call No Show)', 'fa-user-times', 'negative', true)}</div>
                <div class="tab-content" id="tab-sd">${renderAdjustmentTab('sd', 'SandWich (SD)', 'fa-bread-slice', 'negative', true)}</div>
                <div class="tab-content" id="tab-qahr">${renderAdjustmentTab('qaHr', 'QA/HR Docs', 'fa-clipboard-check', 'negative')}</div>
                <div class="tab-content" id="tab-misspunch">${renderAdjustmentTab('misspunch', 'Misspunch / Manual Attendance', 'fa-fingerprint', 'negative', true)}</div>
                <div class="tab-content" id="tab-advance">${renderAdvanceTab()}</div>
                <div class="tab-content" id="tab-manual">${renderManualTab()}</div>
                <div class="tab-content" id="tab-settings">${renderSettingsTab()}</div>
            `;
            showToast(`✅ Payroll calculated for ${payrollData.length} employees`, 'success');
        }

        function renderFullPayrollTable(payrollData) {
            return `
                <div class="adj-section" style="margin-bottom:16px;">
                    <h3><i class="fas fa-table"></i> Complete Payroll Sheet (All Fields)</h3>
                    <p style="color:rgba(255,255,255,0.6);font-size:12px;">Showing comprehensive payroll calculation matching HRM specifications. Scroll horizontally for all columns.</p>
                </div>
                <div class="payroll-table-container">
                <table class="payroll-table">
                    <thead><tr>
                        <th>B-ID</th><th>Employee Name</th><th>Designation</th><th>Department</th>
                        <th>Basic Salary</th><th>Punctuality</th><th>Total Salary</th>
                        <th>Per Day</th><th>Days</th><th>Present</th><th>Leave</th><th>Absent</th><th>T.W.Days</th>
                        <th>P.Reward</th><th>Bonus</th><th>TA/DA</th><th>Arrears</th>
                        <th>Extra Days</th><th>Extra Pay</th>
                        <th>Late</th><th>Late Ded.</th>
                        <th>HD#</th><th>HD Amt</th>
                        <th>SD#</th><th>SD Amt</th>
                        <th>NCNS#</th><th>NCNS Amt</th>
                        <th>QA/HR</th><th>Misspunch</th>
                        <th>Advance</th><th>Absent Ded.</th><th>Tax</th>
                        <th class="amount-positive">GROSS</th>
                        <th>Status</th><th>Action</th>
                     </tr></thead>
                    <tbody>
                        ${(currentPayrollSearchTerm ? payrollData.filter(e => e.name.toLowerCase().includes(currentPayrollSearchTerm.toLowerCase()) || e.id.toLowerCase().includes(currentPayrollSearchTerm.toLowerCase())) : payrollData).map(e => `
                            <tr>
                                <td class="amount-positive">${e.id}</td>
                                <td style="text-align:left;"><strong>${e.name}</strong></td>
                                <td>${e.designation}</td>
                                <td>${e.department}</td>
                                <td>₨${e.basicSalary.toLocaleString()}</td>
                                <td>₨${(e.meta.punctualityEnabled ? PERFECT_ATTENDANCE_BONUS : 0).toLocaleString()}</td>
                                <td>₨${e.totalSalary.toLocaleString()}</td>
                                <td>₨${Math.round(e.perDaySalary).toLocaleString()}</td>
                                <td>${workingDaysCount}</td>
                                <td class="amount-positive">${e.present}</td>
                                <td>${e.adjustedLeaveCount}</td>
                                <td class="${e.adjustedAbsent>0?'amount-negative':''}">${e.adjustedAbsent}</td>
                                <td>${e.totalWorkingDays}</td>
                                <td class="${e.punctualityAmount>0?'amount-positive':'amount-neutral'}">${e.punctualityAmount>0?`+₨${e.punctualityAmount.toLocaleString()}`:'—'}</td>
                                <td class="${e.bonus>0?'amount-positive':'amount-neutral'}">${e.bonus>0?`+₨${e.bonus.toLocaleString()}`:'—'}</td>
                                <td class="${e.tada>0?'amount-positive':'amount-neutral'}">${e.tada>0?`+₨${e.tada.toLocaleString()}`:'—'}</td>
                                <td class="${e.arrears>0?'amount-positive':'amount-neutral'}">${e.arrears>0?`+₨${e.arrears.toLocaleString()}`:'—'}</td>
                                <td>${e.extraDays}</td>
                                <td class="${e.extraDayPay>0?'amount-positive':'amount-neutral'}">${e.extraDayPay>0?`+₨${Math.round(e.extraDayPay).toLocaleString()}`:'—'}</td>
                                <td>${e.late}</td>
                                <td class="${e.lateDeduction>0?'amount-negative':'amount-neutral'}">${e.lateDeduction>0?`-₨${e.lateDeduction.toLocaleString()}`:'—'}</td>
                                <td>${e.halfDayCount}</td>
                                <td class="${e.halfDayAmount>0?'amount-negative':'amount-neutral'}">${e.halfDayAmount>0?`-₨${Math.round(e.halfDayAmount).toLocaleString()}`:'—'}</td>
                                <td>${e.sdCount}</td>
                                <td class="${e.sdAmount>0?'amount-negative':'amount-neutral'}">${e.sdAmount>0?`-₨${Math.round(e.sdAmount).toLocaleString()}`:'—'}</td>
                                <td>${e.ncnsCount}</td>
                                <td class="${e.ncnsAmount>0?'amount-negative':'amount-neutral'}">${e.ncnsAmount>0?`-₨${e.ncnsAmount.toLocaleString()}`:'—'}</td>
                                <td class="${e.qaHrAmount>0?'amount-negative':'amount-neutral'}">${e.qaHrAmount>0?`-₨${e.qaHrAmount.toLocaleString()}`:'—'}</td>
                                <td class="${e.misspunchAmount>0?'amount-negative':'amount-neutral'}">${e.misspunchAmount>0?`-₨${e.misspunchAmount.toLocaleString()}`:'—'}</td>
                                <td class="${e.advanceDeduction>0?'amount-negative':'amount-neutral'}">${e.advanceDeduction>0?`-₨${e.advanceDeduction.toLocaleString()}`:'—'}</td>
                                <td class="${e.absentDeduction>0?'amount-negative':'amount-neutral'}">${e.absentDeduction>0?`-₨${Math.round(e.absentDeduction).toLocaleString()}`:'—'}</td>
                                <td class="${e.tax>0?'amount-negative':'amount-neutral'}">${e.tax>0?`-₨${e.tax.toLocaleString()}`:'—'}</td>
                                <td class="amount-positive" style="font-size:13px;font-weight:800;">₨${Math.round(e.grossSalary).toLocaleString()}</td>
                                <td><span class="${e.statusClass}">${e.status}</span></td>
                                <td><button class="view-slip-btn" onclick="viewPayrollSlip('${e.id}', event)"><i class="fas fa-receipt"></i></button></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
                </div>
            `;
        }

        function renderAdjustmentTab(type, label, icon, sign, isPerDay) {
            const items = payrollAdj[type];
            let listHtml = '';
            let total = 0; let totalCount = 0;
            let filteredList = allData;
            if (currentPayrollSearchTerm) {
                filteredList = filteredList.filter(emp => emp.name.toLowerCase().includes(currentPayrollSearchTerm.toLowerCase()) || emp.id.toLowerCase().includes(currentPayrollSearchTerm.toLowerCase()));
            }

            filteredList.forEach(emp => {
                const arr = items[emp.id] || [];
                arr.forEach((it, idx) => {
                    totalCount++;
                    total += parseFloat(it.amount) || 0;
                    listHtml += `<div class="adj-item">
                        <div class="name">${emp.name} <span style="color:rgba(255,255,255,0.4);font-size:10px;">(${emp.id})</span></div>
                        <div class="amt ${sign==='negative'?'neg':''}">${sign==='negative'?'-':'+'}₨ ${(parseFloat(it.amount)||0).toLocaleString()}</div>
                        <div class="reason">${it.reason || '—'} ${it.date ? `<br><span style="color:#60a5fa;">📅 ${it.date}</span>` : ''} ${it.team ? `<br><span style="color:#a78bfa;">👥 ${it.team}</span>` : ''}</div>
                        <div style="color:rgba(255,255,255,0.4);font-size:10px;">${it.addedAt ? new Date(it.addedAt).toLocaleDateString() : ''}</div>
                        <button class="adj-delete" onclick="deleteAdjItem('${type}','${emp.id}',${idx})"><i class="fas fa-trash"></i></button>
                    </div>`;
                });
            });
            if (!listHtml) listHtml = '<div style="text-align:center;padding:30px;color:rgba(255,255,255,0.4);">No records yet</div>';

            const perItemAmount = isPerDay ? (type === 'ncns' ? `₨${NCNS_PENALTY}` : type === 'misspunch' ? `₨${MISSPUNCH_DEDUCTION}` : 'Auto-calc') : 'Custom';

            return `
                <div class="adj-section">
                    <h3><i class="fas ${icon}"></i> ${label} ${isPerDay ? `<span style="color:rgba(255,255,255,0.5);font-size:12px;font-weight:400;">· Auto Amount: ${perItemAmount}</span>` : ''}</h3>
                    <div class="adj-form-grid ${isPerDay ? '' : 'three'}">
                        <div class="search-input-wrapper">
                            <label class="adj-label">Search Employee</label>
                            <input type="text" class="adj-input" id="${type}-emp-search" placeholder="Type name or employee ID..." 
                                onkeyup="renderEmployeeSearchResults('${type}-emp-search', this.value)">
                        </div>
                        <div style="display:none;">
                            <input type="hidden" id="adj-emp-${type}" value="">
                        </div>
                        ${isPerDay ? `<div><label class="adj-label">Date</label><input type="date" class="adj-input" id="adj-date-${type}" value="${currentYear}-${String(currentMonth).padStart(2,'0')}-01"></div>` : ''}
                        ${!isPerDay || ['halfDay','sd'].includes(type) ? `<div><label class="adj-label">Amount ${isPerDay && (type==='ncns'||type==='misspunch') ? '(Auto)' : ''}</label>
                            <input type="number" class="adj-input" id="adj-amt-${type}" placeholder="${isPerDay && (type==='ncns'||type==='misspunch') ? 'Auto-calculated' : 'Enter amount'}" ${isPerDay && (type==='ncns'||type==='misspunch') ? 'disabled' : ''}></div>` : ''}
                        ${type==='tada'||type==='bonus'||type==='arrears'||type==='qaHr'||type==='halfDay'||type==='ncns'||type==='sd'||type==='misspunch' ? `<div><label class="adj-label">Team (Optional)</label><input type="text" class="adj-input" id="adj-team-${type}" placeholder="Team name"></div>` : ''}
                        <div style="grid-column:span 2;"><label class="adj-label">Reason / Description</label><input type="text" class="adj-input" id="adj-reason-${type}" placeholder="Enter reason..."></div>
                    </div>
                    <button class="btn btn-success" onclick="addAdjItemFromSearch('${type}',${isPerDay})"><i class="fas fa-plus"></i> Add ${label}</button>
                    <div style="margin-top:20px;padding:14px;background:rgba(0,0,0,0.3);border-radius:12px;display:flex;justify-content:space-between;align-items:center;">
                        <div style="color:rgba(255,255,255,0.7);font-size:12px;">Total Records: <strong style="color:white;">${totalCount}</strong></div>
                        <div style="color:rgba(255,255,255,0.7);font-size:12px;">Total Amount: <strong style="color:${sign==='negative'?'#ef4444':'#10b981'};">${sign==='negative'?'-':'+'}₨ ${total.toLocaleString()}</strong></div>
                    </div>
                    <div class="adj-list" style="margin-top:16px;">${listHtml}</div>
                </div>
            `;
        }

        function addAdjItemFromSearch(type, isPerDay) {
            const searchInput = document.getElementById(`${type}-emp-search`);
            const searchValue = searchInput?.value.trim();
            if (!searchValue) {
                showToast('Please search and select an employee first', 'warning');
                return;
            }
            
            // Find employee by name or ID
            const employee = allData.find(emp => 
                emp.name.toLowerCase() === searchValue.toLowerCase() || 
                emp.id.toLowerCase() === searchValue.toLowerCase()
            );
            
            if (!employee) {
                showToast('Employee not found. Please select from search results.', 'warning');
                return;
            }
            
            const empId = employee.id;
            const reason = document.getElementById(`adj-reason-${type}`)?.value || '';
            const team = document.getElementById(`adj-team-${type}`)?.value || '';
            const dateInput = document.getElementById(`adj-date-${type}`);
            const date = dateInput ? dateInput.value : '';
            const amtInput = document.getElementById(`adj-amt-${type}`);
            let amount = amtInput ? parseFloat(amtInput.value) || 0 : 0;
            
            if (type === 'ncns') amount = NCNS_PENALTY;
            else if (type === 'misspunch') amount = MISSPUNCH_DEDUCTION;
            else if (type === 'halfDay' || type === 'sd') {
                amount = amount || 0;
            }
            
            if (!isPerDay && amount === 0 && !['halfDay','sd'].includes(type)) { 
                showToast('Please enter amount', 'warning'); 
                return; 
            }
            
            if (!payrollAdj[type][empId]) payrollAdj[type][empId] = [];
            payrollAdj[type][empId].push({ amount, reason, team, date, addedAt: new Date().toISOString() });
            persistAllAdj();
            showToast(`✅ ${type.toUpperCase()} added for ${employee.name}`, 'success');
            
            // Clear form
            if (searchInput) searchInput.value = '';
            if (reason) document.getElementById(`adj-reason-${type}`).value = '';
            if (team) document.getElementById(`adj-team-${type}`).value = '';
            if (amtInput && !['ncns','misspunch'].includes(type)) amtInput.value = '';
            
            renderPayrollDashboard();
            switchPayrollTab(type === 'halfDay' ? 'halfday' : type === 'qaHr' ? 'qahr' : type);
            
            // Remove search results
            const resultsDiv = document.querySelector(`#${type}-emp-search`).closest('.search-input-wrapper')?.querySelector('.employee-search-results');
            if (resultsDiv) resultsDiv.remove();
        }

        function renderAdvanceTab() {
            let listHtml = '';
            allData.forEach(emp => {
                const adv = payrollAdj.advance[emp.id];
                if (adv) {
                    const remaining = (parseFloat(adv.total)||0) - (parseFloat(adv.paid)||0);
                    listHtml += `<div class="adj-item" style="grid-template-columns:2fr 1fr 1fr 1fr 1fr 0.5fr;">
                        <div class="name">${emp.name} <span style="color:rgba(255,255,255,0.4);font-size:10px;">(${emp.id})</span></div>
                        <div>Total: <strong style="color:#60a5fa;">₨${(parseFloat(adv.total)||0).toLocaleString()}</strong></div>
                        <div>Per Month: <strong style="color:#f59e0b;">₨${(parseFloat(adv.perMonth)||0).toLocaleString()}</strong></div>
                        <div>Paid: <strong style="color:#10b981;">₨${(parseFloat(adv.paid)||0).toLocaleString()}</strong></div>
                        <div>Remaining: <strong style="color:${remaining>0?'#ef4444':'#10b981'};">₨${remaining.toLocaleString()}</strong></div>
                        <button class="adj-delete" onclick="deleteAdvance('${emp.id}')"><i class="fas fa-trash"></i></button>
                    </div>`;
                }
            });
            if (!listHtml) listHtml = '<div style="text-align:center;padding:30px;color:rgba(255,255,255,0.4);">No advance records</div>';
            
            return `
                <div class="adj-section">
                    <h3><i class="fas fa-hand-holding-usd"></i> Advance Salary Management</h3>
                    <p style="color:rgba(255,255,255,0.6);font-size:12px;margin-bottom:16px;">Set total advance amount and monthly deduction. System will auto-deduct each month until cleared.</p>
                    <div class="search-input-wrapper" style="margin-bottom:15px;">
                        <label class="adj-label">Search Employee</label>
                        <input type="text" class="adj-input" id="adv-emp-search" placeholder="Type name or employee ID..." 
                            onkeyup="renderEmployeeSearchResults('adv-emp-search', this.value)">
                        <input type="hidden" id="adv-emp" value="">
                    </div>
                    <div class="adj-form-grid">
                        <div><label class="adj-label">Total Advance Amount</label><input type="number" class="adj-input" id="adv-total" placeholder="e.g. 50000"></div>
                        <div><label class="adj-label">Per Month Deduction</label><input type="number" class="adj-input" id="adv-perMonth" placeholder="e.g. 5000"></div>
                        <div><label class="adj-label">Already Paid (optional)</label><input type="number" class="adj-input" id="adv-paid" value="0"></div>
                    </div>
                    <button class="btn btn-success" onclick="addAdvanceFromSearch()"><i class="fas fa-plus"></i> Set Advance</button>
                    <div class="adj-list" style="margin-top:16px;">${listHtml}</div>
                </div>
            `;
        }

        function addAdvanceFromSearch() {
            const searchInput = document.getElementById('adv-emp-search');
            const searchValue = searchInput?.value.trim();
            if (!searchValue) {
                showToast('Please search and select an employee first', 'warning');
                return;
            }
            
            const employee = allData.find(emp => 
                emp.name.toLowerCase() === searchValue.toLowerCase() || 
                emp.id.toLowerCase() === searchValue.toLowerCase()
            );
            
            if (!employee) {
                showToast('Employee not found. Please select from search results.', 'warning');
                return;
            }
            
            const empId = employee.id;
            const total = parseFloat(document.getElementById('adv-total').value) || 0;
            const perMonth = parseFloat(document.getElementById('adv-perMonth').value) || 0;
            const paid = parseFloat(document.getElementById('adv-paid').value) || 0;
            
            if (total <= 0 || perMonth <= 0) { 
                showToast('Fill all required fields', 'warning'); 
                return; 
            }
            
            payrollAdj.advance[empId] = { total, perMonth, paid, skipMonths: [], addedAt: new Date().toISOString() };
            persistAllAdj();
            showToast(`✅ Advance set for ${employee.name}`, 'success');
            
            // Clear form
            searchInput.value = '';
            document.getElementById('adv-total').value = '';
            document.getElementById('adv-perMonth').value = '';
            document.getElementById('adv-paid').value = '0';
            
            renderPayrollDashboard();
            switchPayrollTab('advance');
            
            const resultsDiv = document.querySelector('#adv-emp-search')?.closest('.search-input-wrapper')?.querySelector('.employee-search-results');
            if (resultsDiv) resultsDiv.remove();
        }

        function renderManualTab() {
            return `
                <div class="adj-section">
                    <h3><i class="fas fa-sliders-h"></i> Manual Late Coming Deduction</h3>
                    <div class="search-input-wrapper" style="margin-bottom:15px;">
                        <label class="adj-label">Search Employee</label>
                        <input type="text" class="adj-input" id="ml-emp-search" placeholder="Type name or employee ID..." 
                            onkeyup="renderEmployeeSearchResults('ml-emp-search', this.value)">
                        <input type="hidden" id="ml-emp" value="">
                    </div>
                    <div class="adj-form-grid three">
                        <div><label class="adj-label">Manual Late Deduction Amount</label><input type="number" class="adj-input" id="ml-amt" placeholder="Override amount"></div>
                        <div style="display:flex;align-items:flex-end;"><button class="btn btn-success" onclick="setManualLateFromSearch()"><i class="fas fa-save"></i> Save</button></div>
                    </div>
                </div>
                <div class="adj-section">
                    <h3><i class="fas fa-star"></i> Manual Punctuality Override</h3>
                    <div class="search-input-wrapper" style="margin-bottom:15px;">
                        <label class="adj-label">Search Employee</label>
                        <input type="text" class="adj-input" id="mp-emp-search" placeholder="Type name or employee ID..." 
                            onkeyup="renderEmployeeSearchResults('mp-emp-search', this.value)">
                        <input type="hidden" id="mp-emp" value="">
                    </div>
                    <div class="adj-form-grid three">
                        <div><label class="adj-label">Punctuality Amount (0 to remove)</label><input type="number" class="adj-input" id="mp-amt"></div>
                        <div style="display:flex;align-items:flex-end;"><button class="btn btn-success" onclick="setManualPunctualityFromSearch()"><i class="fas fa-save"></i> Save</button></div>
                    </div>
                </div>
                <div class="adj-section">
                    <h3><i class="fas fa-leaf"></i> Approved Leaves Override</h3>
                    <div class="search-input-wrapper" style="margin-bottom:15px;">
                        <label class="adj-label">Search Employee</label>
                        <input type="text" class="adj-input" id="al-emp-search" placeholder="Type name or employee ID..." 
                            onkeyup="renderEmployeeSearchResults('al-emp-search', this.value)">
                        <input type="hidden" id="al-emp" value="">
                    </div>
                    <div class="adj-form-grid three">
                        <div><label class="adj-label">Approved Leaves Count</label><input type="number" class="adj-input" id="al-amt" placeholder="Number of approved leaves"></div>
                        <div style="display:flex;align-items:flex-end;"><button class="btn btn-success" onclick="setApprovedLeavesFromSearch()"><i class="fas fa-save"></i> Save</button></div>
                    </div>
                </div>
                <div class="adj-section">
                    <h3><i class="fas fa-receipt"></i> Tax Adjustment</h3>
                    <div class="search-input-wrapper" style="margin-bottom:15px;">
                        <label class="adj-label">Search Employee</label>
                        <input type="text" class="adj-input" id="tx-emp-search" placeholder="Type name or employee ID..." 
                            onkeyup="renderEmployeeSearchResults('tx-emp-search', this.value)">
                        <input type="hidden" id="tx-emp" value="">
                    </div>
                    <div class="adj-form-grid three">
                        <div><label class="adj-label">Tax Amount</label><input type="number" class="adj-input" id="tx-amt"></div>
                        <div style="display:flex;align-items:flex-end;"><button class="btn btn-success" onclick="setTaxFromSearch()"><i class="fas fa-save"></i> Save</button></div>
                    </div>
                </div>
            `;
        }

        function setManualLateFromSearch() {
            const searchInput = document.getElementById('ml-emp-search');
            const searchValue = searchInput?.value.trim();
            if (!searchValue) { showToast('Please search and select an employee first', 'warning'); return; }
            const employee = allData.find(emp => emp.name.toLowerCase() === searchValue.toLowerCase() || emp.id.toLowerCase() === searchValue.toLowerCase());
            if (!employee) { showToast('Employee not found', 'warning'); return; }
            const amt = parseFloat(document.getElementById('ml-amt').value) || 0;
            payrollAdj.manualLate[employee.id] = amt;
            persistAllAdj();
            showToast(`✅ Manual late set for ${employee.name}`, 'success');
            searchInput.value = '';
            document.getElementById('ml-amt').value = '';
            renderPayrollDashboard();
            switchPayrollTab('manual');
            const resultsDiv = document.querySelector('#ml-emp-search')?.closest('.search-input-wrapper')?.querySelector('.employee-search-results');
            if (resultsDiv) resultsDiv.remove();
        }

        function setManualPunctualityFromSearch() {
            const searchInput = document.getElementById('mp-emp-search');
            const searchValue = searchInput?.value.trim();
            if (!searchValue) { showToast('Please search and select an employee first', 'warning'); return; }
            const employee = allData.find(emp => emp.name.toLowerCase() === searchValue.toLowerCase() || emp.id.toLowerCase() === searchValue.toLowerCase());
            if (!employee) { showToast('Employee not found', 'warning'); return; }
            const amt = parseFloat(document.getElementById('mp-amt').value) || 0;
            payrollAdj.manualPunctuality[employee.id] = amt;
            persistAllAdj();
            showToast(`✅ Punctuality override set for ${employee.name}`, 'success');
            searchInput.value = '';
            document.getElementById('mp-amt').value = '';
            renderPayrollDashboard();
            switchPayrollTab('manual');
            const resultsDiv = document.querySelector('#mp-emp-search')?.closest('.search-input-wrapper')?.querySelector('.employee-search-results');
            if (resultsDiv) resultsDiv.remove();
        }

        function setApprovedLeavesFromSearch() {
            const searchInput = document.getElementById('al-emp-search');
            const searchValue = searchInput?.value.trim();
            if (!searchValue) { showToast('Please search and select an employee first', 'warning'); return; }
            const employee = allData.find(emp => emp.name.toLowerCase() === searchValue.toLowerCase() || emp.id.toLowerCase() === searchValue.toLowerCase());
            if (!employee) { showToast('Employee not found', 'warning'); return; }
            const amt = parseInt(document.getElementById('al-amt').value) || 0;
            payrollAdj.manualLeaves[employee.id] = amt;
            persistAllAdj();
            showToast(`✅ Approved leaves set for ${employee.name}`, 'success');
            searchInput.value = '';
            document.getElementById('al-amt').value = '';
            renderPayrollDashboard();
            switchPayrollTab('manual');
            const resultsDiv = document.querySelector('#al-emp-search')?.closest('.search-input-wrapper')?.querySelector('.employee-search-results');
            if (resultsDiv) resultsDiv.remove();
        }

        function setTaxFromSearch() {
            const searchInput = document.getElementById('tx-emp-search');
            const searchValue = searchInput?.value.trim();
            if (!searchValue) { showToast('Please search and select an employee first', 'warning'); return; }
            const employee = allData.find(emp => emp.name.toLowerCase() === searchValue.toLowerCase() || emp.id.toLowerCase() === searchValue.toLowerCase());
            if (!employee) { showToast('Employee not found', 'warning'); return; }
            const amt = parseFloat(document.getElementById('tx-amt').value) || 0;
            payrollAdj.tax[employee.id] = amt;
            persistAllAdj();
            showToast(`✅ Tax saved for ${employee.name}`, 'success');
            searchInput.value = '';
            document.getElementById('tx-amt').value = '';
            renderPayrollDashboard();
            switchPayrollTab('manual');
            const resultsDiv = document.querySelector('#tx-emp-search')?.closest('.search-input-wrapper')?.querySelector('.employee-search-results');
            if (resultsDiv) resultsDiv.remove();
        }

        function renderSettingsTab() {
            let listHtml = '<div style="overflow-x:auto;"><table class="payroll-table" style="min-width:1100px;"><thead><tr><th>ID</th><th>Name</th><th>Basic Salary</th><th>Punctuality Enabled</th><th>Appointment Date</th><th>CNIC</th><th>Action</th></tr></thead><tbody>';
            const filteredForSettings = currentPayrollSearchTerm ? allData.filter(emp => emp.name.toLowerCase().includes(currentPayrollSearchTerm.toLowerCase()) || emp.id.toLowerCase().includes(currentPayrollSearchTerm.toLowerCase())) : allData;
            filteredForSettings.forEach(emp => {
                const meta = getEmpMeta(emp.id);
                listHtml += `<tr>
                    <td>${emp.id}</td>
                    <td style="text-align:left;">${emp.name}</td>
                    <td><input type="number" class="adj-input" id="set-bs-${emp.id}" value="${meta.basicSalary}" style="width:120px;"></td>
                    <td><input type="checkbox" id="set-punc-${emp.id}" ${meta.punctualityEnabled ? 'checked' : ''}></td>
                    <td><input type="date" class="adj-input" id="set-app-${emp.id}" value="${emp.appointmentDate || ''}" style="width:150px;"></td>
                    <td><input type="text" class="adj-input" id="set-cnic-${emp.id}" value="${meta.cnic || emp.cnic || ''}" style="width:140px;" placeholder="XXXXX-XXXXXXX-X"></td>
                    <td><button class="btn btn-success" style="padding:6px 12px;font-size:11px;" onclick="saveEmpSettings('${emp.id}')"><i class="fas fa-save"></i> Save</button></td>
                </tr>`;
            });
            listHtml += '</tbody></table></div>';
            return `
                <div class="adj-section">
                    <h3><i class="fas fa-cog"></i> Employee Settings · Basic Salary, Punctuality, Appointment Date</h3>
                    <p style="color:rgba(255,255,255,0.6);font-size:12px;margin-bottom:16px;">Configure each employee's base salary, punctuality eligibility, and appointment date. Probation period: ${PROBATION_DAYS} days.</p>
                    <div class="search-box" style="margin-bottom:15px; max-width:300px;">
                        <i class="fas fa-search"></i>
                        <input type="text" id="settingsSearchInput" placeholder="Filter employees..." value="${currentPayrollSearchTerm}" 
                            onkeyup="filterSettingsTab(this.value)">
                    </div>
                    ${listHtml}
                </div>
            `;
        }

        function filterSettingsTab(val) {
            currentPayrollSearchTerm = val;
            renderPayrollDashboard();
            switchPayrollTab('settings');
        }

        function switchPayrollTab(tabName) {
            document.querySelectorAll('.payroll-tab').forEach(b => b.classList.remove('active'));
            document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            const tabContent = document.getElementById(`tab-${tabName}`);
            if (tabContent) tabContent.classList.add('active');
        }

        function deleteAdjItem(type, empId, idx) {
            if (!confirm('Delete this record?')) return;
            payrollAdj[type][empId].splice(idx, 1);
            if (payrollAdj[type][empId].length === 0) delete payrollAdj[type][empId];
            persistAllAdj();
            showToast('✅ Deleted', 'success');
            renderPayrollDashboard();
            switchPayrollTab(type === 'halfDay' ? 'halfday' : type === 'qaHr' ? 'qahr' : type);
        }

        function addAdvance() { addAdvanceFromSearch(); }
        function deleteAdvance(empId) {
            if (!confirm('Delete this advance record?')) return;
            delete payrollAdj.advance[empId];
            persistAllAdj();
            showToast('✅ Deleted', 'success');
            renderPayrollDashboard();
            switchPayrollTab('advance');
        }

        function setManualLate() { setManualLateFromSearch(); }
        function setManualPunctuality() { setManualPunctualityFromSearch(); }
        function setApprovedLeaves() { setApprovedLeavesFromSearch(); }
        function setTax() { setTaxFromSearch(); }

        function saveEmpSettings(empId) {
            const bs = parseFloat(document.getElementById(`set-bs-${empId}`).value) || BASE_SALARY;
            const punc = document.getElementById(`set-punc-${empId}`).checked;
            const appDate = document.getElementById(`set-app-${empId}`).value;
            const cnic = document.getElementById(`set-cnic-${empId}`).value;
            const meta = getEmpMeta(empId);
            meta.basicSalary = bs;
            meta.punctualityEnabled = punc;
            meta.cnic = cnic;
            payrollAdj.empMeta[empId] = meta;
            if (appDate) payrollAdj.appointmentDate[empId] = appDate;
            persistAllAdj();
            const emp = allData.find(e => e.id === empId);
            if (emp && appDate) emp.appointmentDate = appDate;
            showToast('✅ Settings saved', 'success');
        }

        function processFullPayroll() {
            renderPayrollDashboard();
            showToast('✅ Payroll re-calculated', 'success');
        }

        function exportPayrollCSV() {
            const payrollData = allData.map(emp => calculatePayrollForEmployee(emp));
            const headers = ['B-ID','Name','Designation','Department','Branch','Team','CNIC','Basic Salary','Punctuality','Total Salary','Per Day','Working Days','Present','Leave','Absent','T.W.Days','P.Reward','Bonus','TA/DA','Arrears','Extra Days','Extra Pay','Late','Late Ded','HD#','HD Amt','SD#','SD Amt','NCNS#','NCNS Amt','QA/HR','Misspunch#','Misspunch Amt','Advance Ded','Advance Remaining','Absent Ded','Tax','Total Earnings','Total Deductions','GROSS SALARY','Status'];
            let csv = headers.map(h=>`"${h}"`).join(',') + '\n';
            payrollData.forEach(e => {
                const row = [e.id, e.name, e.designation, e.department, e.branch, e.team, e.meta.cnic||e.cnic||'', e.basicSalary, e.meta.punctualityEnabled?PERFECT_ATTENDANCE_BONUS:0, e.totalSalary, Math.round(e.perDaySalary), workingDaysCount, e.present, e.adjustedLeaveCount, e.adjustedAbsent, e.totalWorkingDays, e.punctualityAmount, e.bonus, e.tada, e.arrears, e.extraDays, Math.round(e.extraDayPay), e.late, e.lateDeduction, e.halfDayCount, Math.round(e.halfDayAmount), e.sdCount, Math.round(e.sdAmount), e.ncnsCount, e.ncnsAmount, e.qaHrAmount, e.misspunchCount, e.misspunchAmount, e.advanceDeduction, e.advanceRemaining, Math.round(e.absentDeduction), e.tax, Math.round(e.totalEarnings), Math.round(e.totalDeductions), Math.round(e.grossSalary), e.status];
                csv += row.map(c => `"${c}"`).join(',') + '\n';
            });
            downloadCSV(csv, `payroll_${currentYear}_${currentMonth}.csv`);
            showToast('✅ Payroll exported', 'success');
        }

        function viewPayrollSlip(employeeId, event) {
            if (event) event.stopPropagation();
            const employee = allData.find(e => e.id === employeeId);
            if (!employee) return;
            const e = calculatePayrollForEmployee(employee);
            const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

            const slipHtml = `
                <div id="printArea">
                <div class="slip-pro">
                    <div class="slip-header">
                        <div class="slip-company">
                            <h1><i class="fas fa-bolt"></i> BALITECH PVT LTD</h1>
                            <p>Finance Intelligence Hub · Professional Payroll System</p>
                            <p style="margin-top:6px;">📍 Head Office · Karachi, Pakistan</p>
                        </div>
                        <div class="slip-meta">
                            <div><strong>Pay Slip · ${monthNames[currentMonth-1]} ${currentYear}</strong></div>
                            <div>Slip ID: PSL-${employee.id}-${currentYear}${String(currentMonth).padStart(2,'0')}</div>
                            <div>Generated: ${new Date().toLocaleDateString('en-GB')}</div>
                            <div>Pay Period: 01-${daysInMonth} ${monthNames[currentMonth-1]} ${currentYear}</div>
                        </div>
                    </div>

                    <div class="slip-emp-grid">
                        <div><span>Employee ID</span><strong>${employee.id}</strong></div>
                        <div><span>Employee Name</span><strong>${employee.name}</strong></div>
                        <div><span>Designation</span><strong>${employee.designation}</strong></div>
                        <div><span>Department</span><strong>${employee.department}</strong></div>
                        <div><span>Branch</span><strong>${employee.branch}</strong></div>
                        <div><span>Team</span><strong>${employee.team}</strong></div>
                        <div><span>CNIC</span><strong>${e.meta.cnic || employee.cnic || '—'}</strong></div>
                        <div><span>Bank Account</span><strong>${employee.accountNo || '—'}</strong></div>
                        <div><span>Bank Name</span><strong>${employee.bankName || '—'}</strong></div>
                        <div><span>Appointment Date</span><strong>${employee.appointmentDate || '—'}</strong></div>
                        <div><span>Working Days</span><strong>${workingDaysCount} days</strong></div>
                        <div><span>Per Day Salary</span><strong>₨ ${Math.round(e.perDaySalary).toLocaleString()}</strong></div>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:20px;">
                        <div style="background:#dcfce7;padding:10px;border-radius:8px;text-align:center;"><div style="font-size:20px;font-weight:800;color:#059669;">${e.present}</div><div style="font-size:10px;color:#475569;">Present</div></div>
                        <div style="background:#fef3c7;padding:10px;border-radius:8px;text-align:center;"><div style="font-size:20px;font-weight:800;color:#d97706;">${e.late}</div><div style="font-size:10px;color:#475569;">Late</div></div>
                        <div style="background:#fee2e2;padding:10px;border-radius:8px;text-align:center;"><div style="font-size:20px;font-weight:800;color:#dc2626;">${e.adjustedAbsent}</div><div style="font-size:10px;color:#475569;">Absent</div></div>
                        <div style="background:#ede9fe;padding:10px;border-radius:8px;text-align:center;"><div style="font-size:20px;font-weight:800;color:#7c3aed;">${e.adjustedLeaveCount}</div><div style="font-size:10px;color:#475569;">Leave</div></div>
                    </div>

                    <table class="slip-table">
                        <thead><tr><th colspan="2"><i class="fas fa-plus-circle"></i> EARNINGS</th><th colspan="2"><i class="fas fa-minus-circle"></i> DEDUCTIONS</th></tr></thead>
                        <tbody>
                            <tr><td>Basic Salary</td><td class="amt">₨ ${e.basicSalary.toLocaleString()}</td><td>Late Coming (${employee.late})</td><td class="amt neg">${e.lateDeduction>0?'-₨ '+e.lateDeduction.toLocaleString():'—'}</td></tr>
                            <tr><td>Punctuality Bonus</td><td class="amt pos">${e.punctualityAmount>0?'+₨ '+e.punctualityAmount.toLocaleString():'—'}</td><td>Half Day (${e.halfDayCount})</td><td class="amt neg">${e.halfDayAmount>0?'-₨ '+Math.round(e.halfDayAmount).toLocaleString():'—'}</td></tr>
                            <tr><td>Working Days Pay (${e.totalWorkingDays} × ₨${Math.round(e.perDaySalary).toLocaleString()})</td><td class="amt">₨ ${Math.round(e.totalWorkingDays * e.perDaySalary).toLocaleString()}</td><td>SandWich SD (${e.sdCount})</td><td class="amt neg">${e.sdAmount>0?'-₨ '+Math.round(e.sdAmount).toLocaleString():'—'}</td></tr>
                            <tr><td>Bonus</td><td class="amt pos">${e.bonus>0?'+₨ '+e.bonus.toLocaleString():'—'}</td><td>NCNS (${e.ncnsCount})</td><td class="amt neg">${e.ncnsAmount>0?'-₨ '+e.ncnsAmount.toLocaleString():'—'}</td></tr>
                            <tr><td>TA/DA</td><td class="amt pos">${e.tada>0?'+₨ '+e.tada.toLocaleString():'—'}</td><td>QA/HR Docs</td><td class="amt neg">${e.qaHrAmount>0?'-₨ '+e.qaHrAmount.toLocaleString():'—'}</td></tr>
                            <tr><td>Arrears</td><td class="amt pos">${e.arrears>0?'+₨ '+e.arrears.toLocaleString():'—'}</td><td>Misspunch (${e.misspunchCount})</td><td class="amt neg">${e.misspunchAmount>0?'-₨ '+e.misspunchAmount.toLocaleString():'—'}</td></tr>
                            <tr><td>Extra Day Pay (${e.extraDays})</td><td class="amt pos">${e.extraDayPay>0?'+₨ '+Math.round(e.extraDayPay).toLocaleString():'—'}</td><td>Advance Salary</td><td class="amt neg">${e.advanceDeduction>0?'-₨ '+e.advanceDeduction.toLocaleString():'—'}</td></tr>
                            <tr><td></td><td></td><td>Absent Deduction (${e.adjustedAbsent})</td><td class="amt neg">${e.absentDeduction>0?'-₨ '+Math.round(e.absentDeduction).toLocaleString():'—'}</td></tr>
                            <tr><td></td><td></td><td>Tax</td><td class="amt neg">${e.tax>0?'-₨ '+e.tax.toLocaleString():'—'}</td></tr>
                        </tbody>
                    </table>

                    <div class="slip-totals">
                        <div class="slip-total-card"><div class="lbl">Total Earnings</div><div class="val">₨ ${Math.round(e.totalEarnings).toLocaleString()}</div></div>
                        <div class="slip-total-card deduction"><div class="lbl">Total Deductions</div><div class="val">₨ ${Math.round(e.totalDeductions).toLocaleString()}</div></div>
                    </div>

                    <div class="slip-net">
                        <h3><i class="fas fa-wallet"></i> NET SALARY (GROSS)</h3>
                        <div class="amount">₨ ${Math.round(e.grossSalary).toLocaleString()}</div>
                    </div>

                    ${e.advanceRemaining > 0 ? `<div style="margin-top:12px;padding:10px;background:#fef3c7;border-radius:8px;font-size:11px;color:#92400e;"><strong>⚠️ Advance Salary Remaining:</strong> ₨ ${e.advanceRemaining.toLocaleString()} will be deducted in upcoming months.</div>` : ''}

                    <div class="slip-footer">
                        <div><i class="fas fa-check-circle"></i> This is a computer-generated payslip and does not require signature.</div>
                        <div style="margin-top:6px;">For payroll queries, contact HR Department · BALITECH PVT LTD © ${currentYear}</div>
                    </div>
                </div>
                </div>
            `;

            const slipModal = document.createElement('div');
            slipModal.className = 'modal active';
            slipModal.innerHTML = `<div class="modal-content" style="max-width:900px;background:#fff;">
                <div class="modal-header" style="background:linear-gradient(135deg,#10b981,#059669);">
                    <h2><i class="fas fa-file-invoice"></i> Payroll Slip - ${employee.name}</h2>
                    <div class="modal-close" onclick="this.closest('.modal').remove()">&times;</div>
                </div>
                <div class="modal-body" style="padding:0;background:#fff;">${slipHtml}</div>
                <div class="slip-actions">
                    <button class="btn btn-info" onclick="window.print()"><i class="fas fa-print"></i> Print Slip</button>
                    <button class="btn btn-success" onclick="downloadSlipAsHTML('${employee.id}')"><i class="fas fa-download"></i> Download HTML</button>
                </div>
            </div>`;
            document.body.appendChild(slipModal);
        }

        function downloadSlipAsHTML(empId) {
            const printArea = document.getElementById('printArea');
            if (!printArea) return;
            const html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Payslip ${empId}</title><style>body{font-family:Arial;background:#f1f5f9;padding:20px;} ${document.querySelector('style').textContent}</style></head><body>${printArea.outerHTML}</body></html>`;
            const blob = new Blob([html], { type: 'text/html' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = `payslip_${empId}_${currentYear}_${currentMonth}.html`; a.click();
            URL.revokeObjectURL(url);
            showToast('✅ Payslip downloaded', 'success');
        }

        function closePayrollDashboard() { document.getElementById('payrollModal').classList.remove('active'); }

        function viewEmployeeDetails(employeeId, employeeName) {
            const modal = document.getElementById('employeeModal');
            const modalBody = document.getElementById('modalBody');
            const modalName = document.getElementById('modalEmployeeName');
            const employee = allData.find(e => e.id === employeeId);
            if (!employee) { modalBody.innerHTML = '<div style="text-align:center;padding:40px;color:#f87171;">Employee not found</div>'; modal.classList.add('active'); return; }
            modalName.textContent = employeeName;
            modal.classList.add('active');
            const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            let leavesHtml = employee.leaves && employee.leaves.length > 0 ? employee.leaves.map(leave => `<div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:rgba(255,255,255,0.03);border-radius:10px;margin-bottom:8px;"><div><div>📅 ${new Date(leave.date).toLocaleDateString()}</div><div style="font-size:11px;color:rgba(255,255,255,0.5);">${leave.reason || 'No reason provided'}</div></div><span style="background:rgba(139,92,246,0.2);color:#a78bfa;padding:4px 10px;border-radius:20px;font-size:11px;">${leave.type || 'Casual Leave'}</span><button onclick="deleteLeave('${employeeId}', '${leave.date}', event)" style="background:rgba(239,68,68,0.2);color:#ef4444;border:none;padding:5px 10px;border-radius:8px;cursor:pointer;"><i class="fas fa-trash"></i></button></div>`).join('') : '<div style="text-align:center;padding:20px;color:rgba(255,255,255,0.5);">No leaves recorded</div>';
            let tableHtml = `<div style="background:rgba(255,255,255,0.05);border-radius:20px;padding:24px;margin-bottom:24px;"><div style="display:flex;align-items:center;gap:20px;margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid rgba(255,255,255,0.1);"><div style="width:80px;height:80px;background:linear-gradient(135deg,#f97316,#ea580c);border-radius:24px;display:flex;align-items:center;justify-content:center;font-size:36px;color:white;"><i class="fas fa-user"></i></div><div><h3 style="color:white;font-size:24px;">${employee.name}</h3><p><i class="fas fa-id-card"></i> Employee ID: ${employee.id}</p><p><i class="fas fa-building"></i> ${employee.department} · ${employee.designation}</p></div></div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;"><div style="background:rgba(255,255,255,0.03);border-radius:16px;padding:16px;"><div style="color:rgba(255,255,255,0.5);font-size:11px;">Branch</div><div style="color:white;font-weight:600;">${employee.branch}</div></div><div style="background:rgba(255,255,255,0.03);border-radius:16px;padding:16px;"><div style="color:rgba(255,255,255,0.5);font-size:11px;">Team</div><div style="color:white;font-weight:600;">${employee.team}</div></div><div style="background:rgba(255,255,255,0.03);border-radius:16px;padding:16px;"><div style="color:rgba(255,255,255,0.5);font-size:11px;">Working Days</div><div style="color:white;font-weight:600;">${employee.working_days}</div></div></div>
            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:24px;"><div style="background:rgba(255,255,255,0.05);border-radius:16px;padding:16px;text-align:center;"><div style="font-size:28px;font-weight:800;color:#10b981;">${employee.present}</div><div style="font-size:11px;color:rgba(255,255,255,0.6);">Present Days</div></div><div style="background:rgba(255,255,255,0.05);border-radius:16px;padding:16px;text-align:center;"><div style="font-size:28px;font-weight:800;color:#ef4444;">${employee.absent}</div><div style="font-size:11px;color:rgba(255,255,255,0.6);">Absent Days</div></div><div style="background:rgba(255,255,255,0.05);border-radius:16px;padding:16px;text-align:center;"><div style="font-size:28px;font-weight:800;color:#f59e0b;">${employee.late}</div><div style="font-size:11px;color:rgba(255,255,255,0.6);">Late Days</div></div><div style="background:rgba(255,255,255,0.05);border-radius:16px;padding:16px;text-align:center;"><div style="font-size:28px;font-weight:800;color:#a78bfa;">${employee.leave}</div><div style="font-size:11px;color:rgba(255,255,255,0.6);">Leave Days</div></div><div style="background:rgba(255,255,255,0.05);border-radius:16px;padding:16px;text-align:center;"><div style="font-size:28px;font-weight:800;color:#3b82f6;">${employee.working_days}</div><div style="font-size:11px;color:rgba(255,255,255,0.6);">Total Days</div></div></div></div>
            <div style="background:rgba(255,255,255,0.05);border-radius:20px;padding:24px;margin-bottom:24px;"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;"><h4 style="color:white;"><i class="fas fa-umbrella-beach"></i> Leave Management</h4><button onclick="toggleLeaveForm('${employeeId}')" style="background:linear-gradient(135deg,#f97316,#ea580c);color:white;border:none;padding:8px 16px;border-radius:20px;cursor:pointer;"><i class="fas fa-plus"></i> Add Leave</button></div>
            <div id="leaveForm-${employeeId}" style="display:none;margin-bottom:20px;padding:20px;background:rgba(255,255,255,0.03);border-radius:16px;"><div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;"><div><label style="color:white;font-size:13px;">Leave Date</label><input type="date" id="leaveDate-${employeeId}" style="width:100%;padding:10px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:white;"></div><div><label style="color:white;font-size:13px;">Leave Type</label><select id="leaveType-${employeeId}" style="width:100%;padding:10px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:white;"><option value="Casual Leave">Casual Leave</option><option value="Sick Leave">Sick Leave</option><option value="Annual Leave">Annual Leave</option><option value="Unpaid Leave">Unpaid Leave</option></select></div></div><div><label style="color:white;font-size:13px;">Reason (Optional)</label><input type="text" id="leaveReason-${employeeId}" placeholder="Enter reason..." style="width:100%;padding:10px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:white;"></div><button onclick="addLeave('${employeeId}')" style="margin-top:15px;width:100%;padding:10px;background:#10b981;color:white;border:none;border-radius:10px;cursor:pointer;"><i class="fas fa-save"></i> Save Leave</button></div>
            <div id="leaveList-${employeeId}">${leavesHtml}</div></div>
            <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;"><thead><tr><th style="padding:12px;background:rgba(255,255,255,0.05);color:rgba(255,255,255,0.7);font-size:12px;">Date</th><th style="padding:12px;background:rgba(255,255,255,0.05);color:rgba(255,255,255,0.7);font-size:12px;">Day</th><th style="padding:12px;background:rgba(255,255,255,0.05);color:rgba(255,255,255,0.7);font-size:12px;">Check In</th><th style="padding:12px;background:rgba(255,255,255,0.05);color:rgba(255,255,255,0.7);font-size:12px;">Type</th><th style="padding:12px;background:rgba(255,255,255,0.05);color:rgba(255,255,255,0.7);font-size:12px;">Status</th></thead><tbody>`;
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(currentYear, currentMonth - 1, day);
                const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
                const isWeekendDay = isWeekend(currentYear, currentMonth, day);
                const checkin = employee.attendance[day];
                const isPresent = checkin !== '--:--';
                const isLate = isPresent && isCheckinLate(checkin, day);
                const hasLeave = employee.leaves.some(l => l.date === `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`);
                let status = 'Absent', statusColor = '#ef4444', statusBg = 'rgba(239,68,68,0.2)', type = 'Working Day';
                if (isWeekendDay) { type = 'Weekend'; status = isPresent ? 'Present (Weekend)' : 'Weekend'; statusColor = '#a78bfa'; statusBg = 'rgba(139,92,246,0.2)'; }
                else if (hasLeave) { type = 'Working Day'; status = 'On Leave'; statusColor = '#a78bfa'; statusBg = 'rgba(139,92,246,0.2)'; }
                else if (isPresent) { status = isLate ? 'Late' : 'Present'; statusColor = isLate ? '#f59e0b' : '#10b981'; statusBg = isLate ? 'rgba(245,158,11,0.2)' : 'rgba(16,185,129,0.2)'; }
                tableHtml += `<tr><td style="padding:10px;">${day} ${monthNames[currentMonth - 1]}</td><td style="padding:10px;">${dayName}</td><td style="padding:10px;${isPresent ? 'color:#f97316;font-weight:600;' : 'color:rgba(255,255,255,0.4);'}">${checkin}</td><td style="padding:10px;${isWeekendDay ? 'color:#a78bfa;' : ''}">${type}</td><td style="padding:10px;"><span style="background:${statusBg};color:${statusColor};padding:4px 10px;border-radius:20px;font-size:11px;">${status}</span></td></tr>`;
            }
            tableHtml += `</tbody></table></div><div style="margin-top:20px;text-align:right;display:flex;gap:10px;justify-content:flex-end;"><button class="btn btn-success" onclick="viewPayrollSlip('${employeeId}')"><i class="fas fa-receipt"></i> View Payslip</button><button class="btn btn-secondary" onclick="exportEmployeeAttendance('${employeeId}', '${employeeName.replace(/'/g, "\\'")}')"><i class="fas fa-download"></i> Download Report</button></div>`;
            modalBody.innerHTML = tableHtml;
        }

        function toggleLeaveForm(employeeId) { const form = document.getElementById(`leaveForm-${employeeId}`); if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none'; }

        function addLeave(employeeId) {
            const dateInput = document.getElementById(`leaveDate-${employeeId}`);
            const typeSelect = document.getElementById(`leaveType-${employeeId}`);
            const reasonInput = document.getElementById(`leaveReason-${employeeId}`);
            if (!dateInput.value) { showToast('Please select a date', 'warning'); return; }
            const leaveDate = dateInput.value;
            const [year, month, day] = leaveDate.split('-');
            if (isWeekend(parseInt(year), parseInt(month), parseInt(day))) { showToast('Cannot add leave on weekends', 'warning'); return; }
            if (!leaves[employeeId]) leaves[employeeId] = [];
            if (leaves[employeeId].find(l => l.date === leaveDate)) { showToast('Leave already exists', 'warning'); return; }
            leaves[employeeId].push({ date: leaveDate, type: typeSelect.value, reason: reasonInput.value || 'No reason provided', addedAt: new Date().toISOString() });
            leaves[employeeId].sort((a, b) => new Date(a.date) - new Date(b.date));
            saveLeaves();
            showToast('✅ Leave added', 'success');
            toggleLeaveForm(employeeId);
            loadAttendanceData();
            closeModal();
            setTimeout(() => viewEmployeeDetails(employeeId, document.getElementById('modalEmployeeName').textContent), 500);
        }

        function deleteLeave(employeeId, leaveDate, event) {
            event.stopPropagation();
            if (confirm('Delete this leave?')) {
                if (leaves[employeeId]) { leaves[employeeId] = leaves[employeeId].filter(l => l.date !== leaveDate); if (leaves[employeeId].length === 0) delete leaves[employeeId]; saveLeaves(); showToast('✅ Deleted', 'success'); loadAttendanceData(); closeModal(); setTimeout(() => viewEmployeeDetails(employeeId, document.getElementById('modalEmployeeName').textContent), 500); }
            }
        }

        function exportToCSV() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const departmentFilter = document.getElementById('departmentFilter').value;
            const teamLeadFilter = document.getElementById('teamLeadFilter').value;
            let filtered = allData.filter(emp => { 
                const matchSearch = emp.name.toLowerCase().includes(searchTerm) || emp.id.toLowerCase().includes(searchTerm); 
                const matchDept = !departmentFilter || emp.department === departmentFilter;
                let matchTeamLead = true;
                if (teamLeadFilter === 'Team Lead') {
                    const designation = (emp.designation || '').toLowerCase();
                    const team = (emp.team || '').toLowerCase();
                    matchTeamLead = designation.includes('team lead') || designation.includes('lead') || team.includes('team lead') || (team === 'developer team' && designation === 'lead') || (team === 'dialer team' && designation === 'lead');
                }
                return matchSearch && matchDept && matchTeamLead; 
            });
            let headers = ['ID', 'Personnel', 'Department', 'Designation', 'Branch', 'Team'];
            for (let day = 1; day <= daysInMonth; day++) headers.push(`${day} ${getMonthAbbr(currentMonth)}`);
            headers.push('Present Days', 'Absent Days', 'Late Days', 'Leave Days', `Working Days (${workingDaysCount})`);
            let csvContent = headers.map(h => `"${h}"`).join(',') + '\n';
            filtered.forEach(emp => { let row = [emp.id, emp.name, emp.department, emp.designation, emp.branch, emp.team];
                for (let day = 1; day <= daysInMonth; day++) { let val = emp.attendance[day]; const hasLeave = emp.leaves.some(l => l.date === `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`); if (hasLeave && !isWeekend(currentYear, currentMonth, day)) val = 'LEAVE'; row.push(val); }
                row.push(emp.present, emp.absent, emp.late, emp.leave, emp.working_days);
                csvContent += row.map(cell => `"${cell}"`).join(',') + '\n';
            });
            downloadCSV(csvContent, `attendance_${currentYear}_${currentMonth}.csv`);
            showToast(`✅ Exported ${filtered.length} employees`, 'success');
        }

        function exportEmployeeAttendance(employeeId, employeeName) {
            const employee = allData.find(e => e.id === employeeId);
            if (!employee) return;
            const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            let csvContent = `"Employee Attendance Report"\n"Employee ID","${employee.id}"\n"Employee Name","${employee.name}"\n"Department","${employee.department}"\n"Designation","${employee.designation}"\n"Branch","${employee.branch}"\n"Team","${employee.team}"\n"Period","${monthNames[currentMonth - 1]} ${currentYear}"\n"Working Days","${employee.working_days}"\n"Present","${employee.present}"\n"Late","${employee.late}"\n"Absent","${employee.absent}"\n"Leave","${employee.leave}"\n"Rate","${employee.attendance_rate}%"\n\n"Date","Day","Type","Check In","Status","Counted"\n`;
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(currentYear, currentMonth - 1, day);
                const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
                const isWeekendDay = isWeekend(currentYear, currentMonth, day);
                const checkin = employee.attendance[day];
                const isPresent = checkin !== '--:--';
                const isLate = isPresent && isCheckinLate(checkin, day);
                const hasLeave = employee.leaves.some(l => l.date === `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`);
                let status = 'Absent', counted = 'Yes', type = 'Working Day';
                if (isWeekendDay) { type = 'Weekend'; counted = 'No'; status = isPresent ? 'Present (Weekend)' : 'Weekend'; }
                else if (hasLeave) { status = 'On Leave'; counted = 'Yes (Leave)'; }
                else if (isPresent) { status = isLate ? 'Late' : 'Present'; }
                csvContent += `"${day} ${monthNames[currentMonth - 1]}","${dayName}","${type}","${checkin}","${status}","${counted}"\n`;
            }
            downloadCSV(csvContent, `employee_${employeeId}_${currentYear}_${currentMonth}.csv`);
            showToast(`✅ Downloaded ${employeeName}`, 'success');
        }

        function downloadCSV(content, filename) { const blob = new Blob(["\uFEFF" + content], { type: 'text/csv;charset=utf-8;' }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = filename; a.click(); URL.revokeObjectURL(url); }

        function showToast(message, type) { 
            const container = document.getElementById('toastContainer'); 
            const toast = document.createElement('div'); 
            toast.className = 'toast show'; 
            toast.style.borderLeftColor = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#f59e0b'; 
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i> ${message}`; 
            container.appendChild(toast); 
            setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000); 
        }

        function closeModal() { document.getElementById('employeeModal').classList.remove('active'); }

        function handlePayrollSearch(val) {
            currentPayrollSearchTerm = val;
            renderPayrollDashboard();
        }

        function closePayrollDashboard() { 
            document.getElementById('payrollModal').classList.remove('active'); 
            currentPayrollSearchTerm = '';
        }

        document.getElementById('searchInput').addEventListener('keyup', function() { searchTerm = this.value; renderTable(); });
        document.getElementById('departmentFilter').addEventListener('change', function() { currentDepartment = this.value; renderTable(); });

        loadAllAdj();
        loadEmployeeList();
        document.addEventListener('DOMContentLoaded', loadAttendanceData);
        window.onclick = function(event) { if (event.target.classList.contains('modal') && event.target.id !== 'payrollModal') closeModal(); }

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

        // Make functions global for onclick
        window.openPayrollDashboard = openPayrollDashboard;
        window.renderPayrollDashboard = renderPayrollDashboard;
        window.switchPayrollTab = switchPayrollTab;
        window.renderEmployeeSearchResults = renderEmployeeSearchResults;
        window.addAdjItemFromSearch = addAdjItemFromSearch;
        window.deleteAdjItem = deleteAdjItem;
        window.addAdvance = addAdvance;
        window.deleteAdvance = deleteAdvance;
        window.setManualLate = setManualLate;
        window.setManualPunctuality = setManualPunctuality;
        window.setApprovedLeaves = setApprovedLeaves;
        window.setTax = setTax;
        window.saveEmpSettings = saveEmpSettings;
        window.processFullPayroll = processFullPayroll;
        window.exportPayrollCSV = exportPayrollCSV;
        window.viewPayrollSlip = viewPayrollSlip;
        window.downloadSlipAsHTML = downloadSlipAsHTML;
        window.closePayrollDashboard = closePayrollDashboard;
        window.viewEmployeeDetails = viewEmployeeDetails;
        window.addLeave = addLeave;
        window.toggleLeaveForm = toggleLeaveForm;
        window.deleteLeave = deleteLeave;
        window.exportToCSV = exportToCSV;
        window.exportEmployeeAttendance = exportEmployeeAttendance;
        window.loadAttendanceData = loadAttendanceData;
        window.handlePayrollSearch = handlePayrollSearch;
        window.filterSettingsTab = filterSettingsTab;
        window.addAdvanceFromSearch = addAdvanceFromSearch;
        window.setManualLateFromSearch = setManualLateFromSearch;
        window.setManualPunctualityFromSearch = setManualPunctualityFromSearch;
        window.setApprovedLeavesFromSearch = setApprovedLeavesFromSearch;
        window.setTaxFromSearch = setTaxFromSearch;
        window.closeModal = closeModal;
        window.filterByTeamLead = filterByTeamLead;
    </script>
    <script src="js/portal-chat-fab.js"></script>
</body>
</html>