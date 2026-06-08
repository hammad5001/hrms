/* Balitech Employee Self Service Portal */
const HRMS = {
    user: null,
    payroll: null,
    attendance: [],
    leaveSummary: null,
    leaveRequests: [],
    selectedRoute: 'team_lead',
    selectedApprover: null,
    selectedEmployee: null,
    halfApprover: null,
    halfEmployee: null,
    canSelectEmployee: false,
    searchTimers: {},
    notifyTimer: null,
    weekOffset: 0,
    approvalFilter: 'pending',
    timerInterval: null,
    approvalModalRequest: null,
    approvalModalMode: 'approve'
};

const LEAVE_BALANCE_TYPES = [
    { key: 'casual', label: 'Casual Leave', icon: 'fa-calendar-days', quota: 12 },
    { key: 'sick', label: 'Sick Leave', icon: 'fa-notes-medical', quota: 10 },
    { key: 'annual', label: 'Annual Leave', icon: 'fa-umbrella-beach', quota: 14 },
    { key: 'emergency', label: 'On Duty', icon: 'fa-user-check', quota: 5 },
    { key: 'unpaid', label: 'Compensatory Off', icon: 'fa-clock-rotate-left', quota: 0 },
];

async function apiGet(url) {
    const r = await fetch(url, { credentials: 'include' });
    const text = await r.text();
    try { return JSON.parse(text); }
    catch {
        console.error('Invalid JSON from', url, text.slice(0, 200));
        return { success: false, message: 'Server returned invalid response' };
    }
}

async function apiPost(url, body) {
    const r = await fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    const text = await r.text();
    try { return JSON.parse(text); }
    catch { return { success: false, error: 'Server returned invalid response' }; }
}

function setText(id, value, fallback = '—') {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = (value != null && String(value).trim() !== '') ? value : fallback;
}

