document.addEventListener('DOMContentLoaded', () => {
    // Listen for tab changes
    const salaryNavButtons = document.querySelectorAll('[data-view="salary"]');
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

    const findVal = (parts, notParts = []) => {
        for (const [k, v] of Object.entries(slip.slip_data)) {
            const kLower = k.toLowerCase().trim();
            const matchesAll = parts.every(p => kLower.includes(p));
            const matchesNone = notParts.every(p => !kLower.includes(p));
            if (matchesAll && matchesNone) {
                return v;
            }
        }
        return '';
    };

    function formatVal(key, val, isCurrency = true) {
        const strVal = val === null || val === undefined || String(val).trim() === '' ? '0' : String(val).trim();
        if (strVal.includes('Rs') || strVal.includes('Rs.')) return strVal;
        
        const cleanNum = strVal.replace(/,/g, '');
        if (!isNaN(cleanNum) && cleanNum !== '') {
            const num = parseFloat(cleanNum);
            if (!isCurrency) {
                return num.toString();
            }
            return 'Rs. ' + num.toLocaleString('en-US');
        }
        return isCurrency ? 'Rs. ' + strVal : strVal;
    }

    const summaryDef = [
        { label: 'Basic Salary', parts: ['basic'], notParts: [] },
        { label: 'Punctuality Amount', parts: ['punctuality'], notParts: ['deduct', 'fine', 'penalty'] },
        { label: 'Present Days', parts: ['present'], notParts: [], isCurrency: false },
        { label: 'Absent Days', parts: ['absent'], notParts: ['deduct', 'fine', 'penalty'], isCurrency: false },
        { label: 'Paid Leave', parts: ['leave'], notParts: ['deduct', 'fine', 'policy'], isCurrency: false },
        { label: 'Half Day', parts: ['half day'], notParts: ['amount', 'deduct', 'dock'], isCurrency: false },
        { label: 'No. of NCNS', parts: ['ncns'], notParts: ['amount', 'deduct', 'ammount', 'dock'], isCurrency: false },
        { label: 'No. of Sandwich Docks', parts: ['sandwich'], notParts: ['amount', 'ammount', 'deduct', 'dock'], isCurrency: false },
        { label: 'No. of Late Arrivals', parts: ['late coming'], notParts: ['deduct', 'deduction', 'dock'], isCurrency: false },
        { label: 'No. of Misthumb Impressions', parts: ['misthumb'], notParts: ['amount', 'deduct', 'dock'], isCurrency: false },
        { label: 'Traveling Allowance - Females Only', parts: ['traveling'], notParts: [] },
        { label: 'Arrears', parts: ['arrears'], notParts: [] },
        { label: 'Extra Day Pay', parts: ['extra day'], notParts: [] },
        { label: 'Gross Salary', parts: ['gross'], notParts: [], isBold: true }
    ];

    const deductionsDef = [
        { label: 'QA Deduction Amount', parts: ['qa'], notParts: [] },
        { label: 'Punctuality Deduction Amount', parts: ['punctuality', 'deduct'], notParts: [] },
        { label: 'Absenteeism Deduction Amount', parts: ['absent', 'deduct'], notParts: [] },
        { label: 'NCNS Deduction Amount', parts: ['ncns'], notParts: [], fallbackParts: ['ncns', 'ammount'] },
        { label: 'Late Coming Dock Amount', parts: ['late coming', 'deduct'], notParts: [], fallbackParts: ['late coming', 'deduction'] },
        { label: 'HD Deduction Amount', parts: ['hd', 'amount'], notParts: ['total'], fallbackParts: ['hd', 'deduct'] },
        { label: 'Sandwich Dock Amount', parts: ['sd'], notParts: [], fallbackParts: ['sd', 'ammount'] },
        { label: 'Mispunch / Thumbprint Dock Amount', parts: ['missed', 'punch'], notParts: [], fallbackParts: ['mispunch'] },
        { label: 'Advance Salary Deduction', parts: ['advance'], notParts: [] },
        { label: 'Tax Deduction Amount', parts: ['tax'], notParts: [] }
    ];

    let summaryRowsHtml = '';
    for (const item of summaryDef) {
        let rawVal = findVal(item.parts, item.notParts);
        if (rawVal === '') {
            if (item.label === 'Punctuality Amount') rawVal = findVal(['p.reward']);
            if (item.label === 'Present Days') rawVal = findVal(['w.days']) || findVal(['work']);
            if (item.label === 'Traveling Allowance - Females Only') rawVal = findVal(['transport']) || findVal(['ta/da']);
        }
        
        // Hide Traveling Allowance if it is Rs. 0 or empty
        if (item.label === 'Traveling Allowance - Females Only') {
            const valNum = parseFloat(String(rawVal).replace(/[^0-9.]/g, '')) || 0;
            if (valNum === 0) {
                continue;
            }
        }
        
        const isBold = item.isBold;
        const style = isBold ? 'font-weight: 700; color: #0f172a; background: #f8fafc; border-top: 2px solid #cbd5e1;' : 'color: #334155;';
        summaryRowsHtml += `<tr style="border-bottom: 1px solid #e2e8f0; ${style}">
            <td style="text-align:left; padding:10px 15px; font-weight:500;">${item.label}</td>
            <td style="text-align:right; padding:10px 15px; font-weight:700;">${formatVal(item.label, rawVal, item.isCurrency !== false)}</td>
        </tr>`;
    }

    let deductionRowsHtml = '';
    for (const item of deductionsDef) {
        let rawVal = findVal(item.parts, item.notParts);
        if (rawVal === '' && item.fallbackParts) {
            rawVal = findVal(item.fallbackParts);
        }
        deductionRowsHtml += `<tr style="border-bottom: 1px solid #e2e8f0; color: #334155;">
            <td style="text-align:left; padding:10px 15px; font-weight:500;">${item.label}</td>
            <td style="text-align:right; padding:10px 15px; font-weight:700;">${formatVal(item.label, rawVal, true)}</td>
        </tr>`;
    }

    const totalDeductionsVal = findVal(['total deduction']) || findVal(['total deductions']) || '0';
    const netPayableVal = findVal(['net payable']) || findVal(['net salary']) || slip.net_salary || '0';

    let empName = '';
    for (const [k, v] of Object.entries(slip.slip_data)) {
        const kLower = k.toLowerCase().trim();
        if (kLower.includes('name') && !kLower.includes('bank') && !kLower.includes('campaign') && !kLower.includes('department') && !kLower.includes('title') && v) {
            empName = String(v).trim();
            if (empName) break;
        }
    }

    if (!empName || empName.toLowerCase() === 'valued employee' || empName === '-') {
        const profileNameEl = document.getElementById('profileCardName') || document.getElementById('chipName');
        if (profileNameEl && profileNameEl.innerText && profileNameEl.innerText.trim() !== '-') {
            empName = profileNameEl.innerText.trim();
        } else {
            empName = 'Waqar Ahmed';
        }
    }

    const empId = slip.slip_data['B ID'] || slip.slip_data['Biometric ID'] || slip.slip_data['Emp ID'] || slip.slip_data['Sr. No'] || '-';
    const campaign = slip.slip_data['Department / Campaign'] || slip.slip_data['Campaign'] || slip.slip_data['Department'] || 'B2B';

    body.innerHTML = `
        <div id="payslipPrintableArea" style="position: relative; overflow: hidden; padding: 40px; background: #ffffff; color: #0f172a; font-family: 'Plus Jakarta Sans', sans-serif;">
            
            <!-- Watermark -->
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); opacity: 0.03; pointer-events: none; z-index: 1;">
                <img src="assets/images/balitech-logo.png" style="width: 450px; filter: grayscale(100%);">
            </div>

            <!-- Header -->
            <div style="background: #0f172a; color: white; padding: 25px 30px; border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center; position: relative; z-index: 2;">
                <div>
                    <h2 style="margin: 0; font-size: 26px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase;">BALITECH</h2>
                    <p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 14px; font-weight: 500;">Monthly Salary Slip</p>
                </div>
                <div style="background: white; color: #0f172a; padding: 8px 16px; border-radius: 30px; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                    MONTH: ${slip.month.toUpperCase()}, ${slip.year}
                </div>
            </div>

            <!-- Employee Quick Info -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: #f8fafc; padding: 20px; border-radius: 0 0 16px 16px; border: 1px solid #e2e8f0; border-top: none; margin-bottom: 30px; position: relative; z-index: 2;">
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 5px;">Employee Name</span>
                    <span style="font-size: 18px; font-weight: 700; color: #0f172a;">${empName}</span>
                </div>
                <div style="display: flex; flex-direction: column; text-align: right;">
                    <span style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 5px;">Department / Campaign</span>
                    <span style="font-size: 18px; font-weight: 700; color: #0f172a;">${campaign}</span>
                </div>
            </div>
            
            <!-- Salary & Attendance Summary -->
            <div style="margin-bottom: 30px; position: relative; z-index: 2;">
                <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Salary & Attendance Summary</h3>
                <table style="width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; font-size: 13px;">
                    <tbody>
                        ${summaryRowsHtml}
                    </tbody>
                </table>
            </div>

            <!-- Specified Deductions -->
            <div style="margin-bottom: 30px; position: relative; z-index: 2;">
                <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Specified Deductions</h3>
                <table style="width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; font-size: 13px;">
                    <tbody>
                        ${deductionRowsHtml}
                    </tbody>
                </table>
            </div>

            <!-- Highlight Boxes -->
            <div style="background: #f8fafc; border-radius: 12px; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border: 1px solid #e2e8f0; position: relative; z-index: 2;">
                <span style="font-size: 16px; font-weight: 700; color: #334155;">Total Deduction</span>
                <span style="font-size: 20px; font-weight: 800; color: #0f172a;">${formatVal('Total Deduction', totalDeductionsVal)}</span>
            </div>

            <div style="background: #f0fdf4; border-radius: 12px; padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #bbf7d0; position: relative; z-index: 2; margin-bottom: 25px;">
                <span style="font-size: 18px; font-weight: 700; color: #166534;">Net Payable Salary</span>
                <span style="font-size: 24px; font-weight: 800; color: #15803d;">${formatVal('Net Payable Salary', netPayableVal)}</span>
            </div>

            <!-- Note Footer -->
            <div style="font-size: 11px; color: #64748b; line-height: 1.6; text-align: justify; border-top: 1px solid #e2e8f0; padding-top: 15px; position: relative; z-index: 2;">
                <strong>Note:</strong> Traveling allowance and transport deduction apply only for females where approved/applicable. NCNS, sandwich dock, late arrival, misthumb, half day, and paid leave counts are shown in the summary; deduction amounts are listed separately below as per policy.
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="text-align: center; margin-top: 25px; display: flex; justify-content: center; gap: 15px; padding-bottom: 20px;">
            <button onclick="window.print()" style="background: #ffffff; color: #0f172a; border: 1px solid #cbd5e1; padding: 12px 25px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#ffffff'">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="downloadSlipPDF()" style="background: #0f172a; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25); display: inline-flex; align-items: center; gap: 8px;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
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
