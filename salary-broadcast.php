<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['portal_role'] !== 'super_admin' && $_SESSION['portal_role'] !== 'finance')) {
    header('Location: index.html');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Slip Broadcast | Super Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- SheetJS for parsing excel -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    <style>
        :root {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --primary-light: rgba(249, 115, 22, 0.1);
            --bg-main: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            background-color: var(--bg-main);
            color: var(--text-main);
            min-height: 100vh;
        }

        .header {
            background: white;
            padding: 20px 40px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header h1 {
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 i {
            color: var(--primary);
            background: var(--primary-light);
            padding: 10px;
            border-radius: 12px;
        }

        .container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .broadcast-card {
            background: var(--bg-card);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i {
            color: var(--text-muted);
        }

        /* Upload Zone */
        .upload-zone {
            border: 2px dashed var(--border);
            border-radius: 16px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 30px;
            background: #fdfdfd;
        }

        .upload-zone:hover, .upload-zone.dragover {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .upload-zone i {
            font-size: 48px;
            color: var(--primary);
            margin-bottom: 15px;
        }

        .upload-zone h3 {
            font-size: 18px;
            margin-bottom: 8px;
        }

        .upload-zone p {
            color: var(--text-muted);
            font-size: 14px;
        }

        /* Target Audience / Channels */
        .options-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .option-card {
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .option-card.selected {
            border-color: var(--primary);
            background: var(--primary-light);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.1);
        }

        .option-icon {
            font-size: 24px;
            color: var(--primary);
        }

        .option-content h4 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .option-content p {
            font-size: 13px;
            color: var(--text-muted);
        }

        /* Form Fields */
        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary);
        }

        /* Preview Table */
        .preview-container {
            display: none;
            margin-bottom: 30px;
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }
        
        .preview-header {
            background: #f8fafc;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            display: flex;
            justify-content: space-between;
        }

        .table-responsive {
            max-height: 300px;
            overflow-y: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th, td {
            padding: 12px 20px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background: white;
            position: sticky;
            top: 0;
            color: var(--text-muted);
            font-weight: 600;
        }

        /* Action Buttons */
        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-outline {
            background: white;
            border: 1px solid var(--border);
            color: var(--text-main);
        }

        .btn-outline:hover {
            background: #f1f5f9;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px var(--primary-light);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-primary:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(255,255,255,0.9);
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            z-index: 1000;
        }
        .spinner {
            width: 50px; height: 50px;
            border: 4px solid var(--primary-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        @keyframes spin { 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>

    <div class="header">
        <h1><i class="fas fa-paper-plane"></i> Platform Broadcast</h1>
        <a href="admin.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <div class="container">
        <div class="broadcast-card">
            
            <div class="form-group">
                <label>Salary Month & Year</label>
                <div style="display: flex; gap: 15px;">
                    <select id="monthSelect" class="form-control" style="flex: 1;">
                        <option value="January">January</option>
                        <option value="February">February</option>
                        <option value="March">March</option>
                        <option value="April">April</option>
                        <option value="May">May</option>
                        <option value="June">June</option>
                        <option value="July">July</option>
                        <option value="August">August</option>
                        <option value="September">September</option>
                        <option value="October">October</option>
                        <option value="November">November</option>
                        <option value="December">December</option>
                    </select>
                    <input type="number" id="yearSelect" class="form-control" style="width: 150px;" value="<?php echo date('Y'); ?>">
                </div>
            </div>

            <h3 class="section-title"><i class="fas fa-users"></i> Target Audience</h3>
            <div class="options-grid">
                <div class="option-card selected" id="targetAll">
                    <div class="option-icon"><i class="fas fa-file-excel"></i></div>
                    <div class="option-content">
                        <h4>All Employees in Sheet</h4>
                        <p>Broadcast to everyone listed in the uploaded Excel file.</p>
                    </div>
                </div>
            </div>

            <h3 class="section-title"><i class="fas fa-broadcast-tower"></i> Delivery Channels</h3>
            <div class="options-grid">
                <div class="option-card selected" id="channelEmail" onclick="this.classList.toggle('selected')">
                    <div class="option-icon"><i class="fas fa-envelope"></i></div>
                    <div class="option-content">
                        <h4>Email Notification</h4>
                        <p>Send a beautiful HTML salary slip to their registered email.</p>
                    </div>
                </div>
                <div class="option-card selected" id="channelPortal" onclick="this.classList.toggle('selected')">
                    <div class="option-icon"><i class="fas fa-desktop"></i></div>
                    <div class="option-content">
                        <h4>Employee Portal</h4>
                        <p>Make slips available in the "My Payslips" section.</p>
                    </div>
                </div>
            </div>

            <h3 class="section-title"><i class="fas fa-upload"></i> Upload Salary Data</h3>
            <div class="upload-zone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                <i class="fas fa-cloud-upload-alt"></i>
                <h3>Click or drag Excel file to upload</h3>
                <p>Supports .xlsx files with columns: Employee Name, Sudo Name, Gross Salary, Net Payable, etc.</p>
                <input type="file" id="fileInput" accept=".xlsx, .xls" style="display: none;">
            </div>

            <div class="preview-container" id="previewContainer">
                <div class="preview-header">
                    <span><i class="fas fa-table"></i> Data Preview</span>
                    <span id="recordCount" style="color: var(--primary);">0 records found</span>
                </div>
                <div class="table-responsive">
                    <table id="previewTable">
                        <thead>
                            <tr>
                                <th>Emp Code / ID</th>
                                <th>Employee Name</th>
                                <th>Gross Salary</th>
                                <th>Total Deductions</th>
                                <th>Net Payable</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <div class="actions">
                <button class="btn btn-outline" onclick="window.location.reload()">Cancel</button>
                <button class="btn btn-primary" id="broadcastBtn" disabled onclick="startBroadcast()">
                    <i class="fas fa-paper-plane"></i> Broadcast Salary Slips
                </button>
            </div>

        </div>
    </div>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <h2 style="margin-bottom: 10px;">Broadcasting...</h2>
        <p id="loadingStatus" style="color: var(--text-muted);">Please wait while emails are dispatched.</p>
    </div>

    <script>
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        let parsedData = [];

        // Current Month Selection
        const currentMonth = new Date().toLocaleString('default', { month: 'long' });
        document.getElementById('monthSelect').value = currentMonth;

        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });

        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('dragover');
        });

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                handleFile(e.dataTransfer.files[0]);
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length) {
                handleFile(e.target.files[0]);
            }
        });

        function handleFile(file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const data = e.target.result;
                const workbook = XLSX.read(data, { type: 'binary' });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                
                // Read rows, skipping headers as needed, but let's assume row 1 is header
                const rawData = XLSX.utils.sheet_to_json(worksheet, { defval: "" });
                
                // Filter out empty rows flexibly (accommodate 'B ID', trailing spaces, etc.)
                parsedData = rawData.filter(row => {
                    return Object.keys(row).some(k => {
                        const kl = k.trim().toLowerCase();
                        const val = String(row[k]).trim();
                        return (kl.includes('name') || kl.includes('id') || kl.includes('sr')) && val !== "";
                    });
                });
                
                renderPreview(parsedData);
            };
            reader.readAsBinaryString(file);
        }

        function renderPreview(data) {
            const tbody = document.querySelector('#previewTable tbody');
            tbody.innerHTML = '';
            
            if (data.length === 0) {
                alert("No valid data found in the sheet.");
                return;
            }

            // Figure out column names dynamically in case they slightly differ
            const firstRow = data[0];
            const getCol = (keyParts) => Object.keys(firstRow).find(k => keyParts.some(p => k.toLowerCase().includes(p)));
            
            const empCodeCol = getCol(['biometric id', 'employee code', 'emp id', 'sr. no']) || Object.keys(firstRow)[0];
            const nameCol = getCol(['employee name', 'name']) || Object.keys(firstRow)[1];
            const grossCol = getCol(['gross salary', 'gross']) || '';
            const deductCol = getCol(['total deductions', 'deduction']) || '';
            const netCol = getCol(['net payable', 'net salary']) || '';

            data.slice(0, 50).forEach(row => { // Show up to 50 for preview
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${row[empCodeCol] || '-'}</td>
                    <td>${row[nameCol] || '-'}</td>
                    <td>${grossCol ? row[grossCol] : '-'}</td>
                    <td>${deductCol ? row[deductCol] : '-'}</td>
                    <td><strong>${netCol ? row[netCol] : '-'}</strong></td>
                `;
                tbody.appendChild(tr);
            });

            document.getElementById('recordCount').innerText = `${data.length} records found`;
            document.getElementById('previewContainer').style.display = 'block';
            document.getElementById('broadcastBtn').disabled = false;
            document.getElementById('dropzone').style.borderColor = 'var(--secondary)';
            document.getElementById('dropzone').querySelector('h3').innerText = fileInput.files[0].name;
        }

        async function startBroadcast() {
            const sendEmail = document.getElementById('channelEmail').classList.contains('selected');
            const sendPortal = document.getElementById('channelPortal').classList.contains('selected');
            const month = document.getElementById('monthSelect').value;
            const year = document.getElementById('yearSelect').value;

            if (!sendEmail && !sendPortal) {
                alert("Please select at least one delivery channel.");
                return;
            }

            document.getElementById('loadingOverlay').style.display = 'flex';
            document.getElementById('loadingStatus').innerText = `Processing ${parsedData.length} records...`;

            try {
                const response = await fetch('api/send_salary_broadcast.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        month: month,
                        year: year,
                        sendEmail: sendEmail,
                        sendPortal: sendPortal,
                        salaryData: parsedData
                    })
                });

                const result = await response.json();
                
                document.getElementById('loadingOverlay').style.display = 'none';

                if (result.success) {
                    alert(`Broadcast Complete!\nProcessed: ${result.processed}\nEmails Sent: ${result.emails_sent}\nFailed: ${result.failed}`);
                    window.location.reload();
                } else {
                    alert("Error: " + result.message);
                }
            } catch (err) {
                document.getElementById('loadingOverlay').style.display = 'none';
                alert("An error occurred during broadcast.");
                console.error(err);
            }
        }
    </script>
</body>
</html>