function toast(msg, type = 'success') {
    const el = document.getElementById('hrmsToast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'hrms-toast show ' + (type === 'error' ? 'error' : '');
    setTimeout(() => el.classList.remove('show'), 3500);
}

function formatMoney(n) {
    if (n == null || n === '') return '—';
    return 'Rs ' + Number(n).toLocaleString('en-PK', { maximumFractionDigits: 0 });
}

function formatDate(d) {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-PK', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatTime(ts) {
    if (!ts) return '—';
    return new Date(ts).toLocaleTimeString('en-PK', { hour: '2-digit', minute: '2-digit' });
}

function avatarUrlFromUser(u) {
    if (!u) return '';
    if (u.avatar_url) return u.avatar_url;
    if (u.chat_avatar) return 'uploads/chat/avatars/' + u.chat_avatar.replace(/^.*[\\/]/, '');
    return '';
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
}

function applyAvatarToEl(el, url, name) {
    if (!el) return;
    if (url) {
        el.innerHTML = `<img src="${url}" alt="">`;
    } else {
        el.innerHTML = `<span id="profileInitials">${initials(name)}</span>`;
    }
}

function greetingForNow() {
    const h = new Date().getHours();
    if (h < 12) return { text: 'Good Morning', icon: 'fa-sun', sub: 'Have a productive day!' };
    if (h < 17) return { text: 'Good Afternoon', icon: 'fa-cloud-sun', sub: 'Keep up the great work!' };
    return { text: 'Good Evening', icon: 'fa-moon', sub: 'Have a productive day!' };
}

function ensureChatFrameLoaded() {
    const frame = document.getElementById('essChatFrame');
    if (!frame || frame.dataset.loaded === '1') return;
    frame.src = 'chat-portal.html?embed=1';
    frame.dataset.loaded = '1';
}

function showView(id) {
    document.querySelectorAll('.view-section').forEach(v => v.classList.remove('active'));
    document.querySelectorAll('.ess-side-item[data-view], .ess-tab[data-view], .ess-header-link[data-view]').forEach(n => {
        if (n.dataset.view === id) n.classList.add('active');
        else n.classList.remove('active');
    });
    const view = document.getElementById('view-' + id);
    if (view) view.classList.add('active');

    const isChat = (id === 'chat');
    document.body.classList.toggle('ess-chat-active', isChat);
    if (isChat) ensureChatFrameLoaded();

    const titles = {
        dashboard: ['Dashboard', 'Employee Self Service overview'],
        attendance: ['Attendance', 'Punch history and weekly summary'],
        salary: ['Payroll', 'Salary, bonus, and compensation details'],
        leave: ['Leave Tracker', 'Balances and leave applications'],
        halfday: ['Half Day Leave', 'Apply morning or afternoon half day'],
        myleaves: ['My Leave Requests', 'Track status of your applications'],
        approvals: ['Leave Approvals', 'Review and approve team requests'],
        notifications: ['Notifications', 'Alerts from HR and managers'],
        chat: ['Workspace Chat', 'Messages with your team — secure internal chat']
    };
    const t = titles[id] || ['HRMS', ''];
    setText('pageTitle', t[0]);
    setText('pageSubtitle', t[1]);
    if (id === 'leave') { loadMyLeaves(); renderLeaveBalances(); }
    if (id === 'myleaves') loadMyLeaves(true);
    if (id === 'approvals') loadApprovals();
    if (id === 'notifications') loadNotifications();
    if (id === 'attendance') { renderAttendance(); renderWeekView(); }
    if (id === 'salary') renderSalary();
}

/** Night shift: check-in from 2 PM on shift date, check-out until noon next day. */
const ESS_SHIFT = {
    checkinHour: 14,
    shiftStartHour: 19,
    graceMin: 15,
    checkoutNoonHour: 12,
};

function shiftDateForTimestamp(ts) {
    if (!ts) return '';
    const d = new Date(ts);
    if (Number.isNaN(d.getTime())) return String(ts).slice(0, 10);
    const h = d.getHours();
    if (h >= ESS_SHIFT.checkinHour) return d.toISOString().slice(0, 10);
    if (h < ESS_SHIFT.checkoutNoonHour) {
        const prev = new Date(d);
        prev.setDate(prev.getDate() - 1);
        return prev.toISOString().slice(0, 10);
    }
    return d.toISOString().slice(0, 10);
}

function activeShiftDate() {
    const now = new Date();
    if (now.getHours() < ESS_SHIFT.checkoutNoonHour) {
        const prev = new Date(now);
        prev.setDate(prev.getDate() - 1);
        return prev.toISOString().slice(0, 10);
    }
    return now.toISOString().slice(0, 10);
}

function shiftWindows(shiftDateStr) {
    const next = new Date(shiftDateStr + 'T12:00:00');
    next.setDate(next.getDate() + 1);
    const nextStr = next.toISOString().slice(0, 10);
    return {
        checkinStart: new Date(shiftDateStr + 'T14:00:00').getTime(),
        checkinEnd: new Date(shiftDateStr + 'T23:59:59').getTime(),
        checkoutStart: new Date(nextStr + 'T00:00:00').getTime(),
        checkoutEnd: new Date(nextStr + 'T11:59:59').getTime(),
    };
}

function resolveShiftPunches(shiftDateStr, allTimestamps) {
    const w = shiftWindows(shiftDateStr);
    const checkins = [];
    const checkouts = [];
    allTimestamps.forEach(ts => {
        const t = new Date(ts).getTime();
        if (Number.isNaN(t)) return;
        if (t >= w.checkinStart && t <= w.checkinEnd) checkins.push(ts);
        else if (t >= w.checkoutStart && t <= w.checkoutEnd) checkouts.push(ts);
    });
    checkins.sort();
    checkouts.sort();
    return {
        checkIn: checkins[0] || null,
        checkOut: checkouts.length ? checkouts[checkouts.length - 1] : null,
        punches: [...checkins, ...checkouts].sort(),
    };
}

function punchesByShiftDate() {
    const map = {};
    HRMS.attendance.forEach(r => {
        const sd = r.shift_date || shiftDateForTimestamp(r.timestamp);
        if (!sd) return;
        if (!map[sd]) map[sd] = [];
        map[sd].push(r.timestamp);
    });
    return map;
}

function getWeekDates(offset = 0) {
    const now = new Date();
    const day = now.getDay();
    const monday = new Date(now);
    monday.setDate(now.getDate() - ((day + 6) % 7) + offset * 7);
    const dates = [];
    for (let i = 0; i < 7; i++) {
        const d = new Date(monday);
        d.setDate(monday.getDate() + i);
        dates.push(d.toISOString().slice(0, 10));
    }
    return dates;
}

function dayStatus(shiftDateStr) {
    const d = new Date(shiftDateStr + 'T12:00:00');
    const dow = d.getDay();
    if (dow === 0) return { status: 'weekend', label: 'Weekend', hours: '00:00 Hrs' };

    const allTs = (HRMS.attendance || []).map(r => r.timestamp).filter(Boolean);
    const shift = resolveShiftPunches(shiftDateStr, allTs);

    if (!shift.checkIn && !shift.checkOut) {
        return { status: 'absent', label: 'Absent', hours: '00:00 Hrs worked', punchCount: 0 };
    }

    let hrs = 0;
    if (shift.checkIn && shift.checkOut) {
        const start = new Date(shift.checkIn).getTime();
        let end = new Date(shift.checkOut).getTime();
        if (end < start) end += 86400000;
        hrs = Math.max(0, (end - start) / 3600000);
    }

    let late = false;
    if (shift.checkIn) {
        const graceEnd = new Date(shiftDateStr + 'T19:15:00').getTime();
        late = new Date(shift.checkIn).getTime() > graceEnd;
    }

    return {
        status: late ? 'late' : 'present',
        label: late ? 'Late' : 'Present',
        hours: hrs.toFixed(2) + ' Hrs worked',
        punchCount: shift.punches.length,
        checkIn: shift.checkIn,
        checkOut: shift.checkOut,
    };
}

function renderWeekView(targetId = 'attendanceWeekList', stripId = 'attendanceWeekStrip') {
    const list = document.getElementById(targetId);
    const strip = document.getElementById(stripId);
    const label = document.getElementById('attWeekLabel') || document.getElementById('weekRangeLabel');
    const dates = getWeekDates(HRMS.weekOffset);
    if (label && dates.length) {
        label.textContent = formatDate(dates[0]) + ' – ' + formatDate(dates[6]);
    }
    const html = dates.map(dateStr => {
        const d = new Date(dateStr + 'T12:00:00');
        const dayName = d.toLocaleDateString('en-PK', { weekday: 'short' });
        const dayNum = d.getDate();
        const st = dayStatus(dateStr);
        return `<div class="ess-week-day">
            <div><div class="day-label">${dayName} ${String(dayNum).padStart(2, '0')}</div></div>
            <div>
                <div class="ess-week-bar"><span class="${st.status}"></span></div>
                <div class="day-sub">${st.label}</div>
            </div>
            <div class="ess-week-hours">${st.hours}</div>
        </div>`;
    }).join('');
    if (list) list.innerHTML = html;
    if (strip) strip.innerHTML = html;
}

function calcTodayHours(checkIn, checkOut) {
    if (!checkIn) return 0;
    const start = new Date(checkIn).getTime();
    const end = checkOut ? new Date(checkOut).getTime() : Date.now();
    return Math.max(0, (end - start) / 3600000);
}

function updateLiveTimer(checkIn, checkOut) {
    if (HRMS.timerInterval) clearInterval(HRMS.timerInterval);
    const tick = () => {
        const el = document.getElementById('profileTimer');
        if (!el) return;
        if (!checkIn) {
            el.textContent = '00 : 00 : 00';
            return;
        }
        const start = new Date(checkIn).getTime();
        const end = checkOut ? new Date(checkOut).getTime() : Date.now();
        let secs = Math.max(0, Math.floor((end - start) / 1000));
        const h = String(Math.floor(secs / 3600)).padStart(2, '0');
        secs %= 3600;
        const m = String(Math.floor(secs / 60)).padStart(2, '0');
        const s = String(secs % 60).padStart(2, '0');
        el.textContent = `${h} : ${m} : ${s}`;
    };
    tick();
    if (checkIn && !checkOut) {
        HRMS.timerInterval = setInterval(tick, 1000);
    }
}

function applyProfileData(data) {
    const u = data.user || {};
    HRMS.user = u;
    HRMS.payroll = data.payroll || null;
    HRMS.attendance = data.attendance_raw || [];

    const roleNames = {
        user: 'EMPLOYEE', team_lead: 'TEAM LEAD', floor_manager: 'FLOOR MANAGER',
        data_entry: 'DATA ENTRY', dialer: 'DIALER', developer: 'DEVELOPER', admin: 'ADMINISTRATOR'
    };
    const pr = u.portal_role || 'user';

    setText('chipName', u.full_name, 'Employee');
    setText('chipRole', roleNames[pr] || pr.replace(/_/g, ' ').toUpperCase(), 'USER');
    setText('profileCardName', (u.employee_code ? u.employee_code + ' - ' : '') + (u.full_name || 'Employee'));
    setText('profName', u.full_name);
    setText('profEmail', u.email);
    setText('profEmpId', u.employee_code);
    setText('profDept', u.department);
    setText('profDesig', u.designation);
    setText('profTeam', u.team);
    setText('profBranch', u.branch || data.company_branch_label);
    setText('profPhone', u.phone);
    setText('profRole', roleNames[pr] || pr);
    setText('profBidSource', data.meta?.resolution_label || '—');

    const avUrl = avatarUrlFromUser(u);
    applyAvatarToEl(document.getElementById('profileCardAvatar'), avUrl, u.full_name);
    applyAvatarToEl(document.getElementById('topAvatar'), avUrl, u.full_name);

    const g = greetingForNow();
    setText('welcomeGreeting', g.text + ' ' + (u.full_name?.split(' ')[0] || ''));
    setText('welcomeSub', g.sub);
    const wIcon = document.getElementById('welcomeIcon');
    if (wIcon) wIcon.innerHTML = `<i class="fas ${g.icon}"></i>`;

    const sum = data.attendance_summary || {};
    setText('statPresent', String(sum.present_days ?? 0), '0');
    setText('statLate', String(sum.late_days ?? 0), '0');
    setText('statPunches', String(sum.total_punches ?? 0), '0');

    const today = data.today || {};
    const hasIn = !!today.check_in;
    const isLateToday = !!today.is_late;
    const shiftDate = today.date || activeShiftDate();
    const statusEl = document.getElementById('profileCardStatus');
    const dashStatus = document.getElementById('dashTodayStatus');
    const statusText = hasIn ? (isLateToday ? 'Late' : 'Present') : 'Absent';

    if (statusEl) {
        statusEl.textContent = statusText;
        statusEl.className = 'ess-status ' + (
            !hasIn ? 'absent' : (isLateToday ? 'late' : 'present')
        );
    }
    if (dashStatus) {
        const shiftLabel = today.calendar_date && today.date && today.calendar_date !== today.date
            ? `Shift ${formatDate(shiftDate)}`
            : 'Today';
        dashStatus.textContent = shiftLabel + ' ' + statusText;
        dashStatus.className = 'ess-pill ' + (hasIn ? (isLateToday ? 'late' : 'present') : 'absent');
    }

    setText('dashCheckIn', formatTime(today.check_in), '—');
    setText('dashCheckOut', formatTime(today.check_out), '—');
    setText('dashPunchCount', String(today.punch_count ?? 0), '0');
    const hrs = today.working_hours != null
        ? Number(today.working_hours)
        : calcTodayHours(today.check_in, today.check_out);
    setText('dashTotalHours', hrs.toFixed(2) + ' Hrs');
    setText('dashShift', (data.shift && data.shift.label) || 'Night · 2:00 PM check-in – next day 12:00 PM checkout');
    updateLiveTimer(today.check_in, today.check_out);

    if (data.meta && data.meta.employee_code_set === false) {
        toast('Ask HR to link your Employee ID (BID) for attendance & salary.', 'error');
    }

    renderSalary();
    renderAttendance();
    renderWeekView();
}

async function loadProfile() {
    try {
        let data = await apiGet('api/employee_self_service.php');
        if (!data.success) {
            const sess = await apiGet('api/check_session.php');
            if (!sess.success || !sess.authenticated) {
                window.location.href = 'index.html';
                return;
            }
            data = {
                success: true,
                user: { ...sess.user },
                attendance_raw: [],
                attendance_summary: { present_days: 0, late_days: 0, total_punches: 0 },
                today: {},
                payroll: { month: new Date().toISOString().slice(0, 7), has_data: false },
                company_branch_label: sess.user.company_branch_label,
                meta: {}
            };
            const retry = await apiGet('api/employee_self_service.php');
            if (retry.success) data = retry;
        }
        applyProfileData(data);

        const ls = await apiGet('api/leave_api.php?action=summary');
        if (ls.success) {
            HRMS.leaveSummary = ls.data;
            setText('statPendingLeave', String(ls.data.my_pending_leaves ?? 0), '0');
            HRMS.canSelectEmployee = !!ls.data.can_select_employee;
            toggleLeaveEmployeeFields();
            updateManagerApprovalUI(ls.data);
            ['navApprovals', 'badgeApprovals', 'headerApprovals', 'tabApprovals'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                if (ls.data.can_approve) {
                    el.classList.remove('hidden');
                    if (id === 'badgeApprovals' && ls.data.pending_approvals > 0) {
                        el.textContent = ls.data.pending_approvals;
                        el.classList.remove('hidden');
                    }
                }
            });
        }

        const leaves = await apiGet('api/leave_api.php?action=myRequests');
        if (leaves.success) {
            HRMS.leaveRequests = leaves.data || [];
            renderLeaveBalances();
        }
    } catch (err) {
        console.error(err);
        toast('Failed to load employee data. Refresh the page.', 'error');
    }
}

