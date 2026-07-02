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
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);">No payslips available at the moment.</td></tr>';
                    return;
                }

                tbody.innerHTML = '';
                data.data.forEach(slip => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><strong>${slip.month} ${slip.year}</strong></td>
                        <td>${slip.gross_salary}</td>
                        <td style="color:var(--prod-orange); font-weight:bold;">${slip.net_salary}</td>
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

    let tableHtml = `<table style="width:100%; border-collapse:collapse; margin-bottom:20px; font-size: 14px; position:relative; z-index:2;">
        <thead>
            <tr style="background: rgba(249, 115, 22, 0.15); color: #f97316;">
                <th style="text-align:left; padding:12px; border-radius: 8px 0 0 8px; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px;">Description</th>
                <th style="text-align:right; padding:12px; border-radius: 0 8px 8px 0; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px;">Amount</th>
            </tr>
        </thead>
        <tbody>`;
    
    for (const [key, val] of Object.entries(slip.slip_data)) {
        const kLower = key.toLowerCase();
        if (val && !kLower.includes("name") && !kLower.includes("biometric") && !kLower.includes("b id") && !kLower.includes("sr id") && !kLower.includes("emp id") && !kLower.includes("sr. no") && kLower !== "net salary" && kLower !== "net payable") {
            tableHtml += `<tr style="border-bottom: 1px dashed #334155;">
                <td style="text-align:left; padding:12px; color:#cbd5e1; font-weight:500;">${key}</td>
                <td style="text-align:right; padding:12px; font-weight:700; color:#f8fafc;">${val}</td>
            </tr>`;
        }
    }
    tableHtml += `</tbody></table>`;

    const empName = slip.slip_data['Employee Name'] || slip.slip_data['Name'] || slip.slip_data['Sudo Name'] || 'Valued Employee';
    const empId = slip.slip_data['B ID'] || slip.slip_data['Biometric ID'] || slip.slip_data['Emp ID'] || slip.slip_data['Sr. No'] || '-';

    body.innerHTML = `
        <div id="payslipPrintableArea" style="position: relative; overflow: hidden; padding: 30px; background: #0f172a; border-radius: 12px; color: #f8fafc; font-family: 'Inter', sans-serif; border: 1px solid #1e293b; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
            
            <!-- Watermark -->
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); opacity: 0.05; pointer-events: none; z-index: 1;">
                <img src="assets/images/balitech-logo.png" style="width: 500px; filter: grayscale(100%);">
            </div>

            <!-- Header -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1e293b; padding-bottom: 20px; margin-bottom: 20px; position: relative; z-index: 2;">
                <div>
                    <img src="assets/images/balitech-logo.png" alt="Balitech" style="height: 45px; margin-bottom: 12px; filter: brightness(0) invert(1);">
                    <h2 style="margin: 0; color: #f8fafc; font-size: 26px; font-weight: 800; letter-spacing: -0.5px; text-transform: uppercase;">Salary Slip</h2>
                    <p style="margin: 5px 0 0 0; color: #94a3b8; font-size: 14px;">For the month of <strong style="color: #f97316;">${slip.month} ${slip.year}</strong></p>
                </div>
                <div style="text-align: right;">
                    <h3 style="margin: 0 0 5px 0; color: #f8fafc; font-size: 16px; font-weight: 700;">Balitech (Pvt) Ltd.</h3>
                    <p style="margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.6;">
                        Salary & Payroll Department
                    </p>
                </div>
            </div>
            
            <!-- Employee Quick Info -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; background: #1e293b; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; position: relative; z-index: 2; border: 1px solid #334155;">
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 3px;">Employee Name</span>
                    <span style="font-size: 16px; font-weight: 600; color: #f8fafc;">${empName}</span>
                </div>
                <div style="display: flex; flex-direction: column; text-align: right;">
                    <span style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 3px;">Employee ID</span>
                    <span style="font-size: 16px; font-weight: 600; color: #f8fafc;">${empId}</span>
                </div>
            </div>
            
            <!-- Details Table -->
            <div style="position: relative; z-index: 2; min-height: 250px;">
                ${tableHtml}
            </div>

            <!-- Net Payable Highlight -->
            <div style="display: flex; justify-content: flex-end; margin-top: 10px; position: relative; z-index: 2;">
                <div style="background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); padding: 20px 35px; border-radius: 12px; text-align: right; color: white; min-width: 250px; box-shadow: 0 10px 20px -5px rgba(249, 115, 22, 0.4);">
                    <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; margin-bottom: 5px; font-weight: 600;">Net Payable</div>
                    <div style="font-size: 34px; font-weight: 800; line-height: 1;">Rs. ${slip.net_salary}</div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="text-align: center; margin-top: 25px; display: flex; justify-content: center; gap: 15px;">
            <button onclick="window.print()" style="background: #1e293b; color: white; border: 1px solid #334155; padding: 12px 25px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px;" onmouseover="this.style.background='#334155'" onmouseout="this.style.background='#1e293b'">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="downloadSlipPDF()" style="background: #f97316; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3); display: inline-flex; align-items: center; gap: 8px;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class="fas fa-download"></i> Download PDF
            </button>
        </div>
    `;

    modal.style.display = 'flex';
}

function downloadSlipPDF() {
    const element = document.getElementById('payslipPrintableArea');
    if (!element) return;
    
    // Check if html2pdf is loaded, if not load it dynamically
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
        html2canvas:  { scale: 2, useCORS: true, backgroundColor: '#0f172a' },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    
    html2pdf().set(opt).from(element).save();
}
