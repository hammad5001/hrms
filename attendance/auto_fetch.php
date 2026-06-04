<?php
/**
 * Auto Attendance Fetcher - Fixed Version
 */

date_default_timezone_set('Asia/Karachi');

require_once 'config.php';

// Configuration - Use virtual environment Python
$venvPython = __DIR__ . '/python-script/venv/Scripts/python.exe';
$pythonScript = __DIR__ . '/python-script/' . PYTHON_SCRIPT;
$logFile = __DIR__ . '/' . LOG_FILE;

function runPythonScript() {
    global $venvPython, $pythonScript;
    
    // Check if virtual environment Python exists
    if (file_exists($venvPython)) {
        $command = escapeshellarg($venvPython) . ' ' . escapeshellarg($pythonScript) . ' 2>&1';
        $output = shell_exec($command);
        return $output;
    } else {
        return "Error: Virtual environment Python not found at: $venvPython";
    }
}

// Run fetch if requested
if (isset($_GET['fetch'])) {
    $output = runPythonScript();
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Fetch completed\n", FILE_APPEND);
}

// Read logs
$logs = file_exists($logFile) ? file($logFile) : [];
$logs = array_slice($logs, -20);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Auto Attendance Fetcher</title>
    <meta http-equiv="refresh" content="30">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: rgba(255,255,255,0.1);
            padding: 30px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
        }
        h1 { 
            margin-top: 0; 
            border-bottom: 2px solid #f97316; 
            padding-bottom: 10px;
        }
        .button {
            background: #f97316;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        .button:hover {
            background: #ea580c;
        }
        .log-box {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 10px;
            font-family: monospace;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .error { color: #ef4444; }
        .success { color: #10b981; }
        .info { color: #3b82f6; }
        .timestamp { color: #94a3b8; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Auto Attendance Fetcher</h1>
        
        <?php if (isset($_GET['fetch'])): ?>
            <h2>Fetching Attendance Data...</h2>
            <div class="log-box">
                <?php 
                $output = runPythonScript();
                echo "<span class='timestamp'>" . date('Y-m-d H:i:s') . "</span><br>";
                echo nl2br(htmlspecialchars($output)); 
                ?>
            </div>
            
            <?php if (strpos($output, 'Error') === false): ?>
                <p class="success">✅ Fetch completed successfully!</p>
            <?php else: ?>
                <p class="error">❌ Fetch failed. Check the output above.</p>
            <?php endif; ?>
        <?php endif; ?>
        
        <div style="margin-top: 20px;">
            <a href="?fetch=1" class="button">Fetch Now</a>
            <a href="attendance-dashboard.html" class="button">View Dashboard</a>
        </div>
        
        <?php if (!empty($logs)): ?>
            <h3>Recent Activity:</h3>
            <div class="log-box">
                <?php foreach ($logs as $log): ?>
                    <?php echo htmlspecialchars($log) . "<br>"; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <p style="margin-top: 20px; font-size: 12px; opacity: 0.8;">
            <strong>Python:</strong> <?php echo $venvPython; ?><br>
            <strong>Script:</strong> <?php echo $pythonScript; ?><br>
            <strong>Last checked:</strong> <?php echo date('Y-m-d H:i:s'); ?>
        </p>
    </div>
</body>
</html>