function renderSalary() {
    const p = HRMS.payroll;
    if (!p) return;
    setText('salBasic', formatMoney(p.basic_salary));
    setText('salBonus', formatMoney(p.bonus));
    setText('salTada', formatMoney(p.tada));
    setText('salAdvance', formatMoney(p.advance_per_month));
    setText('salLeaves', String(p.leaves_this_month ?? 0));
    setText('salBank', [p.bank_name, p.account_no].filter(Boolean).join(' · ') || '—');
    setText('salMonth', p.month || '—');
}

function getShiftDatesFromAttendance(days = 30) {
    const dates = new Set();
    (HRMS.attendance || []).forEach(r => {
        const sd = r.shift_date || shiftDateForTimestamp(r.timestamp);
        if (sd) dates.add(sd);
    });
    const today = new Date();
    for (let i = 0; i < days; i++) {
        const d = new Date(today);
        d.setDate(today.getDate() - i);
        dates.add(d.toISOString().slice(0, 10));
    }
    return [...dates].sort((a, b) => b.localeCompare(a)).slice(0, days);
}

function renderAttendance() {
    const tbody = document.getElementById('attendanceTableBody');
    if (!tbody) return;

    if (!HRMS.attendance || !HRMS.attendance.length) {
        tbody.innerHTML = '<tr><td colspan="5">No attendance records in the last 30 days</td></tr>';
        return;
    }

    const shiftDates = getShiftDatesFromAttendance(30);
    const rows = shiftDates.map(sd => {
        const st = dayStatus(sd);
        if (st.status === 'absent') return null;
        const statusLabel = st.status === 'late' ? 'Late' : 'Present';
        const hours = st.hours.replace(' worked', '');
        return `<tr>
            <td>${escHtml(formatDate(sd))}</td>
            <td>${st.checkIn ? formatTime(st.checkIn) : '—'}</td>
            <td>${st.checkOut ? formatTime(st.checkOut) : '—'}</td>
            <td>${escHtml(hours)}</td>
            <td><span class="ess-pill ${st.status}">${statusLabel}</span></td>
        </tr>`;
    }).filter(Boolean);

    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="5">No attendance records in the last 30 days</td></tr>';
        return;
    }

    tbody.innerHTML = rows.join('');
}

