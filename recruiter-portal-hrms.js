/**
 * Recruiter portal — own profile, attendance, payroll (same data as Employee HRMS).
 */
let hrmsCache = null;

async function fetchHrmsData(force) {
  if (hrmsCache && !force) return hrmsCache;
  const res = await apiFetch(API.employeeHrms);
  if (!res.success) {
    toast(res.message || res.error || 'Could not load your HR data', 'error');
    return null;
  }
  hrmsCache = res;
  return res;
}

function hrmsMoney(n) {
  if (n == null || n === '') return '—';
  return 'Rs ' + Number(n).toLocaleString('en-PK', { maximumFractionDigits: 0 });
}

function hrmsTime(ts) {
  if (!ts) return '—';
  return new Date(ts).toLocaleString('en-PK', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'
  });
}

function hrmsBidNotice(meta) {
  if (!meta) return '';
  const code = meta.resolved_employee_code || '';
  const profile = meta.profile_employee_code || '';
  if (!code) {
    return `
    <div class="hrms-alert">
      <i class="fas fa-exclamation-triangle"></i>
      <div>
        <strong>No attendance link found</strong>
        <p>We could not match your name to a biometric ID. Ask HR to set your <strong>BID</strong> on your user account (same as device / employee sheet).</p>
      </div>
    </div>`;
  }
  if (meta.bid_auto_updated) {
    return `
    <div class="hrms-alert info">
      <i class="fas fa-check-circle"></i>
      <div>
        <strong>BID linked automatically</strong>
        <p>Your account now uses BID <strong>${esc(String(code))}</strong> (was ${esc(String(profile || 'empty'))}). Attendance and payroll should load correctly.</p>
      </div>
    </div>`;
  }
  if (profile && profile !== code) {
    return `
    <div class="hrms-alert info">
      <i class="fas fa-link"></i>
      <div>
        <strong>Using BID ${esc(String(code))}</strong>
        <p>${esc(meta.resolution_label || 'Matched from roster')}. Profile had: ${esc(String(profile))}.</p>
      </div>
    </div>`;
  }
  if (meta.resolution_source && meta.resolution_source !== 'profile') {
    return `
    <div class="hrms-alert info">
      <i class="fas fa-info-circle"></i>
      <div>
        <strong>BID ${esc(String(code))}</strong>
        <p>${esc(meta.resolution_label || '')}</p>
      </div>
    </div>`;
  }
  return '';
}

function hrmsTopBar(title, subtitle, refreshFn) {
  return `
    <div class="top-bar">
      <div class="page-title">
        <h1>${title}</h1>
        <p>${subtitle}</p>
      </div>
      <div class="top-actions">
        <button class="btn btn-primary" onclick="${refreshFn}()"><i class="fas fa-sync-alt"></i> Refresh</button>
        <a href="chat-portal.html" class="btn btn-info" style="text-decoration:none;"><i class="fas fa-comments"></i> Chat</a>
      </div>
    </div>`;
}

