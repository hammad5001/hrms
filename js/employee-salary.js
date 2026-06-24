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

    let tableHtml = `<table style="width:100%; border-collapse:collapse; margin-bottom:20px;">`;
    for (const [key, val] of Object.entries(slip.slip_data)) {
        if (val && !key.toLowerCase().includes("name") && !key.toLowerCase().includes("biometric")) {
            tableHtml += `<tr>
                <th style="text-align:left; padding:10px; border-bottom:1px solid #eee; color:#64748b;">${key}</th>
                <td style="text-align:right; padding:10px; border-bottom:1px solid #eee; font-weight:600;">${val}</td>
            </tr>`;
        }
    }
    tableHtml += `</table>`;

    body.innerHTML = `
        <h2 style="margin-bottom:5px; color:#f97316;">Salary Slip</h2>
        <p style="color:#64748b; margin-bottom:20px;">For the month of ${slip.month} ${slip.year}</p>
        
        <div style="background:#f8fafc; padding:20px; border-radius:12px; margin-bottom:20px; text-align:center;">
            <div style="font-size:14px; color:#64748b; text-transform:uppercase;">Net Payable</div>
            <div style="font-size:32px; color:#10b981; font-weight:bold;">${slip.net_salary}</div>
        </div>

        <h3 style="margin-bottom:10px; font-size:16px;">Earnings & Deductions</h3>
        ${tableHtml}
        
        <div style="text-align:center; margin-top:20px;">
            <button class="prod-btn prod-btn-outline" style="width:auto; padding:10px 20px;" onclick="window.print()">
                <i class="fas fa-print"></i> Print Slip
            </button>
        </div>
    `;

    modal.style.display = 'flex';
}