function renderLeaveBalances() {
    const grid = document.getElementById('leaveBalanceGrid');
    if (!grid) return;
    const year = new Date().getFullYear();
    const taken = {};
    (HRMS.leaveRequests || []).forEach(r => {
        if (r.status !== 'approved') return;
        if (!r.start_date || !r.start_date.startsWith(String(year))) return;
        const k = r.leave_type || 'casual';
        taken[k] = (taken[k] || 0) + 1;
    });
    grid.innerHTML = LEAVE_BALANCE_TYPES.map(t => {
        const used = taken[t.key] || 0;
        const avail = Math.max(0, t.quota - used);
        return `<div class="ess-leave-card">
            <i class="fas ${t.icon}"></i>
            <h4>${t.label}</h4>
            <p>Available: <strong>${avail}</strong></p>
            <p>Taken: <strong>${used}</strong></p>
        </div>`;
    }).join('');
}

function toggleLeaveEmployeeFields() {
    const show = HRMS.canSelectEmployee;
    ['leaveEmployeeField', 'halfLeaveEmployeeField'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('hidden', !show);
    });
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

function renderSelectedPerson(chipId, person, onClear) {
    const chip = document.getElementById(chipId);
    if (!chip) return;
    if (!person) {
        chip.classList.add('hidden');
        chip.innerHTML = '';
        return;
    }
    const meta = [person.role_label || person.portal_role, person.designation, person.team].filter(Boolean).join(' · ');
    chip.classList.remove('hidden');
    chip.innerHTML = `
        <span class="ess-person-chip">
            <i class="fas fa-user-check"></i>
            <span><strong>${escHtml(person.full_name)}</strong>${meta ? `<small>${escHtml(meta)}</small>` : ''}</span>
            <button type="button" class="ess-chip-clear" title="Clear" aria-label="Clear selection"><i class="fas fa-times"></i></button>
        </span>`;
    chip.querySelector('.ess-chip-clear')?.addEventListener('click', onClear);
}

