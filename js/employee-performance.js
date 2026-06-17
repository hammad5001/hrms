/**
 * Employee Performance — Daily Full Day Report (submit once, no edit).
 */
const Perf = {
    date: null,
    tab: 'history',
    day: null,
};

function perfTodayStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function perfFormatDate(str) {
    if (!str) return '—';
    const d = new Date(str + 'T12:00:00');
    return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}

function perfFormatDateTime(str) {
    if (!str) return '—';
    const d = new Date(str.replace(' ', 'T'));
    return d.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

async function perfApi(action, payload = null) {
    const opts = { credentials: 'include' };
    let url = `api/performance_api.php?action=${encodeURIComponent(action)}`;
    if (payload) {
        opts.method = 'POST';
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body = JSON.stringify(payload);
    } else if (Perf.date) {
        url += `&date=${encodeURIComponent(Perf.date)}`;
    }
    try {
        const res = await fetch(url, opts);
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON from', url, text.substring(0, 200));
            return { success: false, error: 'Server returned invalid response. Check console.' };
        }
    } catch (err) {
        console.error('Fetch error in perfApi:', err);
        return { success: false, error: 'Network error or server unreachable.' };
    }
}

function renderPerfAttendance(att) {
    const el = document.getElementById('perfAttendanceStrip');
    if (!el || !att) return;
    const p = att.punctuality || {};
    const items = [
        { icon: 'fa-user-check', lbl: 'Attendance', val: att.status_label || '—' },
        { icon: 'fa-right-to-bracket', lbl: 'Check-in', val: att.check_in || '—' },
        { icon: 'fa-right-from-bracket', lbl: 'Check-out', val: att.check_out || '—' },
        { icon: 'fa-hourglass-half', lbl: 'Duty hours', val: `${att.duty_hours ?? 0}h` },
        { icon: 'fa-stopwatch', lbl: 'Punctuality', val: p.label || '—', cls: `ess-perf-punct-text-${p.color || 'warn'}` },
        { icon: 'fa-fingerprint', lbl: 'Biometric', val: `${att.punch_count ?? 0} punches` },
    ];
    el.innerHTML = `
        <div class="ess-perf-att-card ess-perf-att-status-${att.status}">
            <div class="ess-perf-att-inner">
                <div class="ess-perf-att-badge ess-perf-punct-${p.color || 'warn'}">
                    <i class="fas ${p.icon || 'fa-fingerprint'}"></i>
                    <span>${p.label || att.status_label}</span>
                </div>
                <div class="ess-perf-att-grid">
                    ${items.map(it => `
                    <div class="ess-perf-att-item">
                        <span class="ess-perf-att-icon"><i class="fas ${it.icon}"></i></span>
                        <div>
                            <span class="lbl">${it.lbl}</span>
                            <strong class="${it.cls || ''}">${it.val}</strong>
                        </div>
                    </div>`).join('')}
                </div>
                <p class="ess-perf-att-note"><i class="fas fa-shield-halved"></i> Verified from attendance system — not editable</p>
            </div>
        </div>`;
}

