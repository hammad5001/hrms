/**
 * Employee Performance - Daily Call Transfer Reporting & QA Analytics
 */

// Helper to make calls to performance APIs
async function drpApi(action, payload = null) {
    const opts = { credentials: 'include' };
    let url = `api/get_agent_performance.php?action=${encodeURIComponent(action)}`;
    
    if (payload) {
        opts.method = 'POST';
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body = JSON.stringify(payload);
    }
    
    try {
        const res = await fetch(url, opts);
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON response:', text.substring(0, 200));
            return { success: false, message: 'Server returned invalid response.' };
        }
    } catch (err) {
        console.error('Network error in drpApi:', err);
        return { success: false, message: 'Network error or server unreachable.' };
    }
}

// 1. Daily Report Form & Today's Logs Console
window.loadPerformanceView = async function() {
    const res = await drpApi('load_dashboard');
    if (res.success) {
        const data = res.data;
        
        // Populate Secure Biometric ID (DID)
        const didEl = document.getElementById('drpBiometricId');
        if (didEl) {
            didEl.textContent = data.biometric_id || 'Not Assigned';
        }
        
        // Render Today's Logged Transfers Table
        const tbody = document.getElementById('todayTransfersTableBody');
        if (tbody) {
            tbody.innerHTML = '';
            const list = data.today_transfers || [];
            
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 20px; color: var(--text-muted);">No transfers logged today yet.</td></tr>';
                return;
            }
            
            list.forEach(r => {
                const tr = document.createElement('tr');
                const time = new Date(r.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                tr.innerHTML = `
                    <td><strong>${time}</strong></td>
                    <td><span style="font-family: monospace; color: #818cf8; font-weight: 600;">${r.customer_number || '-'}</span></td>
                    <td>${r.customer_name || '-'}</td>
                    <td><span class="ess-badge" style="background: rgba(245,158,11,0.1); color: #f59e0b; padding: 4px 8px; border-radius: 4px; font-weight: 600;">${r.transfer_on || '-'}</span></td>
                `;
                tbody.appendChild(tr);
            });
        }
    } else {
        console.error("Could not load dashboard data:", res.message);
    }
};

// Form submission handler
async function submitDailyTransfer(e) {
    e.preventDefault();
    
    const numEl = document.getElementById('drpCustomerNumber');
    const zipEl = document.getElementById('drpCustomerZip');
    const nameEl = document.getElementById('drpCustomerName');
    const ageEl = document.getElementById('drpCustomerAge');
    const transferEl = document.getElementById('drpTransferOn');
    const btn = e.target.querySelector('button[type="submit"]');
    
    if (!numEl || !transferEl) return;
    
    const payload = {
        customer_number: numEl.value.trim(),
        customer_zip: zipEl ? zipEl.value.trim() : '',
        customer_name: nameEl ? nameEl.value.trim() : '',
        customer_age: ageEl ? ageEl.value.trim() : '',
        transfer_on: transferEl.value
    };
    
    if (!payload.customer_number || !payload.transfer_on) {
        window.showToast?.('Customer Number and Transfer Option are required', 'error');
        return;
    }
    
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        btn.disabled = true;
    }
    
    try {
        const response = await fetch('api/submit_daily_report.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const res = await response.json();
        
        if (res.success) {
            window.showToast?.('Transfer logged successfully!', 'success');
            e.target.reset();
            loadPerformanceView();
        } else {
            window.showToast?.(res.message || 'Error submitting report', 'error');
        }
    } catch(err) {
        window.showToast?.('Network error. Check connection.', 'error');
    } finally {
        if (btn) {
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }
    }
}

// 2. My Performance Analytics Dashboard
let perfTrendChartInstance = null;
let perfRatioChartInstance = null;

window.loadPerformanceAnalyticsView = async function() {
    const res = await drpApi('load_analytics');
    if (res.success) {
        const data = res.data;
        
        // Populate Stats
        const sales = parseInt(data.qa_stats.sales) || 0;
        const rejected = parseInt(data.qa_stats.rejected) || 0;
        const transfers = parseInt(data.qa_stats.transfers) || 0;

        document.getElementById('qaStatsSales').textContent = sales;
        document.getElementById('qaStatsRejected').textContent = rejected;
        document.getElementById('qaStatsTransfers').textContent = transfers;

        const convEl = document.getElementById('qaStatsConversion');
        if (convEl) {
            convEl.textContent = transfers > 0 ? ((sales / transfers) * 100).toFixed(1) + '%' : '0%';
        }

        // Render charts
        renderPerformanceCharts(data.history || []);
    } else {
        window.showToast?.(res.message || 'Error loading analytics', 'error');
    }
};

function renderPerformanceCharts(history) {
    if (typeof Chart === 'undefined') {
        console.error('Chart.js is not loaded.');
        return;
    }

    const trendCtx = document.getElementById('perfTrendChart');
    const ratioCtx = document.getElementById('perfRatioChart');
    if (!trendCtx || !ratioCtx) return;

    if (perfTrendChartInstance) perfTrendChartInstance.destroy();
    if (perfRatioChartInstance) perfRatioChartInstance.destroy();

    // Prepare trend data (grouping by date)
    const labels = history.map(h => {
        const d = new Date(h.date);
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    });
    const transfersData = history.map(h => parseInt(h.transfers) || 0);
    const salesData = history.map(h => parseInt(h.sales) || 0);
    const convRateData = history.map(h => {
        const t = parseInt(h.transfers) || 0;
        const s = parseInt(h.sales) || 0;
        return t > 0 ? ((s / t) * 100).toFixed(1) : 0;
    });

    perfTrendChartInstance = new Chart(trendCtx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Transfers',
                    data: transfersData,
                    backgroundColor: 'rgba(99, 102, 241, 0.7)',
                    borderColor: '#6366f1',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y'
                },
                {
                    type: 'bar',
                    label: 'QA Sales',
                    data: salesData,
                    backgroundColor: 'rgba(16, 185, 129, 0.7)',
                    borderColor: '#10b981',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Conv. Rate (%)',
                    data: convRateData,
                    borderColor: '#f59e0b',
                    backgroundColor: 'transparent',
                    borderWidth: 3,
                    pointBackgroundColor: '#1e1e2d',
                    pointBorderColor: '#f59e0b',
                    tension: 0.4,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { 
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true, 
                    grid: { color: 'rgba(255,255,255,0.05)', drawBorder: false }, 
                    ticks: { color: '#94a3b8' } 
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: { drawOnChartArea: false },
                    ticks: { color: '#f59e0b', callback: function(value) { return value + '%'; } }
                },
                x: { 
                    grid: { display: false }, 
                    ticks: { color: '#94a3b8' } 
                }
            },
            plugins: {
                legend: { labels: { color: '#f8fafc', usePointStyle: true } }
            }
        }
    });

    const totalSales = salesData.reduce((a, b) => a + b, 0);
    const totalRejected = history.map(h => parseInt(h.rejected) || 0).reduce((a, b) => a + b, 0);

    perfRatioChartInstance = new Chart(ratioCtx, {
        type: 'doughnut',
        data: {
            labels: ['Approved Sales', 'Rejected'],
            datasets: [{
                data: [totalSales, totalRejected],
                backgroundColor: ['#10b981', '#ef4444'],
                borderColor: '#0f111a',
                borderWidth: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { color: '#f8fafc', usePointStyle: true } }
            },
            cutout: '70%'
        }
    });
}

// 3. Initialize module events
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('dailyReportingForm');
    if (form) {
        form.addEventListener('submit', submitDailyTransfer);
    }
});
