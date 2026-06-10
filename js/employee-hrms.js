/* Balitech Employee Self Service Portal */
const HRMS = {
    user: null,
    payroll: null,
    attendance: [],
    leaveSummary: null,
    leaveRequests: [],
    policyAllotments: [],
    canAllotLeavePolicy: false,
    selectedPolicyEmployees: [],
    selectedRoute: 'team_lead',
    selectedApprover: null,
    selectedEmployee: null,
    halfApprover: null,
    halfEmployee: null,
    canSelectEmployee: false,
    searchTimers: {},
    notifyTimer: null,
    chatPollTimer: null,
    lastNotifUnread: null,
    lastChatUnread: null,
    weekOffset: 0,
    approvalFilter: 'pending',
    timerInterval: null,
    attendanceRefreshInterval: null,
    todayDuty: null,
    reporting: null,
    reporteesList: [],
    selectedReportee: null,
    selectedAssignReportee: null,
    selectedAssignManager: null,
    approvalModalRequest: null,
    approvalModalMode: 'approve',
    withdrawLeaveRequest: null,
    currentView: 'dashboard',
    activeNavId: 'nav-tab-activities',
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
    const d = parseAttendanceTs(ts);
    if (!d) return '—';
    return d.toLocaleTimeString('en-PK', { hour: '2-digit', minute: '2-digit' });
}

/** Parse MySQL / API timestamps reliably in the browser. */
function parseAttendanceTs(ts) {
    if (ts == null || ts === '') return null;
    const s = String(ts).trim();
    const m = s.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::(\d{2}))?/);
    if (m) {
        const sec = m[3] != null ? m[3] : '00';
        const d = new Date(`${m[1]}T${m[2]}:${sec}`);
        return Number.isNaN(d.getTime()) ? null : d;
    }
    const d = new Date(s);
    return Number.isNaN(d.getTime()) ? null : d;
}

function isOnDuty(checkIn, checkOut) {
    return !!checkIn && (checkOut == null || checkOut === '');
}

function shiftDeadlineUnix(shiftDateStr) {
    if (!shiftDateStr) return null;
    const next = addDaysToDateStr(shiftDateStr, 1);
    const d = new Date(next + 'T11:00:00');
    const t = d.getTime();
    return Number.isNaN(t) ? null : Math.floor(t / 1000);
}

function applyShiftConfig(shift) {
    if (!shift) return;
    if (shift.checkin_from) {
        const h = parseInt(String(shift.checkin_from).split(':')[0], 10);
        if (Number.isFinite(h)) ESS_SHIFT.checkinHour = h;
    }
    if (shift.shift_start) {
        const h = parseInt(String(shift.shift_start).split(':')[0], 10);
        if (Number.isFinite(h)) ESS_SHIFT.shiftStartHour = h;
    }
    if (shift.checkout_until) {
        const h = parseInt(String(shift.checkout_until).split(':')[0], 10);
        if (Number.isFinite(h)) ESS_SHIFT.checkoutEndHour = h;
    }
    if (shift.grace_minutes != null) ESS_SHIFT.graceMin = Number(shift.grace_minutes) || 15;
}

function setTodayDuty(today) {
    today = today || {};
    const timerActive = !!today.timer_active;
    const checkIn = today.duty_check_in || today.check_in || null;
    const checkOut = (timerActive || today.auto_closed)
        ? null
        : (today.duty_check_out ?? today.check_out ?? null);
    const checkInUnix = Number(today.duty_check_in_unix ?? today.check_in_unix);
    const checkOutUnix = Number(today.check_out_unix);
    const serverTs = Number(today.server_ts);
    const shiftDate = today.date || (checkIn ? shiftDateForTimestamp(checkIn) : activeShiftDate());

    HRMS.todayDuty = {
        checkIn,
        checkOut,
        timerActive,
        shiftDate,
        autoClosed: !!today.auto_closed,
        shiftDeadlineUnix: shiftDeadlineUnix(shiftDate),
        checkInUnix: Number.isFinite(checkInUnix) && checkInUnix > 0 ? checkInUnix : null,
        checkOutUnix: Number.isFinite(checkOutUnix) && checkOutUnix > 0 ? checkOutUnix : null,
        baseSeconds: Number.isFinite(Number(today.duty_seconds))
            ? Math.max(0, Math.floor(Number(today.duty_seconds)))
            : 0,
        serverTs: Number.isFinite(serverTs) && serverTs > 0 ? serverTs : null,
        syncedAt: Date.now(),
    };
}

function formatTimerDisplay(totalSecs) {
    const safe = Math.max(0, Math.floor(totalSecs));
    const h = String(Math.floor(safe / 3600)).padStart(2, '0');
    const m = String(Math.floor((safe % 3600) / 60)).padStart(2, '0');
    const s = String(safe % 60).padStart(2, '0');
    return `${h} : ${m} : ${s}`;
}

/**
 * Duty timer: first punch-in time (from machine fetch) → server now while on duty,
 * or → check-out punch when shift ended. Example: in 6:27 PM, now 6:40 PM = 13 min.
 */
function dutyElapsedSeconds(duty) {
    duty = duty || HRMS.todayDuty;
    if (!duty?.checkIn && !duty?.checkInUnix) return 0;

    const onDuty = duty.timerActive || isOnDuty(duty.checkIn, duty.checkOut);
    let startUnix = duty.checkInUnix;
    if (!startUnix && duty.checkIn) {
        const parsed = parseAttendanceTs(duty.checkIn);
        if (parsed) startUnix = Math.floor(parsed.getTime() / 1000);
    }
    if (!startUnix) {
        return onDuty
            ? Math.max(0, (duty.baseSeconds || 0) + Math.floor((Date.now() - (duty.syncedAt || Date.now())) / 1000))
            : Math.max(0, duty.baseSeconds || 0);
    }

    if (onDuty) {
        const drift = Math.floor((Date.now() - (duty.syncedAt || Date.now())) / 1000);
        let fromServer = Math.max(0, (duty.baseSeconds || 0) + drift);
        if (fromServer <= 0) {
            const clientNow = Math.floor(Date.now() / 1000);
            fromServer = Math.max(0, clientNow - startUnix);
            if (fromServer <= 0 && duty.serverTs) {
                const serverNow = duty.serverTs + (Date.now() - duty.syncedAt) / 1000;
                fromServer = Math.max(0, Math.floor(serverNow - startUnix));
            }
        }
        const deadline = duty.shiftDeadlineUnix || shiftDeadlineUnix(duty.shiftDate);
        if (deadline) {
            fromServer = Math.min(fromServer, Math.max(0, deadline - startUnix));
        }
        return fromServer;
    }

    let endUnix = duty.checkOutUnix;
    if (!endUnix && duty.checkOut) {
        const parsed = parseAttendanceTs(duty.checkOut);
        if (parsed) endUnix = Math.floor(parsed.getTime() / 1000);
    }
    if (endUnix) {
        if (endUnix < startUnix) endUnix += 86400;
        return Math.max(0, Math.floor(endUnix - startUnix));
    }

    return Math.max(0, duty.baseSeconds || 0);
}