async function showMyHrms() {
  setActiveNav('myHrms');
  setLoading('<div class="loading-state"><div class="loading-spinner"></div><p>Loading your profile…</p></div>');
  hrmsCache = null;
  const data = await fetchHrmsData(true);
  if (!data) {
    setLoading(`<div class="top-bar"><div class="page-title"><h1>My Profile</h1></div></div>
      <div class="empty-state"><i class="fas fa-user-slash"></i><p>Could not load profile. Try logging in again.</p></div>`);
    return;
  }

  const u = data.user || {};
  const sum = data.attendance_summary || {};
  const today = data.today || {};
  const p = data.payroll || {};
  const meta = data.meta || {};
  const hasBid = !!(meta.employee_code_set && meta.resolved_employee_code);

  let html = hrmsTopBar('👤 My Profile', 'Your details, attendance summary & payroll snapshot', 'showMyHrms');

  html += hrmsBidNotice(meta);

  if (isSuperAdmin) {
    html += `
      <div class="hrms-quick-actions">
        <button type="button" class="btn btn-primary" onclick="showRecruitersList()"><i class="fas fa-user-plus"></i> Create / manage accounts</button>
        <button type="button" class="btn btn-secondary" onclick="showMyLeads()"><i class="fas fa-users"></i> Pipeline work</button>
      </div>`;
  }

  html += `
    <div class="stats-grid stats-4" style="margin-bottom:20px;">
      <div class="stat-card" onclick="showMyAttendance()" style="cursor:pointer;">
        <div class="stat-icon green"><i class="fas fa-calendar-check"></i></div>
        <div class="stat-value">${sum.present_days ?? 0}</div>
        <div class="stat-label">Present (month)</div>
      </div>
      <div class="stat-card" onclick="showMyAttendance()" style="cursor:pointer;">
        <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
        <div class="stat-value">${sum.late_days ?? 0}</div>
        <div class="stat-label">Late (month)</div>
      </div>
      <div class="stat-card" onclick="showMyAttendance()" style="cursor:pointer;">
        <div class="stat-icon blue"><i class="fas fa-fingerprint"></i></div>
        <div class="stat-value">${sum.total_punches ?? 0}</div>
        <div class="stat-label">Punches (30d)</div>
      </div>
      <div class="stat-card" onclick="showMyPayroll()" style="cursor:pointer;">
        <div class="stat-icon purple"><i class="fas fa-wallet"></i></div>
        <div class="stat-value" style="font-size:18px;">${p.has_data ? hrmsMoney(p.basic_salary) : '—'}</div>
        <div class="stat-label">Basic salary</div>
      </div>
    </div>

    <div class="stats-grid stats-2" style="margin-bottom:20px;">
      <div class="stat-card">
        <div class="stat-label">Today check-in</div>
        <div class="stat-value" style="font-size:20px;">${today.check_in ? hrmsTime(today.check_in) : '—'}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Today check-out</div>
        <div class="stat-value" style="font-size:20px;">${today.check_out ? hrmsTime(today.check_out) : '—'}</div>
      </div>
    </div>

    <div class="table-wrap">
      <div class="table-header"><h3><i class="fas fa-id-card"></i> Personal details</h3></div>
      <div class="hrms-profile-grid">
        <div><span class="hrms-lbl">Full name</span><strong>${esc(u.full_name || '—')}</strong></div>
        <div><span class="hrms-lbl">Email</span><strong>${esc(u.email || '—')}</strong></div>
        <div><span class="hrms-lbl">Employee ID (BID)</span><strong>${esc(u.employee_code || '—')}</strong></div>
        <div><span class="hrms-lbl">Department</span><strong>${esc(u.department || '—')}</strong></div>
        <div><span class="hrms-lbl">Designation</span><strong>${esc(u.designation || '—')}</strong></div>
        <div><span class="hrms-lbl">Team</span><strong>${esc(u.team || '—')}</strong></div>
        <div><span class="hrms-lbl">Branch</span><strong>${esc(u.branch || data.company_branch_label || '—')}</strong></div>
        <div><span class="hrms-lbl">Portal role</span><strong>${esc(u.portal_role || 'recruiter')}</strong></div>
      </div>
    </div>

    <div class="hrms-quick-actions" style="margin-top:20px;">
      <button type="button" class="btn btn-primary" onclick="showMyAttendance()"><i class="fas fa-fingerprint"></i> View attendance</button>
      <button type="button" class="btn btn-primary" onclick="showMyPayroll()"><i class="fas fa-wallet"></i> View payroll</button>
      <a href="employee-portal.html" class="btn btn-secondary" style="text-decoration:none;"><i class="fas fa-calendar-minus"></i> Leaves &amp; HR portal</a>
      <a href="chat-portal.html" class="btn btn-info" style="text-decoration:none;"><i class="fas fa-comments"></i> Open chat</a>
    </div>`;

  document.getElementById('mainContent').innerHTML = html;
  if (refreshTimer) clearInterval(refreshTimer);
}

