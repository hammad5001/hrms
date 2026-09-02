/**
 * Enterprise HR Productivity OS - Advanced Frontend Module v3
 * Features: Time Tracker * Timesheets * Activity Feed * Score Engine
 *           Analytics Charts * Leaderboard * Smart Alerts * TS Approvals
 */

// ─────────────────────────────────────────────────────────────────────────────
// STATE
// ─────────────────────────────────────────────────────────────────────────────
const ProdState = {
    activeLog:       null,
    sessionInterval: null,
    feedInterval:    null,
    alertInterval:   null,
    currentTsId:     null,
    tsWeekStart:     null,
    currentTs:       null,
    charts:          {},   // Chart.js instances keyed by canvas id
};

// ─────────────────────────────────────────────────────────────────────────────
// API HELPER
// ─────────────────────────────────────────────────────────────────────────────
async function prodApi(action, payload = null) {
    const isSubdir = window.location.pathname.includes('/workfromhome/');
    const baseUrl = isSubdir ? '../api/productivity_api.php' : 'api/productivity_api.php';
    const url  = `${baseUrl}?action=${encodeURIComponent(action)}`;
    const opts = { credentials: 'include' };
    if (payload) {
        opts.method  = 'POST';
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body    = JSON.stringify(payload);
    }
    try {
        const res  = await fetch(url, opts);
        const text = await res.text();
        try { return JSON.parse(text); }
        catch (e) {
            console.error('[ProdAPI] Non-JSON from', action, text.slice(0, 300));
            return { success: false, error: 'Server returned invalid response' };
        }
    } catch (e) {
        console.error('[ProdAPI] Network error', e);
        return { success: false, error: 'Network error' };
    }
}

function escH(str) {
    const d = document.createElement('div');
    d.textContent = String(str ?? '');
    return d.innerHTML;
}

// ─────────────────────────────────────────────────────────────────────────────
// ① TIME TRACKER
// ─────────────────────────────────────────────────────────────────────────────
function startSessionTimer(clockInStr) {
    stopSessionTimer();
    const start = new Date(clockInStr.replace(' ', 'T'));
    ProdState.sessionInterval = setInterval(() => {
        const el = document.getElementById('prodSessionTime');
        if (!el) return stopSessionTimer();
        const sec = Math.max(0, Math.floor((Date.now() - start.getTime()) / 1000));
        const h = String(Math.floor(sec / 3600)).padStart(2, '0');
        const m = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
        const s = String(sec % 60).padStart(2, '0');
        el.textContent = `${h}:${m}:${s}`;
    }, 1000);
}
function stopSessionTimer() {
    clearInterval(ProdState.sessionInterval);
    ProdState.sessionInterval = null;
    const el = document.getElementById('prodSessionTime');
    if (el) el.textContent = '00:00:00';
}

function updateWidgetUI(log) {
    const dot      = document.getElementById('prodStatusDot');
    const txt      = document.getElementById('prodStatusText');
    const btnClock = document.getElementById('btnClockInOut');
    const btnBreak = document.getElementById('btnBreakToggle');
    if (!dot || !txt || !btnClock || !btnBreak) return;

    if (!log) {
        dot.className = 'prod-status-dot';
        dot.style.background = '';
        txt.textContent = 'Not Working';
        btnClock.innerHTML = '<i class="fas fa-play"></i> Clock In';
        btnClock.className = 'prod-btn prod-btn-emerald';
        btnClock.style.display = 'flex';
        btnBreak.style.display = 'none';
        ProdState.activeLog = null;
        stopSessionTimer();
        return;
    }
    ProdState.activeLog = log;

    if (log.status === 'active') {
        dot.className = 'prod-status-dot active';
        dot.style.background = '';
        txt.textContent = '🟢 Working';
        btnClock.innerHTML = '<i class="fas fa-stop"></i> Clock Out';
        btnClock.className = 'prod-btn prod-btn-primary';
        btnClock.style.display = 'flex';
        btnBreak.innerHTML = '<i class="fas fa-coffee"></i> Break';
        btnBreak.style.display = 'flex';
        startSessionTimer(log.clock_in);
    } else if (log.status === 'on_break') {
        dot.className = 'prod-status-dot';
        dot.style.background = '#F59E0B';
        txt.textContent = '☕ On Break';
        btnClock.style.display = 'none';
        btnBreak.innerHTML = '<i class="fas fa-play"></i> End Break';
        btnBreak.style.display = 'flex';
        stopSessionTimer();
    }
}

async function prodLoadStatus() {
    const res = await prodApi('current_status');
    if (!res.success) return;
    updateWidgetUI(res.data.active_log);
    const totalEl = document.getElementById('prodTotalTime');
    if (totalEl && res.data.active_log) {
        totalEl.textContent = (parseFloat(res.data.active_log.total_hours) || 0).toFixed(2) + 'h';
    }
}