function resolveTodayHours(today) {
    if (today) setTodayDuty(today);
    if (!HRMS.todayDuty?.checkIn) return 0;
    return dutyElapsedSeconds(HRMS.todayDuty) / 3600;
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

const PORTAL_ROLE_LABELS = {
    user: 'Employee',
    team_lead: 'Team Lead',
    floor_manager: 'Floor Manager',
    data_entry: 'Data Entry',
    dialer: 'Dialer',
    developer: 'Developer',
    admin: 'Administrator',
    super_admin: 'Super Admin',
    hr: 'HR',
    management: 'Management',
    receptionist: 'Receptionist',
};

function portalRoleLabel(role) {
    if (!role) return 'Employee';
    return PORTAL_ROLE_LABELS[role] || role.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function splitFullName(name) {
    const parts = (name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return { first: '—', last: '—' };
    return { first: parts[0], last: parts.slice(1).join(' ') || '—' };
}

function formatJoinedDate(d) {
    if (!d) return '—';
    const dt = new Date(d + (String(d).includes('T') ? '' : 'T12:00:00'));
    if (Number.isNaN(dt.getTime())) return d;
    return dt.toLocaleDateString('en-PK', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function setProfileInput(id, value, fallback = '') {
    const el = document.getElementById(id);
    if (!el) return;
    if (fallback === '—' && (value == null || String(value).trim() === '')) {
        el.value = '—';
        return;
    }
    el.value = (value != null && String(value).trim() !== '') ? value : fallback;
}

function setProfileTextarea(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    el.value = value != null ? String(value) : '';
}

function setProfileSelect(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    el.value = value != null ? String(value) : '';
}

function formatDateTime(ts) {
    if (!ts) return '—';
    const d = new Date(String(ts).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return ts;
    return d.toLocaleString('en-PK', {
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
    });
}

function collectEditableProfileData() {
    return {
        date_of_birth: document.getElementById('pfDob')?.value || '',
        marital_status: document.getElementById('pfMarital')?.value || '',
        expertise: document.getElementById('pfExpertise')?.value?.trim() || '',
        about_me: document.getElementById('pfAbout')?.value?.trim() || '',
        emergency_contact: document.getElementById('pfEmergency')?.value?.trim() || '',
        personal_mobile: document.getElementById('pfPersonalMobile')?.value?.trim() || '',
        extension: document.getElementById('pfExtension')?.value?.trim() || '',
        personal_email: document.getElementById('pfPersonalEmail')?.value?.trim() || '',
        present_address: document.getElementById('pfPresentAddr')?.value?.trim() || '',
        permanent_address: document.getElementById('pfPermanentAddr')?.value?.trim() || '',
    };
}

function renderProfilePage(data) {
    data = data || {};
    const u = data.user || HRMS.user || {};
    const pr = u.portal_role || 'user';
    const names = splitFullName(u.full_name);
    const manager = HRMS.reporting?.reporting_to;
    const managerLabel = manager
        ? [manager.full_name, manager.employee_code ? `(ID ${manager.employee_code})` : '', manager.role_label].filter(Boolean).join(' · ')
        : 'Not assigned — contact your team lead or HR';

    setText('profilePageName', u.full_name || 'Employee');
    setText('profilePageRole', [portalRoleLabel(pr), u.designation].filter(Boolean).join(' · '));
    setText('profilePageBid', u.employee_code || 'No ID');
    const statusEl = document.getElementById('profilePageStatus');
    if (statusEl) {
        const active = (u.status || 'active') === 'active';
        statusEl.textContent = active ? 'Active' : 'Inactive';
        statusEl.className = 'ess-pill ' + (active ? 'present' : 'absent');
    }

    setProfileInput('pfEmpId', u.employee_code);
    setProfileInput('pfEmail', u.email);
    setProfileInput('pfFirstName', names.first);
    setProfileInput('pfLastName', names.last);
    setProfileInput('pfDepartment', u.department);
    setProfileInput('pfPortalRole', portalRoleLabel(pr));
    setProfileInput('pfBranch', u.branch || data.company_branch_label);
    setProfileInput('pfTeam', u.team);
    setProfileInput('pfDesignation', u.designation);
    setProfileInput('pfEmpStatus', (u.status || 'active') === 'active' ? 'Active' : 'Inactive');
    setProfileInput('pfJoined', formatJoinedDate(u.joined_date));
    setProfileInput('pfBidSource', data.meta?.resolution_label || '—', '—');
    setProfileInput('pfManager', managerLabel, '—');

    const linkNote = document.getElementById('pfManagerLinkNote');
    const goBtn = document.getElementById('btnGoManageReportees');
    if (linkNote) linkNote.classList.remove('hidden');
    if (goBtn) {
        goBtn.textContent = HRMS.reporting?.is_manager ? 'Manage reportees' : 'View my reporting';
    }

    const pd = data.profile_details || HRMS.profileDetails || {};
    HRMS.profileDetails = pd;
    setProfileInput('pfDob', pd.date_of_birth || '');
    setProfileSelect('pfMarital', pd.marital_status || '');
    setProfileInput('pfExpertise', pd.expertise || '');
    setProfileTextarea('pfAbout', pd.about_me || '');
    setProfileInput('pfEmergency', pd.emergency_contact || '');
    setProfileInput('pfPersonalMobile', pd.personal_mobile || u.phone || '');
    setProfileInput('pfExtension', pd.extension || '');
    setProfileInput('pfPersonalEmail', pd.personal_email || '');
    setProfileTextarea('pfPresentAddr', pd.present_address || '');
    setProfileTextarea('pfPermanentAddr', pd.permanent_address || '');
    setProfileInput('pfAddedBy', pd.added_by_name || u.full_name || '—', '—');
    setProfileInput('pfModifiedBy', pd.modified_by_name || '—', '—');
    setProfileInput('pfAddedTime', formatDateTime(pd.created_at), '—');
    setProfileInput('pfModifiedTime', formatDateTime(pd.updated_at), '—');

    const note = document.getElementById('pfManagerLinkNote');
    if (note) note.classList.remove('hidden');

    const avUrl = avatarUrlFromUser(u);
    applyAvatarToEl(document.getElementById('profilePageAvatar'), avUrl, u.full_name);
}

async function saveProfile() {
    const btn = document.getElementById('btnSaveProfile');
    if (btn) btn.disabled = true;
    const payload = collectEditableProfileData();
    const res = await apiPost('api/employee_profile.php?action=save', payload);
    if (btn) btn.disabled = false;
    if (!res.success) {
        toast(res.message || 'Could not save profile', 'error');
        return;
    }
    HRMS.profileDetails = res.profile || HRMS.profileDetails;
    if (HRMS.user && res.phone != null) HRMS.user.phone = res.phone;
    setText('profPhone', res.phone || payload.personal_mobile || '—');
    renderProfilePage({
        user: HRMS.user,
        meta: HRMS.profileMeta,
        company_branch_label: HRMS.branchLabel,
        profile_details: res.profile,
    });
    toast('Profile saved successfully');
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

const ESS_DEFAULT_NAV = {
    dashboard: 'nav-tab-activities',
    attendance: 'nav-tab-attendance',
    salary: 'nav-side-payroll',
    leave: 'nav-tab-leave',
    halfday: 'nav-tab-leave',
    myleaves: 'nav-tab-myleaves',
    'leave-policy': 'nav-tab-leave-policy',
    approvals: 'nav-tab-approvals',
    reportees: 'nav-tab-reportees',
    profile: 'nav-tab-profile',
    notifications: 'nav-tab-notifications',
    chat: 'nav-side-chat',
    'coming-soon': null,
};

const ESS_VIEW_TITLES = {
    dashboard: ['Dashboard', 'Employee Self Service overview'],
    attendance: ['Attendance', 'Punch history and weekly summary'],
    salary: ['Payroll', 'Salary, bonus, and compensation details'],
    leave: ['Leave Tracker', 'Balances and leave applications'],
    halfday: ['Half Day Leave', 'Apply morning or afternoon half day'],
    myleaves: ['My Leave Requests', 'Track status of your applications'],
    'leave-policy': ['Leave Policy', 'Entitlements, holidays, and company allotments'],
    approvals: ['Leave Approvals', 'Review and approve team requests'],
    reportees: ['My Reporting', 'Your reporting line and team'],
    profile: ['My Profile', 'Your employee record and work information'],
    notifications: ['Notifications', 'Alerts from HR and managers'],
    chat: ['Workspace Chat', 'Messages with your team — secure internal chat'],
    'coming-soon': ['Coming soon', 'This module is under development'],
};

function setActiveNav(navId) {
    document.querySelectorAll('[data-nav-id]').forEach(n => {
        n.classList.toggle('active', !!navId && n.dataset.navId === navId);
    });
    HRMS.activeNavId = navId || null;
}

function showComingSoon(label, navId) {
    HRMS.currentView = 'coming-soon';
    setActiveNav(navId);
    document.querySelectorAll('.view-section').forEach(v => v.classList.remove('active'));
    document.getElementById('view-coming-soon')?.classList.add('active');
    document.body.classList.remove('ess-chat-active');

    const title = label ? `${label}` : 'This section';
    setText('comingSoonTitle', `Working on ${title}`);
    setText('comingSoonSubtitle', `${title} is not available yet. Our team is building it for a future release.`);
    const t = ESS_VIEW_TITLES['coming-soon'];
    setText('pageTitle', t[0]);
    setText('pageSubtitle', t[1]);
}

function navigateFromEl(el) {
    if (!el) return;
    const view = el.dataset.view;
    const navId = el.dataset.navId || null;
    if (!view) return;
    if (view === 'coming-soon') {
        showComingSoon(el.dataset.navLabel || 'This section', navId);
        return;
    }
    showView(view, navId);
}

function showView(id, navId = null) {
    if (id === 'coming-soon') {
        showComingSoon('This section', navId);
        return;
    }

    HRMS.currentView = id;
    navId = navId || ESS_DEFAULT_NAV[id] || null;
    setActiveNav(navId);

    document.querySelectorAll('.view-section').forEach(v => v.classList.remove('active'));
    const view = document.getElementById('view-' + id);
    if (view) view.classList.add('active');

    const isChat = (id === 'chat');
    const isDashboard = (id === 'dashboard');
    document.body.classList.toggle('ess-chat-active', isChat);
    document.body.classList.toggle('ess-dashboard-active', isDashboard);
    if (isChat) {
        ensureChatFrameLoaded();
    } else {
        document.getElementById('view-chat')?.classList.remove('active');
        pollChatUnread();
    }

    const t = ESS_VIEW_TITLES[id] || ['HRMS', ''];
    setText('pageTitle', t[0]);
    setText('pageSubtitle', t[1]);

    if (id === 'dashboard') {
        renderSidebarHierarchy(HRMS.reporting);
    } else {
        document.getElementById('hierarchyCard')?.classList.add('hidden');
    }
    if (id === 'leave') { loadMyLeaves(); renderLeaveBalances(); }
    if (id === 'myleaves') loadMyLeaves(true);
    if (id === 'leave-policy') loadLeavePolicy();
    if (id === 'approvals') loadApprovals();
    if (id === 'reportees') loadReporteesView();
    if (id === 'profile') {
        renderProfilePage({
            user: HRMS.user,
            meta: HRMS.profileMeta,
            company_branch_label: HRMS.branchLabel,
            profile_details: HRMS.profileDetails,
        });
    }
    if (id === 'notifications') loadNotifications();
    if (id === 'attendance') { renderAttendance(); renderWeekView(); }
    if (id === 'salary') renderSalary();
}

/** Night shift: 4 PM shift date → 11 AM next day (duty 6 PM – 4 AM). */
const ESS_SHIFT = {
    checkinHour: 16,
    shiftStartHour: 18,
    graceMin: 15,
    checkoutEndHour: 11,
};

/** Local calendar date YYYY-MM-DD (avoid UTC drift from toISOString). */
function localDateStr(d = new Date()) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function addDaysToDateStr(dateStr, days) {
    const d = new Date(dateStr + 'T12:00:00');
    d.setDate(d.getDate() + days);
    return localDateStr(d);
}

function shiftDateForTimestamp(ts) {
    if (!ts) return '';
    const d = parseAttendanceTs(ts) || new Date(ts);
    if (Number.isNaN(d.getTime())) return String(ts).slice(0, 10);
    const h = d.getHours();
    if (h >= ESS_SHIFT.checkinHour) return localDateStr(d);
    if (h < ESS_SHIFT.checkoutEndHour) {
        const prev = new Date(d);
        prev.setDate(prev.getDate() - 1);
        return localDateStr(prev);
    }
    return localDateStr(d);
}

function activeShiftDate() {
    const now = new Date();
    if (now.getHours() < ESS_SHIFT.checkoutEndHour) {
        const prev = new Date(now);
        prev.setDate(prev.getDate() - 1);
        return localDateStr(prev);
    }
    return localDateStr(now);
}

function shiftWindows(shiftDateStr) {
    const nextStr = addDaysToDateStr(shiftDateStr, 1);
    const checkinH = String(ESS_SHIFT.checkinHour).padStart(2, '0');
    return {
        checkinStart: new Date(shiftDateStr + `T${checkinH}:00:00`).getTime(),
        checkinEnd: new Date(shiftDateStr + 'T23:59:59').getTime(),
        checkoutStart: new Date(nextStr + 'T00:00:00').getTime(),
        checkoutEnd: new Date(nextStr + 'T11:00:00').getTime(),
    };
}

function isShiftDateUpcoming(shiftDateStr) {
    const active = activeShiftDate();
    if (shiftDateStr > active) return true;
    if (shiftDateStr < active) return false;
    const now = new Date();
    const checkinStart = new Date(shiftDateStr + `T${String(ESS_SHIFT.checkinHour).padStart(2, '0')}:00:00`);
    return now < checkinStart;
}

function dayStatusLabel(st) {
    const labels = {
        upcoming: 'Upcoming',
        absent: 'Absent',
        present: 'Present',
        late: 'Late',
        weekend: 'Weekend',
    };
    return labels[st?.status] || st?.label || 'Absent';
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
        dates.push(localDateStr(d));
    }
    return dates;
}

function formatDurationFromHours(hours) {
    const totalMin = Math.max(0, Math.round(hours * 60));
    const h = Math.floor(totalMin / 60);
    const m = totalMin % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function isWeekendShiftDate(shiftDateStr) {
    const d = new Date(shiftDateStr + 'T12:00:00');
    const dow = d.getDay();
    return dow === 0 || dow === 6;
}

function weekShiftScheduleLabel() {
    const h = ESS_SHIFT.checkinHour;
    const endH = ESS_SHIFT.checkoutEndHour;
    const start = new Date(`2000-01-01T${String(h).padStart(2, '0')}:00:00`).toLocaleTimeString('en-PK', { hour: '2-digit', minute: '2-digit' });
    const end = new Date(`2000-01-01T${String(endH).padStart(2, '0')}:00:00`).toLocaleTimeString('en-PK', { hour: '2-digit', minute: '2-digit' });
    return `Night shift · ${start} – next day ${end}`;
}

function weekTimeRange(st, shiftDateStr) {
    if (st.status === 'weekend') return 'Weekly off · no shift scheduled';
    if (st.status === 'upcoming') return weekShiftScheduleLabel();
    if (st.status === 'absent') return 'No check-in recorded for this shift';
    if (st.checkIn) {
        const inT = formatTime(st.checkIn);
        let outT;
        if (st.checkOut) {
            outT = formatTime(st.checkOut);
        } else if (shiftDateStr) {
            const deadline = shiftDeadlineUnix(shiftDateStr);
            const pastClose = deadline && Date.now() >= deadline * 1000;
            outT = pastClose ? '—' : 'Still on duty';
        } else {
            outT = 'Still on duty';
        }
        return `${inT}  →  ${outT}`;
    }
    return '—';
}

function dayStatus(shiftDateStr) {
    if (isShiftDateUpcoming(shiftDateStr)) {
        return {
            status: 'upcoming',
            label: 'Upcoming',
            hours: '00:00 Hrs',
            hoursShort: '00:00',
            punchCount: 0,
            checkIn: null,
            checkOut: null,
        };
    }

    const allTs = (HRMS.attendance || []).map(r => r.timestamp).filter(Boolean);
    const shift = resolveShiftPunches(shiftDateStr, allTs);

    if (!shift.checkIn && !shift.checkOut) {
        if (isWeekendShiftDate(shiftDateStr)) {
            return {
                status: 'weekend',
                label: 'Weekend',
                hours: '—',
                hoursShort: '—',
                punchCount: 0,
                checkIn: null,
                checkOut: null,
            };
        }
        return {
            status: 'absent',
            label: 'Absent',
            hours: '00:00 Hrs',
            hoursShort: '00:00',
            punchCount: 0,
            checkIn: null,
            checkOut: null,
        };
    }

    let hrs = 0;
    if (shift.checkIn && shift.checkOut) {
        const start = new Date(shift.checkIn).getTime();
        let end = new Date(shift.checkOut).getTime();
        if (end < start) end += 86400000;
        hrs = Math.max(0, (end - start) / 3600000);
    } else if (shift.checkIn && shiftDateStr === activeShiftDate()) {
        const start = new Date(shift.checkIn).getTime();
        let end = Date.now();
        const deadline = shiftDeadlineUnix(shiftDateStr);
        if (deadline) end = Math.min(end, deadline * 1000);
        if (end >= start) hrs = Math.max(0, (end - start) / 3600000);
    }

    let late = false;
    if (shift.checkIn) {
        const lateH = String(ESS_SHIFT.shiftStartHour).padStart(2, '0');
        const lateAfter = new Date(shiftDateStr + `T${lateH}:00:00`).getTime();
        late = new Date(shift.checkIn).getTime() > lateAfter;
    }

    const dur = formatDurationFromHours(hrs);
    return {
        status: late ? 'late' : 'present',
        label: late ? 'Late' : 'Present',
        hours: `${dur} Hrs`,
        hoursShort: dur,
        punchCount: shift.punches.length,
        checkIn: shift.checkIn,
        checkOut: shift.checkOut,
    };
}

function formatActivitiesDate(d) {
    if (!d) return '—';
    const dt = new Date(d + 'T12:00:00');
    const day = String(dt.getDate()).padStart(2, '0');
    const mon = dt.toLocaleDateString('en-GB', { month: 'short' });
    return `${day}-${mon}-${dt.getFullYear()}`;
}

function activitiesStatusLabel(st) {
    const map = {
        present: 'Present',
        late: 'Late',
        absent: 'Absent',
        upcoming: 'Upcoming',
        weekend: 'Weekend',
    };
    return map[st?.status] || st?.label || '—';
}

function buildActivitiesDayCell(dateStr) {
    const d = new Date(dateStr + 'T12:00:00');
    const dayName = d.toLocaleDateString('en-US', { weekday: 'short' });
    const dayNum = String(d.getDate()).padStart(2, '0');
    const st = dayStatus(dateStr);
    const isToday = dateStr === activeShiftDate();
    const status = activitiesStatusLabel(st);

    return `<div class="ess-act-day ess-act-day--${st.status}${isToday ? ' ess-act-day--today' : ''}" role="listitem" title="${escHtml(weekTimeRange(st, dateStr))}">
        <span class="ess-act-dow">${escHtml(dayName)}</span>
        <span class="ess-act-dom">${dayNum}</span>
        <span class="ess-act-status ess-act-status--${st.status}">${escHtml(status)}</span>
    </div>`;
}

function renderActivitiesTimeline() {
    const el = document.getElementById('activitiesTimeline');
    if (!el) return;
    const dates = getWeekDates(0);
    const label = document.getElementById('activitiesRangeLabel');
    if (label && dates.length) {
        label.textContent = `${formatActivitiesDate(dates[0])} - ${formatActivitiesDate(dates[6])}`;
    }
    el.innerHTML = dates.map(buildActivitiesDayCell).join('');
}

function buildWeekDayRow(dateStr) {
    const d = new Date(dateStr + 'T12:00:00');
    const dayName = d.toLocaleDateString('en-PK', { weekday: 'short' });
    const dayNum = String(d.getDate()).padStart(2, '0');
    const st = dayStatus(dateStr);
    const isToday = dateStr === activeShiftDate();
    const timeRange = weekTimeRange(st, dateStr);
    const hoursMain = st.hoursShort === '—' ? '—' : st.hoursShort;
    const hoursSub = st.status === 'weekend' ? 'Off day' : 'Hrs worked';

    return `<article class="ess-week-day ess-week-day--${st.status}${isToday ? ' ess-week-day--today' : ''}">
        <div class="ess-week-date">
            ${isToday ? '<span class="ess-week-today">Today</span>' : ''}
            <span class="ess-week-dow">${escHtml(dayName)}</span>
            <span class="ess-week-dom">${dayNum}</span>
        </div>
        <div class="ess-week-track">
            <div class="ess-week-line ess-week-line--${st.status}" role="presentation">
                <span class="ess-week-chip">${escHtml(st.label)}</span>
            </div>
            <p class="ess-week-times">${escHtml(timeRange)}</p>
        </div>
        <div class="ess-week-summary">
            <strong>${escHtml(hoursMain)}</strong>
            <span>${escHtml(hoursSub)}</span>
        </div>
    </article>`;
}

function renderWeekView(targetId = 'attendanceWeekList') {
    const list = document.getElementById(targetId);
    const label = document.getElementById('attWeekLabel');
    const dates = getWeekDates(HRMS.weekOffset);
    if (label && dates.length) {
        label.textContent = formatDate(dates[0]) + ' – ' + formatDate(dates[6]);
    }
    const header = `<div class="ess-week-head">
        <span>Day</span>
        <span>Shift status &amp; punch window</span>
        <span>Duration</span>
    </div>`;
    const rows = dates.map(buildWeekDayRow).join('');
    if (list) list.innerHTML = header + rows;
    renderActivitiesTimeline();
}

function updateLiveTimer() {
    if (HRMS.timerInterval) {
        clearInterval(HRMS.timerInterval);
        HRMS.timerInterval = null;
    }

    const tick = () => {
        const el = document.getElementById('profileTimer');
        if (!el) return;

        const duty = HRMS.todayDuty;
        if (!duty?.checkIn) {
            el.textContent = '00 : 00 : 00';
            el.classList.remove('running');
            return;
        }

        const onDuty = duty.timerActive || isOnDuty(duty.checkIn, duty.checkOut);
        const secs = dutyElapsedSeconds(duty);
        el.textContent = formatTimerDisplay(secs);
        el.classList.toggle('running', onDuty);

        const hrsEl = document.getElementById('dashTotalHours');
        if (hrsEl && onDuty) {
            hrsEl.textContent = (secs / 3600).toFixed(2) + ' Hrs';
        }
    };

    tick();
    const duty = HRMS.todayDuty;
    const onDuty = duty?.timerActive || isOnDuty(duty?.checkIn, duty?.checkOut);
    if (onDuty) {
        HRMS.timerInterval = setInterval(() => {
            const deadline = duty.shiftDeadlineUnix || shiftDeadlineUnix(duty.shiftDate);
            if (deadline && Math.floor(Date.now() / 1000) >= deadline) {
                clearInterval(HRMS.timerInterval);
                HRMS.timerInterval = null;
                apiGet('api/employee_self_service.php').then(refreshTodayAttendance);
                return;
            }
            tick();
        }, 1000);
    }
}

function resolveTodayStatus(today) {
    today = today || {};
    if (today.status) {
        return {
            status: today.status,
            label: today.status_label || dayStatusLabel({ status: today.status }),
        };
    }
    if (!today.check_in) {
        const shiftDate = today.date || activeShiftDate();
        if (isShiftDateUpcoming(shiftDate)) {
            return { status: 'upcoming', label: 'Upcoming' };
        }
        return { status: 'absent', label: 'Absent' };
    }
    if (today.is_late) {
        return { status: 'late', label: 'Late' };
    }
    return { status: 'present', label: 'Present' };
}

function applyTodayStatus(today) {
    today = today || {};
    const st = resolveTodayStatus(today);
    const hasIn = st.status === 'present' || st.status === 'late';
    const shiftDate = today.date || activeShiftDate();

    const statusEl = document.getElementById('profileCardStatus');
    if (statusEl) {
        statusEl.textContent = st.label;
        statusEl.className = 'ess-status ' + st.status;
    }

    const dashStatus = document.getElementById('dashTodayStatus');
    if (dashStatus) {
        const shiftLabel = today.calendar_date && today.date && today.calendar_date !== today.date
            ? `Shift ${formatDate(shiftDate)}`
            : 'Today';
        dashStatus.textContent = `${shiftLabel} ${st.label}`;
        dashStatus.className = 'ess-pill ' + st.status;
    }

    setText('dashCheckIn', formatTime(today.check_in), '—');
    setText('dashCheckOut', today.auto_closed ? '—' : formatTime(today.check_out), '—');
    setText('dashPunchCount', String(today.punch_count ?? 0), '0');
    setTodayDuty(today);
    setText('dashTotalHours', resolveTodayHours(today).toFixed(2) + ' Hrs');
    updateLiveTimer();

    return { st, hasIn };
}

function refreshTodayAttendance(data) {
    if (!data?.success) return;
    const wasOnDuty = HRMS.todayDuty?.timerActive;
    HRMS.attendance = data.attendance_raw || HRMS.attendance;
    applyTodayStatus(data.today || {});
    const today = data.today || {};
    if (today.auto_closed && wasOnDuty) {
        toast('Shift window ended at 11:00 AM — duty closed automatically.', 'info');
    }
    renderActivitiesTimeline();
    if (HRMS.reporting?.is_manager) {
        loadReportingHierarchy();
    }
}

function startAttendanceRefresh() {
    if (HRMS.attendanceRefreshInterval) clearInterval(HRMS.attendanceRefreshInterval);
    HRMS.attendanceRefreshInterval = setInterval(async () => {
        if (document.hidden) return;
        try {
            const data = await apiGet('api/employee_self_service.php');
            refreshTodayAttendance(data);
        } catch (err) {
            console.warn('Attendance refresh failed', err);
        }
    }, 30000);
}

function applyProfileData(data) {
    const u = data.user || {};
    HRMS.user = u;
    HRMS.payroll = data.payroll || null;
    HRMS.attendance = data.attendance_raw || [];
    applyShiftConfig(data.shift);

    const pr = u.portal_role || 'user';
    HRMS.profileMeta = data.meta || {};
    HRMS.branchLabel = data.company_branch_label;
    HRMS.profileDetails = data.profile_details || HRMS.profileDetails || {};

    setText('chipName', u.full_name, 'Employee');
    setText('chipRole', portalRoleLabel(pr).toUpperCase(), 'USER');
    setText('profileCardName', (u.employee_code ? u.employee_code + ' - ' : '') + (u.full_name || 'Employee'));
    setText('profName', u.full_name);
    setText('profEmail', u.email);
    setText('profEmpId', u.employee_code);
    setText('profDept', u.department);
    setText('profDesig', u.designation);
    setText('profTeam', u.team);
    setText('profBranch', u.branch || data.company_branch_label);
    setText('profPhone', u.phone);
    setText('profRole', portalRoleLabel(pr));
    setText('profBidSource', data.meta?.resolution_label || '—');

    renderProfilePage(data);

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
    applyTodayStatus(today);
    const dashShiftLabel = ((data.shift && data.shift.label) || 'Night shift (6 PM – 4 AM)')
        .replace(/\s*·\s*window\s+.+$/i, '')
        .trim();
    setText('dashShift', dashShiftLabel || 'Night shift (6 PM – 4 AM)');
    setText('activitiesShiftHours', '6:00 PM – 4:00 AM');

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
        await loadReportingHierarchy();

        const ls = await apiGet('api/leave_api.php?action=summary');
        if (ls.success) {
            HRMS.leaveSummary = ls.data;
            setText('statPendingLeave', String(ls.data.my_pending_leaves ?? 0), '0');
            HRMS.canSelectEmployee = !!ls.data.can_select_employee;
            HRMS.canAllotLeavePolicy = !!ls.data.can_allot_leave_policy;
            toggleLeaveEmployeeFields();
            toggleLeavePolicyAllotUI();
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
        dates.add(localDateStr(d));
    }
    return [...dates].sort((a, b) => b.localeCompare(a)).slice(0, days);
}

function renderAttendance() {
    const tbody = document.getElementById('attendanceTableBody');
    if (!tbody) return;

    const shiftDates = getShiftDatesFromAttendance(30);
    if (!shiftDates.length) {
        tbody.innerHTML = '<tr><td colspan="5">No attendance records in the last 30 days</td></tr>';
        return;
    }

    const rows = shiftDates.map(sd => {
        const st = dayStatus(sd);
        const hours = st.hoursShort && st.hoursShort !== '—' ? `${st.hoursShort} Hrs` : (st.hours || '00:00 Hrs').replace(' worked', '');
        return `<tr>
            <td>${escHtml(formatDate(sd))}</td>
            <td>${st.checkIn ? formatTime(st.checkIn) : '—'}</td>
            <td>${st.checkOut ? formatTime(st.checkOut) : '—'}</td>
            <td>${escHtml(hours)}</td>
            <td><span class="ess-pill ${st.status}">${escHtml(dayStatusLabel(st))}</span></td>
        </tr>`;
    });

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

function toggleLeavePolicyAllotUI() {
    const card = document.getElementById('leavePolicyAllotCard');
    if (card) card.classList.toggle('hidden', !HRMS.canAllotLeavePolicy);
}

function togglePolicyEmployeeField() {
    const field = document.getElementById('policyEmployeeField');
    const target = document.querySelector('input[name="policy_target"]:checked')?.value || 'all';
    if (field) field.classList.toggle('hidden', target !== 'selected');
}

function addPolicyEmployee(person) {
    if (!person?.id) return;
    const list = HRMS.selectedPolicyEmployees;
    if (list.some(p => p.id === person.id)) return;
    list.push(person);
    renderPolicyEmployeeChips();
}

function removePolicyEmployee(id) {
    HRMS.selectedPolicyEmployees = HRMS.selectedPolicyEmployees.filter(p => p.id !== id);
    renderPolicyEmployeeChips();
}

function renderPolicyEmployeeChips() {
    const wrap = document.getElementById('policyEmployeeSelected');
    if (!wrap) return;
    const list = HRMS.selectedPolicyEmployees;
    if (!list.length) {
        wrap.classList.add('hidden');
        wrap.innerHTML = '';
        return;
    }
    wrap.classList.remove('hidden');
    wrap.innerHTML = list.map(p => `
        <span class="ess-person-chip ess-policy-chip">
            <i class="fas fa-user"></i>
            <span><strong>${escHtml(p.full_name)}</strong><small>${escHtml([p.employee_code, p.team].filter(Boolean).join(' · '))}</small></span>
            <button type="button" class="ess-chip-clear" data-id="${p.id}" title="Remove" aria-label="Remove ${escHtml(p.full_name)}"><i class="fas fa-times"></i></button>
        </span>
    `).join('');
    wrap.querySelectorAll('.ess-chip-clear').forEach(btn => {
        btn.addEventListener('click', () => removePolicyEmployee(parseInt(btn.dataset.id, 10)));
    });
}

async function resolvePolicyEmployeesFromSearchInput() {
    const input = document.getElementById('policyEmployeeSearch');
    const q = input?.value.trim() || '';
    if (!q || q.length < 2) return;
    const rows = await searchLeavePeople('searchPolicyEmployees', q);
    if (!rows.length) return;
    const exact = rows.find(p =>
        String(p.employee_code || '').toLowerCase() === q.toLowerCase()
        || String(p.id) === q
    );
    if (exact) {
        addPolicyEmployee(exact);
        if (input) input.value = '';
        return;
    }
    if (rows.length === 1) {
        addPolicyEmployee(rows[0]);
        if (input) input.value = '';
    }
}

function initLeavePolicyForm() {
    document.querySelectorAll('input[name="policy_target"]').forEach(r => {
        r.addEventListener('change', togglePolicyEmployeeField);
    });
    bindPersonSearch(
        'policyEmployeeSearch', 'policyEmployeeResults', null, 'searchPolicyEmployees',
        p => { addPolicyEmployee(p); },
        null,
        { multi: true }
    );
    document.getElementById('formPolicyAllot')?.addEventListener('submit', async e => {
        e.preventDefault();
        await submitPolicyAllot();
    });
    const start = document.getElementById('policyStartDate');
    const end = document.getElementById('policyEndDate');
    start?.addEventListener('change', () => {
        if (end && !end.value) end.value = start.value;
    });
}

async function submitPolicyAllot() {
    if (!HRMS.canAllotLeavePolicy) {
        toast('Only HR and Super Admin can allot leave', 'error');
        return;
    }
    const fd = new FormData(document.getElementById('formPolicyAllot'));
    const target = fd.get('policy_target') || 'all';
    const start_date = fd.get('start_date');
    const end_date = fd.get('end_date') || start_date;
    const reason = (fd.get('reason') || '').toString().trim();
    if (!start_date || !reason) {
        toast('Start date and occasion are required', 'error');
        return;
    }
    const payload = {
        all_employees: target === 'all',
        leave_type: fd.get('leave_type') || 'public_holiday',
        start_date,
        end_date,
        reason,
        user_ids: [],
    };
    if (target === 'selected') {
        await resolvePolicyEmployeesFromSearchInput();
        const ids = HRMS.selectedPolicyEmployees.map(p => p.id).filter(Boolean);
        if (!ids.length) {
            toast('Search and add at least one employee, or choose all employees', 'error');
            return;
        }
        payload.user_ids = ids;
    }
    const btn = document.getElementById('btnPolicyAllot');
    if (btn) btn.disabled = true;
    const res = await apiPost('api/leave_api.php?action=allotLeave', payload);
    if (btn) btn.disabled = false;
    toast(res.error || res.data?.message || 'Leave allotted', res.success ? 'success' : 'error');
    if (res.success) {
        document.getElementById('formPolicyAllot')?.reset();
        HRMS.selectedPolicyEmployees = [];
        renderPolicyEmployeeChips();
        document.querySelector('input[name="policy_target"][value="all"]')?.click();
        togglePolicyEmployeeField();
        await loadLeavePolicy();
        pollNotifications();
    }
}

function renderLeavePolicyHistory(items) {
    const tbody = document.getElementById('leavePolicyHistoryBody');
    if (!tbody) return;
    if (!items?.length) {
        tbody.innerHTML = '<tr><td colspan="6">No company allotments recorded yet.</td></tr>';
        return;
    }
    const groups = [];
    const map = new Map();
    items.forEach(r => {
        const key = [r.reason, r.start_date, r.end_date, r.allotted_by_name || '', (r.created_at || '').slice(0, 16)].join('\0');
        if (!map.has(key)) {
            const g = { ...r, count: 1 };
            map.set(key, g);
            groups.push(g);
        } else {
            map.get(key).count += 1;
        }
    });
    tbody.innerHTML = groups.map(r => {
        const dates = r.start_date === r.end_date ? r.start_date : `${r.start_date} → ${r.end_date}`;
        const empCell = r.count > 1
            ? `All employees <span class="ess-pill present ess-pill-sm">${r.count}</span>`
            : `${escHtml(r.employee_name)} <small class="ess-muted-inline">${escHtml(r.employee_code || '')}</small>`;
        return `<tr>
            <td>${escHtml(r.reason || '—')}</td>
            <td>${escHtml(leaveTypeLabel(r.leave_type))}</td>
            <td>${escHtml(dates)}</td>
            <td>${empCell}</td>
            <td>${escHtml(r.allotted_by_name || 'HR')}</td>
            <td>${escHtml((r.created_at || '').slice(0, 16))}</td>
        </tr>`;
    }).join('');
}

async function loadLeavePolicy() {
    toggleLeavePolicyAllotUI();
    const res = await apiGet('api/leave_api.php?action=policyList');
    if (!res.success) {
        renderLeavePolicyHistory([]);
        return;
    }
    HRMS.canAllotLeavePolicy = !!res.data.can_allot;
    HRMS.policyAllotments = res.data.items || [];
    toggleLeavePolicyAllotUI();
    renderLeavePolicyHistory(HRMS.policyAllotments);
    const hint = document.getElementById('leavePolicyHistoryHint');
    if (hint) {
        hint.textContent = HRMS.canAllotLeavePolicy
            ? 'All leave you allot appears here. Employees are notified automatically.'
            : 'Company-wide holidays and leave allotted by HR for your branch.';
    }
}

function toggleLeaveEmployeeFields() {
    const show = HRMS.canSelectEmployee;
    ['leaveEmployeeField', 'halfLeaveEmployeeField'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('hidden', !show);
    });
}

function openLeaveApplyModal() {
    toggleLeaveEmployeeFields();
    const modal = document.getElementById('leaveApplyModal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ess-modal-open');
    setTimeout(() => document.getElementById('leaveApproverSearch')?.focus(), 120);
}

function closeLeaveApplyModal() {
    const modal = document.getElementById('leaveApplyModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ess-modal-open');
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

function bindPersonSearch(inputId, resultsId, chipId, action, onSelect, onClear, opts = {}) {
    const input = document.getElementById(inputId);
    const results = document.getElementById(resultsId);
    if (!input || !results) return;

    const hideResults = () => results.classList.add('hidden');
    let lastRows = [];

    const pickPerson = (person) => {
        if (!person) return;
        onSelect(person);
        input.value = '';
        hideResults();
        if (!opts.multi && chipId) {
            renderSelectedPerson(chipId, person, onClear);
        }
    };

    input.addEventListener('input', () => {
        const q = input.value.trim();
        clearTimeout(HRMS.searchTimers[inputId]);
        if (q.length < 2) {
            hideResults();
            results.innerHTML = '';
            lastRows = [];
            return;
        }
        HRMS.searchTimers[inputId] = setTimeout(async () => {
            const rows = await searchLeavePeople(action, q);
            lastRows = rows;
            if (!rows.length) {
                results.innerHTML = '<div class="ess-search-empty">No matches found</div>';
            } else {
                results.innerHTML = rows.map(p => `
                    <button type="button" class="ess-search-item" data-id="${p.id}">
                        <strong>${escHtml(p.full_name)}</strong>
                        <small>${escHtml([p.role_label || p.portal_role, p.designation, p.employee_code ? 'ID ' + p.employee_code : ''].filter(Boolean).join(' · '))}</small>
                    </button>`).join('');
            }
            results.classList.remove('hidden');
            results.querySelectorAll('.ess-search-item').forEach(btn => {
                btn.addEventListener('mousedown', e => e.preventDefault());
                btn.addEventListener('click', () => {
                    pickPerson(rows.find(r => String(r.id) === btn.dataset.id));
                });
            });
        }, 280);
    });

    input.addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (!lastRows.length) return;
        const q = input.value.trim().toLowerCase();
        const exact = lastRows.find(p =>
            String(p.employee_code || '').toLowerCase() === q
            || String(p.id) === q
        );
        pickPerson(exact || (lastRows.length === 1 ? lastRows[0] : null));
    });

    input.addEventListener('blur', () => setTimeout(hideResults, 200));
}

function renderManagerCard(manager) {
    if (!manager) return '';
    const code = manager.employee_code ? `<span class="ess-id-badge">${escHtml(manager.employee_code)}</span>` : '';
    const meta = [manager.role_label, manager.designation, manager.team].filter(Boolean).join(' · ');
    return `<div class="ess-hierarchy-person">
        ${code}
        <strong>${escHtml(manager.full_name || 'Manager')}</strong>
        ${meta ? `<small>${escHtml(meta)}</small>` : ''}
    </div>`;
}

function renderDashPersonCard(person) {
    if (!person) return '';
    const name = person.full_name || '—';
    const code = person.employee_code || '';
    const meta = [person.role_label, person.designation, person.team].filter(Boolean).join(' · ');
    return `<div class="ess-hierarchy-person-card">
        <span class="ess-hierarchy-person-avatar" aria-hidden="true">${escHtml(initials(name))}</span>
        <div class="ess-hierarchy-person-info">
            <strong>${escHtml(name)}</strong>
            <span class="ess-hierarchy-person-meta">${code ? `<span class="ess-id-badge">${escHtml(code)}</span>` : ''}${meta ? `<span>${escHtml(meta)}</span>` : ''}</span>
        </div>
    </div>`;
}

function renderSidebarReporteesList(reportees) {
    if (!reportees?.length) return '';
    return `<div class="ess-sidebar-reportee-list">${reportees.map(r => {
        const att = r.attendance || {};
        const st = att.status || 'absent';
        const label = dayStatusLabel({ status: st, label: att.label });
        const name = r.full_name || '—';
        const code = r.employee_code || '—';
        return `<div class="ess-sidebar-reportee-row">
            <span class="ess-sidebar-reportee-name">${escHtml(name)}</span>
            <span class="ess-id-badge">${escHtml(code)}</span>
            <span class="ess-pill ${st}">${escHtml(label)}</span>
        </div>`;
    }).join('')}</div>`;
}

function renderSidebarManagerRow(manager) {
    if (!manager) return '';
    const code = manager.employee_code || '';
    const meta = [manager.role_label, manager.designation].filter(Boolean).join(' · ');
    return `<div class="ess-sidebar-manager-row">
        <span class="ess-sidebar-person-avatar" aria-hidden="true">${escHtml(initials(manager.full_name || 'M'))}</span>
        <div class="ess-sidebar-person-details">
            <strong>${escHtml(manager.full_name || 'Manager')}</strong>
            ${code ? `<span class="ess-id-badge">${escHtml(code)}</span>` : ''}
            ${meta ? `<small>${escHtml(meta)}</small>` : ''}
        </div>
    </div>`;
}

function renderReporteesAttendanceRows(reportees, { clickable = false } = {}) {
    if (!reportees?.length) return '';
    return `<div class="ess-dash-reportee-list">${reportees.map(r => {
        const att = r.attendance || {};
        const st = att.status || 'absent';
        const label = dayStatusLabel({ status: st, label: att.label });
        const name = r.full_name || '—';
        const code = r.employee_code || '—';
        const uid = r.user_id || r.employee_code || '';
        const hint = att.check_in
            ? `${formatTime(att.check_in)}${att.check_out ? ' → ' + formatTime(att.check_out) : (att.on_duty ? ' · on duty' : '')}`
            : '';
        const tag = clickable ? 'button' : 'div';
        const extra = clickable
            ? ` type="button" class="ess-dash-reportee-row ess-dash-reportee-row--click" data-reportee-id="${escHtml(String(uid))}" title="${escHtml(name)}${hint ? ' · ' + hint : ''}"`
            : ' class="ess-dash-reportee-row"';
        return `<${tag}${extra}>
            <span class="ess-dash-reportee-name">${escHtml(name)}</span>
            <span class="ess-id-badge">${escHtml(code)}</span>
            <span class="ess-pill ${st}">${escHtml(label)}</span>
        </${tag}>`;
    }).join('')}</div>`;
}

function renderSidebarHierarchy(data) {
    data = data || HRMS.reporting || {};
    const manager = data.reporting_to;
    const reportees = data.reportees || [];
    const isManager = !!(data.can_manage_reportees || data.is_manager);
    const onDashboard = HRMS.currentView === 'dashboard';

    const card = document.getElementById('hierarchyCard');
    if (card) {
        card.classList.toggle('hidden', !onDashboard);
    }

    document.getElementById('btnSidebarManageReportees')?.classList.toggle('hidden', !isManager && !data.can_assign_manager);

    const reportingEl = document.getElementById('hierarchyReporting');
    const reportingEmpty = document.getElementById('hierarchyReportingEmpty');
    if (manager && reportingEl) {
        reportingEl.innerHTML = renderSidebarManagerRow(manager);
        reportingEmpty?.classList.add('hidden');
    } else {
        if (reportingEl) reportingEl.innerHTML = '';
        reportingEmpty?.classList.remove('hidden');
    }

    const reporteesEl = document.getElementById('hierarchyReportees');
    const reporteesEmpty = document.getElementById('hierarchyReporteesEmpty');
    const countBadge = document.getElementById('sidebarReporteesCount');
    if (countBadge) {
        if (reportees.length) {
            countBadge.textContent = String(reportees.length);
            countBadge.classList.remove('hidden');
        } else {
            countBadge.classList.add('hidden');
        }
    }

    if (reportees.length && reporteesEl) {
        reporteesEl.innerHTML = renderSidebarReporteesList(reportees);
        reporteesEmpty?.classList.add('hidden');
    } else {
        if (reporteesEl) reporteesEl.innerHTML = '';
        reporteesEmpty?.classList.remove('hidden');
        if (reporteesEmpty) {
            reporteesEmpty.textContent = isManager
                ? 'No reportees assigned yet.'
                : 'No reportees assigned';
        }
    }
}

function renderDashboardHierarchy(data) {
    renderSidebarHierarchy(data);
}

function renderReporteesTeamOverview(reportees) {
    const overview = document.getElementById('reporteesTeamOverview');
    const chipsEl = document.getElementById('reporteesTeamChips');
    const emptyEl = document.getElementById('reporteesTeamOverviewEmpty');
    const countEl = document.getElementById('reporteesOverviewCount');
    const isManager = !!(HRMS.reporting?.is_manager || HRMS.reporting?.can_manage_reportees);

    overview?.classList.toggle('hidden', !isManager);
    if (!isManager || !overview) return;

    if (countEl) countEl.textContent = String(reportees?.length || 0);

    if (reportees?.length && chipsEl) {
        chipsEl.innerHTML = renderReporteeChips(reportees, 0);
        chipsEl.classList.remove('hidden');
        bindReporteeChipClicks(chipsEl);
        emptyEl?.classList.add('hidden');
    } else {
        if (chipsEl) {
            chipsEl.innerHTML = '';
            chipsEl.classList.add('hidden');
        }
        emptyEl?.classList.remove('hidden');
    }
}

function renderReporteesPageLayout(data) {
    data = data || HRMS.reporting || {};
    const isManager = !!data.is_manager;
    const manager = data.reporting_to;
    const pageTitle = isManager ? 'Manage Reportees' : 'My Reporting';

    const tab = document.getElementById('tabReportees');
    const sideLabel = document.querySelector('[data-nav-id="nav-side-reportees"] span');
    if (tab) tab.textContent = pageTitle;
    if (sideLabel) sideLabel.textContent = isManager ? 'Reportees' : 'Reporting';

    const heroTitle = document.getElementById('reporteesPageTitle');
    const heroSub = document.getElementById('reporteesPageSubtitle');
    if (heroTitle) {
        heroTitle.innerHTML = `<i class="fas fa-${isManager ? 'users-gear' : 'sitemap'}"></i> ${pageTitle}`;
    }
    if (heroSub) {
        heroSub.textContent = isManager
            ? 'Search employees and add them to your team. Review attendance and punch history below.'
            : 'Your reporting manager is assigned by your team lead, floor manager, HR, or admin.';
    }

    const canAssignMgr = !!data.can_assign_manager;
    const viewEl = document.getElementById('reporteesReportingView');
    const emptyEl = document.getElementById('reporteesReportingEmpty');
    const managerAssign = document.getElementById('reporteesManagerAssign');
    const emptyHint = document.getElementById('reporteesReportingEmptyHint');

    if (manager && viewEl) {
        viewEl.classList.remove('hidden');
        viewEl.innerHTML = renderDashPersonCard(manager);
        emptyEl?.classList.add('hidden');
    } else {
        viewEl?.classList.add('hidden');
        if (viewEl) viewEl.innerHTML = '';
        emptyEl?.classList.remove('hidden');
    }

    managerAssign?.classList.toggle('hidden', !canAssignMgr);
    if (emptyHint) {
        emptyHint.textContent = canAssignMgr
            ? 'Search and assign your reporting manager below.'
            : 'Your team lead, floor manager, HR, or admin will link you to the correct reporting line.';
    }

    document.getElementById('reporteesAssignCard')?.classList.toggle('hidden', !isManager);
    renderReporteesTeamOverview(data.reportees || []);

    if (HRMS.currentView === 'reportees') {
        setText('pageTitle', pageTitle);
        setText('pageSubtitle', isManager
            ? 'Add employees to your team and monitor attendance'
            : 'View who you report to');
    }
}

function renderReporteeChips(reportees, limit = 0) {
    if (!reportees?.length) {
        return '<p class="ess-muted-line">No reportees yet.</p>';
    }
    const list = limit > 0 ? reportees.slice(0, limit) : reportees;
    return `<div class="ess-reportee-chips">${list.map(r => {
        const att = r.attendance || {};
        const st = att.status || 'absent';
        const label = dayStatusLabel({ status: st, label: att.label });
        const code = r.employee_code || '—';
        const name = r.full_name || '—';
        const uid = r.user_id || r.employee_code || '';
        const hint = st === 'upcoming'
            ? 'Shift not started'
            : (att.check_in ? `${formatTime(att.check_in)}${att.check_out ? ' → ' + formatTime(att.check_out) : (att.on_duty ? ' · on duty' : '')}` : '');
        return `<button type="button" class="ess-reportee-chip" data-reportee-id="${escHtml(String(uid))}" title="${escHtml(name)}${hint ? ' · ' + hint : ''}">
            <span class="ess-reportee-chip-name">${escHtml(name)}</span>
            <span class="ess-id-badge">${escHtml(code)}</span>
            <span class="ess-pill ${st}">${escHtml(label)}</span>
        </button>`;
    }).join('')}</div>`;
}

function bindReporteeChipClicks(container) {
    if (!container) return;
    container.querySelectorAll('.ess-reportee-chip[data-reportee-id]').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.reporteeId;
            const reportee = (HRMS.reporting?.reportees || HRMS.reporteesList || [])
                .find(r => String(r.user_id) === id || String(r.employee_code) === id);
            if (reportee) openReporteeAttendance(reportee);
        });
    });
}

function formatPunchList(punches) {
    if (!punches?.length) return '—';
    return punches.map(ts => formatTime(ts)).join(', ');
}

function renderReporteeHistoryRows(history) {
    if (!history?.length) {
        return '<tr><td colspan="7">No punch records in the last 10 days.</td></tr>';
    }
    return history.map(day => {
        const st = day.status || 'absent';
        const checkOut = day.check_out
            ? formatTime(day.check_out)
            : (day.on_duty ? 'On duty' : '—');
        const hrs = day.working_hours != null ? Number(day.working_hours).toFixed(2) : '0.00';
        return `<tr>
            <td>${formatDate(day.shift_date)}</td>
            <td>${day.check_in ? formatTime(day.check_in) : '—'}</td>
            <td>${checkOut}</td>
            <td>${hrs} Hrs</td>
            <td>${day.punch_count ?? 0}</td>
            <td class="ess-punch-times">${escHtml(formatPunchList(day.punches))}</td>
            <td><span class="ess-pill ${st}">${escHtml(day.label || st)}</span></td>
        </tr>`;
    }).join('');
}

function renderReporteeAttendanceDetail(reportee, history, loadingHistory = false) {
    const panel = document.getElementById('reporteeAttendancePanel');
    if (!panel) return;
    if (!reportee) {
        panel.classList.add('hidden');
        panel.innerHTML = '';
        return;
    }

    const att = reportee.attendance || {};
    const st = att.status || 'absent';
    const label = dayStatusLabel({ status: st, label: att.label });
    const code = reportee.employee_code || '—';
    const name = reportee.full_name || '—';
    const hours = att.working_hours != null ? Number(att.working_hours).toFixed(2) + ' Hrs' : '0.00 Hrs';
    const checkOut = att.check_out
        ? formatTime(att.check_out)
        : (att.on_duty ? 'On duty' : '—');
    const shiftNote = att.shift_date ? `Shift date: ${formatDate(att.shift_date)}` : 'Night shift (5 PM – next day 4 AM)';
    const historyBody = loadingHistory
        ? '<tr><td colspan="7">Loading punch history…</td></tr>'
        : renderReporteeHistoryRows(history);

    panel.classList.remove('hidden');
    panel.innerHTML = `
        <div class="ess-reportee-att-head">
            <div>
                <h4>${escHtml(name)}</h4>
                <p class="ess-muted-line"><span class="ess-id-badge">${escHtml(code)}</span>${reportee.team ? ` · ${escHtml(reportee.team)}` : ''}</p>
            </div>
            <span class="ess-pill ${st}">${escHtml(label)}</span>
        </div>
        <div class="ess-reportee-att-grid">
            <div><span class="lbl">Check In</span><strong>${att.check_in ? formatTime(att.check_in) : '—'}</strong></div>
            <div><span class="lbl">Check Out</span><strong>${checkOut}</strong></div>
            <div><span class="lbl">Total Hours</span><strong>${hours}</strong></div>
            <div><span class="lbl">Punches</span><strong>${att.punch_count ?? 0}</strong></div>
        </div>
        <p class="ess-shift-note" style="margin-top:12px;margin-bottom:0"><i class="fas fa-moon"></i> ${escHtml(shiftNote)}</p>
        <div class="ess-reportee-history">
            <h5><i class="fas fa-clock-rotate-left"></i> Last 10 days punches</h5>
            <div class="ess-table-wrap">
                <table class="data-table ess-reportee-history-table">
                    <thead>
                        <tr>
                            <th>Shift Date</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Hours</th>
                            <th>Punches</th>
                            <th>All Punch Times</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>${historyBody}</tbody>
                </table>
            </div>
        </div>`;
}

async function loadReporteeHistory(reportee) {
    if (!reportee) return;
    const token = `${reportee.user_id || ''}:${reportee.employee_code || ''}`;
    const code = encodeURIComponent(reportee.employee_code || '');
    const uid = reportee.user_id || '';
    try {
        const res = await apiGet(`api/reportees_api.php?action=reporteeHistory&employee_code=${code}&user_id=${uid}`);
        const current = `${HRMS.selectedReportee?.user_id || ''}:${HRMS.selectedReportee?.employee_code || ''}`;
        if (current !== token) return;
        renderReporteeAttendanceDetail(reportee, res.success ? (res.data || []) : []);
        if (!res.success) toast(res.error || 'Could not load punch history', 'error');
    } catch (err) {
        console.warn('Reportee history load failed', err);
        const current = `${HRMS.selectedReportee?.user_id || ''}:${HRMS.selectedReportee?.employee_code || ''}`;
        if (current === token) renderReporteeAttendanceDetail(reportee, []);
    }
}

async function openReporteeAttendance(reportee) {
    if (!reportee) return;
    HRMS.selectedReportee = reportee;
    renderReporteeAttendanceDetail(reportee, null, true);
    renderReporteesTable(HRMS.reporteesList);
    showView('reportees', 'nav-tab-reportees');
    document.getElementById('reporteeAttendancePanel')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    await loadReporteeHistory(reportee);
}

async function selectReportee(reportee) {
    HRMS.selectedReportee = reportee;
    renderReporteeAttendanceDetail(reportee, null, true);
    renderReporteesTable(HRMS.reporteesList);
    await loadReporteeHistory(reportee);
}

function renderReportingHierarchy(data) {
    HRMS.reporting = data || null;
    if (data?.reportees?.length) {
        HRMS.reporteesList = data.reportees;
    }
    const teamCard = document.getElementById('reporteesTeamCard');
    const isManager = !!data?.is_manager;
    const reportees = data?.reportees || [];

    renderReporteesPageLayout(data);
    renderSidebarHierarchy(data);

    if (teamCard) {
        teamCard.classList.toggle('hidden', !isManager);
    }
    setText('reporteesCountBadge', `${reportees.length} reportee${reportees.length === 1 ? '' : 's'}`);
    renderReporteesTeamOverview(reportees);
    if (document.getElementById('view-profile')?.classList.contains('active')) {
        renderProfilePage({ user: HRMS.user, meta: HRMS.profileMeta, company_branch_label: HRMS.branchLabel });
    }
}

async function loadReportingHierarchy() {
    try {
        const res = await apiGet('api/reportees_api.php?action=hierarchy');
        if (res.success) {
            renderReportingHierarchy(res.data);
        }
    } catch (err) {
        console.warn('Reporting hierarchy load failed', err);
    }
}

function renderReporteesTable(rows) {
    const tbody = document.getElementById('reporteesTableBody');
    if (!tbody) return;
    if (!rows?.length) {
        tbody.innerHTML = '<tr><td colspan="6">No reportees yet. Use <strong>Add Reportee</strong> above to build your team.</td></tr>';
        renderReporteeAttendanceDetail(null);
        return;
    }
    const selectedId = HRMS.selectedReportee
        ? String(HRMS.selectedReportee.user_id || HRMS.selectedReportee.employee_code || '')
        : '';
    tbody.innerHTML = rows.map(r => {
        const att = r.attendance || {};
        const st = att.status || 'absent';
        const rowId = String(r.user_id || r.employee_code || '');
        const selected = selectedId && rowId === selectedId ? ' ess-reportee-row-selected' : '';
        return `<tr class="ess-reportee-row${selected}" data-reportee-id="${escHtml(rowId)}" tabindex="0" role="button" aria-label="View ${escHtml(r.full_name || '')} attendance">
            <td><span class="ess-id-badge">${escHtml(r.employee_code || '—')}</span></td>
            <td><strong>${escHtml(r.full_name || '—')}</strong>${r.team ? `<br><small>${escHtml(r.team)}</small>` : ''}</td>
            <td>${escHtml(r.team || '—')}</td>
            <td>${att.check_in ? formatTime(att.check_in) : '—'}</td>
            <td>${att.check_out ? formatTime(att.check_out) : (att.on_duty ? 'On duty' : '—')}</td>
            <td><span class="ess-pill ${st}">${escHtml(dayStatusLabel({ status: st, label: att.label }))}</span></td>
        </tr>`;
    }).join('');

    tbody.querySelectorAll('.ess-reportee-row').forEach(row => {
        const pick = () => {
            const id = row.dataset.reporteeId;
            const reportee = rows.find(r => String(r.user_id) === id || String(r.employee_code) === id);
            if (reportee) selectReportee(reportee);
        };
        row.addEventListener('click', pick);
        row.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                pick();
            }
        });
    });
}

