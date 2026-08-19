document.addEventListener('DOMContentLoaded', () => {
    // Listen for tab changes
    const salaryNavButtons = document.querySelectorAll('[data-view="payslips"]');
    salaryNavButtons.forEach(btn => {
        btn.addEventListener('click', loadMyPayslips);
    });

    function loadMyPayslips() {
        fetch('api/get_my_payslips.php')
            .then(res => res.json())
            .then(data => {
                const tbody = document.getElementById('payslipBody');
                if (!tbody) return;
                
                if (!data.success || !data.data || data.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--text-muted);">No payslips available at the moment.</td></tr>';
                    return;
                }

                tbody.innerHTML = '';
                data.data.forEach(slip => {
                    let totalDeduct = '0';
                    if (slip.slip_data) {
                        for (const [key, val] of Object.entries(slip.slip_data)) {
                            const kLower = key.trim().toLowerCase();
                            if (kLower === "total deduction" || kLower === "total deductions" || kLower.includes("total deduction")) {
                                totalDeduct = val;
                                break;
                            }
                        }
                    }

                    const grossNum = parseFloat(String(slip.gross_salary).replace(/,/g, '')) || 0;
                    const netNum = parseFloat(String(slip.net_salary).replace(/,/g, '')) || 0;
                    const deductNum = parseFloat(String(totalDeduct).replace(/,/g, '')) || 0;

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><strong>${slip.month} ${slip.year}</strong></td>
                        <td>Rs. ${grossNum.toLocaleString('en-US')}</td>
                        <td style="color:#ef4444; font-weight:bold;">Rs. ${deductNum.toLocaleString('en-US')}</td>
                        <td style="color:var(--prod-emerald); font-weight:bold;">Rs. ${netNum.toLocaleString('en-US')}</td>
                        <td><span style="background:var(--prod-emerald-light); color:var(--prod-emerald); padding:4px 8px; border-radius:4px; font-size:12px;">Issued</span></td>
                        <td>
                            <button class="prod-btn prod-btn-primary" style="padding:6px 12px; font-size:12px; width:auto;" onclick='viewPayslipDetails(${JSON.stringify(slip).replace(/'/g, "&#39;")})'>
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            })
            .catch(err => {
                console.error("Error loading payslips:", err);
            });
    }
});

