<?php
// attendance/download_qrcodes.php
require_once __DIR__ . '/../api/config.php';
header('Content-Type: text/html; charset=utf-8');

// Auth Guard: Only Admin/Super Admin allowed
if (!isset($_SESSION['portal_role']) || !in_array($_SESSION['portal_role'], ['super_admin', 'admin'])) {
    die("Access denied. Please log in as an administrator.");
}

// Fetch all active users who have an employee code
$res = $conn->query("
    SELECT employee_code, full_name, designation, department 
    FROM users 
    WHERE status = 'active' AND employee_code IS NOT NULL AND employee_code != ''
    ORDER BY CAST(employee_code AS UNSIGNED), employee_code ASC
");

$users = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee QR Badges Download · Balitech</title>
    <!-- CSS & Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <!-- JSZip for ZIP generation & QRCode.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    
    <style>
        :root {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --bg-dark: #0b0f19;
            --card-bg: rgba(22, 28, 45, 0.6);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
            padding: 40px 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 24px 32px;
            border-radius: 20px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
        }

        .header h1 {
            font-size: 24px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 span {
            color: var(--primary);
        }

        .actions {
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(249, 115, 22, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        /* Grid */
        .badges-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 20px;
        }

        /* Badge design */
        .badge-card {
            background: #eef1f4;
            border-radius: 16px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            border: 1px solid rgba(255, 255, 255, 0.05);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 200px;
            height: 200px;
            margin: 0 auto;
        }

        .qr-placeholder {
            width: 170px;
            height: 170px;
            background: #eef1f4;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .progress-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.85);
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }

        .progress-box {
            background: #1e293b;
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.1);
            max-width: 400px;
            width: 90%;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(249,115,22,0.1);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px auto;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* Off-screen Canvas rendering container */
        #canvasContainer {
            position: absolute;
            left: -9999px;
            top: -9999px;
        }

        /* Printable view styles */
        @media print {
            body { background: white; color: black; padding: 0; }
            .header, .progress-overlay { display: none !important; }
            .badges-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 15px;
                page-break-inside: auto;
            }
            .badge-card {
                page-break-inside: avoid;
                box-shadow: none;
                border: 1px solid #cbd5e1;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div>
                <h1><span>Balitech</span> QR Badges Center</h1>
                <p style="color: var(--text-muted); font-size: 13px; margin-top: 5px;">Manage and download verification QR Codes for <?php echo count($users); ?> active members.</p>
            </div>
            <div class="actions">
                <button onclick="window.print()" class="btn btn-secondary"><i class="fas fa-print"></i> Print Cards</button>
                <button onclick="downloadAllBadgesZip()" class="btn btn-primary"><i class="fas fa-file-archive"></i> Download All as ZIP</button>
            </div>
        </header>

        <div class="badges-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 35px 20px;">
            <?php foreach ($users as $u): 
                $code = $u['employee_code'];
                $verify_url = "https://hrms.balitech.org/verify.php?code=" . $code;
            ?>
                <div style="display: flex; flex-direction: column; align-items: center;">
                    <div class="badge-card" data-code="<?php echo htmlspecialchars($code); ?>" data-name="<?php echo htmlspecialchars($u['full_name']); ?>">
                        <div class="qr-placeholder" id="qr-<?php echo $code; ?>"></div>
                    </div>
                    <div style="text-align: center; margin-top: 10px; font-size: 13px; font-weight: 600; color: #cbd5e1; width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($u['full_name']); ?>">
                        <?php echo htmlspecialchars($u['full_name']); ?>
                    </div>
                    <div style="text-align: center; font-size: 11px; font-weight: 700; color: var(--primary); margin-top: 2px;">
                        ID: <?php echo htmlspecialchars($code); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Canvas container for offline generating -->
    <div id="canvasContainer"></div>

    <div class="progress-overlay" id="progressOverlay">
        <div class="progress-box">
            <div class="spinner"></div>
            <h3 style="color: white; margin-bottom: 10px;">Generating ZIP Archive</h3>
            <p id="progressText" style="color: var(--text-muted); font-size: 14px;">Initializing batch generation...</p>
        </div>
    </div>

    <script>
        const usersList = <?php echo json_encode($users); ?>;

        // Render all QR codes in HTML
        document.addEventListener('DOMContentLoaded', () => {
            usersList.forEach(u => {
                const container = document.getElementById(`qr-${u.employee_code}`);
                if (container) {
                    new QRCode(container, {
                        text: `https://hrms.balitech.org/verify.php?code=${u.employee_code}`,
                        width: 170,
                        height: 170,
                        colorDark: "#ffffff",
                        colorLight: "#eef1f4",
                        correctLevel: QRCode.CorrectLevel.M
                    });
                }
            });
        });

        // Helper to load Balitech Logo Image for Canvas drawing
        function loadLogoImage() {
            return new Promise((resolve) => {
                const img = new Image();
                img.src = '../assets/images/balitech-logo.png';
                img.onload = () => resolve(img);
                img.onerror = () => resolve(null);
            });
        }

        async function downloadAllBadgesZip() {
            const overlay = document.getElementById('progressOverlay');
            const text = document.getElementById('progressText');
            overlay.style.display = 'flex';

            const zip = new JSZip();
            const logoImg = await loadLogoImage();

            // Create offscreen container
            const container = document.getElementById('canvasContainer');

            // Loop through each user sequentially to draw high-quality badges
            for (let i = 0; i < usersList.length; i++) {
                const user = usersList[i];
                text.textContent = `Processing badge ${i + 1} of ${usersList.length}: ID ${user.employee_code}`;

                // 1. Generate temp QR code in DOM
                const tempDiv = document.createElement('div');
                container.appendChild(tempDiv);
                
                const qr = new QRCode(tempDiv, {
                    text: `https://hrms.balitech.org/verify.php?code=${user.employee_code}`,
                    width: 250,
                    height: 250,
                    colorDark: "#ffffff",
                    colorLight: "#eef1f4",
                    correctLevel: QRCode.CorrectLevel.H
                });

                // Wait 100ms for QR generation
                await new Promise(r => setTimeout(r, 80));

                const qrImg = tempDiv.querySelector('img');
                if (!qrImg) {
                    container.removeChild(tempDiv);
                    continue;
                }

                // 2. Create Canvas (Size: 250px width x 250px height)
                const canvas = document.createElement('canvas');
                canvas.width = 250;
                canvas.height = 250;
                const ctx = canvas.getContext('2d');

                // Draw background card (Light Grey matching user reference)
                ctx.fillStyle = '#eef1f4';
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                // Draw QR Code directly covering the canvas
                ctx.drawImage(qrImg, 0, 0, 250, 250);

                // 3. Save to Zip
                const dataUrl = canvas.toDataURL('image/png');
                const base64Data = dataUrl.split(',')[1];
                
                // Filename structure: ID_NAME.png (safe for filesystem)
                const safeName = user.full_name.replace(/[^a-zA-Z0-9]/g, '_');
                zip.file(`${user.employee_code}_${safeName}.png`, base64Data, { base64: true });

                // Cleanup
                container.removeChild(tempDiv);
            }

            text.textContent = "Finalizing ZIP archive...";
            const blob = await zip.generateAsync({ type: 'blob' });
            saveAs(blob, 'Balitech_HRMS_QR_Badges.zip');
            
            overlay.style.display = 'none';
        }
    </script>
</body>
</html>