async function loadReporteesView() {
    let hierarchy = HRMS.reporting;
    if (!hierarchy) {
        const hres = await apiGet('api/reportees_api.php?action=hierarchy');
        if (hres.success) hierarchy = hres.data;
    }
    if (hierarchy) renderReportingHierarchy(hierarchy);

    if (!hierarchy?.is_manager) {
        const teamCard = document.getElementById('reporteesTeamCard');
        teamCard?.classList.add('hidden');
        return;
    }

    const tbody = document.getElementById('reporteesTableBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="6">Loading team…</td></tr>';
    const res = await apiGet('api/reportees_api.php?action=reportees');
    if (!res.success) {
        if (tbody) tbody.innerHTML = `<tr><td colspan="6">${escHtml(res.error || 'Could not load reportees')}</td></tr>`;
        return;
    }
    HRMS.reporteesList = res.data || [];
    renderReporteesTable(HRMS.reporteesList);
    renderReporteesTeamOverview(HRMS.reporteesList);
    if (HRMS.selectedReportee) {
        const still = HRMS.reporteesList.find(r =>
            (HRMS.selectedReportee.user_id && r.user_id === HRMS.selectedReportee.user_id)
            || (HRMS.selectedReportee.employee_code && r.employee_code === HRMS.selectedReportee.employee_code)
        );
        HRMS.selectedReportee = still || null;
        if (HRMS.selectedReportee) {
            renderReporteeAttendanceDetail(HRMS.selectedReportee, null, true);
            await loadReporteeHistory(HRMS.selectedReportee);
        } else {
            renderReporteeAttendanceDetail(null);
        }
    }
    setText('reporteesCountBadge', `${HRMS.reporteesList.length} reportee${HRMS.reporteesList.length === 1 ? '' : 's'}`);
}

function bindPersonSearchField({
    inputId, resultsId, selectedId, confirmId, action, onSelect, onClear, onConfirm,
}) {
    const input = document.getElementById(inputId);
    const results = document.getElementById(resultsId);
    const confirmBtn = confirmId ? document.getElementById(confirmId) : null;
    if (!input || !results) return;

    const hideResults = () => results.classList.add('hidden');
    const timerKey = inputId;
    let lastRows = [];

    const pickPerson = (person) => {
        if (!person) return;
        onSelect(person);
        input.value = '';
        hideResults();
        renderSelectedPerson(selectedId, person, () => {
            onClear();
            renderSelectedPerson(selectedId, null);
            if (confirmBtn) confirmBtn.disabled = true;
        });
        if (confirmBtn) confirmBtn.disabled = false;
    };

    const bindResultButtons = (rows) => {
        results.querySelectorAll('.ess-search-item').forEach(btn => {
            btn.addEventListener('mousedown', (e) => e.preventDefault());
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const person = rows.find(r => String(r.id) === btn.dataset.id);
                pickPerson(person);
            });
        });
    };

    input.addEventListener('input', () => {
        const q = input.value.trim();
        clearTimeout(HRMS.searchTimers[timerKey]);
        if (q.length < 2) {
            hideResults();
            results.innerHTML = '';
            lastRows = [];
            return;
        }
        HRMS.searchTimers[timerKey] = setTimeout(async () => {
            const res = await apiGet(`api/reportees_api.php?action=${action}&q=${encodeURIComponent(q)}`);
            const rows = res.success ? (res.data || []) : [];
            lastRows = rows;
            if (!rows.length) {
                results.innerHTML = '<div class="ess-search-empty">No match found</div>';
            } else {
                results.innerHTML = rows.map(p => `
                    <button type="button" class="ess-search-item" data-id="${escHtml(String(p.id))}">
                        <strong>${escHtml(p.full_name)}</strong>
                        <small>${escHtml([p.employee_code ? 'ID ' + p.employee_code : '', p.role_label, p.team || p.designation].filter(Boolean).join(' · '))}</small>
                    </button>`).join('');
            }
            results.classList.remove('hidden');
            bindResultButtons(rows);
        }, 280);
    });

    input.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (!lastRows.length) return;
        const q = input.value.trim().toLowerCase();
        const exact = lastRows.find(p =>
            String(p.employee_code || '').toLowerCase() === q
            || String(p.id) === q
            || String(p.full_name || '').toLowerCase() === q
        );
        pickPerson(exact || (lastRows.length === 1 ? lastRows[0] : null));
    });

    input.addEventListener('blur', () => setTimeout(hideResults, 220));
    confirmBtn?.addEventListener('click', onConfirm);
}