async function prodHandleClockInOut() {
    const btn = document.getElementById('btnClockInOut');
    if (btn) btn.disabled = true;
    if (!ProdState.activeLog) {
        const res = await prodApi('clock_in');
        if (res.success) { updateWidgetUI(res.data.active_log); prodLoadFeed(); }
        else alert(res.error || 'Clock in failed');
    } else {
        if (!confirm('Clock out and end your session?')) { if (btn) btn.disabled = false; return; }
        const res = await prodApi('clock_out');
        if (res.success) {
            updateWidgetUI(null);
            const el = document.getElementById('prodTotalTime');
            if (el) el.textContent = '-';
            prodLoadFeed();
            prodLoadDashScore();
        } else alert(res.error || 'Clock out failed');
    }
    if (btn) btn.disabled = false;
}

async function prodHandleBreak() {
    if (!ProdState.activeLog) return;
    const isOnBreak = ProdState.activeLog.status === 'on_break';
    const res = await prodApi(isOnBreak ? 'break_end' : 'break_start');
    if (res.success) { await prodLoadStatus(); prodLoadFeed(); }
    else alert(res.error || 'Action failed');
}

// ─────────────────────────────────────────────────────────────────────────────
// ② ACTIVITY FEED
// ─────────────────────────────────────────────────────────────────────────────
const ANN_CATEGORIES = {
    general: { label: 'General Notice', icon: 'fa-bullhorn', color: '#3B82F6', bg: 'rgba(59, 130, 246, 0.15)' },
    urgent:  { label: 'Urgent',         icon: 'fa-exclamation-triangle', color: '#EF4444', bg: 'rgba(239, 68, 68, 0.15)' },
    policy:  { label: 'Policy Update',  icon: 'fa-scroll',   color: '#8B5CF6', bg: 'rgba(139, 92, 246, 0.15)' },
    holiday: { label: 'Holiday',        icon: 'fa-calendar-check', color: '#10B981', bg: 'rgba(16, 185, 129, 0.15)' },
    event:   { label: 'Company Event',  icon: 'fa-star',     color: '#F59E0B', bg: 'rgba(245, 158, 11, 0.15)' },
};