async function searchLeavePeople(action, query) {
    if (!query || query.length < 2) return [];
    const res = await apiGet(`api/leave_api.php?action=${action}&q=${encodeURIComponent(query)}`);
    return res.success ? (res.data || []) : [];
}

function bindPersonSearch(inputId, resultsId, chipId, action, onSelect, onClear) {
    const input = document.getElementById(inputId);
    const results = document.getElementById(resultsId);
    if (!input || !results) return;

    const hideResults = () => results.classList.add('hidden');

    input.addEventListener('input', () => {
        const q = input.value.trim();
        clearTimeout(HRMS.searchTimers[inputId]);
        if (q.length < 2) {
            hideResults();
            results.innerHTML = '';
            return;
        }
        HRMS.searchTimers[inputId] = setTimeout(async () => {
            const rows = await searchLeavePeople(action, q);
            if (!rows.length) {
                results.innerHTML = '<div class="ess-search-empty">No matches found</div>';
            } else {
                results.innerHTML = rows.map(p => `
                    <button type="button" class="ess-search-item" data-id="${p.id}">
                        <strong>${escHtml(p.full_name)}</strong>
                        <small>${escHtml([p.role_label || p.portal_role, p.designation, p.employee_code].filter(Boolean).join(' · '))}</small>
                    </button>`).join('');
            }
            results.classList.remove('hidden');
            results.querySelectorAll('.ess-search-item').forEach(btn => {
                btn.addEventListener('click', () => {
                    const person = rows.find(r => String(r.id) === btn.dataset.id);
                    if (!person) return;
                    onSelect(person);
                    input.value = '';
                    hideResults();
                    renderSelectedPerson(chipId, person, onClear);
                });
            });
        }, 280);
    });

    input.addEventListener('blur', () => setTimeout(hideResults, 180));
}

function initLeaveForms() {
    bindPersonSearch('leaveApproverSearch', 'leaveApproverResults', 'leaveApproverSelected', 'searchApprovers',
        p => { HRMS.selectedApprover = p; },
        () => { HRMS.selectedApprover = null; renderSelectedPerson('leaveApproverSelected', null); }
    );
    bindPersonSearch('leaveEmployeeSearch', 'leaveEmployeeResults', 'leaveEmployeeSelected', 'searchEmployees',
        p => { HRMS.selectedEmployee = p; },
        () => { HRMS.selectedEmployee = null; renderSelectedPerson('leaveEmployeeSelected', null); }
    );
    bindPersonSearch('halfApproverSearch', 'halfApproverResults', 'halfApproverSelected', 'searchApprovers',
        p => { HRMS.halfApprover = p; },
        () => { HRMS.halfApprover = null; renderSelectedPerson('halfApproverSelected', null); }
    );
    bindPersonSearch('halfEmployeeSearch', 'halfEmployeeResults', 'halfEmployeeSelected', 'searchEmployees',
        p => { HRMS.halfEmployee = p; },
        () => { HRMS.halfEmployee = null; renderSelectedPerson('halfEmployeeSelected', null); }
    );

    document.getElementById('formFullLeave')?.addEventListener('submit', async e => {
        e.preventDefault();
        await submitLeave('full_day', 'formFullLeave');
    });
    document.getElementById('formHalfLeave')?.addEventListener('submit', async e => {
        e.preventDefault();
        await submitLeave('half_day', 'formHalfLeave');
    });
}