function bindReporteesPageEditors() {
    bindPersonSearchField({
        inputId: 'reporteeManagerSearchInput',
        resultsId: 'reporteeManagerSearchResults',
        selectedId: 'reporteeManagerSearchSelected',
        confirmId: 'btnConfirmReporteeManager',
        action: 'searchManagers',
        onSelect: (person) => { HRMS.selectedAssignManager = person; },
        onClear: () => { HRMS.selectedAssignManager = null; },
        onConfirm: async () => {
            const btn = document.getElementById('btnConfirmReporteeManager');
            if (!HRMS.selectedAssignManager?.id) {
                toast('Please search and select a manager first', 'error');
                return;
            }
            if (btn) btn.disabled = true;
            const res = await apiPost('api/reportees_api.php?action=assignManager', {
                manager_user_id: HRMS.selectedAssignManager.id,
            });
            if (res.success) {
                toast(res.data?.message || 'Reporting manager saved.');
                HRMS.selectedAssignManager = null;
                renderSelectedPerson('reporteeManagerSearchSelected', null);
                const inp = document.getElementById('reporteeManagerSearchInput');
                if (inp) inp.value = '';
                renderReportingHierarchy(res.data?.hierarchy);
            } else {
                toast(res.error || 'Could not assign manager', 'error');
                if (btn) btn.disabled = false;
            }
        },
    });
}