function viewPayslipDetails(slip) {
    const modal = document.getElementById('payslipModal');
    const body = document.getElementById('payslipModalBody');

    if (!modal || !body) return;

    const data = slip.slip_data || {};

    const esc = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const compatibilityKeys = new Set([
        'biometric id',
        'employee name',
        'total deductions',
        'net payable'
    ]);

    const entries = Object.entries(data).filter(([key]) => {
        const clean = String(key ?? '').trim();
        const lower = clean.toLowerCase();

        if (!clean) return false;
        if (/^_\d+$/.test(clean)) return false;
        if (compatibilityKeys.has(lower)) return false;

        return true;
    });

    const exact = (...names) => {
        for (const name of names) {
            for (const [k, v] of entries) {
                if (
                    String(k).trim().toLowerCase() ===
                    String(name).trim().toLowerCase()
                ) {
                    return v;
                }
            }
        }
        return '';
    };

    const byKeys = names => {
        const normalized = names.map(x => x.toLowerCase());

        return entries.filter(([key]) =>
            normalized.includes(String(key).trim().toLowerCase())
        );
    };

    const employeeFields = byKeys([
        'B-ID',
        'Employees Name',
        'Sudo Names',
        'Designation',
        'Campaign',
        'CNIC#',
        'Contact No.',
        'Branch Code',
        'Account Nos',
        'Account Title',
        'BANK',
        'Bank Name',
        'Appointment Date'
    ]);

    const attendanceFields = byKeys([
        'Basic Salary',
        'Punctuality',
        'Total Salary',
        'Salary Per Day',
        'Num of Days',
        'Present',
        'Leave',
        'Absent',
        'Total No of W.Days',
        'Late Coming',
        'HD',
        'SD',
        'NCNS',
        'Docs',
        'Missed Punchin'
    ]);

    const additionFields = byKeys([
        'Punch Reward',
        'Bonus',
        'TA/DA',
        'Arrears',
        'Extra Day',
        'Extra Day pay',
        'Total Addition'
    ]);

    const deductionFields = byKeys([
        'Late Coming Deduction',
        'HD Deduction',
        'SD Deduction',
        'NCNS Deduction',
        'Missed Punchin Deduction',
        'Transport Deduction',
        'Advance Salary',
        'Absent Deduction',
        'Total Deduction Ept Tax',
        'Tax'
    ]);

    const finalFields = byKeys([
        'Gross Salary',
        'SUB - Net Salary',
        'Final Net Salary',
        'Remarks',
        'Comments',
        'Annual Salary',
        'Annual Tax'
    ]);

    const renderRows = fields => fields.map(([key, value]) => {
        const display =
            value === undefined ||
            value === null ||
            String(value).trim() === ''
                ? '—'
                : value;

        return `
            <tr style="border-bottom:1px solid #e2e8f0;">
                <td style="
                    padding:9px 14px;
                    font-weight:600;
                    color:#334155;
                    width:48%;
                ">${esc(key)}</td>

                <td style="
                    padding:9px 14px;
                    text-align:right;
                    font-weight:700;
                    color:#0f172a;
                ">${esc(display)}</td>
            </tr>
        `;
    }).join('');

    const section = (title, fields) => {
        if (!fields.length) return '';

        return `
            <div style="
                margin-bottom:24px;
                position:relative;
                z-index:2;
            ">
                <h3 style="
                    margin:0 0 10px;
                    font-size:15px;
                    font-weight:800;
                    color:#0f172a;
                    text-transform:uppercase;
                    letter-spacing:.4px;
                ">${esc(title)}</h3>

                <table style="
                    width:100%;
                    border-collapse:collapse;
                    border:1px solid #e2e8f0;
                    background:white;
                    font-size:13px;
                ">
                    <tbody>
                        ${renderRows(fields)}
                    </tbody>
                </table>
            </div>
        `;
    };

    const empName =
        exact('Employees Name', 'Employee Name') ||
        'Employee';

    const empId =
        exact('B-ID', 'B ID', 'Biometric ID', 'Emp ID') ||
        '-';

    const campaign =
        exact('Campaign', 'Department / Campaign', 'Department') ||
        '—';

    const totalDeduction =
        exact(
            'Total Deduction Ept Tax',
            'Total Deduction Except Tax',
            'Total Deductions'
        ) || '0';

    const finalNet =
        exact('Final Net Salary') ||
        slip.net_salary ||
        '0';

    body.innerHTML = `
        <div id="payslipPrintableArea"
             style="
                position:relative;
                overflow:hidden;
                padding:40px;
                background:#fff;
                color:#0f172a;
                font-family:'Plus Jakarta Sans',sans-serif;
             ">

            <div style="
                position:absolute;
                top:50%;
                left:50%;
                transform:translate(-50%,-50%);
                opacity:.03;
                pointer-events:none;
                z-index:1;
            ">
                <img src="assets/images/balitech-logo.png"
                     style="width:450px;filter:grayscale(100%);">
            </div>

            <div style="
                background:#0f172a;
                color:white;
                padding:25px 30px;
                border-radius:16px 16px 0 0;
                display:flex;
                justify-content:space-between;
                align-items:center;
                position:relative;
                z-index:2;
            ">
                <div>
                    <h2 style="
                        margin:0;
                        font-size:26px;
                        font-weight:800;
                        color:white;
                    ">BALITECH</h2>

                    <div style="
                        margin-top:4px;
                        color:#94a3b8;
                        font-size:14px;
                    ">Monthly Salary Slip</div>
                </div>

                <div style="
                    background:white;
                    color:#0f172a;
                    padding:8px 16px;
                    border-radius:30px;
                    font-size:13px;
                    font-weight:800;
                ">
                    ${esc(String(slip.month || '').toUpperCase())},
                    ${esc(slip.year)}
                </div>
            </div>

            <div style="
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:20px;
                background:#f8fafc;
                padding:20px;
                border:1px solid #e2e8f0;
                border-top:none;
                border-radius:0 0 16px 16px;
                margin-bottom:28px;
                position:relative;
                z-index:2;
            ">
                <div>
                    <div style="
                        font-size:11px;
                        color:#64748b;
                        text-transform:uppercase;
                        font-weight:700;
                    ">Employee</div>

                    <div style="
                        margin-top:5px;
                        font-size:18px;
                        font-weight:800;
                    ">
                        ${esc(empName)} (${esc(empId)})
                    </div>
                </div>

                <div style="text-align:right;">
                    <div style="
                        font-size:11px;
                        color:#64748b;
                        text-transform:uppercase;
                        font-weight:700;
                    ">Campaign</div>

                    <div style="
                        margin-top:5px;
                        font-size:18px;
                        font-weight:800;
                    ">${esc(campaign)}</div>
                </div>
            </div>

            ${section('Employee Information', employeeFields)}

            ${section('Salary & Attendance', attendanceFields)}

            ${section('Allowances / Additions', additionFields)}

            ${section('Deductions', deductionFields)}

            ${section('Final Payroll Summary', finalFields)}

            <div style="
                background:#f8fafc;
                border:1px solid #e2e8f0;
                border-radius:12px;
                padding:16px 22px;
                display:flex;
                justify-content:space-between;
                margin-bottom:10px;
                position:relative;
                z-index:2;
            ">
                <span style="
                    font-size:16px;
                    font-weight:800;
                    color:#334155;
                ">Total Deduction</span>

                <span style="
                    font-size:20px;
                    font-weight:900;
                    color:#ef4444;
                ">${esc(totalDeduction)}</span>
            </div>

            <div style="
                background:#f0fdf4;
                border:1px solid #bbf7d0;
                border-radius:12px;
                padding:18px 22px;
                display:flex;
                justify-content:space-between;
                position:relative;
                z-index:2;
            ">
                <span style="
                    font-size:18px;
                    font-weight:800;
                    color:#166534;
                ">Final Net Salary</span>

                <span style="
                    font-size:24px;
                    font-weight:900;
                    color:#15803d;
                ">${esc(finalNet)}</span>
            </div>
        </div>

        <div style="
            text-align:center;
            margin-top:25px;
            display:flex;
            justify-content:center;
            gap:15px;
            padding-bottom:20px;
        ">
            <button onclick="window.print()"
                    style="
                        background:#fff;
                        color:#0f172a;
                        border:1px solid #cbd5e1;
                        padding:12px 25px;
                        border-radius:8px;
                        cursor:pointer;
                    ">
                <i class="fas fa-print"></i> Print
            </button>

            <button onclick="downloadSlipPDF()"
                    style="
                        background:#0f172a;
                        color:white;
                        border:none;
                        padding:12px 25px;
                        border-radius:8px;
                        cursor:pointer;
                    ">
                <i class="fas fa-download"></i> Download PDF
            </button>
        </div>
    `;

    modal.style.display = 'flex';
}

function downloadSlipPDF() {
    const element = document.getElementById('payslipPrintableArea');
    if (!element) return;
    
    if (typeof html2pdf === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
        script.onload = () => executePDFDownload(element);
        document.head.appendChild(script);
    } else {
        executePDFDownload(element);
    }
}

function executePDFDownload(element) {
    const opt = {
        margin:       10,
        filename:     'Salary_Slip.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    
    html2pdf().set(opt).from(element).save();
}