async function submitLeave(durationType, formId) {
    const form = document.getElementById(formId);
    const fd = new FormData(form);
    const start = fd.get('start_date');
    const approver = durationType === 'half_day' ? HRMS.halfApprover : HRMS.selectedApprover;
    const employee = durationType === 'half_day' ? HRMS.halfEmployee : HRMS.selectedEmployee;

    if (!approver?.id) {
        toast('Please search and select an approver (Admin, Team Lead, or HR)', 'error');
        return;
    }

    const payload = {
        leave_type: fd.get('leave_type') || 'annual',
        duration_type: durationType,
        start_date: start,
        end_date: durationType === 'half_day' ? start : (fd.get('end_date') || start),
        half_day_slot: fd.get('half_day_slot'),
        reason: fd.get('reason'),
        approver_user_id: approver.id,
        apply_through: 'team_lead'
    };
    if (employee?.id && HRMS.canSelectEmployee) {
        payload.for_user_id = employee.id;
    }
    const res = await apiPost('api/leave_api.php?action=apply', payload);
    if (res.success) {
        toast('Leave request submitted successfully');
        form.reset();
        if (durationType === 'half_day') {
            HRMS.halfApprover = null;
            HRMS.halfEmployee = null;
            renderSelectedPerson('halfApproverSelected', null);
            renderSelectedPerson('halfEmployeeSelected', null);
        } else {
            HRMS.selectedApprover = null;
            HRMS.selectedEmployee = null;
            renderSelectedPerson('leaveApproverSelected', null);
            renderSelectedPerson('leaveEmployeeSelected', null);
        }
        await loadProfile();
        showView('leave');
    } else {
        toast(res.error || 'Failed to submit', 'error');
    }
}

function renderLeaveRows(data, tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    if (!data.length) {
        tbody.innerHTML = '<tr><td colspan="6">No leave applications found yet.</td></tr>';
        return;
    }
    tbody.innerHTML = data.map(r => `<tr>
        <td>${r.duration_type === 'half_day' ? 'Half (' + (r.half_day_slot || '') + ')' : 'Full'}</td>
        <td>${r.leave_type}</td>
        <td>${formatDate(r.start_date)}${r.end_date !== r.start_date ? ' – ' + formatDate(r.end_date) : ''}</td>
        <td>${escHtml(r.approver_name || (r.apply_through || '').replace(/_/g, ' '))}</td>
        <td><span class="status-pill ${r.status}">${r.status}</span></td>
        <td>${new Date(r.created_at).toLocaleString()}</td>
    </tr>`).join('');
}

async function loadMyLeaves(tabOnly = false) {
    const res = await apiGet('api/leave_api.php?action=myRequests');
    if (!res.success) return;
    HRMS.leaveRequests = res.data || [];
    renderLeaveRows(res.data, 'myLeavesBody');
    renderLeaveRows(res.data, 'myLeavesBodyTab');
    if (!tabOnly) renderLeaveBalances();
}

function updateManagerApprovalUI(summary) {
    const pending = summary?.pending_approvals ?? 0;
    const canApprove = !!summary?.can_approve;
    const panel = document.getElementById('managerPanel');
    const statWrap = document.getElementById('statTeamPendingWrap');
    if (panel) panel.classList.toggle('hidden', !canApprove);
    if (statWrap) statWrap.classList.toggle('hidden', !canApprove);
    setText('managerPendingCount', String(pending), '0');
    setText('statTeamPending', String(pending), '0');
}

function leaveTypeLabel(type) {
    const map = { casual: 'Casual', sick: 'Sick', annual: 'Annual', emergency: 'On Duty', unpaid: 'Comp Off' };
    return map[type] || (type || 'Leave').replace(/_/g, ' ');
}

function durationLabel(r) {
    if (r.duration_type === 'half_day') {
        return 'Half day' + (r.half_day_slot ? ' (' + r.half_day_slot + ')' : '');
    }
    return 'Full day';
}

