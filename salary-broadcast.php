<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['portal_role'] !== 'super_admin' && $_SESSION['portal_role'] !== 'finance')) {
    header('Location: index.html');
    exit;
}
require_once 'config.php';

// Auto-create history table if not exists
$conn->query("
CREATE TABLE IF NOT EXISTS `salary_broadcast_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `month` VARCHAR(20) NOT NULL,
    `year` INT(11) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `uploaded_by_id` INT NOT NULL,
    `uploaded_by_name` VARCHAR(150) NOT NULL,
    `total_records` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_month_year` (`month`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$uploader_name = 'User';
if ($stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?")) {
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($name_val);
    if ($stmt->fetch()) {
        $uploader_name = $name_val;
    }
    $stmt->close();
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
        <div style="display: flex; align-items: center; gap: 20px;">
            <span style="font-size: 14px; color: var(--text-muted);">
                <i class="fas fa-user-circle"></i> Logged in: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($uploader_name); ?></strong>
            </span>
            <a href="admin.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
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
                <p>Supports official Finance template: B-ID, Employees Name, Gross Salary, Total Deduction Ept Tax, Tax and Final Net Salary.</p>
                <input type="file" id="fileInput" accept=".xlsx, .xls" style="display: none;">
            </div>

            <div style="text-align: right; margin-top: -15px; margin-bottom: 25px;">
                <a href="javascript:void(0)" onclick="downloadTemplate()" style="font-size: 13px; color: var(--primary); text-decoration: none; font-weight: 600;">
                    <i class="fas fa-download"></i> Download Excel Template
                </a>
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
                <button class="btn btn-outline" id="revertBtn" style="display: none; color: #ef4444; border-color: rgba(239, 68, 68, 0.2);" onclick="revertSheet()">
                    <i class="fas fa-undo"></i> Clear/Revert Sheet
                </button>
                <button class="btn btn-outline" onclick="window.location.reload()">Cancel</button>
                <button class="btn btn-primary" id="broadcastBtn" disabled onclick="startBroadcast()">
                    <i class="fas fa-paper-plane"></i> Broadcast Salary Slips
                </button>
            </div>

        </div>

        <div class="broadcast-card" style="margin-top: 30px; border-color: rgba(239, 68, 68, 0.2);">
            <h3 class="section-title" style="color: #ef4444;"><i class="fas fa-history"></i> Revert / Delete Past Broadcast</h3>
            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
                If you made a mistake or uploaded the wrong sheet, select the month and year below to delete all associated salary slips. 
                They will be immediately removed from the system and will no longer show in the Employee Portal.
            </p>
            
            <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">Month</label>
                    <select id="revertMonthSelect" class="form-control">
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
                </div>
                <div style="width: 150px;">
                    <label style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px;">Year</label>
                    <input type="number" id="revertYearSelect" class="form-control" value="<?php echo date('Y'); ?>">
                </div>
                <button class="btn btn-outline" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.3); background: #fef2f2; height: 46px;" onclick="revertPastBroadcast()">
                    <i class="fas fa-trash-alt"></i> Delete Broadcast
                </button>
            </div>
        </div>

        <div class="broadcast-card" style="margin-top: 30px;">
            <h3 class="section-title"><i class="fas fa-list-alt"></i> Broadcast History Logs</h3>
            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
                Below is the history of all sheets uploaded and broadcasted. You can revert any specific month's upload directly from here.
            </p>
            
            <div class="table-responsive">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border);">
                            <th style="padding: 12px; text-align: left; color: var(--text-muted); font-weight: 600;">Month / Year</th>
                            <th style="padding: 12px; text-align: left; color: var(--text-muted); font-weight: 600;">Uploaded Sheet Name</th>
                            <th style="padding: 12px; text-align: left; color: var(--text-muted); font-weight: 600;">Uploaded By</th>
                            <th style="padding: 12px; text-align: left; color: var(--text-muted); font-weight: 600;">Records</th>
                            <th style="padding: 12px; text-align: left; color: var(--text-muted); font-weight: 600;">Broadcast Date</th>
                            <th style="padding: 12px; text-align: center; color: var(--text-muted); font-weight: 600;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $histQuery = $conn->query("SELECT month, year, file_name, uploaded_by_name, total_records, created_at FROM salary_broadcast_history ORDER BY created_at DESC");
                        if ($histQuery && $histQuery->num_rows > 0) {
                            while ($hRow = $histQuery->fetch_assoc()) {
                                $hMonth = htmlspecialchars($hRow['month']);
                                $hYear = (int)$hRow['year'];
                                $hFileName = htmlspecialchars($hRow['file_name']);
                                $hUploadedBy = htmlspecialchars($hRow['uploaded_by_name']);
                                $hTotal = (int)$hRow['total_records'];
                                $hDate = date('M d, Y h:i A', strtotime($hRow['created_at']));
                                
                                echo "<tr style='border-bottom: 1px solid var(--border);'>";
                                echo "<td style='padding: 12px;'><strong>{$hMonth} {$hYear}</strong></td>";
                                echo "<td style='padding: 12px; font-family: monospace; font-size: 12px;'>{$hFileName}</td>";
                                echo "<td style='padding: 12px;'>{$hUploadedBy}</td>";
                                echo "<td style='padding: 12px;'>{$hTotal}</td>";
                                echo "<td style='padding: 12px; color: var(--text-muted);'>{$hDate}</td>";
                                echo "<td style='padding: 12px; text-align: center;'>
                                        <button class='btn btn-outline' style='padding: 6px 12px; font-size: 12px; color: #ef4444; border-color: rgba(239, 68, 68, 0.2); display: inline-flex; margin: auto;' onclick=\"revertPastBroadcast('{$hMonth}', {$hYear})\">
                                            <i class='fas fa-undo'></i> Revert
                                        </button>
                                      </td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='6' style='padding: 20px; text-align: center; color: var(--text-muted);'>No broadcast history found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
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
        document.getElementById('revertMonthSelect').value = currentMonth;

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

                /*
                 * New official Finance Salary Template mapping.
                 *
                 * Keep all uploaded columns exactly as they are, but add the
                 * canonical keys used by the existing payslip system.
                 */
                if (parsedData.length > 0) {

                    const normalizeHeader = (value) => String(value || '')
                        .trim()
                        .toLowerCase()
                        .replace(/[_\.]+/g, ' ')
                        .replace(/\s+/g, ' ');

                    const findExactCol = (row, aliases) => {
                        const keys = Object.keys(row);

                        for (const alias of aliases) {
                            const wanted = normalizeHeader(alias);

                            const found = keys.find(
                                key => normalizeHeader(key) === wanted
                            );

                            if (found) return found;
                        }

                        return null;
                    };

                    parsedData = parsedData.map(sourceRow => {

                        /*
                         * Preserve every field from new temp.xlsx.
                         */
                        const row = { ...sourceRow };

                        const biometricCol = findExactCol(sourceRow, [
                            'B-ID',
                            'B ID',
                            'Biometric ID',
                            'Employee Code',
                            'Emp ID'
                        ]);

                        const employeeNameCol = findExactCol(sourceRow, [
                            'Employees Name',
                            'Employee Name'
                        ]);

                        const basicCol = findExactCol(sourceRow, [
                            'Basic Salary'
                        ]);

                        const punctualityCol = findExactCol(sourceRow, [
                            'Punctuality'
                        ]);

                        const grossCol = findExactCol(sourceRow, [
                            'Gross Salary'
                        ]);

                        /*
                         * IMPORTANT:
                         * This exact column is Total Deduction excluding Tax.
                         * Do NOT accidentally select Late Coming Deduction,
                         * HD Deduction, SD Deduction, etc.
                         */
                        const deductionCol = findExactCol(sourceRow, [
                            'Total Deduction Ept Tax',
                            'Total Deduction Except Tax',
                            'Total Deductions'
                        ]);

                        const subNetCol = findExactCol(sourceRow, [
                            'SUB - Net Salary',
                            'SUB Net Salary'
                        ]);

                        const taxCol = findExactCol(sourceRow, [
                            'Tax'
                        ]);

                        /*
                         * New template final payable salary.
                         * FINAL NET SALARY always has priority.
                         */
                        const finalNetCol = findExactCol(sourceRow, [
                            'Final Net Salary',
                            'Net Payable'
                        ]);

                        /*
                         * Add old/canonical keys so existing payslip code
                         * continues working without requiring Excel changes.
                         */
                        if (biometricCol) {
                            row['Biometric ID'] =
                                String(sourceRow[biometricCol] ?? '').trim();
                        }

                        if (employeeNameCol) {
                            row['Employee Name'] =
                                String(sourceRow[employeeNameCol] ?? '').trim();
                        }

                        if (basicCol) {
                            row['Basic Salary'] = sourceRow[basicCol];
                        }

                        if (punctualityCol) {
                            row['Punctuality'] = sourceRow[punctualityCol];
                        }

                        /*
                         * Use sheet Gross Salary exactly.
                         * Do NOT recalculate Basic + Punctuality.
                         */
                        if (grossCol) {
                            row['Gross Salary'] = sourceRow[grossCol];
                        }

                        if (deductionCol) {
                            row['Total Deductions'] =
                                sourceRow[deductionCol];
                        }

                        if (subNetCol) {
                            row['SUB - Net Salary'] =
                                sourceRow[subNetCol];
                        }

                        if (taxCol) {
                            row['Tax'] = sourceRow[taxCol];
                        }

                        /*
                         * This is the critical mapping for employee payslip:
                         *
                         * Final Net Salary -> Net Payable
                         */
                        if (finalNetCol) {
                            row['Final Net Salary'] =
                                sourceRow[finalNetCol];

                            row['Net Payable'] =
                                sourceRow[finalNetCol];
                        }

                        return row;
                    });
                }

                renderPreview(parsedData);
            };
            reader.readAsBinaryString(file);
        }

        function renderPreview(data) {
            const table = document.getElementById('previewTable');
            const thead = table.querySelector('thead');
            const tbody = table.querySelector('tbody');

            thead.innerHTML = '';
            tbody.innerHTML = '';

            if (!data || data.length === 0) {
                alert("No valid data found in the sheet.");
                return;
            }

            const escapePreview = value => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            // Keep the same order as uploaded Excel.
            // Ignore only genuinely blank / generated blank headers.
            const headers = Object.keys(data[0]).filter(key => {
                const clean = String(key ?? '').trim();

                if (!clean) return false;
                if (/^_\d+$/.test(clean)) return false;

                return true;
            });

            const headerRow = document.createElement('tr');

            headers.forEach(header => {
                const th = document.createElement('th');
                th.textContent = header;
                th.style.whiteSpace = 'nowrap';
                headerRow.appendChild(th);
            });

            thead.appendChild(headerRow);

            // Show first 50 employees, but ALL columns.
            data.slice(0, 50).forEach(row => {
                const tr = document.createElement('tr');

                headers.forEach(header => {
                    const td = document.createElement('td');
                    const value = row[header];

                    td.innerHTML =
                        value === undefined ||
                        value === null ||
                        String(value).trim() === ''
                            ? '<span style="color:var(--text-muted);">—</span>'
                            : escapePreview(value);

                    td.style.whiteSpace = 'nowrap';
                    tr.appendChild(td);
                });

                tbody.appendChild(tr);
            });

            document.getElementById('recordCount').innerText =
                `${data.length} records found · ${headers.length} columns`;

            document.getElementById('previewContainer').style.display = 'block';
            document.getElementById('broadcastBtn').disabled = false;
            document.getElementById('revertBtn').style.display = 'inline-flex';
            document.getElementById('dropzone').style.borderColor = 'var(--secondary)';

            document.getElementById('dropzone').querySelector('h3').innerText =
                fileInput.files[0]
                    ? fileInput.files[0].name
                    : 'File Loaded';
        }

        function revertSheet() {
            parsedData = [];
            fileInput.value = '';
            document.getElementById('previewContainer').style.display = 'none';
            document.getElementById('broadcastBtn').disabled = true;
            document.getElementById('revertBtn').style.display = 'none';
            dropzone.style.borderColor = 'var(--border)';
            dropzone.querySelector('h3').innerText = 'Click or drag Excel file to upload';
            const tbody = document.querySelector('#previewTable tbody');
            tbody.innerHTML = '';
            document.getElementById('recordCount').innerText = '0 records found';
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
                        fileName: fileInput.files[0] ? fileInput.files[0].name : 'Unknown File',
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

        async function revertPastBroadcast(month, year) {
            if (!month || !year) {
                month = document.getElementById('revertMonthSelect').value;
                year = document.getElementById('revertYearSelect').value;
            }

            if (!confirm(`Are you sure you want to delete all salary slips for ${month} ${year}?\nThis action CANNOT be undone and they will be removed from the Employee Portal.`)) {
                return;
            }

            document.getElementById('loadingOverlay').style.display = 'flex';
            document.getElementById('loadingStatus').innerText = `Reverting ${month} ${year} broadcast...`;

            try {
                const response = await fetch('api/delete_salary_broadcast.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        month: month,
                        year: year
                    })
                });

                const result = await response.json();
                document.getElementById('loadingOverlay').style.display = 'none';

                if (result.success) {
                    alert(result.message || "Broadcast successfully reverted.");
                    window.location.reload();
                } else {
                    alert("Error: " + result.message);
                }
            } catch (err) {
                document.getElementById('loadingOverlay').style.display = 'none';
                alert("An error occurred during revert.");
                console.error(err);
            }
        }

        function downloadTemplate() {

            /*
             * Official Salary Broadcast template.
             * Exact column structure of the new temp.xlsx.
             */
            const headers = [
                "B-ID",
                "Employees Name",
                "Sudo Names",
                "Designation",
                "Campaign",
                "CNIC#",
                "Contact No.",
                "Branch Code",
                "Account Nos",
                "Account Title",
                "Bank Name",
                "Appointment Date",
                "Basic Salary",
                "Punctuality",
                "Total Salary",
                "Salary Per Day",
                "Num of Days",
                "Present",
                "Leave",
                "Absent",
                "Total No of W.Days",
                "Punch Reward",
                "Bonus",
                "TA/DA",
                "Arrears",
                "Extra Day",
                "Extra Day pay",
                "Late Coming",
                "Late Coming Deduction",
                "HD",
                "HD Deduction",
                "SD",
                "SD Deduction",
                "NCNS",
                "NCNS Deduction",
                "Docs",
                "Missed Punchin",
                "Missed Punchin Deduction",
                "Transport Deduction",
                "Advance Salary",
                "Absent Deduction",
                "Total Addition",
                "Gross Salary",
                "Total Deduction Ept Tax",
                "SUB - Net Salary",
                "Tax",
                "Final Net Salary",
                "Remarks",
                "Comments",
                "",
                "",
                "",
                "Annual Salary",
                "Annual Tax"
            ];

            const worksheet = XLSX.utils.aoa_to_sheet([headers]);

            worksheet['!cols'] = headers.map(header => ({
                wch: Math.max(
                    14,
                    String(header || '').length + 3
                )
            }));

            const workbook = XLSX.utils.book_new();

            XLSX.utils.book_append_sheet(
                workbook,
                worksheet,
                "Salary Template"
            );

            XLSX.writeFile(
                workbook,
                "Salary_Slip_Import_Template.xlsx"
            );
        }
    </script>
</body>
</html>