function bindReporteeAssignSearch() {
    bindPersonSearchField({
        inputId: 'reporteeSearchInput',
        resultsId: 'reporteeSearchResults',
        selectedId: 'reporteeSearchSelected',
        confirmId: 'btnConfirmReportee',
        action: 'searchReportees',
        onSelect: (person) => { HRMS.selectedAssignReportee = person; },
        onClear: () => { HRMS.selectedAssignReportee = null; },
        onConfirm: async () => {
            const confirmBtn = document.getElementById('btnConfirmReportee');
            if (!HRMS.selectedAssignReportee?.id) {
                toast('Please search and select an employee first', 'error');
                return;
            }
            if (confirmBtn) confirmBtn.disabled = true;
            const res = await apiPost('api/reportees_api.php?action=assignReportee', {
                employee_user_id: HRMS.selectedAssignReportee.id,
            });
            if (res.success) {
                toast(res.data?.message || 'Reportee added to your team.');
                HRMS.selectedAssignReportee = null;
                renderSelectedPerson('reporteeSearchSelected', null);
                const inp = document.getElementById('reporteeSearchInput');
                if (inp) inp.value = '';
                renderReportingHierarchy(res.data?.hierarchy);
                await loadReporteesView();
                if (confirmBtn) confirmBtn.disabled = true;
            } else {
                toast(res.error || 'Could not add reportee', 'error');
                if (confirmBtn) confirmBtn.disabled = false;
            }
        },
    });
}