function renderApprovalCard(r) {
    const name = r.employee_name || r.full_name || 'Employee';
    const isPending = r.status === 'pending';
    return `
        <article class="ess-approval-card">
            <div class="ess-approval-card-head">
                <div class="ess-approval-employee">
                    <div class="ess-approval-avatar">${initials(name)}</div>
                    <div>
                        <h4>${escHtml(name)}</h4>
                        <small>${escHtml(r.employee_code || '—')} · ${escHtml(r.team || '—')} · ${escHtml(r.department || '—')}</small>
                    </div>
                </div>
                <span class="status-pill ${r.status}">${r.status}</span>
            </div>
            <div class="ess-approval-meta">
                <div><span>Leave type</span><strong>${escHtml(leaveTypeLabel(r.leave_type))}</strong></div>
                <div><span>Duration</span><strong>${durationLabel(r)}</strong></div>
                <div><span>Dates</span><strong>${formatDate(r.start_date)}${r.end_date !== r.start_date ? ' – ' + formatDate(r.end_date) : ''}</strong></div>
                <div><span>Applied</span><strong>${r.created_at ? new Date(r.created_at).toLocaleString() : '—'}</strong></div>
            </div>
            ${r.reason ? `<div class="ess-approval-reason"><i class="fas fa-quote-left"></i> ${escHtml(r.reason)}</div>` : ''}
            ${r.approver_name ? `<small style="color:var(--ess-muted)">Routed to: <strong>${escHtml(r.approver_name)}</strong></small>` : ''}
            ${isPending ? `
            <div class="ess-approval-actions">
                <button type="button" class="ess-btn ess-btn-outline" onclick="HRMS.openApprovalModal(${r.id}, false)"><i class="fas fa-times"></i> Reject</button>
                <button type="button" class="ess-btn ess-btn-primary" onclick="HRMS.openApprovalModal(${r.id}, true)"><i class="fas fa-check"></i> Approve</button>
            </div>` : ''}
        </article>`;
}

async function loadApprovals() {
    const wrap = document.getElementById('approvalsList');
    if (!wrap) return;
    let rows = [];
    if (HRMS.approvalFilter === 'pending') {
        const res = await apiGet('api/leave_api.php?action=pendingApprovals');
        if (!res.success) return;
        rows = res.data || [];
    } else {
        const status = HRMS.approvalFilter === 'all' ? 'all' : HRMS.approvalFilter;
        const res = await apiGet('api/leave_api.php?action=approvalHistory&status=' + encodeURIComponent(status));
        if (!res.success) return;
        rows = res.data || [];
    }
    HRMS._approvalRows = rows;
    if (!rows.length) {
        wrap.innerHTML = `<div class="ess-approval-empty"><i class="fas fa-inbox"></i><p>No leave requests in this tab.</p></div>`;
        return;
    }
    wrap.innerHTML = rows.map(renderApprovalCard).join('');
}

function openApprovalModal(id, approve) {
    const row = (HRMS._approvalRows || []).find(r => parseInt(r.id, 10) === parseInt(id, 10));
    HRMS.approvalModalRequest = row || { id };
    HRMS.approvalModalMode = approve ? 'approve' : 'reject';
    const modal = document.getElementById('approvalModal');
    const body = document.getElementById('approvalModalBody');
    const note = document.getElementById('approvalModalNote');
    const title = document.getElementById('approvalModalTitle');
    const reqLabel = document.getElementById('approvalNoteRequired');
    const btnApprove = document.getElementById('approvalModalApprove');
    const btnReject = document.getElementById('approvalModalReject');
    if (!modal || !body) return;
    const name = row?.employee_name || row?.full_name || 'this employee';
    title.textContent = approve ? 'Approve leave request' : 'Reject leave request';
    body.innerHTML = row ? `
        <p><strong>${escHtml(name)}</strong> · ${escHtml(row.employee_code || '')}</p>
        <p>${durationLabel(row)} · ${formatDate(row.start_date)}${row.end_date !== row.start_date ? ' – ' + formatDate(row.end_date) : ''}</p>
    ` : '<p>Confirm your decision for this leave request.</p>';
    if (note) note.value = '';
    if (reqLabel) reqLabel.classList.toggle('hidden', approve);
    if (btnApprove) btnApprove.classList.toggle('hidden', !approve);
    if (btnReject) btnReject.classList.toggle('hidden', approve);
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
}