async function showMyAttendance() {
  setActiveNav('myAttendance');
  setLoading();
  const data = await fetchHrmsData();
  if (!data) return;

  const rows = data.attendance_raw || [];
  const meta = data.meta || {};

  let html = hrmsTopBar('🕐 My Attendance', 'Punch history for the last 30 days', 'showMyAttendance');
  html += hrmsBidNotice(meta);

  html += `
    <div class="table-wrap">
      <div class="table-header">
        <h3><i class="fas fa-list"></i> Recent punches</h3>
        <span class="badge badge-active">${rows.length} records</span>
      </div>
      <table>
        <thead><tr><th>Date</th><th>Time</th></tr></thead>
        <tbody>`;

  if (!rows.length) {
    html += `<tr><td colspan="2" class="empty-state"><i class="fas fa-fingerprint"></i><p>No attendance punches found for your BID.</p></td></tr>`;
  } else {
    [...rows].reverse().forEach(r => {
      const ts = r.timestamp;
      html += `<tr><td>${esc(fmt(ts))}</td><td>${esc(hrmsTime(ts))}</td></tr>`;
    });
  }

  html += `</tbody></table></div>
    <div class="hrms-quick-actions" style="margin-top:16px;">
      <button type="button" class="btn btn-secondary" onclick="showMyHrms()"><i class="fas fa-arrow-left"></i> Back to profile</button>
    </div>`;

  document.getElementById('mainContent').innerHTML = html;
  if (refreshTimer) clearInterval(refreshTimer);
}

async function showMyPayroll() {
  setActiveNav('myPayroll');
  setLoading();
  const data = await fetchHrmsData();
  if (!data) return;

  const p = data.payroll || {};
  const meta = data.meta || {};
  const hasBid = !!(meta.resolved_employee_code);
  const monthLabel = p.month || new Date().toISOString().slice(0, 7);

  let html = hrmsTopBar('💰 My Payroll', `Salary details · ${monthLabel}`, 'showMyPayroll');
  html += hrmsBidNotice(meta);

  if (!p.has_data && hasBid) {
    html += `<div class="hrms-alert info"><i class="fas fa-info-circle"></i><div><strong>No payroll row yet</strong><p>HR may still be setting up your salary in the system.</p></div></div>`;
  }

  html += `
    <div class="stats-grid stats-4" style="margin-bottom:20px;">
      <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-money-bill-wave"></i></div>
        <div class="stat-value" style="font-size:22px;">${hrmsMoney(p.basic_salary)}</div>
        <div class="stat-label">Basic salary</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-gift"></i></div>
        <div class="stat-value" style="font-size:22px;">${hrmsMoney(p.bonus)}</div>
        <div class="stat-label">Bonus (month)</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-car"></i></div>
        <div class="stat-value" style="font-size:22px;">${hrmsMoney(p.tada)}</div>
        <div class="stat-label">TADA (month)</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-hand-holding-usd"></i></div>
        <div class="stat-value" style="font-size:22px;">${hrmsMoney(p.advance_per_month)}</div>
        <div class="stat-label">Advance / month</div>
      </div>
    </div>

    <div class="table-wrap">
      <div class="table-header"><h3><i class="fas fa-university"></i> Bank &amp; extras</h3></div>
      <div class="hrms-profile-grid">
        <div><span class="hrms-lbl">Designation (payroll)</span><strong>${esc(p.designation || '—')}</strong></div>
        <div><span class="hrms-lbl">Bank</span><strong>${esc(p.bank_name || '—')}</strong></div>
        <div><span class="hrms-lbl">Account title</span><strong>${esc(p.account_title || '—')}</strong></div>
        <div><span class="hrms-lbl">Account no.</span><strong>${esc(p.account_no || '—')}</strong></div>
        <div><span class="hrms-lbl">Leaves this month</span><strong>${p.leaves_this_month ?? 0}</strong></div>
        <div><span class="hrms-lbl">Pay month</span><strong>${esc(monthLabel)}</strong></div>
      </div>
    </div>

    <div class="hrms-quick-actions" style="margin-top:16px;">
      <button type="button" class="btn btn-secondary" onclick="showMyHrms()"><i class="fas fa-arrow-left"></i> Back to profile</button>
      <a href="employee-portal.html" class="btn btn-primary" style="text-decoration:none;"><i class="fas fa-external-link-alt"></i> Full HR portal</a>
    </div>`;

  document.getElementById('mainContent').innerHTML = html;
  if (refreshTimer) clearInterval(refreshTimer);
}

window.showMyHrms = showMyHrms;
window.showMyAttendance = showMyAttendance;
window.showMyPayroll = showMyPayroll;