function initLeaveForms() {
    bindReporteesPageEditors();
    bindReporteeAssignSearch();
    document.getElementById('btnSidebarManageReportees')?.addEventListener('click', () => {
        showView('reportees', 'nav-tab-reportees');
    });
    document.getElementById('btnDashReviewApprovals')?.addEventListener('click', () => {
        showView('approvals', 'nav-tab-approvals');
    });
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

    document.getElementById('btnOpenLeaveApply')?.addEventListener('click', openLeaveApplyModal);
    document.getElementById('btnPolicyApplyLeave')?.addEventListener('click', () => {
        showView('leave', 'nav-tab-leave');
        openLeaveApplyModal();
    });
    document.getElementById('leaveApplyModalClose')?.addEventListener('click', closeLeaveApplyModal);
    document.getElementById('leaveApplyModalCancel')?.addEventListener('click', closeLeaveApplyModal);
    document.getElementById('leaveApplyModal')?.addEventListener('click', e => {
        if (e.target.id === 'leaveApplyModal') closeLeaveApplyModal();
    });
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (!document.getElementById('leaveWithdrawModal')?.classList.contains('hidden')) {
            closeLeaveWithdrawModal();
            return;
        }
        if (!document.getElementById('leaveApplyModal')?.classList.contains('hidden')) {
            closeLeaveApplyModal();
        }
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
            closeLeaveApplyModal();
        }
        await loadProfile();
        showView('leave');
    } else {
        toast(res.error || 'Failed to submit', 'error');
    }
}