function closeApprovalModal() {
    const modal = document.getElementById('approvalModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    HRMS.approvalModalRequest = null;
}

async function submitApprovalModal(approve) {
    const req = HRMS.approvalModalRequest;
    if (!req?.id) return;
    const noteEl = document.getElementById('approvalModalNote');
    const note = (noteEl?.value || '').trim();
    if (!approve && !note) {
        toast('Please provide a rejection reason', 'error');
        noteEl?.focus();
        return;
    }
    const res = await apiPost(`api/leave_api.php?action=${approve ? 'approve' : 'reject'}`, { leave_id: req.id, note });
    toast(res.error || (approve ? 'Leave approved' : 'Leave rejected'), res.success ? 'success' : 'error');
    if (res.success) {
        closeApprovalModal();
        loadApprovals();
        loadProfile();
    }
}

async function approveLeave(id, approve) {
    openApprovalModal(id, approve);
}

async function loadNotifications() {
    const res = await apiGet('api/leave_api.php?action=notifications');
    const list = document.getElementById('notifList');
    if (!res.success || !list) return;
    const items = res.data.items || [];
    if (!items.length) {
        list.innerHTML = '<p class="ess-muted-line">No notifications</p>';
        return;
    }
    list.innerHTML = items.map(n => `
        <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}">
            <strong>${n.title}</strong><br>
            <span style="font-size:12px;color:var(--ess-muted)">${n.message}</span><br>
            <small>${n.created_at}</small>
        </div>
    `).join('');
    await apiPost('api/leave_api.php?action=markNotificationsRead', {});
    document.getElementById('badgeNotif')?.classList.add('hidden');
}

async function pollNotifications() {
    const res = await apiGet('api/leave_api.php?action=notifications');
    if (res.success && res.data.unread > 0) {
        const b = document.getElementById('badgeNotif');
        if (b) {
            b.textContent = res.data.unread;
            b.classList.remove('hidden');
        }
    }
}

async function uploadProfilePhoto(file) {
    if (!file || !file.type.startsWith('image/')) {
        toast('Choose a JPG, PNG, GIF, or WEBP image', 'error');
        return;
    }
    const fd = new FormData();
    fd.append('file', file);
    const r = await fetch('api/chat_api.php?action=uploadProfilePhoto', {
        method: 'POST',
        credentials: 'include',
        body: fd
    });
    let res;
    try { res = await r.json(); } catch { res = { success: false }; }
    if (!res.success) {
        toast(res.error || 'Could not upload photo', 'error');
        return;
    }
    if (HRMS.user) {
        HRMS.user.avatar_url = res.data.avatar_url;
        HRMS.user.chat_avatar = res.data.avatar_url?.split('/').pop();
    }
    applyAvatarToEl(document.getElementById('profileCardAvatar'), res.data.avatar_url, HRMS.user?.full_name);
    applyAvatarToEl(document.getElementById('topAvatar'), res.data.avatar_url, HRMS.user?.full_name);
    toast('Profile photo updated');
}

function bindNavigation() {
    document.querySelectorAll('[data-view]').forEach(el => {
        el.addEventListener('click', () => {
            const v = el.dataset.view;
            if (v) showView(v);
        });
    });
    document.getElementById('btnNotifBell')?.addEventListener('click', () => showView('notifications'));
    document.getElementById('btnUserMenu')?.addEventListener('click', () => showView('dashboard'));
    document.getElementById('essBrandHome')?.addEventListener('click', (e) => {
        e.preventDefault();
        showView('dashboard');
    });

    document.getElementById('weekPrev')?.addEventListener('click', () => {
        HRMS.weekOffset--;
        renderWeekView();
    });
    document.getElementById('weekNext')?.addEventListener('click', () => {
        HRMS.weekOffset++;
        renderWeekView();
    });

    document.querySelectorAll('.ess-approval-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.ess-approval-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            HRMS.approvalFilter = tab.dataset.filter;
            loadApprovals();
        });
    });

    document.getElementById('btnChangePhoto')?.addEventListener('click', () => {
        document.getElementById('profilePhotoInput')?.click();
    });
    document.getElementById('profilePhotoInput')?.addEventListener('change', e => {
        const f = e.target.files?.[0];
        e.target.value = '';
        if (f) uploadProfilePhoto(f);
    });

    document.getElementById('approvalModalClose')?.addEventListener('click', closeApprovalModal);
    document.getElementById('approvalModalCancel')?.addEventListener('click', closeApprovalModal);
    document.getElementById('approvalModalApprove')?.addEventListener('click', () => submitApprovalModal(true));
    document.getElementById('approvalModalReject')?.addEventListener('click', () => submitApprovalModal(false));
    document.getElementById('approvalModal')?.addEventListener('click', e => {
        if (e.target.id === 'approvalModal') closeApprovalModal();
    });
}

function setupWorkPortalLink() {
    const link = document.getElementById('essBackToWork');
    if (!link) return;
    const role = HRMS.user?.portal_role || localStorage.getItem('userRole');
    const workUrl = (window.isSuperAdminRole && window.isSuperAdminRole(role))
        ? (window.WORK_PORTAL_URLS?.super_admin || 'admin-dashboard.html')
        : (window.workPortalUrlForRole ? window.workPortalUrlForRole(role) : null);
    const separate = window.hasSeparateWorkPortal
        ? window.hasSeparateWorkPortal(role)
        : (workUrl && workUrl !== 'employee-portal.html');
    if (!separate || !workUrl) {
        link.classList.add('hidden');
        return;
    }
    link.href = workUrl;
    link.classList.remove('hidden');
}

document.addEventListener('DOMContentLoaded', () => {
    bindNavigation();
    initLeaveForms();
    loadProfile().then(() => setupWorkPortalLink());
    HRMS.notifyTimer = setInterval(pollNotifications, 20000);
    if (location.hash === '#chat') showView('chat');
});

window.HRMS = HRMS;
window.HRMS.approveLeave = approveLeave;
window.HRMS.openApprovalModal = openApprovalModal;
window.HRMS.closeApprovalModal = closeApprovalModal;
window.showView = showView;