function renderPerfSubmitForm(day) {
    const wrap = document.getElementById('perfSubmitWrap');
    const submitted = document.getElementById('perfSubmittedWrap');
    if (!wrap || !submitted) return;

    submitted.innerHTML = '';
    wrap.innerHTML = '';

    if (!day.can_submit) {
        if (day.submitted && day.report) {
            renderPerfSubmittedReport(day.report, day.attendance);
        } else if (day.report_date > perfTodayStr()) {
            wrap.innerHTML = `<div class="ess-perf-empty"><i class="fas fa-calendar"></i><p>Future dates cannot be reported yet.</p></div>`;
        }
        return;
    }

    wrap.innerHTML = `
        <div class="ess-perf-form-card">
            <div class="ess-perf-form-head">
                <h3><i class="fas fa-pen-to-square"></i> Submit Full Day Report</h3>
                <span class="ess-perf-form-date">${perfFormatDate(day.report_date)}</span>
            </div>
            <p class="ess-muted-line ess-perf-form-intro">Enter everything you accomplished today. This report is submitted once and cannot be changed later.</p>
            <form id="perfReportForm" class="ess-perf-form">
                <div class="ess-perf-form-grid">
                    <div class="form-field ess-perf-field">
                        <label><i class="fas fa-phone-volume"></i> Calls taken</label>
                        <input type="number" name="calls_made" min="0" required placeholder="e.g. 85" class="ess-leave-input">
                    </div>
                    <div class="form-field ess-perf-field">
                        <label><i class="fas fa-handshake"></i> Sales closed</label>
                        <input type="number" name="sales_closed" min="0" placeholder="e.g. 3" class="ess-leave-input">
                    </div>
                    <div class="form-field ess-perf-field">
                        <label><i class="fas fa-arrow-right-arrow-left"></i> Transfers done</label>
                        <input type="number" name="transfers_done" min="0" placeholder="e.g. 12" class="ess-leave-input">
                    </div>
                    <div class="form-field ess-perf-field">
                        <label><i class="fas fa-user-group"></i> Leads contacted</label>
                        <input type="number" name="leads_contacted" min="0" placeholder="e.g. 40" class="ess-leave-input">
                    </div>
                    <div class="form-field ess-perf-field">
                        <label><i class="fas fa-rotate"></i> Follow-ups</label>
                        <input type="number" name="follow_ups" min="0" placeholder="e.g. 15" class="ess-leave-input">
                    </div>
                    <div class="form-field ess-perf-field">
                        <label><i class="fas fa-phone"></i> Callbacks</label>
                        <input type="number" name="callbacks_done" min="0" placeholder="e.g. 8" class="ess-leave-input">
                    </div>
                    <div class="form-field ess-perf-field">
                        <label><i class="fas fa-clock"></i> Talk time (minutes)</label>
                        <input type="number" name="talk_minutes" min="0" placeholder="e.g. 240" class="ess-leave-input">
                    </div>
                </div>
                <div class="form-field">
                    <label><i class="fas fa-align-left"></i> Day summary (optional)</label>
                    <textarea name="day_summary" rows="3" class="ess-leave-input" placeholder="Brief note about your shift — challenges, wins, pending items…"></textarea>
                </div>
                <button type="submit" class="ess-btn ess-btn-primary ess-perf-submit-btn" id="perfSubmitBtn">
                    <i class="fas fa-paper-plane"></i> Submit Full Day Report
                </button>
                <p class="ess-perf-lock-note form-note"><i class="fas fa-lock"></i> After submit, this report is permanent and cannot be edited.</p>
            </form>
        </div>`;

    document.getElementById('perfReportForm')?.addEventListener('submit', submitPerfReport);
}

