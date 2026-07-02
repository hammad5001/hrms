<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['portal_role'] ?? 'user';
if ($role !== 'super_admin' && $role !== 'qa') {
    die("Unauthorized Access: This portal is restricted to QA and Super Admins only.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QA Intelligence - Balitech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/portal-ui-polish.css?v=1">
    <link rel="stylesheet" href="css/advanced-analytics.css?v=1">
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f111a; color: #f8fafc; min-height: 100vh; margin: 0; padding: 40px 20px; position: relative; }
        .bg-mesh { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: radial-gradient(circle at 20% 30%, rgba(99,102,241,0.15) 0%, transparent 50%), radial-gradient(circle at 80% 70%, rgba(16,185,129,0.1) 0%, transparent 50%); z-index: -1; }
        .qa-container { max-width: 900px; margin: 0 auto; }
        .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header-bar h2 { font-size: 28px; font-weight: 800; background: linear-gradient(135deg, #818cf8, #c084fc); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin: 0; }
        .btn-back { color: var(--adv-text-muted); text-decoration: none; font-weight: 500; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .btn-back:hover { color: white; }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>
    
    <div class="qa-container">
        <div class="header-bar">
            <h2><i class="fas fa-shield-check" style="color: #6366f1;"></i> QA Bulk Intelligence</h2>
            <a href="admin-dashboard.html" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Admin</a>
        </div>

        <div class="adv-glass-card" style="margin-bottom: 30px;">
            <div style="display: flex; gap: 20px; align-items: flex-start;">
                <div style="background: rgba(99,102,241,0.1); padding: 16px; border-radius: 12px; color: #818cf8; font-size: 24px;">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div>
                    <h3 style="margin: 0 0 8px 0; font-size: 18px;">How it works</h3>
                    <p style="color: var(--adv-text-muted); font-size: 14px; margin: 0 0 12px 0; line-height: 1.5;">Drop your QA verification sheet below. The system will parse it locally, let you preview the data, and then securely map it to agents using their Biometric ID (DID).</p>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <span class="adv-badge" style="background: rgba(255,255,255,0.05); color: #cbd5e1; border: 1px solid rgba(255,255,255,0.1);">Biometric ID</span>
                        <span class="adv-badge" style="background: rgba(255,255,255,0.05); color: #cbd5e1; border: 1px solid rgba(255,255,255,0.1);">Date (YYYY-MM-DD)</span>
                        <span class="adv-badge" style="background: rgba(16,185,129,0.1); color: #34d399; border: 1px solid rgba(16,185,129,0.2);">Sales</span>
                        <span class="adv-badge" style="background: rgba(239,68,68,0.1); color: #f87171; border: 1px solid rgba(239,68,68,0.2);">Rejected</span>
                        <span class="adv-badge" style="background: rgba(99,102,241,0.1); color: #818cf8; border: 1px solid rgba(99,102,241,0.2);">Transfers</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="adv-drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
            <div class="adv-drop-icon"><i class="fas fa-file-csv"></i></div>
            <div class="adv-drop-title">Drop your CSV or XLSX here</div>
            <div class="adv-drop-subtitle">Or click to browse files</div>
            <input type="file" id="fileInput" accept=".csv, .xlsx, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.ms-excel" style="display:none;">
        </div>

        <!-- Preview Container -->
        <div id="previewContainer" class="adv-preview-container" style="display: none;">
            <h3 style="margin: 0 0 15px 0; font-size: 16px; color: #818cf8; display: flex; justify-content: space-between; align-items: center;">
                <span><i class="fas fa-table" style="margin-right: 8px;"></i> Data Preview</span>
                <span id="rowCount" style="font-size: 12px; color: var(--adv-text-muted); background: rgba(0,0,0,0.5); padding: 4px 10px; border-radius: 10px;"></span>
            </h3>
            <table class="adv-table" id="previewTable">
                <thead></thead>
                <tbody></tbody>
            </table>
        </div>

        <button class="adv-btn-primary" id="uploadBtn" disabled onclick="uploadData()" style="opacity: 0.5;">
            <i class="fas fa-cloud-upload-alt"></i> Upload & Process Intelligence
        </button>
    </div>

    <!-- Custom Toast -->
    <div id="advToast" class="adv-toast">
        <i id="toastIcon" class="fas fa-check-circle"></i>
        <div style="display:flex; flex-direction:column; gap:4px;">
            <strong id="toastTitle" style="font-size: 15px;">Success</strong>
            <span id="toastMsg" style="font-size: 13px; color: var(--adv-text-muted);">Operation completed</span>
        </div>
    </div>

    <!-- SheetJS -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const uploadBtn = document.getElementById('uploadBtn');
        const previewContainer = document.getElementById('previewContainer');
        const previewTableHead = document.querySelector('#previewTable thead');
        const previewTableBody = document.querySelector('#previewTable tbody');
        const rowCountEl = document.getElementById('rowCount');
        
        let parsedData = null;
        let filename = '';

        // Drag & Drop Handlers
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
        });

        dropZone.addEventListener('drop', (e) => {
            let dt = e.dataTransfer;
            let files = dt.files;
            if (files.length > 0) handleFile(files[0]);
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) handleFile(e.target.files[0]);
        });

        async function handleFile(file) {
            const ext = file.name.split('.').pop().toLowerCase();
            if (!['csv', 'xlsx'].includes(ext)) {
                showToast('Error', 'Invalid file format. Please upload CSV or XLSX.', 'error');
                return;
            }
            
            filename = file.name;
            dropZone.querySelector('.adv-drop-title').innerHTML = `<i class="fas fa-file-excel" style="color:#10b981; margin-right:8px;"></i> ${file.name}`;
            dropZone.querySelector('.adv-drop-subtitle').textContent = `Parsing file...`;

            try {
                const buffer = await file.arrayBuffer();
                const workbook = XLSX.read(buffer, {type: 'array'});
                const firstSheet = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheet];
                const json = XLSX.utils.sheet_to_json(worksheet, {raw: false});

                if (json.length === 0) {
                    showToast('Error', 'The file is empty.', 'error');
                    resetState();
                    return;
                }

                parsedData = json;
                dropZone.querySelector('.adv-drop-subtitle').textContent = `${(file.size / 1024).toFixed(1)} KB • ${json.length} rows detected`;
                
                renderPreview(json);
                uploadBtn.disabled = false;
                uploadBtn.style.opacity = '1';
                showToast('Success', 'File parsed locally. Please review the data.', 'success');
            } catch (err) {
                console.error(err);
                showToast('Error', 'Failed to parse file.', 'error');
                resetState();
            }
        }

        function renderPreview(data) {
            previewContainer.style.display = 'block';
            rowCountEl.textContent = `${data.length} Rows`;
            
            // Get headers
            const headers = Object.keys(data[0]);
            previewTableHead.innerHTML = `<tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr>`;

            // Render max 100 rows for preview
            const previewRows = data.slice(0, 100);
            previewTableBody.innerHTML = previewRows.map(row => {
                return `<tr>${headers.map(h => `<td>${row[h] || '-'}</td>`).join('')}</tr>`;
            }).join('');
            
            if (data.length > 100) {
                previewTableBody.innerHTML += `<tr><td colspan="${headers.length}" style="text-align:center; color: var(--adv-text-muted); font-style: italic;">...and ${data.length - 100} more rows</td></tr>`;
            }
        }

        async function uploadData() {
            if (!parsedData) return;

            uploadBtn.disabled = true;
            uploadBtn.style.opacity = '0.5';
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Intelligence...';

            try {
                const response = await fetch('api/upload_qa_sheet.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filename: filename, data: parsedData })
                });

                const result = await response.json();
                
                if (result.success) {
                    showToast('Data Synced', `Successfully processed ${result.processed_rows} QA records!`, 'success');
                    setTimeout(() => {
                        resetState();
                    }, 2000);
                } else {
                    showToast('Sync Failed', result.message || 'Error syncing data.', 'error');
                    uploadBtn.disabled = false;
                    uploadBtn.style.opacity = '1';
                    uploadBtn.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Upload & Process Intelligence';
                }
            } catch (err) {
                console.error(err);
                showToast('Network Error', 'Failed to communicate with server.', 'error');
                uploadBtn.disabled = false;
                uploadBtn.style.opacity = '1';
                uploadBtn.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Upload & Process Intelligence';
            }
        }

        function resetState() {
            parsedData = null;
            filename = '';
            fileInput.value = '';
            uploadBtn.disabled = true;
            uploadBtn.style.opacity = '0.5';
            uploadBtn.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Upload & Process Intelligence';
            previewContainer.style.display = 'none';
            dropZone.querySelector('.adv-drop-title').innerHTML = `Drop your CSV or XLSX here`;
            dropZone.querySelector('.adv-drop-subtitle').textContent = `Or click to browse files`;
        }

        function showToast(title, msg, type) {
            const toast = document.getElementById('advToast');
            const toastIcon = document.getElementById('toastIcon');
            
            document.getElementById('toastTitle').textContent = title;
            document.getElementById('toastMsg').textContent = msg;
            
            toast.className = `adv-toast ${type} show`;
            toastIcon.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 5000);
        }
    </script>
</body>
</html>