function leaveStatusLabel(status) {
    const map = {
        pending: 'Pending',
        approved: 'Approved',
        rejected: 'Rejected',
        cancelled: 'Withdrawn',
    };
    return map[status] || status;
}

function leaveWithdrawActionCell(r) {
    if (!r.can_withdraw) {
        return '<td class="ess-leave-actions-col"><span class="ess-leave-no-action">—</span></td>';
    }
    const label = r.status === 'approved' ? 'Revert' : 'Withdraw';
    return `<td class="ess-leave-actions-col">
        <button type="button" class="ess-leave-withdraw-btn" data-leave-id="${r.id}" title="${label} this leave request">
            <i class="fas fa-undo"></i> ${label}
        </button>
    </td>`;
}

function renderLeaveRows(data, tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    if (!data.length) {
        tbody.innerHTML = '<tr><td colspan="7">No leave applications found yet.</td></tr>';
        return;
    }
    tbody.innerHTML = data.map(r => `<tr>
        <td>${r.duration_type === 'half_day' ? 'Half (' + (r.half_day_slot || '') + ')' : 'Full'}</td>
        <td>${escHtml(leaveTypeLabel(r.leave_type) || r.leave_type)}</td>
        <td>${formatDate(r.start_date)}${r.end_date !== r.start_date ? ' – ' + formatDate(r.end_date) : ''}</td>
        <td>${escHtml(r.approver_name || (r.apply_through || '').replace(/_/g, ' '))}</td>
        <td><span class="status-pill ${r.status}">${leaveStatusLabel(r.status)}</span></td>
        <td>${new Date(r.created_at).toLocaleString()}</td>
        ${leaveWithdrawActionCell(r)}
    </tr>`).join('');
    tbody.querySelectorAll('.ess-leave-withdraw-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.leaveId, 10);
            const row = (HRMS.leaveRequests || []).find(x => parseInt(x.id, 10) === id);
            openLeaveWithdrawModal(row || { id });
        });
    });
}