function renderPerfSubmittedReport(report, attendance) {
    const wrap = document.getElementById('perfSubmitWrap');
    const submitted = document.getElementById('perfSubmittedWrap');
    if (wrap) wrap.innerHTML = '';
    if (!submitted || !report) return;

    submitted.innerHTML = `
        <div class="ess-perf-submitted-card">
            <div class="ess-perf-submitted-seal"><i class="fas fa-lock"></i> Submitted & Locked</div>
            <div class="ess-perf-submitted-head">
                <h3><i class="fas fa-clipboard-check"></i> Your Day Report</h3>
                <span class="ess-perf-submitted-at">Submitted ${perfFormatDateTime(report.submitted_at)}</span>
            </div>
            <div class="ess-perf-report-grid">
                <div class="ess-perf-report-stat"><span>Calls</span><strong>${report.calls_made}</strong></div>
                <div class="ess-perf-report-stat"><span>Sales</span><strong>${report.sales_closed}</strong></div>
                <div class="ess-perf-report-stat"><span>Transfers</span><strong>${report.transfers_done}</strong></div>
                <div class="ess-perf-report-stat"><span>Leads</span><strong>${report.leads_contacted}</strong></div>
                <div class="ess-perf-report-stat"><span>Follow-ups</span><strong>${report.follow_ups}</strong></div>
                <div class="ess-perf-report-stat"><span>Callbacks</span><strong>${report.callbacks_done}</strong></div>
                <div class="ess-perf-report-stat"><span>Talk time</span><strong>${report.talk_minutes}m</strong></div>
            </div>
            ${report.day_summary ? `<div class="ess-perf-summary-box"><strong>Summary</strong><p>${escapeHtml(report.day_summary)}</p></div>` : ''}
            <p class="ess-perf-no-edit"><i class="fas fa-ban"></i> No edit option — contact your manager if correction is needed.</p>
        </div>`;
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function renderPerfMonthSummary(summary) {
    const el = document.getElementById('perfMonthSummary');
    if (!el || !summary) return;
    const monthName = new Date().toLocaleString('default', { month: 'long' });
    const stats = [
        { icon: 'fa-calendar-check', label: 'Days Reported', value: summary.days_reported, highlight: true },
        { icon: 'fa-phone-volume', label: 'Total Calls', value: summary.total_calls },
        { icon: 'fa-handshake', label: 'Total Sales', value: summary.total_sales },
        { icon: 'fa-arrow-right-arrow-left', label: 'Transfers', value: summary.total_transfers },
        { icon: 'fa-user-group', label: 'Leads', value: summary.total_leads },
    ];
    el.innerHTML = `
        <h3><i class="fas fa-chart-pie"></i> This Month (${monthName})</h3>
        <div class="ess-perf-month-stats">
            ${stats.map(s => `
            <div class="ess-perf-month-stat${s.highlight ? ' highlight' : ''}">
                <span><i class="fas ${s.icon}"></i> ${s.label}</span>
                <strong>${s.value}</strong>
            </div>`).join('')}
        </div>`;
}

function renderPerfHistory(history) {
    const el = document.getElementById('perfHistoryList');
    if (!el) return;
    if (!history || !history.length) {
        el.innerHTML = `<div class="ess-perf-empty"><i class="fas fa-clipboard"></i><p>No daily reports submitted yet. Submit your first full day report above.</p></div>`;
        return;
    }
    el.innerHTML = history.map(item => {
        const r = item.report;
        const a = item.attendance || {};
        const p = a.punctuality || {};
        return `
        <div class="ess-perf-history-card">
            <div class="ess-perf-history-head">
                <div>
                    <div class="ess-perf-history-date">${perfFormatDate(r.report_date)}</div>
                    <span class="ess-perf-history-sub">Submitted ${perfFormatDateTime(r.submitted_at)}</span>
                </div>
                <span class="ess-perf-punct-badge ess-perf-punct-${p.color}"><i class="fas ${p.icon}"></i> ${p.label}</span>
            </div>
            <div class="ess-perf-history-stats">
                <span class="ess-perf-history-pill"><i class="fas fa-phone-volume"></i> ${r.calls_made} calls</span>
                <span class="ess-perf-history-pill"><i class="fas fa-handshake"></i> ${r.sales_closed} sales</span>
                <span class="ess-perf-history-pill"><i class="fas fa-arrow-right-arrow-left"></i> ${r.transfers_done} transfers</span>
                <span class="ess-perf-history-pill attendance"><i class="fas fa-fingerprint"></i> ${a.check_in || '—'} – ${a.check_out || '—'}</span>
            </div>
        </div>`;
    }).join('');
}

function renderPerfTeam(data) {
    const summaryEl = document.getElementById('perfTeamSummary');
    const tableEl = document.getElementById('perfTeamTable');
    if (!tableEl) return;

    if (summaryEl && data.summary) {
        summaryEl.innerHTML = `
            <div class="ess-stats-row">
                <div class="ess-stat highlight"><span>Team</span><strong>${data.summary.total}</strong></div>
                <div class="ess-stat"><span>Submitted Today</span><strong>${data.summary.submitted}</strong></div>
                <div class="ess-stat"><span>Pending</span><strong style="color:var(--perf-amber,#d97706)">${data.summary.pending}</strong></div>
            </div>`;
    }

    const team = data.team || [];
    if (!team.length) {
        tableEl.innerHTML = `<div class="ess-perf-empty"><p>No team members to show.</p></div>`;
        return;
    }

    tableEl.innerHTML = `
        <div class="ess-perf-team-card">
            <div class="ess-perf-team-card-head">
                <h3><i class="fas fa-users"></i> Team Daily Reports — ${perfFormatDate(data.date)}</h3>
            </div>
            <div class="ess-perf-team-table">
                <div class="ess-perf-team-row head">
                    <span>Agent</span><span>Status</span><span>Calls</span><span>Sales</span><span>Transfers</span><span>Punctuality</span>
                </div>
                ${team.map(row => `
                <div class="ess-perf-team-row ${row.submitted ? 'submitted' : 'pending'}">
                    <span class="ess-perf-team-agent">${row.full_name}<small>${row.team || ''}</small></span>
                    <span>${row.submitted ? '<span class="ess-perf-tag done"><i class="fas fa-check"></i> Submitted</span>' : '<span class="ess-perf-tag wait"><i class="fas fa-hourglass"></i> Pending</span>'}</span>
                    <span>${row.calls_made}</span>
                    <span>${row.sales_closed}</span>
                    <span>${row.transfers_done}</span>
                    <span class="${row.is_late ? 'late' : ''}">${row.punctuality}</span>
                </div>
                `).join('')}
            </div>
        </div>`;
}

async function loadPerfDay() {
    const res = await perfApi('day');
    if (!res.success) throw new Error(res.error || 'Failed to load day');
    Perf.day = res.data;
    renderPerfAttendance(res.data.attendance);
    if (res.data.submitted) {
        renderPerfSubmittedReport(res.data.report, res.data.attendance);
        document.getElementById('perfSubmitWrap').innerHTML = '';
    } else {
        renderPerfSubmitForm(res.data);
    }
}

async function loadPerfHistory() {
    const res = await perfApi('history');
    if (!res.success) return;
    renderPerfHistory(res.data.history);
    renderPerfMonthSummary(res.data.month_summary);
    const teamTab = document.getElementById('perfTabTeam');
    if (teamTab) teamTab.classList.toggle('hidden', !res.data.permissions?.can_view_team);
}

async function loadPerfTeam() {
    const res = await perfApi('team_day');
    if (res.success) renderPerfTeam(res.data);
}

async function loadPerformanceView() {
    const loading = document.getElementById('perfLoading');
    loading?.classList.remove('hidden');
    try {
        const dateInput = document.getElementById('perfReportDate');
        if (dateInput) {
            if (!dateInput.max) dateInput.max = perfTodayStr();
            if (!Perf.date) Perf.date = dateInput.value || perfTodayStr();
            dateInput.value = Perf.date;
        }
        await loadPerfDay();
        await loadPerfHistory();
        if (Perf.tab === 'team') await loadPerfTeam();
    } catch (e) {
        console.error('Performance load error:', e);
        showToast?.(e.message || 'Failed to load daily report', 'error');
        document.getElementById('perfAttendanceStrip').innerHTML =
            '<div class="ess-perf-empty"><p>Could not load report data. Click Refresh or check you are logged in.</p></div>';
        renderPerfSubmitForm({ can_submit: true, report_date: Perf.date || perfTodayStr(), submitted: false, report: null, attendance: perf_empty_attendance_ui() });
    } finally {
        loading?.classList.add('hidden');
    }
}

function perf_empty_attendance_ui() {
    return { status_label: '—', check_in: null, check_out: null, duty_hours: 0, punch_count: 0, punctuality: { label: '—', color: 'warn', icon: 'fa-fingerprint' } };
}

async function submitPerfReport(e) {
    e.preventDefault();
    const btn = document.getElementById('perfSubmitBtn');
    const fd = new FormData(e.target);
    const payload = {
        report_date: Perf.date || perfTodayStr(),
        calls_made: fd.get('calls_made'),
        sales_closed: fd.get('sales_closed'),
        transfers_done: fd.get('transfers_done'),
        leads_contacted: fd.get('leads_contacted'),
        follow_ups: fd.get('follow_ups'),
        callbacks_done: fd.get('callbacks_done'),
        talk_minutes: fd.get('talk_minutes'),
        day_summary: fd.get('day_summary'),
    };

    if (!confirm('Submit your full day report? This cannot be edited afterwards.')) return;

    btn.disabled = true;
    try {
        const res = await perfApi('submit', payload);
        if (res.success) {
            showToast?.('Daily report submitted successfully', 'success');
            Perf.day = res.data.day;
            renderPerfSubmittedReport(res.data.report, res.data.day?.attendance);
            document.getElementById('perfSubmitWrap').innerHTML = '';
            await loadPerfHistory();
        } else {
            showToast?.(res.error || 'Submit failed', 'error');
        }
    } catch (err) {
        showToast?.('Submit failed', 'error');
    } finally {
        btn.disabled = false;
    }
}

function setPerfTab(tab) {
    Perf.tab = tab;
    document.querySelectorAll('.ess-perf-tab').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.perfTab === tab);
    });
    document.querySelectorAll('.ess-perf-panel').forEach(p => {
        p.classList.toggle('active', p.dataset.perfPanel === tab);
    });
    if (tab === 'team') loadPerfTeam();
}

function initPerformanceModule() {
    const dateInput = document.getElementById('perfReportDate');
    if (dateInput) {
        dateInput.max = perfTodayStr();
        dateInput.value = perfTodayStr();
        Perf.date = perfTodayStr();
        dateInput.addEventListener('change', () => {
            Perf.date = dateInput.value;
            loadPerfDay();
        });
    }
    document.querySelectorAll('.ess-perf-tab').forEach(btn => {
        btn.addEventListener('click', () => setPerfTab(btn.dataset.perfTab));
    });
    document.getElementById('perfBtnRefresh')?.addEventListener('click', loadPerformanceView);
}

document.addEventListener('DOMContentLoaded', initPerformanceModule);
window.loadPerformanceView = loadPerformanceView;