function prodTimeAgo(dateStr) {
    if (!dateStr) return '';
    const d = new Date(String(dateStr).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(dateStr);
    const sec = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
    if (sec < 60)      return `${sec}s ago`;
    if (sec < 3600)    return `${Math.floor(sec / 60)}m ago`;
    if (sec < 86400)   return `${Math.floor(sec / 3600)}h ago`;
    if (sec < 2592000) return `${Math.floor(sec / 86400)}d ago`;
    return d.toLocaleDateString();
}

async function prodLoadFeed() {
    const list = document.getElementById('prodFeedList');
    const composer = document.getElementById('prodAnnComposer');
    if (!list) return;

    const res = await prodApi('feed');
    if (!res.success) {
        list.innerHTML = `<div class="prod-feed-empty"><i class="fas fa-exclamation-circle"></i><p>Could not load announcements.</p></div>`;
        return;
    }

    const birthdays = res.data.birthdays || [];
    const announcements = res.data.announcements || [];
    const canPost = !!res.data.can_post_announcement;

    // Toggle Composer visibility based on role
    if (composer) {
        if (canPost) {
            composer.classList.remove('hidden');
            composer.style.display = 'block';
        } else {
            composer.classList.add('hidden');
            composer.style.display = 'none';
        }
    }

    let html = '';

    // 1. Render Birthday Celebrations
    if (birthdays.length > 0) {
        html += birthdays.map(item => `
            <div class="prod-feed-item prod-feed-item--birthday">
                <div class="prod-feed-avatar prod-feed-avatar--birthday">🎂</div>
                <div class="prod-feed-content prod-feed-content--birthday">
                    <div class="prod-bday-header">
                        <span class="prod-bday-badge"><i class="fas fa-sparkles"></i> 🎉 Birthday Celebration! 🎂</span>
                        <span class="prod-bday-today-tag">Today</span>
                    </div>
                    <div class="prod-bday-name">${escH(item.employee_name)}</div>
                    <div class="prod-bday-wishes">Wishing you a fantastic birthday filled with happiness and success! 🎈✨</div>
                    <div class="prod-bday-details">
                        ${item.designation ? `<span class="prod-bday-tag prod-bday-tag--role"><i class="fas fa-id-badge"></i> ${escH(item.designation)}</span>` : ''}
                        ${item.department ? `<span class="prod-bday-tag prod-bday-tag--dept"><i class="fas fa-building"></i> ${escH(item.department)}</span>` : ''}
                        ${item.team ? `<span class="prod-bday-tag prod-bday-tag--team"><i class="fas fa-users"></i> ${escH(item.team)}</span>` : ''}
                    </div>
                </div>
            </div>
        `).join('');
    }

    // 2. Render Official Announcements
    if (announcements.length > 0) {
        html += announcements.map(ann => {
            const cat = ANN_CATEGORIES[ann.category] || ANN_CATEGORIES.general;
            const init = (ann.author_name || 'HR').charAt(0).toUpperCase();
            const pinnedBadge = ann.is_pinned
                ? `<span class="prod-ann-pinned-badge"><i class="fas fa-thumbtack"></i> Pinned</span>`
                : '';
            const deleteBtn = canPost
                ? `<button type="button" class="prod-ann-del-btn" onclick="prodDeleteAnnouncement(${ann.id})" title="Delete announcement"><i class="fas fa-trash-alt"></i></button>`
                : '';

            return `
                <div class="prod-ann-card ${ann.is_pinned ? 'prod-ann-card--pinned' : ''}">
                    <div class="prod-ann-top">
                        <div class="prod-ann-author-wrap">
                            <div class="prod-ann-avatar" style="background:linear-gradient(135deg, ${cat.color}33, ${cat.color}66); border-color:${cat.color}66; color:#fff;">${init}</div>
                            <div class="prod-ann-author-info">
                                <div class="prod-ann-author-name">
                                    <strong>${escH(ann.author_name)}</strong>
                                    <span class="prod-ann-role-pill">${escH(ann.author_role.toUpperCase())}</span>
                                </div>
                                <div class="prod-ann-time"><i class="far fa-clock"></i> ${prodTimeAgo(ann.created_at)}</div>
                            </div>
                        </div>
                        <div class="prod-ann-actions">
                            <span class="prod-ann-cat-badge" style="background:${cat.bg}; color:${cat.color}; border:1px solid ${cat.color}44;">
                                <i class="fas ${cat.icon}"></i> ${cat.label}
                            </span>
                            ${pinnedBadge}
                            ${deleteBtn}
                        </div>
                    </div>
                    <div class="prod-ann-body">
                        <h4 class="prod-ann-title">${escH(ann.title)}</h4>
                        <div class="prod-ann-content">${escH(ann.content).replace(/\n/g, '<br>')}</div>
                    </div>
                </div>
            `;
        }).join('');
    }

    if (!birthdays.length && !announcements.length) {
        html = `<div class="prod-feed-empty">
            <i class="fas fa-bullhorn" style="font-size:32px; color:var(--prod-indigo); margin-bottom:10px;"></i>
            <p>No active announcements or birthday celebrations today.</p>
        </div>`;
    }

    list.innerHTML = html;
}

async function prodSubmitAnnouncement() {
    const titleEl = document.getElementById('annTitle');
    const contentEl = document.getElementById('annContent');
    const catEl = document.getElementById('annCategory');
    const pinEl = document.getElementById('annPinned');
    const btn = document.getElementById('btnPublishAnn');

    if (!titleEl || !contentEl) return;
    const title = titleEl.value.trim();
    const content = contentEl.value.trim();
    const category = catEl ? catEl.value : 'general';
    const is_pinned = pinEl ? pinEl.checked : false;

    if (!title || !content) {
        alert('Please provide both title and announcement content.');
        return;
    }

    if (btn) btn.disabled = true;
    const res = await prodApi('create_announcement', { title, content, category, is_pinned });
    if (btn) btn.disabled = false;

    if (res.success) {
        titleEl.value = '';
        contentEl.value = '';
        if (pinEl) pinEl.checked = false;
        await prodLoadFeed();
    } else {
        alert(res.error || 'Failed to post announcement.');
    }
}

async function prodDeleteAnnouncement(id) {
    if (!confirm('Are you sure you want to delete this announcement?')) return;
    const res = await prodApi('delete_announcement', { id });
    if (res.success) {
        await prodLoadFeed();
    } else {
        alert(res.error || 'Failed to remove announcement.');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ③ TIMESHEETS
// ─────────────────────────────────────────────────────────────────────────────
const PROD_DAYS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
function prodMondayOfWeek(offset = 0) {
    const d   = new Date();
    const dow = d.getDay();
    const mon = new Date(d);
    mon.setDate(d.getDate() - ((dow + 6) % 7) + offset * 7);
    return mon.toISOString().slice(0, 10);
}
function prodWeekDates(monday) {
    return PROD_DAYS.map((_, i) => {
        const d = new Date(monday + 'T00:00:00');
        d.setDate(d.getDate() + i);
        return d.toISOString().slice(0, 10);
    });
}

async function prodLoadTimesheet(weekStart = null) {
    const body = document.getElementById('prodTsBody');
    if (!body) return;
    weekStart = weekStart || prodMondayOfWeek(0);
    ProdState.tsWeekStart = weekStart;
    body.innerHTML = `<tr><td colspan="12" class="prod-ts-loading">Loading...</td></tr>`;
    const res = await prodApi('get_timesheet', { week_start: weekStart });
    if (!res.success) { body.innerHTML = `<tr><td colspan="12" class="prod-ts-error">Error: ${escH(res.error)}</td></tr>`; return; }
    ProdState.currentTsId = res.data.timesheet.id;
    ProdState.currentTs   = res.data.timesheet;
    const statusEl   = document.getElementById('prodTsStatus');
    const submitBtn  = document.getElementById('btnSubmitTimesheet');
    if (statusEl) {
        const s = res.data.timesheet.status;
        statusEl.textContent = s.charAt(0).toUpperCase() + s.slice(1);
        statusEl.className = `prod-ts-badge prod-ts-badge--${s}`;
    }
    if (submitBtn) submitBtn.disabled = res.data.timesheet.status !== 'draft';
    prodRenderTsBody(res.data.entries, res.data.timesheet);
}

function prodRenderTsBody(entries, timesheet) {
    const body = document.getElementById('prodTsBody');
    if (!body) return;
    const dates = prodWeekDates(ProdState.tsWeekStart);
    const isSubmitted = timesheet.status !== 'draft';
    const rowMap = {};
    entries.forEach(e => {
        const key = `${e.project}||${e.task || ''}`;
        if (!rowMap[key]) rowMap[key] = { project: e.project, task: e.task || '', days: {} };
        rowMap[key].days[e.log_date] = parseFloat(e.hours);
    });
    const makeRow = (project, task, days) => {
        const rowTotal = dates.reduce((s, d) => s + (days[d] || 0), 0);
        const ro = isSubmitted ? 'readonly' : '';
        return `<tr class="prod-ts-row" data-project="${escH(project)}" data-task="${escH(task)}">
            <td><input class="prod-ts-input prod-ts-project" value="${escH(project)}" placeholder="Project..." ${ro}></td>
            <td><input class="prod-ts-input prod-ts-task" value="${escH(task)}" placeholder="Task..." ${ro}></td>
            ${dates.map(date => `<td><input type="number" class="prod-ts-input prod-ts-hours" min="0" max="24" step="0.5" data-date="${date}" value="${days[date] || ''}" placeholder="0" ${ro}></td>`).join('')}
            <td class="prod-ts-rowtotal"><strong>${rowTotal.toFixed(1)}h</strong></td>
            ${isSubmitted ? '' : '<td><button class="prod-ts-del" title="Remove">×</button></td>'}
        </tr>`;
    };
    const html = Object.values(rowMap).map(r => makeRow(r.project, r.task, r.days)).join('');
    body.innerHTML = html || `<tr><td colspan="${isSubmitted?11:12}" class="prod-ts-empty">No entries yet - click Add Row to start.</td></tr>`;
    if (!isSubmitted) {
        body.querySelectorAll('.prod-ts-hours').forEach(inp => inp.addEventListener('change', () => { prodSaveTsEntry(inp.closest('tr')); prodUpdateTsFooter(); }));
        body.querySelectorAll('.prod-ts-project, .prod-ts-task').forEach(inp => inp.addEventListener('blur', () => prodSaveTsEntry(inp.closest('tr'))));
        body.querySelectorAll('.prod-ts-del').forEach(btn => btn.addEventListener('click', () => { btn.closest('tr').remove(); prodUpdateTsFooter(); }));
    }
    prodUpdateTsFooter();
}

function prodUpdateTsFooter() {
    const body   = document.getElementById('prodTsBody');
    const footer = document.getElementById('prodTsFooter');
    if (!footer || !ProdState.tsWeekStart) return;
    const dates = prodWeekDates(ProdState.tsWeekStart);
    body?.querySelectorAll('.prod-ts-row').forEach(row => {
        let sum = 0;
        row.querySelectorAll('.prod-ts-hours').forEach(i => sum += parseFloat(i.value || 0));
        const el = row.querySelector('.prod-ts-rowtotal strong');
        if (el) el.textContent = sum.toFixed(1) + 'h';
    });
    const colTotals = dates.map(date => {
        let sum = 0;
        body?.querySelectorAll(`.prod-ts-hours[data-date="${date}"]`).forEach(i => sum += parseFloat(i.value || 0));
        return sum;
    });
    const grand = colTotals.reduce((a, b) => a + b, 0);
    footer.innerHTML = `<tr class="prod-ts-footer-row">
        <td colspan="2" style="font-weight:700;color:var(--prod-text-muted);">Total</td>
        ${colTotals.map(t => `<td><strong>${t.toFixed(1)}</strong></td>`).join('')}
        <td><strong class="prod-ts-grand">${grand.toFixed(1)}h</strong></td>
    </tr>`;
}

async function prodSaveTsEntry(row) {
    if (!ProdState.currentTsId || !row) return;
    const project = row.querySelector('.prod-ts-project')?.value?.trim();
    if (!project) return;
    const task = row.querySelector('.prod-ts-task')?.value?.trim() || '';
    const promises = [];
    row.querySelectorAll('.prod-ts-hours').forEach(inp => {
        const date  = inp.dataset.date;
        const hours = parseFloat(inp.value || 0);
        promises.push(prodApi('save_timesheet_entry', { timesheet_id: ProdState.currentTsId, project, task, log_date: date, hours }));
    });
    await Promise.all(promises);
    prodUpdateTsFooter();
}

function prodAddTsRow() {
    const body = document.getElementById('prodTsBody');
    if (!body || !ProdState.tsWeekStart) return;
    const dates = prodWeekDates(ProdState.tsWeekStart);
    const tr = document.createElement('tr');
    tr.className = 'prod-ts-row';
    tr.innerHTML = `
        <td><input class="prod-ts-input prod-ts-project" value="" placeholder="Project..."></td>
        <td><input class="prod-ts-input prod-ts-task" value="" placeholder="Task..."></td>
        ${dates.map(d => `<td><input type="number" class="prod-ts-input prod-ts-hours" min="0" max="24" step="0.5" data-date="${d}" value="" placeholder="0"></td>`).join('')}
        <td class="prod-ts-rowtotal"><strong>0h</strong></td>
        <td><button class="prod-ts-del">×</button></td>`;
    body.appendChild(tr);
    tr.querySelectorAll('.prod-ts-hours').forEach(i => i.addEventListener('change', () => { prodSaveTsEntry(tr); prodUpdateTsFooter(); }));
    tr.querySelectorAll('.prod-ts-project,.prod-ts-task').forEach(i => i.addEventListener('blur', () => prodSaveTsEntry(tr)));
    tr.querySelector('.prod-ts-del').addEventListener('click', () => { tr.remove(); prodUpdateTsFooter(); });
    tr.querySelector('.prod-ts-project').focus();
}

async function prodSubmitTimesheet() {
    if (!ProdState.currentTsId) return;
    if (!confirm('Submit your timesheet for approval? It cannot be edited after submission.')) return;
    const res = await prodApi('submit_timesheet', { timesheet_id: ProdState.currentTsId });
    if (res.success) { prodLoadTimesheet(ProdState.tsWeekStart); prodLoadFeed(); }
    else alert(res.error || 'Submit failed');
}

// ─────────────────────────────────────────────────────────────────────────────
// ④ PRODUCTIVITY SCORE (Dashboard widget)
// ─────────────────────────────────────────────────────────────────────────────
async function prodLoadDashScore() {
    const row = document.getElementById('dashScoreRow');
    if (!row) return;
    const res = await prodApi('my_score');
    if (!res.success) return;
    const { score, detail, week } = res.data;
    const circumference = 2 * Math.PI * 40;
    const offset = circumference - (score / 100) * circumference;
    const color = score >= 80 ? '#10B981' : score >= 60 ? '#F59E0B' : score >= 40 ? '#6366F1' : '#EF4444';
    const weekBars = week.map(w => {
        const s = parseInt(w.score);
        const day = new Date(w.score_date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short' });
        return `<div class="prod-score-mini-bar">
            <div class="prod-score-mini-fill" style="height:${s}%;background:${s>=80?'#10B981':s>=60?'#F59E0B':'#6366F1'};"></div>
            <span>${day}</span>
        </div>`;
    }).join('');
    const breakdown = [
        { label: 'Attendance', val: detail.attendance_score, max: 25, color: '#10B981' },
        { label: 'Report',     val: detail.report_score,     max: 20, color: '#6366F1' },
        { label: 'Timesheet',  val: detail.timesheet_score,  max: 20, color: '#F59E0B' },
        { label: 'Activity',   val: detail.activity_score,   max: 25, color: '#EC4899' },
        { label: 'Overtime',   val: detail.overtime_score,   max: 10, color: '#3B82F6' },
    ];
    row.style.display = 'grid';
    row.innerHTML = `
        <div class="prod-glass-card prod-score-main">
            <svg viewBox="0 0 100 100" class="prod-score-ring">
                <circle cx="50" cy="50" r="40" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="10"/>
                <circle cx="50" cy="50" r="40" fill="none" stroke="${color}" stroke-width="10"
                    stroke-dasharray="${circumference}" stroke-dashoffset="${offset}"
                    stroke-linecap="round" transform="rotate(-90 50 50)"
                    style="transition: stroke-dashoffset 1.2s cubic-bezier(0.4,0,0.2,1);"/>
            </svg>
            <div class="prod-score-center">
                <div class="prod-score-val" style="color:${color}">${score}</div>
                <div class="prod-score-lbl">Today</div>
            </div>
            <div class="prod-score-right">
                <h4>Productivity Score</h4>
                ${breakdown.map(b => `
                    <div class="prod-breakdown-row">
                        <span>${b.label}</span>
                        <div class="prod-breakdown-bar">
                            <div style="width:${(b.val/b.max)*100}%;background:${b.color};"></div>
                        </div>
                        <span class="prod-breakdown-val">${b.val}/${b.max}</span>
                    </div>`).join('')}
            </div>
        </div>
        <div class="prod-glass-card prod-score-week">
            <h4>This Week</h4>
            <div class="prod-score-week-bars">${weekBars || '<p style="color:var(--prod-text-muted);font-size:12px;">No data yet</p>'}</div>
        </div>`;
}

// ─────────────────────────────────────────────────────────────────────────────
// ⑤ ANALYTICS CHARTS
// ─────────────────────────────────────────────────────────────────────────────
const CHART_DEFAULTS = {
    plugins: { legend: { display: false } },
    scales: {
        x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#9CA3AF', font: { size: 11 } } },
        y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#9CA3AF', font: { size: 11 } }, beginAtZero: true },
    },
};

function destroyChart(id) {
    if (ProdState.charts[id]) { ProdState.charts[id].destroy(); delete ProdState.charts[id]; }
}

function shortDate(str) {
    return new Date(str + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

async function prodLoadAnalytics() {
    const res = await prodApi('analytics');
    if (!res.success) return;
    const d = res.data;

    // KPIs
    const weekHours = d.hours_data.slice(-7).reduce((s, r) => s + parseFloat(r.hours), 0);
    const monthCalls = d.calls_data.reduce((s, r) => s + parseInt(r.calls_made), 0);
    const monthSales = d.calls_data.reduce((s, r) => s + parseInt(r.sales_closed), 0);
    const latestScore = d.score_data.length ? d.score_data[d.score_data.length - 1].score : 0;
    setText('kpiScore', latestScore + '/100');
    setText('kpiHours', weekHours.toFixed(1) + 'h');
    setText('kpiCalls', monthCalls.toString());
    setText('kpiSales', monthSales.toString());

    function setText(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }

    // ── Chart: Work Hours ──────────────────────────────────────────────────
    destroyChart('chartHours');
    const hoursEl = document.getElementById('chartHours');
    if (hoursEl && d.hours_data.length) {
        ProdState.charts['chartHours'] = new Chart(hoursEl, {
            type: 'line',
            data: {
                labels: d.hours_data.map(r => shortDate(r.work_date)),
                datasets: [{
                    data: d.hours_data.map(r => parseFloat(r.hours)),
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16,185,129,0.08)',
                    fill: true, tension: 0.4, pointRadius: 3, pointBackgroundColor: '#10B981',
                }],
            },
            options: { ...CHART_DEFAULTS, responsive: true, maintainAspectRatio: false },
        });
    }

    // ── Chart: Score Trend ─────────────────────────────────────────────────
    destroyChart('chartScore');
    const scoreEl = document.getElementById('chartScore');
    if (scoreEl && d.score_data.length) {
        ProdState.charts['chartScore'] = new Chart(scoreEl, {
            type: 'line',
            data: {
                labels: d.score_data.map(r => shortDate(r.score_date)),
                datasets: [{
                    data: d.score_data.map(r => parseInt(r.score)),
                    borderColor: '#6366F1',
                    backgroundColor: 'rgba(99,102,241,0.08)',
                    fill: true, tension: 0.4, pointRadius: 3, pointBackgroundColor: '#6366F1',
                }],
            },
            options: {
                ...CHART_DEFAULTS, responsive: true, maintainAspectRatio: false,
                scales: { ...CHART_DEFAULTS.scales, y: { ...CHART_DEFAULTS.scales.y, max: 100 } },
            },
        });
    }

    // ── Chart: Calls & Sales ──────────────────────────────────────────────
    destroyChart('chartCalls');
    const callsEl = document.getElementById('chartCalls');
    if (callsEl && d.calls_data.length) {
        ProdState.charts['chartCalls'] = new Chart(callsEl, {
            type: 'bar',
            data: {
                labels: d.calls_data.map(r => shortDate(r.report_date)),
                datasets: [
                    { label: 'Calls', data: d.calls_data.map(r => parseInt(r.calls_made)), backgroundColor: 'rgba(99,102,241,0.6)', borderRadius: 4 },
                    { label: 'Sales', data: d.calls_data.map(r => parseInt(r.sales_closed)), backgroundColor: 'rgba(16,185,129,0.6)', borderRadius: 4 },
                ],
            },
            options: { ...CHART_DEFAULTS, responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, labels: { color: '#9CA3AF' } } } },
        });
    }

    // ── Chart: Timesheet Donut ────────────────────────────────────────────
    destroyChart('chartTs');
    const tsEl = document.getElementById('chartTs');
    if (tsEl) {
        const tsSum   = d.ts_summary || {};
        const tsLabels = Object.keys(tsSum);
        const tsVals   = Object.values(tsSum);
        const tsColors = { draft: '#6366F1', submitted: '#F59E0B', approved: '#10B981', rejected: '#EF4444' };
        if (tsLabels.length) {
            ProdState.charts['chartTs'] = new Chart(tsEl, {
                type: 'doughnut',
                data: {
                    labels: tsLabels.map(l => l.charAt(0).toUpperCase() + l.slice(1)),
                    datasets: [{ data: tsVals, backgroundColor: tsLabels.map(l => tsColors[l] || '#9CA3AF'), borderWidth: 0, hoverOffset: 6 }],
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, labels: { color: '#9CA3AF', padding: 16 } } }, cutout: '65%' },
            });
        }
    }

    // ── Heatmap ───────────────────────────────────────────────────────────
    const heatmapEl = document.getElementById('prodHeatmap');
    if (heatmapEl) {
        const lookup = {};
        d.heatmap.forEach(r => { lookup[r.work_date] = parseFloat(r.hours); });
        const cells = [];
        for (let i = 89; i >= 0; i--) {
            const dt   = new Date(); dt.setDate(dt.getDate() - i);
            const key  = dt.toISOString().slice(0, 10);
            const hrs  = lookup[key] || 0;
            const intensity = hrs === 0 ? 0 : Math.min(1, hrs / 8);
            const bg   = hrs === 0 ? 'rgba(255,255,255,0.04)' : `rgba(16,185,129,${0.15 + intensity * 0.85})`;
            cells.push(`<div class="prod-heatmap-cell" style="background:${bg};" title="${key}: ${hrs.toFixed(1)}h"></div>`);
        }
        heatmapEl.innerHTML = cells.join('');
    }

    // ── Break / Rhythm Insights ───────────────────────────────────────────
    const insightsEl = document.getElementById('prodBreakInsights');
    if (insightsEl && d.break_analytics) {
        const ba = d.break_analytics;
        const bestDayStr = ba.best_day ? `${ba.best_day.work_date} (${ba.best_day.total_hours}h)` : 'N/A';
        insightsEl.innerHTML = `
            <div class="prod-insight-card"><i class="fas fa-coffee" style="color:#F59E0B;"></i><strong>${ba.avg_breaks}</strong><span>Avg breaks/day</span></div>
            <div class="prod-insight-card"><i class="fas fa-hourglass-half" style="color:#6366F1;"></i><strong>${ba.avg_break_min}m</strong><span>Avg break duration</span></div>
            <div class="prod-insight-card"><i class="fas fa-fire" style="color:#10B981;"></i><strong>${bestDayStr}</strong><span>Most productive day</span></div>
            <div class="prod-insight-card"><i class="fas fa-bolt" style="color:#EC4899;"></i><strong>${ba.total_overtime}h</strong><span>Overtime (period)</span></div>`;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ⑥ LEADERBOARD
// ─────────────────────────────────────────────────────────────────────────────
async function prodLoadLeaderboard() {
    const el = document.getElementById('prodLeaderboard');
    if (!el) return;
    const res = await prodApi('leaderboard');
    if (!res.success) { el.innerHTML = `<div class="prod-feed-empty"><p>Could not load leaderboard.</p></div>`; return; }
    const { leaderboard, my_id } = res.data;
    if (!leaderboard.length) {
        el.innerHTML = `<div class="prod-feed-empty"><i class="fas fa-trophy"></i><p>No scores this week yet. Clock in and submit reports to appear!</p></div>`;
        return;
    }
    const medals = ['🥇', '🥈', '🥉'];
    el.innerHTML = leaderboard.map((row, i) => {
        const isMe = parseInt(row.employee_id) === my_id;
        const score = parseInt(row.avg_score);
        const color = score >= 80 ? '#10B981' : score >= 60 ? '#F59E0B' : '#6366F1';
        return `<div class="prod-lb-row ${isMe ? 'prod-lb-row--me' : ''}">
            <div class="prod-lb-rank">${medals[i] || `#${i+1}`}</div>
            <div class="prod-lb-avatar">${(row.full_name || '?').charAt(0).toUpperCase()}</div>
            <div class="prod-lb-info">
                <strong>${escH(row.full_name)}${isMe ? ' <span class="prod-lb-you">(You)</span>' : ''}</strong>
                <span>${escH(row.designation || 'Employee')}</span>
            </div>
            <div class="prod-lb-score-wrap">
                <div class="prod-lb-bar"><div style="width:${score}%;background:${color};"></div></div>
                <span class="prod-lb-score" style="color:${color}">${score}</span>
            </div>
        </div>`;
    }).join('');
}

// ─────────────────────────────────────────────────────────────────────────────
// ⑦ SMART ALERTS
// ─────────────────────────────────────────────────────────────────────────────
const ALERT_ICONS = {
    no_clockin:     { icon: 'fa-alarm-clock',   color: '#F59E0B' },
    long_break:     { icon: 'fa-mug-hot',        color: '#6366F1' },
    ts_reminder:    { icon: 'fa-file-alt',        color: '#10B981' },
    report_reminder:{ icon: 'fa-clipboard-list', color: '#EC4899' },
};

async function prodCheckAlerts() {
    const res = await prodApi('alerts');
    if (!res.success || !res.data.alerts.length) return;
    const banner = document.getElementById('smartAlertsBanner');
    if (!banner) return;
    banner.style.display = 'block';
    banner.innerHTML = res.data.alerts.map(a => {
        const cfg = ALERT_ICONS[a.alert_type] || { icon: 'fa-bell', color: '#9CA3AF' };
        return `<div class="prod-alert-item" data-id="${a.id}">
            <i class="fas ${cfg.icon}" style="color:${cfg.color};"></i>
            <div class="prod-alert-body">
                <strong>${escH(a.title)}</strong>
                <p>${escH(a.message)}</p>
            </div>
            <button class="prod-alert-close" onclick="prodDismissAlert(${a.id})">×</button>
        </div>`;
    }).join('');
}

async function prodDismissAlert(id) {
    await prodApi('dismiss_alert', { alert_id: id });
    const el = document.querySelector(`#smartAlertsBanner .prod-alert-item[data-id="${id}"]`);
    if (el) {
        el.style.transition = 'opacity 0.3s, transform 0.3s';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        setTimeout(() => {
            el.remove();
            const banner = document.getElementById('smartAlertsBanner');
            if (banner && !banner.children.length) banner.style.display = 'none';
        }, 300);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ⑧ TIMESHEET APPROVALS (Managers)
// ─────────────────────────────────────────────────────────────────────────────
async function prodLoadTsApprovals() {
    const el = document.getElementById('prodTsApprovals');
    if (!el) return;
    const parentSection = document.getElementById('prodTsApprovalsSection');
    const res = await prodApi('pending_timesheets');
    if (!res.success) {
        if (parentSection) parentSection.style.display = 'none';
        el.innerHTML = `<div class="prod-feed-empty"><p>${res.error === 'Access denied' ? 'This section is for managers only.' : 'Could not load timesheets.'}</p></div>`;
        return;
    }
    if (parentSection) parentSection.style.display = 'block';
    const items = res.data.timesheets;
    if (!items.length) {
        el.innerHTML = `<div class="prod-feed-empty"><i class="fas fa-check-circle" style="color:#10B981;"></i><p>All caught up! No pending timesheets.</p></div>`;
        return;
    }
    el.innerHTML = items.map(ts => {
        const hrs = parseFloat(ts.hours_logged || 0).toFixed(1);
        return `<div class="prod-approval-row" id="approval-ts-${ts.id}">
            <div class="prod-approval-info">
                <div class="prod-lb-avatar" style="width:40px;height:40px;font-size:16px;">${(ts.full_name||'?').charAt(0).toUpperCase()}</div>
                <div>
                    <strong>${escH(ts.full_name)}</strong>
                    <span>${escH(ts.designation || 'Employee')} * ${escH(ts.team || '')}</span>
                    <span class="prod-approval-dates"><i class="fas fa-calendar"></i> ${ts.week_start} → ${ts.week_end} * <i class="fas fa-clock"></i> ${hrs}h logged</span>
                </div>
            </div>
            <div class="prod-approval-actions">
                <textarea class="prod-approval-note" placeholder="Optional note..." id="note-ts-${ts.id}"></textarea>
                <div style="display:flex;gap:8px;">
                    <button class="prod-btn prod-btn-emerald" style="flex:1;padding:8px;" onclick="prodApproveTs(${ts.id},'approved')"><i class="fas fa-check"></i> Approve</button>
                    <button class="prod-btn" style="flex:1;padding:8px;background:rgba(239,68,68,0.15);color:#F87171;border:1px solid rgba(239,68,68,0.3);" onclick="prodApproveTs(${ts.id},'rejected')"><i class="fas fa-times"></i> Reject</button>
                </div>
            </div>
        </div>`;
    }).join('');
}

async function prodApproveTs(tsId, status) {
    const noteEl = document.getElementById(`note-ts-${tsId}`);
    const note = noteEl ? noteEl.value.trim() : '';
    const res = await prodApi('approve_timesheet', { timesheet_id: tsId, status, note });
    if (res.success) {
        const row = document.getElementById(`approval-ts-${tsId}`);
        if (row) {
            row.style.transition = 'all 0.4s';
            row.style.opacity = '0';
            row.style.transform = 'translateY(-10px)';
            setTimeout(() => { row.remove(); prodLoadFeed(); prodLoadTsApprovals(); }, 400);
        }
    } else alert(res.error || 'Action failed');
}

// ─────────────────────────────────────────────────────────────────────────────
// SHOW VIEW PATCH
// ─────────────────────────────────────────────────────────────────────────────
(function patchShowView() {
    const _orig = window.showView;
    if (typeof _orig !== 'function') return;
    window.showView = function (id, navId) {
        _orig(id, navId);
        if (id === 'feeds') {
            prodLoadFeed();
            prodLoadLeaderboard();
        }
        if (id === 'timelogs') {
            prodLoadStatus();
            prodLoadAnalytics();
        }
        if (id === 'timesheets') {
            prodLoadTimesheet();
            prodLoadTsApprovals();
        }
        if (id === 'dashboard') {
            prodLoadDashScore();
        }
    };
})();

// ─────────────────────────────────────────────────────────────────────────────
// BOOT
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    prodLoadStatus();
    prodLoadDashScore();
    prodCheckAlerts();
    prodLoadFeed();
    prodLoadLeaderboard();

    document.querySelectorAll('[data-view="feeds"], [data-nav-id="nav-tab-feeds"]').forEach(el => {
        el.addEventListener('click', () => {
            setTimeout(() => {
                prodLoadFeed();
                prodLoadLeaderboard();
            }, 50);
        });
    });

    document.getElementById('btnClockInOut')  ?.addEventListener('click', prodHandleClockInOut);
    document.getElementById('btnBreakToggle') ?.addEventListener('click', prodHandleBreak);
    document.getElementById('btnAddTsRow')    ?.addEventListener('click', prodAddTsRow);
    document.getElementById('btnSubmitTimesheet')?.addEventListener('click', prodSubmitTimesheet);

    // Auto-refresh feed every 60s, alerts every 5 min
    ProdState.feedInterval  = setInterval(prodLoadFeed,   60_000);
    ProdState.alertInterval = setInterval(prodCheckAlerts, 5 * 60_000);
});