function openLeaveWithdrawModal(row) {
    if (!row?.id) return;
    HRMS.withdrawLeaveRequest = row;
    const modal = document.getElementById('leaveWithdrawModal');
    const summary = document.getElementById('leaveWithdrawSummary');
    const note = document.getElementById('leaveWithdrawNote');
    const title = document.getElementById('leaveWithdrawModalTitle');
    if (!modal || !summary) return;
    const isApproved = row.status === 'approved';
    if (title) {
        title.textContent = isApproved ? 'Revert approved leave' : 'Withdraw leave request';
    }
    const dur = row.duration_type === 'half_day'
        ? `Half day (${row.half_day_slot || 'session'})`
        : 'Full day';
    const dates = formatDate(row.start_date) + (row.end_date !== row.start_date ? ' – ' + formatDate(row.end_date) : '');
    summary.innerHTML = `
        <div class="ess-withdraw-summary-grid">
            <div class="ess-withdraw-summary-item">
                <span class="ess-withdraw-summary-label">Leave type</span>
                <strong>${escHtml(leaveTypeLabel(row.leave_type) || row.leave_type)}</strong>
            </div>
            <div class="ess-withdraw-summary-item">
                <span class="ess-withdraw-summary-label">Duration</span>
                <strong>${escHtml(dur)}</strong>
            </div>
            <div class="ess-withdraw-summary-item ess-withdraw-summary-item--wide">
                <span class="ess-withdraw-summary-label">Dates</span>
                <strong>${dates}</strong>
            </div>
            <div class="ess-withdraw-summary-item ess-withdraw-summary-item--wide">
                <span class="ess-withdraw-summary-label">Current status</span>
                <strong><span class="status-pill ${row.status}">${leaveStatusLabel(row.status)}</span></strong>
            </div>
        </div>
        <p class="ess-withdraw-hint">${isApproved
            ? 'This approved leave will be cancelled and removed from your schedule.'
            : 'Your approver will be notified that you no longer need this leave.'}</p>`;
    if (note) note.value = '';
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ess-modal-open');
    setTimeout(() => note?.focus(), 120);
}

function closeLeaveWithdrawModal() {
    const modal = document.getElementById('leaveWithdrawModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ess-modal-open');
    HRMS.withdrawLeaveRequest = null;
}

async function submitLeaveWithdraw() {
    const row = HRMS.withdrawLeaveRequest;
    if (!row?.id) return;
    const note = (document.getElementById('leaveWithdrawNote')?.value || '').trim();
    const btn = document.getElementById('leaveWithdrawModalConfirm');
    if (btn) btn.disabled = true;
    const res = await apiPost('api/leave_api.php?action=withdrawLeave', { leave_id: row.id, note });
    if (btn) btn.disabled = false;
    if (res.success) {
        toast(res.error || 'Leave request withdrawn successfully');
        closeLeaveWithdrawModal();
        await loadMyLeaves();
        await loadProfile();
    } else {
        toast(res.error || 'Could not withdraw leave request', 'error');
    }
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

    const dashCard = document.getElementById('dashLeaveApprovalsCard');
    if (dashCard) dashCard.classList.toggle('hidden', !canApprove);
    document.getElementById('essDashBottomGrid')?.classList.toggle('ess-dash-bottom-grid--solo', !canApprove);
    setText('dashLeaveApprovalsBadge', String(pending), '0');
    const dashText = document.getElementById('dashLeaveApprovalsText');
    if (dashText) {
        dashText.textContent = pending > 0
            ? `${pending} leave request${pending === 1 ? '' : 's'} waiting for your review.`
            : 'No pending leave requests from your team right now.';
    }
}

function leaveTypeLabel(type) {
    const map = {
        casual: 'Casual',
        sick: 'Sick',
        annual: 'Annual',
        emergency: 'On Duty',
        unpaid: 'Comp Off',
        public_holiday: 'Public Holiday',
        eid: 'Eid Vacation',
        company_holiday: 'Company Holiday',
        other: 'Other',
    };
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

function formatBadgeCount(n) {
    return n > 99 ? '99+' : String(n);
}

function updateChatBadges(unread) {
    const count = Math.max(0, parseInt(unread, 10) || 0);
    ['badgeHeaderChat', 'badgeSideChat', 'badgeChatFab'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (count > 0) {
            el.textContent = formatBadgeCount(count);
            el.classList.remove('hidden');
        } else {
            el.classList.add('hidden');
        }
    });
}

async function pollChatUnread() {
    const res = await apiGet('api/chat_api.php?action=unreadSummary');
    if (!res.success) return;
    const unread = res.data?.total_unread ?? 0;
    updateChatBadges(unread);
    const onChat = HRMS.currentView === 'chat';
    if (!onChat && HRMS.lastChatUnread != null && unread > HRMS.lastChatUnread) {
        window.PortalNotifySound?.play();
    }
    HRMS.lastChatUnread = unread;
}

async function pollNotifications() {
    const res = await apiGet('api/leave_api.php?action=notifications');
    if (!res.success) return;
    const unread = res.data.unread || 0;
    const b = document.getElementById('badgeNotif');
    if (b) {
        if (unread > 0) {
            b.textContent = formatBadgeCount(unread);
            b.classList.remove('hidden');
        } else {
            b.classList.add('hidden');
        }
    }
    if (HRMS.lastNotifUnread != null && unread > HRMS.lastNotifUnread) {
        window.PortalNotifySound?.play();
    }
    HRMS.lastNotifUnread = unread;
}

function startPortalNotificationPolling() {
    pollNotifications();
    pollChatUnread();
    if (HRMS.notifyTimer) clearInterval(HRMS.notifyTimer);
    if (HRMS.chatPollTimer) clearInterval(HRMS.chatPollTimer);
    HRMS.notifyTimer = setInterval(pollNotifications, 20000);
    HRMS.chatPollTimer = setInterval(() => {
        if (HRMS.currentView !== 'chat') pollChatUnread();
    }, 6000);
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
    applyAvatarToEl(document.getElementById('profilePageAvatar'), res.data.avatar_url, HRMS.user?.full_name);
    applyAvatarToEl(document.getElementById('topAvatar'), res.data.avatar_url, HRMS.user?.full_name);
    toast('Profile photo updated');
}

function bindNavigation() {
    document.querySelectorAll('[data-nav-id]').forEach(el => {
        el.addEventListener('click', () => navigateFromEl(el));
    });
    document.getElementById('btnUserMenu')?.addEventListener('click', () => showView('profile', 'nav-tab-profile'));
    document.getElementById('btnChatBackDashboard')?.addEventListener('click', () => {
        if (location.hash === '#chat') {
            history.replaceState(null, '', location.pathname + location.search);
        }
        showView('dashboard', 'nav-tab-activities');
    });
    document.getElementById('essBrandHome')?.addEventListener('click', (e) => {
        e.preventDefault();
        showView('dashboard', 'nav-side-home');
    });
    document.getElementById('btnComingSoonBack')?.addEventListener('click', () => {
        showView('dashboard', 'nav-side-home');
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

    const openPhotoPicker = () => document.getElementById('profilePhotoInput')?.click();
    document.getElementById('btnChangePhoto')?.addEventListener('click', openPhotoPicker);
    document.getElementById('btnProfilePagePhoto')?.addEventListener('click', openPhotoPicker);
    document.getElementById('btnSaveProfile')?.addEventListener('click', saveProfile);
    document.getElementById('btnGoManageReportees')?.addEventListener('click', () => showView('reportees', 'nav-tab-reportees'));
    document.getElementById('profilePhotoInput')?.addEventListener('change', e => {
        const f = e.target.files?.[0];
        e.target.value = '';
        if (f) uploadProfilePhoto(f);
    });

    document.getElementById('leaveWithdrawModalClose')?.addEventListener('click', closeLeaveWithdrawModal);
    document.getElementById('leaveWithdrawModalCancel')?.addEventListener('click', closeLeaveWithdrawModal);
    document.getElementById('leaveWithdrawModalConfirm')?.addEventListener('click', submitLeaveWithdraw);
    document.getElementById('leaveWithdrawModal')?.addEventListener('click', e => {
        if (e.target.id === 'leaveWithdrawModal') closeLeaveWithdrawModal();
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
    initLeavePolicyForm();
    if (location.hash === '#chat') showView('chat', 'nav-side-chat');
    else if (location.hash === '#profile') showView('profile', 'nav-tab-profile');
    else showView('dashboard', 'nav-tab-activities');
    loadProfile().then(() => {
        setupWorkPortalLink();
        startAttendanceRefresh();
    });
    startPortalNotificationPolling();
    window.addEventListener('message', (e) => {
        if (e.data?.type === 'portal-chat-unread') {
            const total = parseInt(e.data.total, 10) || 0;
            updateChatBadges(total);
            HRMS.lastChatUnread = total;
        }
        if (e.data?.type === 'portal-navigate' && e.data.view) {
            if (location.hash === '#chat') {
                history.replaceState(null, '', location.pathname + location.search);
            }
            showView(e.data.view, ESS_DEFAULT_NAV[e.data.view] || null);
        }
    });
    document.addEventListener('click', () => window.PortalNotifySound?.unlock?.(), { once: true, capture: true });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            pollNotifications();
            if (HRMS.currentView !== 'chat') pollChatUnread();
            if (HRMS.todayDuty?.timerActive || isOnDuty(HRMS.todayDuty?.checkIn, HRMS.todayDuty?.checkOut)) {
                updateLiveTimer();
            }
        }
    });
});

window.HRMS = HRMS;
window.openLeaveApplyModal = openLeaveApplyModal;
window.closeLeaveApplyModal = closeLeaveApplyModal;
window.openLeaveWithdrawModal = openLeaveWithdrawModal;
window.closeLeaveWithdrawModal = closeLeaveWithdrawModal;
window.updatePortalChatBadge = updateChatBadges;
window.HRMS.approveLeave = approveLeave;
window.HRMS.openApprovalModal = openApprovalModal;
window.HRMS.closeApprovalModal = closeApprovalModal;
window.showView = showView;
