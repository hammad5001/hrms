/* Balitech Employee HRMS Portal */
const HRMS = {
    user: null,
    payroll: null,
    attendance: [],
    leaveSummary: null,
    selectedRoute: 'team_lead',
    notifyTimer: null
};

async function apiGet(url) {
    const r = await fetch(url, { credentials: 'include' });
    const text = await r.text();
    try {
        return JSON.parse(text);
    } catch {
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
    try {
        return JSON.parse(text);
    } catch {
        return { success: false, error: 'Server returned invalid response' };
    }
}

function setText(id, value, fallback = '—') {
    const el = document.getElementById(id);
    if (!el) return;
    const v = value != null && String(value).trim() !== '' ? value : fallback;
    el.textContent = v;
}

function toast(msg, type = 'success') {
    const el = document.getElementById('hrmsToast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'hrms-toast show ' + type;
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

function showView(id) {
    document.querySelectorAll('.view-section').forEach(v => v.classList.remove('active'));
    document.querySelectorAll('.hrms-nav-item').forEach(n => n.classList.remove('active'));
    const view = document.getElementById('view-' + id);
    if (view) view.classList.add('active');
    const nav = document.querySelector(`[data-view="${id}"]`);
    if (nav) nav.classList.add('active');
    const titles = {
        dashboard: ['Dashboard', 'Overview of your HR workspace'],
        attendance: ['Attendance', 'Punch history and monthly summary'],
        salary: ['Salary & Payroll', 'Current month compensation details'],
        leave: ['Apply Leave', 'Submit full-day leave to your manager'],
        halfday: ['Half Day Leave', 'Apply morning or afternoon half day'],
        myleaves: ['My Leave Requests', 'Track status of your applications'],
        approvals: ['Team Approvals', 'Review and approve leave requests'],
        notifications: ['Notifications', 'Alerts from HR and managers']
    };
    const t = titles[id] || ['HRMS', ''];
    document.getElementById('pageTitle').textContent = t[0];
    document.getElementById('pageSubtitle').textContent = t[1];
    if (id === 'myleaves') loadMyLeaves();
    if (id === 'approvals') loadApprovals();
    if (id === 'notifications') loadNotifications();
    if (id === 'attendance') renderAttendance();
    if (id === 'salary') renderSalary();
}

function applyProfileData(data) {
    const u = data.user || {};
    HRMS.user = u;
    HRMS.payroll = data.payroll || null;
    HRMS.attendance = data.attendance_raw || [];

    setText('chipName', u.full_name, 'Employee');
    const roleNames = {
        user: 'EMPLOYEE', team_lead: 'TEAM LEAD', floor_manager: 'FLOOR MANAGER',
        data_entry: 'DATA ENTRY', dialer: 'DIALER', developer: 'DEVELOPER'
    };
    const pr = u.portal_role || 'user';
    setText('chipRole', roleNames[pr] || pr.replace(/_/g, ' ').toUpperCase(), 'USER');
    setText('profName', u.full_name);
    setText('profEmail', u.email);
    setText('profEmpId', u.employee_code);
    setText('profDept', u.department);
    setText('profDesig', u.designation);
    setText('profTeam', u.team);
    setText('profBranch', u.branch || data.company_branch_label);

    const sum = data.attendance_summary || {};
    setText('statPresent', String(sum.present_days ?? 0), '0');
    setText('statLate', String(sum.late_days ?? 0), '0');
    setText('statPunches', String(sum.total_punches ?? 0), '0');

    if (data.today) {
        setText('dashCheckIn', formatTime(data.today.check_in), '—');
        setText('dashCheckOut', formatTime(data.today.check_out), '—');
    }

    if (data.meta && data.meta.employee_code_set === false) {
        toast('Ask HR to link your Employee ID (BID) to your account for attendance & salary.', 'error');
    }

    renderSalary();
    renderAttendance();
}

async function loadProfile() {
    try {
        let data = await apiGet('api/employee_self_service.php');

        if (!data.success) {
            const sess = await apiGet('api/check_session.php');
            if (!sess.success || !sess.authenticated) {
                window.location.href = 'user-login.html';
                return;
            }
            data = {
                success: true,
                user: {
                    full_name: sess.user.full_name,
                    email: sess.user.email,
                    portal_role: sess.user.portal_role,
                    employee_code: sess.user.employee_code,
                    department: sess.user.department,
                    designation: sess.user.designation,
                    team: sess.user.team || '',
                    branch: sess.user.branch || ''
                },
                attendance_raw: [],
                attendance_summary: { present_days: 0, late_days: 0, total_punches: 0 },
                today: {},
                payroll: { month: new Date().toISOString().slice(0, 7), has_data: false },
                company_branch_label: sess.user.company_branch_label
            };
            const retry = await apiGet('api/employee_self_service.php');
            if (retry.success) {
                data = retry;
            } else if (data.user.employee_code) {
                const details = await apiGet('get_user_details.php?id=' + encodeURIComponent(sess.user.id));
                if (details.success) {
                    data.attendance_raw = details.attendance_raw || [];
                    data.attendance_summary = {
                        present_days: 0,
                        late_days: 0,
                        total_punches: (details.attendance_raw || []).length
                    };
                }
            }
            if (!data.success && !data.user?.full_name) {
                toast(data.message || 'Could not load your profile. Try logging in again.', 'error');
                return;
            }
        }

        applyProfileData(data);

        const ls = await apiGet('api/leave_api.php?action=summary');
        if (ls.success) {
            HRMS.leaveSummary = ls.data;
            setText('statPendingLeave', String(ls.data.my_pending_leaves ?? 0), '0');
            const apprNav = document.getElementById('navApprovals');
            const apprBadge = document.getElementById('badgeApprovals');
            if (ls.data.can_approve && apprNav) {
                apprNav.classList.remove('hidden');
                if (ls.data.pending_approvals > 0 && apprBadge) {
                    apprBadge.textContent = ls.data.pending_approvals;
                    apprBadge.classList.remove('hidden');
                }
            }
        }
    } catch (err) {
        console.error(err);
        toast('Failed to load HR data. Check your connection and refresh.', 'error');
    }
}

function renderSalary() {
    const p = HRMS.payroll;
    if (!p) return;
    document.getElementById('salBasic').textContent = formatMoney(p.basic_salary);
    document.getElementById('salBonus').textContent = formatMoney(p.bonus);
    document.getElementById('salTada').textContent = formatMoney(p.tada);
    document.getElementById('salAdvance').textContent = formatMoney(p.advance_per_month);
    document.getElementById('salLeaves').textContent = String(p.leaves_this_month ?? 0);
    document.getElementById('salBank').textContent = [p.bank_name, p.account_no].filter(Boolean).join(' · ') || '—';
    document.getElementById('salMonth').textContent = p.month || '—';
}

function renderAttendance() {
    const tbody = document.getElementById('attendanceTableBody');
    if (!tbody) return;
    const rows = [...HRMS.attendance].reverse().slice(0, 60);
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="2">No attendance records in the last 30 days</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => `<tr><td>${r.timestamp}</td><td>${formatTime(r.timestamp)}</td></tr>`).join('');
}

function initLeaveForms() {
    document.querySelectorAll('.route-card').forEach(card => {
        card.addEventListener('click', () => {
            document.querySelectorAll('.route-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            HRMS.selectedRoute = card.dataset.route;
        });
    });
    document.querySelector('.route-card[data-route="team_lead"]')?.classList.add('selected');

    document.getElementById('leaveDuration')?.addEventListener('change', e => {
        const half = document.getElementById('halfDayFields');
        if (half) half.classList.toggle('hidden', e.target.value !== 'half_day');
        const end = document.getElementById('leaveEndDate');
        if (end && e.target.value === 'half_day') end.disabled = true;
        else if (end) end.disabled = false;
    });

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
    const payload = {
        leave_type: fd.get('leave_type') || 'annual',
        duration_type: durationType,
        start_date: start,
        end_date: durationType === 'half_day' ? start : (fd.get('end_date') || start),
        half_day_slot: fd.get('half_day_slot'),
        reason: fd.get('reason'),
        apply_through: durationType === 'half_day' ? (fd.get('apply_through') || HRMS.selectedRoute) : HRMS.selectedRoute
    };
    const res = await apiPost('api/leave_api.php?action=apply', payload);
    if (res.success) {
        toast(res.error || 'Leave request submitted successfully');
        form.reset();
        showView('myleaves');
    } else {
        toast(res.error || 'Failed to submit', 'error');
    }
}

async function loadMyLeaves() {
    const res = await apiGet('api/leave_api.php?action=myRequests');
    const tbody = document.getElementById('myLeavesBody');
    if (!res.success || !tbody) return;
    if (!res.data.length) {
        tbody.innerHTML = '<tr><td colspan="6">No leave requests yet</td></tr>';
        return;
    }
    tbody.innerHTML = res.data.map(r => `<tr>
        <td>${r.duration_type === 'half_day' ? 'Half (' + (r.half_day_slot || '') + ')' : 'Full'}</td>
        <td>${r.leave_type}</td>
        <td>${formatDate(r.start_date)}${r.end_date !== r.start_date ? ' – ' + formatDate(r.end_date) : ''}</td>
        <td>${r.apply_through.replace('_', ' ')}</td>
        <td><span class="status-pill ${r.status}">${r.status}</span></td>
        <td>${new Date(r.created_at).toLocaleString()}</td>
    </tr>`).join('');
}

async function loadApprovals() {
    const res = await apiGet('api/leave_api.php?action=pendingApprovals');
    const wrap = document.getElementById('approvalsList');
    if (!res.success || !wrap) return;
    if (!res.data.length) {
        wrap.innerHTML = '<p style="color:var(--muted)">No pending requests for your approval.</p>';
        return;
    }
    wrap.innerHTML = res.data.map(r => `
        <div class="panel" style="margin-bottom:12px;padding:16px;">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <strong>${r.employee_name}</strong> <span style="color:var(--muted)">(${r.employee_code})</span><br>
                    <small>${r.team || '—'} · ${r.department || '—'}</small><br>
                    <span class="status-pill pending">${r.duration_type}</span>
                    ${formatDate(r.start_date)} – ${formatDate(r.end_date)} · via ${r.apply_through.replace('_', ' ')}<br>
                    <em style="color:var(--muted)">${r.reason}</em>
                </div>
                <div style="display:flex;gap:8px;align-items:flex-start;">
                    <button class="btn btn-success" onclick="HRMS.approveLeave(${r.id}, true)"><i class="fas fa-check"></i> Approve</button>
                    <button class="btn btn-danger" onclick="HRMS.approveLeave(${r.id}, false)"><i class="fas fa-times"></i> Reject</button>
                </div>
            </div>
        </div>
    `).join('');
}

async function approveLeave(id, approve) {
    const note = prompt(approve ? 'Approval note (optional):' : 'Rejection reason:') || '';
    const res = await apiPost(`api/leave_api.php?action=${approve ? 'approve' : 'reject'}`, { leave_id: id, note });
    toast(res.error || (approve ? 'Approved' : 'Rejected'), res.success ? 'success' : 'error');
    if (res.success) {
        loadApprovals();
        loadProfile();
    }
}

async function loadNotifications() {
    const res = await apiGet('api/leave_api.php?action=notifications');
    const list = document.getElementById('notifList');
    if (!res.success || !list) return;
    const items = res.data.items || [];
    if (!items.length) {
        list.innerHTML = '<p style="padding:16px;color:var(--muted)">No notifications</p>';
        return;
    }
    list.innerHTML = items.map(n => `
        <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}">
            <strong>${n.title}</strong><br>
            <span style="font-size:12px;color:var(--muted)">${n.message}</span><br>
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

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.hrms-nav-item[data-view]').forEach(btn => {
        btn.addEventListener('click', () => showView(btn.dataset.view));
    });
    initLeaveForms();
    loadProfile();
    HRMS.notifyTimer = setInterval(pollNotifications, 20000);
});

window.HRMS = HRMS;
window.HRMS.approveLeave = approveLeave;
window.showView = showView;
