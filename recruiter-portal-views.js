// ===== RECRUITER PORTAL VIEWS - COMPLETE =====

let charts = {};

// Global Filters
window.dashBranch = 'all';
window.dashDateRange = 'all_time';

function destroyCharts() {
  if (charts && typeof charts === 'object') {
    Object.keys(charts).forEach(k => {
      try {
        if (charts[k] && typeof charts[k].destroy === 'function') {
          charts[k].destroy();
        }
      } catch (e) {}
    });
  }
  charts = {};
}

function changeDashBranch(b) {
  window.dashBranch = b;
  showDashboard();
}

function changeDashRange(r) {
  window.dashDateRange = r;
  showDashboard();
}

// --- REVAMPED DASHBOARD ---
async function showDashboard() {
  setActiveNav('dashboard');
  if (!document.getElementById('dashView')) setLoading();

  const bParam = encodeURIComponent(window.dashBranch || 'all');
  const rParam = encodeURIComponent(window.dashDateRange || 'all_time');
  const res = await apiFetch(`${API.stats}?branch=${bParam}&range=${rParam}`);
  
  if (!res.success) {
    toast(res.error || 'Failed to load dashboard metrics', 'error');
    return;
  }
  const s = res.data || {};
  lastRefresh = new Date();

  // Branch selector options for Admins / Super Admin / HR
  let branchSelectHtml = '';
  if (isSuperAdmin && s.available_branches) {
    const branches = s.available_branches;
    branchSelectHtml = `
      <select class="branch-select-badge" onchange="changeDashBranch(this.value)" title="Filter by Branch">
        <option value="all" ${window.dashBranch === 'all' ? 'selected' : ''}>🏢 All Branches</option>
        ${Object.keys(branches).map(k => `
          <option value="${k}" ${window.dashBranch === k ? 'selected' : ''}>${esc(branches[k].label)}</option>
        `).join('')}
      </select>
    `;
  }

  // Active date filter helper
  const rangePills = [
    { key: 'all_time', label: 'All Time' },
    { key: 'today', label: 'Today' },
    { key: 'this_week', label: 'This Week' },
    { key: 'this_month', label: 'This Month' }
  ];

  let html = `
    <div class="top-bar" id="dashView" style="margin-bottom: 14px;">
      <div class="page-title">
        <h1>📊 Recruiter Operations Dashboard</h1>
        <p style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span style="color:var(--text-secondary);"><i class="fas fa-building" style="color:var(--primary);"></i> ${esc(s.branch_label || 'Main Branch')}</span>
          <span style="color:rgba(255,255,255,0.25);">•</span>
          <span class="live-indicator">
            <span class="live-dot"></span>
            <span style="font-weight:600;">Live Activity</span>
            <span style="opacity:0.4;">•</span>
            <span id="liveUpdated" style="font-weight:600;">Just now</span>
          </span>
        </p>
      </div>
      <div class="top-actions">
        ${isSuperAdmin ? `
          <button class="btn btn-warning btn-sm" onclick="showTeamPerformance()">
            <i class="fas fa-users-cog"></i> Team Performance
          </button>
        ` : ''}
        <button class="btn btn-primary btn-sm" onclick="showDashboard()">
          <i class="fas fa-sync-alt"></i> Refresh Data
        </button>
        <button class="btn btn-info btn-sm" onclick="showExportPerformanceModal()">
          <i class="fas fa-file-excel"></i> Export Report
        </button>
      </div>
    </div>

    <!-- Control & Filter Bar -->
    <div class="dash-control-bar">
      <div class="dash-filter-group">
        <span style="font-size: 12px; font-weight: 700; color: var(--text-muted);"><i class="fas fa-calendar-alt"></i> Date Range:</span>
        <div class="filter-pills">
          ${rangePills.map(p => `
            <button class="filter-pill ${window.dashDateRange === p.key ? 'active' : ''}" onclick="changeDashRange('${p.key}')">
              ${p.label}
            </button>
          `).join('')}
        </div>
      </div>
      <div class="dash-filter-group">
        ${branchSelectHtml}
        <span style="font-size: 11px; color: var(--text-dim);">Last synced: ${fmtTime(new Date())}</span>
      </div>
    </div>
  `;

  // For Regular Recruiter: Personal Rank Trophy Card
  if (!isSuperAdmin && s.personal_rank) {
    const prk = s.personal_rank;
    const rankEmoji = prk.rank === 1 ? '🥇' : prk.rank === 2 ? '🥈' : prk.rank === 3 ? '🥉' : '⭐';
    html += `
      <div class="rank-trophy-card">
        <div class="rank-trophy-icon">${rankEmoji}</div>
        <div class="rank-trophy-text">
          <h3>Your Standing: Rank #${prk.rank} in ${esc(prk.branch)}</h3>
          <p>Compromising ${prk.total} active recruiters. Keep dialing and converting leads to maintain top performance!</p>
        </div>
      </div>
    `;
  }

  // Row 1: 6 Real KPI Cards
  const assignedCnt = s.assigned_leads || 0;
  const dialedCnt = s.dialed_leads || 0;
  const remCnt = s.remaining_leads || 0;
  const schedCnt = s.scheduled_leads || 0;
  const appearedCnt = s.appeared_leads || 0;
  const hiredCnt = s.hired_leads || 0;

  html += `
    <div class="stats-grid stats-6">
      <!-- Assigned Leads -->
      <div class="stat-card stat-card-compact theme-blue" onclick="${isSuperAdmin ? 'showTeamPerformance()' : 'showMyLeads()'}" title="Assigned Leads">
        <div class="stat-card-top">
          <div class="stat-icon-mini blue"><i class="fas fa-address-book"></i></div>
          <span class="stat-pill ${s.today_assigned > 0 ? 'active' : ''}">+${s.today_assigned || 0} Today</span>
        </div>
        <div class="stat-card-body">
          <div class="stat-num">${formatNumber(assignedCnt)}</div>
          <div class="stat-title">Assigned Leads</div>
        </div>
      </div>

      <!-- Dialed Leads -->
      <div class="stat-card stat-card-compact theme-yellow" onclick="${isSuperAdmin ? 'showTeamPerformance()' : 'showMyLeads()'}" title="Dialed Leads">
        <div class="stat-card-top">
          <div class="stat-icon-mini yellow"><i class="fas fa-phone-volume"></i></div>
          <span class="stat-pill ${s.today_dialed > 0 ? 'active' : ''}">+${s.today_dialed || 0} Today</span>
        </div>
        <div class="stat-card-body">
          <div class="stat-num">${formatNumber(dialedCnt)}</div>
          <div class="stat-title">Dialed Leads</div>
        </div>
      </div>

      <!-- Remaining / Not Dialed -->
      <div class="stat-card stat-card-compact theme-red" onclick="${isSuperAdmin ? 'showTeamPerformance()' : 'showMyLeads()'}" title="Remaining (Uncalled)">
        <div class="stat-card-top">
          <div class="stat-icon-mini red"><i class="fas fa-hourglass-start"></i></div>
          <span class="stat-pill warning">Pending</span>
        </div>
        <div class="stat-card-body">
          <div class="stat-num">${formatNumber(remCnt)}</div>
          <div class="stat-title">Remaining</div>
        </div>
      </div>

      <!-- Scheduled Interviews -->
      <div class="stat-card stat-card-compact theme-purple" onclick="${isSuperAdmin ? 'showAllLeads(0, \"\", \"interview_scheduled\")' : 'showMyLeads()'}" title="Scheduled Interviews">
        <div class="stat-card-top">
          <div class="stat-icon-mini purple"><i class="fas fa-calendar-check"></i></div>
          <span class="stat-pill ${s.today_scheduled > 0 ? 'active' : ''}">+${s.today_scheduled || 0} Today</span>
        </div>
        <div class="stat-card-body">
          <div class="stat-num">${formatNumber(schedCnt)}</div>
          <div class="stat-title">Scheduled</div>
        </div>
      </div>

      <!-- Appeared -->
      <div class="stat-card stat-card-compact theme-cyan" title="Appeared Candidates">
        <div class="stat-card-top">
          <div class="stat-icon-mini cyan"><i class="fas fa-user-check"></i></div>
          <span class="stat-pill ${s.today_appeared > 0 ? 'active' : ''}">+${s.today_appeared || 0} Today</span>
        </div>
        <div class="stat-card-body">
          <div class="stat-num">${formatNumber(appearedCnt)}</div>
          <div class="stat-title">Appeared</div>
        </div>
      </div>

      <!-- Hired -->
      <div class="stat-card stat-card-compact theme-green" title="Hired / Training">
        <div class="stat-card-top">
          <div class="stat-icon-mini green"><i class="fas fa-award"></i></div>
          <span class="stat-pill ${s.today_hired > 0 ? 'active' : ''}">+${s.today_hired || 0} Today</span>
        </div>
        <div class="stat-card-body">
          <div class="stat-num">${formatNumber(hiredCnt)}</div>
          <div class="stat-title">Hired (Training+)</div>
        </div>
      </div>
    </div>

    <!-- Row 2: 4 Real Interactive ApexCharts -->
    <div class="charts-grid-4">
      <!-- 1. Recruitment Conversion Funnel -->
      <div class="chart-card">
        <div class="chart-card-header">
          <h4><i class="fas fa-filter" style="color:var(--primary)"></i> Recruitment Conversion Funnel</h4>
          <span class="badge badge-active" style="font-size: 11px;">
            Conversion: ${assignedCnt > 0 ? Math.round((hiredCnt / assignedCnt) * 100) : 0}%
          </span>
        </div>
        <div id="recruitmentFunnelChart" style="height: 290px;"></div>
      </div>

      <!-- 2. 14-Day Performance Trend (Area) -->
      <div class="chart-card">
        <div class="chart-card-header">
          <h4><i class="fas fa-chart-area" style="color:var(--info)"></i> 14-Day Department Activity Trend</h4>
          <span class="badge" style="background: rgba(59,130,246,0.15); color: #60a5fa; font-size: 11px;">
            Daily Flow
          </span>
        </div>
        <div id="dailyPerformanceChart" style="height: 290px;"></div>
      </div>

      <!-- 3. Leaderboard or My Activity Chart -->
      <div class="chart-card">
        <div class="chart-card-header">
          <h4>
            <i class="fas ${isSuperAdmin ? 'fa-trophy' : 'fa-chart-bar'}" style="color:var(--warning)"></i> 
            ${isSuperAdmin ? 'Recruiter Team Leaderboard' : 'My Conversion Metrics'}
          </h4>
          <span class="badge badge-active" style="font-size: 11px;">Performance</span>
        </div>
        <div id="leaderboardChart" style="height: 290px;"></div>
      </div>

      <!-- 4. Lead Sources Breakdown -->
      <div class="chart-card">
        <div class="chart-card-header">
          <h4><i class="fas fa-pie-chart" style="color:var(--purple)"></i> Lead Acquisition Channels</h4>
          <span class="badge" style="background: rgba(139,92,246,0.15); color: #a78bfa; font-size: 11px;">
            Source Channels
          </span>
        </div>
        <div id="sourceBreakdownChart" style="height: 290px;"></div>
      </div>
    </div>

    <!-- Row 3: Feeds & Priority Lists Grid -->
    <div class="dashboard-advanced-grid">
      <!-- Col 1: Real-time Live Activity Feed -->
      <div class="dash-col">
        <div class="chart-container activity-feed" style="height: 100%; min-height: 380px;">
          <div class="chart-header">
            <h4><i class="fas fa-bolt" style="color:var(--primary)"></i> Real-time Activity Feed</h4>
            <div class="live-indicator">
              <span class="live-dot"></span>
              <span>Live Updates</span>
            </div>
          </div>
          <div class="activity-list">
            ${(s.recent_activity || []).map(a => `
              <div class="activity-item">
                <div class="activity-icon ${a.action==='assign'?'purple':a.action==='update'?'blue':'green'}">
                  <i class="fas ${a.action==='assign'?'fa-share':a.action==='update'?'fa-edit':'fa-check'}"></i>
                </div>
                <div class="activity-content">
                  <p><strong>${esc(a.user_name || 'System')}</strong> ${esc(a.notes || 'updated status')}</p>
                  <div class="activity-meta">
                    <span><i class="fas fa-user"></i> ${esc(a.lead_name || 'Candidate')}</span>
                    <span><i class="fas fa-clock"></i> ${ago(a.created_at)}</span>
                  </div>
                </div>
              </div>
            `).join('') || '<div class="empty-state">No recent activity recorded yet.</div>'}
          </div>
        </div>
      </div>

      <!-- Col 2: Upcoming Interviews -->
      <div class="dash-col">
        <div class="chart-container upcoming-interviews" style="height: 100%; min-height: 380px;">
          <div class="chart-header">
            <h4><i class="fas fa-calendar-day" style="color:var(--purple)"></i> Upcoming Interviews</h4>
            <span class="badge badge-active">${(s.upcoming_interviews || []).length} Scheduled</span>
          </div>
          <div class="upcoming-list">
            ${(s.upcoming_interviews || []).map(i => `
              <div class="upcoming-item">
                <div class="date-badge">
                  <span class="day">${new Date(i.interview_date).getDate()}</span>
                  <span class="month">${new Date(i.interview_date).toLocaleString('en-US', {month: 'short'})}</span>
                </div>
                <div class="upcoming-info">
                  <h5>${esc(i.full_name)}</h5>
                  <p><i class="fas fa-phone-alt"></i> ${esc(i.phone)} ${i.recruiter_name ? `• ${esc(i.recruiter_name)}` : ''}</p>
                </div>
                <button class="btn btn-secondary btn-xs" onclick="editLead(${i.id})"><i class="fas fa-arrow-right"></i></button>
              </div>
            `).join('') || '<div class="empty-state">No scheduled interviews today.</div>'}
          </div>
        </div>
      </div>

      <!-- Col 3: Priority Outreach (Uncalled / Stale) -->
      <div class="dash-col">
        <div class="chart-container" style="height: 100%; min-height: 380px;">
          <div class="chart-header">
            <h4><i class="fas fa-fire" style="color:var(--danger)"></i> Priority Outreach Required</h4>
            <span class="priority-count-badge">${(s.priority_leads || []).length} Priority</span>
          </div>
          <div class="priority-list">
            ${(s.priority_leads || []).map(l => `
              <div class="priority-item" onclick="editLead(${l.id})">
                <div class="priority-info">
                  <h5>${esc(l.full_name)}</h5>
                  <p>${esc(l.phone)} • ${l.recruiter_name ? `${esc(l.recruiter_name)} • ` : ''}${esc(l.current_stage)}</p>
                </div>
                <div class="priority-actions">
                  <span class="priority-tag">${!l.call_count ? 'NEVER CALLED' : 'STALE (3D+)'}</span>
                  <button class="btn btn-primary btn-xs" onclick="event.stopPropagation(); editLead(${l.id})">
                    <i class="fas fa-phone"></i> Call
                  </button>
                </div>
              </div>
            `).join('') || '<div class="empty-state">All leads are up-to-date! Great job.</div>'}
          </div>
        </div>
      </div>
    </div>
  `;

  renderMainView(html);

  // Render Charts after DOM injection
  setTimeout(() => {
    renderDashboardCharts(s);
  }, 100);

  startAutoRefresh(showDashboard);
}

// Render Dashboard ApexCharts
function renderDashboardCharts(s) {
  if (typeof ApexCharts === 'undefined') return;
  destroyCharts();

  // 1. Recruitment Funnel Chart (Horizontal Bar)
  const funnelEl = document.querySelector("#recruitmentFunnelChart");
  if (funnelEl) {
    const funnelOptions = {
      series: [{
        name: 'Candidates',
        data: [
          s.assigned_leads || 0,
          s.dialed_leads || 0,
          s.scheduled_leads || 0,
          s.appeared_leads || 0,
          s.hired_leads || 0
        ]
      }],
      chart: {
        type: 'bar',
        height: 280,
        toolbar: { show: false },
        background: 'transparent'
      },
      plotOptions: {
        bar: {
          borderRadius: 6,
          horizontal: true,
          barHeight: '55%',
          distributed: true,
          dataLabels: { position: 'right' }
        }
      },
      colors: ['#3b82f6', '#f59e0b', '#8b5cf6', '#06b6d4', '#10b981'],
      dataLabels: {
        enabled: true,
        textAnchor: 'start',
        offsetX: 10,
        style: { colors: ['#fff'], fontSize: '12px', fontWeight: 700 }
      },
      xaxis: {
        categories: ['Assigned', 'Dialed', 'Scheduled', 'Appeared', 'Hired'],
        labels: { style: { colors: '#94a3b8', fontSize: '11px' } },
        axisBorder: { show: false }
      },
      yaxis: {
        labels: { style: { colors: '#e2e8f0', fontSize: '12px', fontWeight: 600 } }
      },
      grid: { borderColor: 'rgba(255,255,255,0.05)' },
      tooltip: { theme: 'dark' },
      legend: { show: false }
    };
    const fChart = new ApexCharts(funnelEl, funnelOptions);
    fChart.render();
    charts.funnel = fChart;
  }

  // 2. 14-Day Performance Trend Chart (Area)
  const trendEl = document.querySelector("#dailyPerformanceChart");
  if (trendEl) {
    const trendData = s.daily_trend || [];
    const trendOptions = {
      series: [
        { name: 'Dialed Calls', data: trendData.map(d => d.dialed || 0) },
        { name: 'Interviews Scheduled', data: trendData.map(d => d.scheduled || 0) },
        { name: 'Hires', data: trendData.map(d => d.hired || 0) }
      ],
      chart: {
        type: 'area',
        height: 280,
        toolbar: { show: false },
        background: 'transparent'
      },
      colors: ['#f59e0b', '#8b5cf6', '#10b981'],
      stroke: { curve: 'smooth', width: 2.5 },
      fill: {
        type: 'gradient',
        gradient: {
          shadeIntensity: 1,
          opacityFrom: 0.45,
          opacityTo: 0.05,
          stops: [0, 90, 100]
        }
      },
      dataLabels: { enabled: false },
      xaxis: {
        categories: trendData.map(d => d.label || d.date),
        labels: { style: { colors: '#94a3b8', fontSize: '10px' } },
        axisBorder: { show: false }
      },
      yaxis: {
        labels: { style: { colors: '#94a3b8', fontSize: '11px' } }
      },
      grid: { borderColor: 'rgba(255,255,255,0.05)' },
      legend: {
        position: 'top',
        horizontalAlign: 'right',
        labels: { colors: '#94a3b8' },
        markers: { radius: 6 }
      },
      tooltip: { theme: 'dark' }
    };
    const tChart = new ApexCharts(trendEl, trendOptions);
    tChart.render();
    charts.trend = tChart;
  }

  // 3. Leaderboard Chart (or Personal breakdown)
  const lbEl = document.querySelector("#leaderboardChart");
  if (lbEl) {
    if (isSuperAdmin && s.recruiter_breakdown && s.recruiter_breakdown.length) {
      // Top 6 recruiters
      const topRecs = s.recruiter_breakdown.slice(0, 6);
      const lbOptions = {
        series: [
          { name: 'Dialed', data: topRecs.map(r => r.dialed || 0) },
          { name: 'Scheduled', data: topRecs.map(r => r.scheduled || 0) },
          { name: 'Hired', data: topRecs.map(r => r.hired || 0) }
        ],
        chart: {
          type: 'bar',
          height: 280,
          toolbar: { show: false },
          background: 'transparent'
        },
        plotOptions: {
          bar: {
            horizontal: false,
            columnWidth: '55%',
            borderRadius: 5
          }
        },
        colors: ['#f59e0b', '#8b5cf6', '#10b981'],
        dataLabels: { enabled: false },
        xaxis: {
          categories: topRecs.map(r => r.full_name.split(' ')[0]),
          labels: { style: { colors: '#cbd5e1', fontSize: '11px', fontWeight: 600 } }
        },
        yaxis: {
          labels: { style: { colors: '#94a3b8', fontSize: '11px' } }
        },
        grid: { borderColor: 'rgba(255,255,255,0.05)' },
        legend: {
          position: 'top',
          horizontalAlign: 'right',
          labels: { colors: '#94a3b8' }
        },
        tooltip: { theme: 'dark' }
      };
      const lChart = new ApexCharts(lbEl, lbOptions);
      lChart.render();
      charts.leaderboard = lChart;
    } else {
      // Regular Recruiter Personal Status Donut
      const lbOptions = {
        series: [
          s.dialed_leads || 0,
          s.remaining_leads || 0,
          s.scheduled_leads || 0,
          s.hired_leads || 0
        ],
        chart: {
          type: 'donut',
          height: 280,
          background: 'transparent'
        },
        labels: ['Dialed', 'Remaining', 'Scheduled', 'Hired'],
        colors: ['#f59e0b', '#ef4444', '#8b5cf6', '#10b981'],
        legend: {
          position: 'bottom',
          labels: { colors: '#94a3b8' }
        },
        plotOptions: {
          pie: {
            donut: {
              size: '65%',
              labels: {
                show: true,
                total: {
                  show: true,
                  label: 'My Leads',
                  fontSize: '13px',
                  color: '#94a3b8',
                  formatter: () => s.assigned_leads || 0
                }
              }
            }
          }
        },
        stroke: { show: false },
        tooltip: { theme: 'dark' }
      };
      const lChart = new ApexCharts(lbEl, lbOptions);
      lChart.render();
      charts.leaderboard = lChart;
    }
  }

  // 4. Lead Source Channels Donut Chart
  const srcEl = document.querySelector("#sourceBreakdownChart");
  if (srcEl) {
    const srcList = s.source_breakdown && s.source_breakdown.length 
      ? s.source_breakdown 
      : [{ src: 'Direct Intake', cnt: 1 }];
    
    const srcOptions = {
      series: srcList.map(x => parseInt(x.cnt) || 0),
      labels: srcList.map(x => x.src || 'Direct'),
      chart: {
        type: 'donut',
        height: 280,
        background: 'transparent'
      },
      colors: ['#f97316', '#3b82f6', '#10b981', '#8b5cf6', '#ec4899', '#f59e0b'],
      legend: {
        position: 'bottom',
        labels: { colors: '#94a3b8', fontSize: '11px' },
        markers: { radius: 6 }
      },
      plotOptions: {
        pie: {
          donut: {
            size: '68%',
            labels: {
              show: true,
              total: {
                show: true,
                label: 'Total Intake',
                fontSize: '13px',
                color: '#94a3b8',
                formatter: () => s.total_leads || (srcList.reduce((a, b) => a + (parseInt(b.cnt) || 0), 0))
              }
            }
          }
        }
      },
      stroke: { show: false },
      tooltip: { theme: 'dark' }
    };
    const sChart = new ApexCharts(srcEl, srcOptions);
    sChart.render();
    charts.source = sChart;
  }
}

// Format numbers with K/M suffix
function formatNumber(num) {
  if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
  if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
  return num.toString();
}

// ==================== PERFORMANCE EXPORT MODAL & DOWNLOAD ====================

// Cache recruiters list for the export selector
window.cachedRecruiterList = window.cachedRecruiterList || [];

async function getExportRecruitersList() {
  if (window.cachedRecruiterList && window.cachedRecruiterList.length > 0) {
    return window.cachedRecruiterList;
  }
  try {
    const res = await apiFetch(`${API.performance}?branch=all`);
    if (res.success && res.data && res.data.recruiters) {
      window.cachedRecruiterList = res.data.recruiters;
      return window.cachedRecruiterList;
    }
  } catch (e) {
    console.error('Failed to load recruiters list for export:', e);
  }
  return [];
}

async function showExportPerformanceModal(preselectRecruiterId = null) {
  const recruiters = await getExportRecruitersList();
  const currentBranch = window.dashBranch || 'all';
  const defaultMode = (!isSuperAdmin || preselectRecruiterId) ? 'individual' : 'team';
  
  // Real company branches configured in Balitech HRMS
  const branches = [
    { key: 'all', label: '🏢 All Branches (Centralized)' },
    { key: 'main', label: '🏢 Main Branch' },
    { key: 'v2', label: '🏢 2.0 Branch' },
    { key: 'v3', label: '🏢 3.0 Branch' },
    { key: 'commercial', label: '🏢 Commercial Branch' },
    { key: 'I9', label: '🏢 I-9 Branch' },
    { key: 'workfromhome', label: '🏠 Work From Home' }
  ];

  const recBranchLabel = (r) => {
    if (!r.company_branch) return 'Main Branch';
    const found = COMPANY_BRANCH_OPTIONS.find(b => b.key.toLowerCase() === r.company_branch.toLowerCase());
    return found ? found.label : r.company_branch;
  };

  const html = `
    <div class="modal-overlay" id="exportPerfModal" style="z-index: 9999;">
      <div class="modal" style="max-width: 520px; width: 92%; background: #0f172a; border: 1px solid rgba(255, 255, 255, 0.16); border-radius: 18px; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.95), 0 0 35px rgba(16, 185, 129, 0.08); overflow: hidden; animation: modalPop 0.22s cubic-bezier(0.16, 1, 0.3, 1);">
        
        <!-- Modal Header -->
        <div class="modal-header" style="background: linear-gradient(180deg, #1e293b, #0f172a); border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding: 18px 24px; display: flex; align-items: center; justify-content: space-between;">
          <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-file-excel" style="color: #10b981; font-size: 20px;"></i>
            <span>Export Performance Report</span>
          </h3>
          <button class="modal-close" onclick="closeModal()" title="Close">&times;</button>
        </div>

        <!-- Modal Body -->
        <div class="modal-body" style="padding: 22px 24px; background: #0f172a;">
          <p style="font-size: 13px; color: #94a3b8; margin: 0 0 18px 0; line-height: 1.5;">
            Download comprehensive Excel performance reports with call stats, candidate pipelines, conversion metrics, and complete audit history.
          </p>

          ${isSuperAdmin ? `
          <!-- Report Scope Selector -->
          <div class="form-group" style="margin-bottom: 16px;">
            <label style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
              <i class="fas fa-layer-group" style="color: #f97316;"></i> Report Scope:
            </label>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
              <label class="export-scope-card ${defaultMode === 'team' ? 'active-team' : ''}" id="scopeCardTeam" onclick="toggleExportScope('team')">
                <input type="radio" name="exportScope" value="team" ${defaultMode === 'team' ? 'checked' : ''} style="display: none;">
                <span style="font-size: 13.5px; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 8px;">
                  <i class="fas fa-users" style="color: #f97316; font-size: 15px;"></i> TEAM SUMMARY
                </span>
                <span style="font-size: 11px; color: #94a3b8; line-height: 1.4;">
                  All recruiters ranked by dials, pipeline &amp; conversion
                </span>
              </label>

              <label class="export-scope-card ${defaultMode === 'individual' ? 'active-indiv' : ''}" id="scopeCardIndiv" onclick="toggleExportScope('individual')">
                <input type="radio" name="exportScope" value="individual" ${defaultMode === 'individual' ? 'checked' : ''} style="display: none;">
                <span style="font-size: 13.5px; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 8px;">
                  <i class="fas fa-user-circle" style="color: #38bdf8; font-size: 15px;"></i> SINGLE RECRUITER
                </span>
                <span style="font-size: 11px; color: #94a3b8; line-height: 1.4;">
                  Detailed candidate-level breakdown &amp; call notes
                </span>
              </label>
            </div>
          </div>
          ` : `
          <input type="hidden" id="exportScopeHidden" value="individual">
          <div style="background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; font-size: 12px; color: #7dd3fc; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-info-circle"></i> Exporting your personal performance and candidate pipeline breakdown.
          </div>
          `}

          <!-- Recruiter Selector (for individual scope) -->
          <div class="form-group" id="exportRecruiterGroup" style="margin-bottom: 15px; ${defaultMode === 'individual' && isSuperAdmin ? '' : 'display: none;'}">
            <label style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; display: flex; align-items: center; gap: 6px; margin-bottom: 7px;">
              <i class="fas fa-user-tie" style="color: #38bdf8;"></i> Select Recruiter:
            </label>
            <select id="exportRecruiterSelect" class="export-field form-control">
              ${recruiters.map(r => `
                <option value="${r.id}" ${(preselectRecruiterId && parseInt(preselectRecruiterId) === parseInt(r.id)) ? 'selected' : ''}>
                  ${esc(r.full_name)} (${esc(recBranchLabel(r))}) - ${r.total_assigned || 0} Leads
                </option>
              `).join('')}
              ${recruiters.length === 0 ? `<option value="${currentUser?.id || 0}">${esc(currentUser?.full_name || 'Current User')}</option>` : ''}
            </select>
          </div>

          <!-- Branch Filter (Super Admin only) -->
          ${isSuperAdmin ? `
          <div class="form-group" style="margin-bottom: 15px;">
            <label style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; display: flex; align-items: center; gap: 6px; margin-bottom: 7px;">
              <i class="fas fa-building" style="color: #3b82f6;"></i> Branch Scope:
            </label>
            <select id="exportBranchSelect" class="export-field form-control">
              ${branches.map(b => `
                <option value="${b.key}" ${currentBranch === b.key ? 'selected' : ''}>${b.label}</option>
              `).join('')}
            </select>
          </div>
          ` : ''}

          <!-- Date Range Filter -->
          <div class="form-group" style="margin-bottom: 15px;">
            <label style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; display: flex; align-items: center; gap: 6px; margin-bottom: 7px;">
              <i class="fas fa-calendar-alt" style="color: #10b981;"></i> Date Range:
            </label>
            <select id="exportRangeSelect" class="export-field form-control" onchange="toggleExportCustomDates()">
              <option value="all_time" selected>📅 All Time</option>
              <option value="today">⚡ Today</option>
              <option value="this_week">📆 This Week</option>
              <option value="this_month">🗓️ This Month</option>
              <option value="custom">🛠️ Custom Date Range...</option>
            </select>
          </div>

          <!-- Custom Date Range Inputs -->
          <div id="exportCustomDateGroup" style="display: none; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 15px;">
            <div>
              <label style="font-size: 11px; font-weight: 600; color: #94a3b8; display: block; margin-bottom: 4px;">From Date:</label>
              <input type="date" id="exportDateFrom" class="export-field form-control">
            </div>
            <div>
              <label style="font-size: 11px; font-weight: 600; color: #94a3b8; display: block; margin-bottom: 4px;">To Date:</label>
              <input type="date" id="exportDateTo" class="export-field form-control">
            </div>
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="modal-footer" style="padding: 16px 24px; background: #0b1120; border-top: 1px solid rgba(255, 255, 255, 0.08); display: flex; align-items: center; justify-content: space-between; gap: 12px;">
          <div style="font-size: 12px; color: #94a3b8; display: flex; align-items: center; gap: 6px;">
            <i class="fas fa-check-circle" style="color: #10b981; font-size: 13px;"></i>
            <span>UTF-8 Excel (.CSV)</span>
          </div>
          <div style="display: flex; gap: 10px;">
            <button type="button" class="btn btn-secondary" onclick="closeModal()" style="background: rgba(255,255,255,0.08); color: #cbd5e1; border: 1px solid rgba(255,255,255,0.14); border-radius: 8px; padding: 9px 18px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
              Cancel
            </button>
            <button type="button" class="btn btn-success" id="btnExecuteDownload" onclick="executeReportDownload()" style="background: linear-gradient(135deg, #10b981, #059669); color: #ffffff; border: none; border-radius: 8px; padding: 9px 20px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35); transition: all 0.2s;">
              <i class="fas fa-file-excel"></i> Download Excel (.CSV)
            </button>
          </div>
        </div>
      </div>
    </div>
  `;

  const existing = document.getElementById('exportPerfModal');
  if (existing) existing.remove();
  document.body.insertAdjacentHTML('beforeend', html);
}

function toggleExportScope(mode) {
  const cardTeam = document.getElementById('scopeCardTeam');
  const cardIndiv = document.getElementById('scopeCardIndiv');
  const recGroup = document.getElementById('exportRecruiterGroup');
  const radioTeam = cardTeam ? cardTeam.querySelector('input[type="radio"]') : null;
  const radioIndiv = cardIndiv ? cardIndiv.querySelector('input[type="radio"]') : null;

  if (mode === 'team') {
    if (radioTeam) radioTeam.checked = true;
    if (radioIndiv) radioIndiv.checked = false;
    if (cardTeam) {
      cardTeam.className = 'export-scope-card active-team';
    }
    if (cardIndiv) {
      cardIndiv.className = 'export-scope-card';
    }
    if (recGroup) recGroup.style.display = 'none';
  } else {
    if (radioIndiv) radioIndiv.checked = true;
    if (radioTeam) radioTeam.checked = false;
    if (cardIndiv) {
      cardIndiv.className = 'export-scope-card active-indiv';
    }
    if (cardTeam) {
      cardTeam.className = 'export-scope-card';
    }
    if (recGroup) recGroup.style.display = 'block';
  }
}

function toggleExportCustomDates() {
  const sel = document.getElementById('exportRangeSelect');
  const customGroup = document.getElementById('exportCustomDateGroup');
  if (customGroup) {
    customGroup.style.display = (sel && sel.value === 'custom') ? 'grid' : 'none';
  }
}

function executeReportDownload() {
  const isIndividual = !isSuperAdmin || (document.querySelector('input[name="exportScope"]:checked')?.value === 'individual');
  const branchSel = document.getElementById('exportBranchSelect');
  const rangeSel = document.getElementById('exportRangeSelect');
  const recSel = document.getElementById('exportRecruiterSelect');
  const fromInp = document.getElementById('exportDateFrom');
  const toInp = document.getElementById('exportDateTo');

  const mode = isIndividual ? 'individual' : 'team';
  const branch = branchSel ? branchSel.value : (window.dashBranch || 'all');
  const range = rangeSel ? rangeSel.value : 'all_time';
  const recruiterId = isIndividual ? (recSel ? recSel.value : (currentUser?.id || 0)) : 0;

  let url = `${API.exportReport}?mode=${mode}&branch=${encodeURIComponent(branch)}&range=${encodeURIComponent(range)}`;
  if (isIndividual && recruiterId) {
    url += `&recruiter_id=${recruiterId}`;
  }
  if (range === 'custom') {
    if (fromInp && fromInp.value) url += `&from=${encodeURIComponent(fromInp.value)}`;
    if (toInp && toInp.value) url += `&to=${encodeURIComponent(toInp.value)}`;
  }

  const btn = document.getElementById('btnExecuteDownload');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing Report...';
  }

  toast('📊 Generating performance report, download started...', 'info');

  const iframe = document.createElement('iframe');
  iframe.style.display = 'none';
  iframe.src = url;
  document.body.appendChild(iframe);

  setTimeout(() => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-file-excel"></i> Download Excel (.CSV)';
    }
    closeModal();
    setTimeout(() => { iframe.remove(); }, 6000);
    toast('✅ Report download completed successfully!', 'success');
  }, 1200);
}

// 1-Click download for specific recruiter from table row
function downloadSingleRecruiterReport(recruiterId, recruiterName) {
  if (!recruiterId) return;
  const branch = window.dashBranch || 'all';
  const range = window.dashDateRange || 'all_time';
  const url = `${API.exportReport}?mode=individual&recruiter_id=${recruiterId}&branch=${encodeURIComponent(branch)}&range=${encodeURIComponent(range)}`;

  toast(`📊 Downloading performance report for ${recruiterName || 'Recruiter'}...`, 'info');

  const iframe = document.createElement('iframe');
  iframe.style.display = 'none';
  iframe.src = url;
  document.body.appendChild(iframe);

  setTimeout(() => {
    iframe.remove();
    toast(`✅ Downloaded report for ${recruiterName || 'Recruiter'}!`, 'success');
  }, 2000);
}

// Backward compatible alias
function exportDashboardReport() {
  showExportPerformanceModal();
}

// --- ALL LEADS (Super Admin) with Advanced Table ---
async function showAllLeads(offset = 0, search = '', stage = '', branch = '') {
  if (!isSuperAdmin) return;
  setActiveNav('allLeads');
  if (offset === 0) setLoading();
  
  let url = `${API.allLeads}?offset=${offset}&search=${encodeURIComponent(search)}&stage=${encodeURIComponent(stage)}`;
  if (branch) url += `&branch=${encodeURIComponent(branch)}`;

  const res = await apiFetch(url);
  if (!res.success) return toast('Failed to load', 'error');
  
  lastRefresh = new Date();
  const leads = res.data.leads || [];
  const total = res.data.total || 0;
  const isGlobalAdmin = (currentUser.portal_role === 'super_admin' || currentUser.portal_role === 'admin');

  let html = `
  <div class="top-bar">
    <div class="page-title">
      <h1>📊 Master Database</h1>
      <p>${total} total leads - ${isGlobalAdmin ? 'All Branches Centralized' : 'Branch Synchronization'}</p>
    </div>
    <div class="top-actions">
      <button class="btn btn-info" onclick="showExportPerformanceModal()" title="Export Performance Report">
        <i class="fas fa-file-excel"></i> Export
      </button>
      <button class="btn btn-warning" onclick="syncWebsiteLeadsModal()" style="background:linear-gradient(135deg, #3b82f6, #1d4ed8); color:#fff; border:none; box-shadow:0 4px 12px rgba(59,130,246,0.35);">
        <i class="fas fa-cloud-download-alt"></i> Sync Website Leads
      </button>
      <button class="btn btn-primary" onclick="showAddLeadModal()">
        <i class="fas fa-plus"></i> Add Lead
      </button>
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" placeholder="Search by name, phone, city..." 
               value="${esc(search)}" onkeypress="if(event.key==='Enter') triggerAllLeadsFilter()">
      </div>
      ${isGlobalAdmin ? `
        <select id="branchFilter" class="form-control" style="min-width: 160px; width: auto;" onchange="triggerAllLeadsFilter()">
          <option value="" ${!branch ? 'selected' : ''}>🏢 All Branches</option>
          ${COMPANY_BRANCH_OPTIONS.map(b => `<option value="${b.key}" ${branch === b.key ? 'selected' : ''}>${esc(b.label)}</option>`).join('')}
        </select>
      ` : ''}
      <select id="stageFilter" class="form-control" style="min-width: 175px; width: auto;" onchange="triggerAllLeadsFilter()">
        <option value="">📋 All Pipeline Stages</option>
        ${statusSelectHtml(stage)}
      </select>
    </div>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Candidate</th>
          <th>Contact</th>
          <th>Position / Branch</th>
          <th>Status</th>
          <th>Last Activity</th>
          <th>Assigned To</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>`;
  
  if (!leads.length) {
    html += `<tr><td colspan="7" class="empty-state"><i class="fas fa-folder-open"></i><p>No leads found</p></td></tr>`;
  }
  
  leads.forEach(l => {
    const rawActivity = l.updated_at || l.created_at;
    const branchObj = COMPANY_BRANCH_OPTIONS.find(b => b.key.toLowerCase() === (l.company_branch || '').toLowerCase());
    const bLabel = branchObj ? branchObj.label : (l.company_branch || 'Main Branch');

    html += `
      <tr>
        <td>
          <strong>${esc(l.full_name)}</strong>
          ${l.source === 'website' ? `<span class="badge" style="background:rgba(59,130,246,0.15);color:#60a5fa;border:1px solid rgba(59,130,246,0.3);font-size:9px;margin-left:4px;padding:2px 6px;"><i class="fas fa-globe"></i> Web</span>` : ''}
          ${l.cnic ? `<br><span style="font-size: 11px; font-weight:700; color: #fb923c;"><i class="fas fa-id-card"></i> ${esc(l.cnic)}</span>` : ''}
          <br>
          <span style="font-size: 10px; color: var(--text-dim);">ID: #${l.id}</span>
        </td>
        <td>
          <i class="fas fa-phone-alt" style="font-size: 10px; color: var(--primary);"></i> ${esc(l.phone)}<br>
          ${l.email ? `<i class="fas fa-envelope" style="font-size: 10px; color: var(--text-dim);"></i> ${esc(l.email).substring(0, 20)}` : ''}
        </td>
        <td>
          <strong>${esc(l.position_applied || 'Dialer')}</strong><br>
          <span style="font-size: 11px; color: var(--text-dim);"><i class="fas fa-map-marker-alt"></i> ${esc(l.city || 'Islamabad')}</span>
          <br>
          <span class="badge" style="font-size:9px;padding:2px 6px;margin-top:3px;background:rgba(249,115,22,0.12);color:#fb923c;border:1px solid rgba(249,115,22,0.25);">
            <i class="fas fa-building"></i> ${esc(bLabel)}
          </span>
        </td>
        <td>${stageBadge(l.current_stage)}</td>
        <td style="font-size: 11px;">
          <i class="fas fa-clock" style="color:var(--primary);"></i> ${ago(rawActivity)}<br>
          <span style="color: var(--text-dim);">Created: ${fmt(l.created_at)}</span>
        </td>
        <td>
          ${l.recruiter_name ? 
            `<span class="badge badge-active"><i class="fas fa-user-check"></i> ${esc(l.recruiter_name)}</span>` : 
            '<span class="badge badge-inactive" style="background:rgba(239,68,68,0.12);color:#ef4444;"><i class="fas fa-user-clock"></i> Unassigned</span>'}
        </td>
        <td>
          <div style="display:flex;gap:6px;">
            <button class="btn btn-primary btn-sm" onclick="editLead(${l.id})">
              <i class="fas fa-edit"></i> Edit
            </button>
            ${(l.external_lead_id || l.cv_file_url) ? `
              <a href="api/fetch_lead_cv.php?external_id=${encodeURIComponent(l.external_lead_id || l.id)}" target="_blank" class="btn btn-info btn-sm" title="View CV" style="text-decoration:none;padding:6px 10px;">
                <i class="fas fa-file-pdf"></i>
              </a>
            ` : ''}
          </div>
        </td>
      </tr>`;
  });
  
  html += `</tbody>
    </table>
  </div>`;
  
  if (total > 200) {
    html += `<div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
      <span style="color: var(--text-dim); font-size: 12px;">Showing ${offset + 1} - ${Math.min(offset + 200, total)} of ${total}</span>
      <div style="display: flex; gap: 12px;">
        <button class="btn btn-secondary btn-sm" ${offset === 0 ? 'disabled' : ''} 
                onclick="showAllLeads(${Math.max(0, offset - 200)},'${esc(search)}','${stage}','${branch}')">
          <i class="fas fa-chevron-left"></i> Previous
        </button>
        <button class="btn btn-secondary btn-sm" ${offset + 200 >= total ? 'disabled' : ''} 
                onclick="showAllLeads(${offset + 200},'${esc(search)}','${stage}','${branch}')">
          Next <i class="fas fa-chevron-right"></i>
        </button>
      </div>
    </div>`;
  }
  
  renderMainView(html);
  startAutoRefresh(() => {
    const s = document.getElementById('searchInput')?.value || '';
    const st = document.getElementById('stageFilter')?.value || '';
    const br = document.getElementById('branchFilter')?.value || '';
    showAllLeads(offset, s, st, br);
  });
}

function triggerAllLeadsFilter() {
  const s = document.getElementById('searchInput')?.value || '';
  const st = document.getElementById('stageFilter')?.value || '';
  const br = document.getElementById('branchFilter')?.value || '';
  showAllLeads(0, s, st, br);
}

// --- MY LEADS with Enhanced Filters & UI ---
async function showMyLeads(search = '', stage = '') {
  setActiveNav('myLeads');
  setLoading();
  
  let url = API.myLeads;
  const q = [];
  if (search) q.push(`search=${encodeURIComponent(search)}`);
  if (stage) q.push(`stage=${encodeURIComponent(stage)}`);
  if (q.length) url += `?${q.join('&')}`;

  const res = await apiFetch(url);
  if (!res.success) return toast('Failed to load', 'error');
  
  lastRefresh = new Date();
  const leads = res.data.leads || [];
  
  // Calculate stats
  const total = leads.length;
  const pending = leads.filter(l => ['new','assigned','outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg','not_answered','callback'].includes(l.current_stage)).length;
  const scheduled = leads.filter(l => l.current_stage === 'interview_scheduled').length;
  const completed = leads.filter(l => l.current_stage === 'hired' || l.current_stage === 'deployed' || l.current_stage === 'training').length;
  
  let html = `
  <div class="top-bar">
    <div class="page-title">
      <h1>📋 My Leads Pipeline</h1>
      <p>
        <span class="live-indicator">
          <span class="live-dot"></span>
          <span>Live updates</span>
        </span>
      </p>
    </div>
    <div class="top-actions">
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="myLeadsSearch" placeholder="Search my leads..." 
               value="${esc(search)}" onkeypress="if(event.key==='Enter') triggerMyLeadsFilter()">
      </div>
      <select id="myLeadsStageFilter" class="form-control" style="min-width: 175px; width: auto;" onchange="triggerMyLeadsFilter()">
        <option value="">📋 All Pipeline Stages</option>
        ${statusSelectHtml(stage)}
      </select>
      <button class="btn btn-info" onclick="showExportPerformanceModal(${currentUser?.id || 0})" title="Export My Pipeline Report">
        <i class="fas fa-file-excel"></i> Export
      </button>
      <button class="btn btn-success" onclick="showAddLeadModal()">
        <i class="fas fa-plus"></i> Add Lead
      </button>
      <button class="btn btn-primary" onclick="showMyLeads('${esc(search)}', '${stage}')" title="Refresh">
        <i class="fas fa-sync-alt"></i> Refresh
      </button>
    </div>
  </div>
  
  <!-- Mini Stats -->
  <div class="stats-grid stats-4" style="margin-bottom: 20px;">
    <div class="stat-card" style="padding: 14px;">
      <div class="stat-icon blue" style="width: 36px; height: 36px; font-size: 14px;"><i class="fas fa-users"></i></div>
      <div class="stat-value" style="font-size: 24px;">${total}</div>
      <div class="stat-label">Total Assigned</div>
    </div>
    <div class="stat-card" style="padding: 14px;">
      <div class="stat-icon yellow" style="width: 36px; height: 36px; font-size: 14px;"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-value" style="font-size: 24px;">${pending}</div>
      <div class="stat-label">Pending / In Reach</div>
    </div>
    <div class="stat-card" style="padding: 14px;">
      <div class="stat-icon purple" style="width: 36px; height: 36px; font-size: 14px;"><i class="fas fa-calendar"></i></div>
      <div class="stat-value" style="font-size: 24px;">${scheduled}</div>
      <div class="stat-label">Scheduled</div>
    </div>
    <div class="stat-card" style="padding: 14px;">
      <div class="stat-icon green" style="width: 36px; height: 36px; font-size: 14px;"><i class="fas fa-check-circle"></i></div>
      <div class="stat-value" style="font-size: 24px;">${completed}</div>
      <div class="stat-label">Completed / Training</div>
    </div>
  </div>
  
  <div class="table-wrap">
    <div class="table-header">
      <h3><i class="fas fa-list"></i> My Lead Pipeline</h3>
      <span class="badge badge-active">${total} leads listed</span>
    </div>
    <table>
      <thead>
        <tr>
          <th>Candidate</th>
          <th>Contact</th>
          <th>Position</th>
          <th>Status</th>
          <th>Last Contact</th>
          <th>Calls</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>`;
  
  if (!leads.length) {
    html += `<tr><td colspan="7" class="empty-state"><i class="fas fa-inbox"></i><p>No leads found in this filter</p><p style="margin-top: 10px;"><button class="btn btn-primary btn-sm" onclick="showAddLeadModal()">+ Add New Lead</button></p></td></tr>`;
  }
  
  leads.forEach(l => {
    const lastContact = l.last_call_date ? fmtTime(l.last_call_date) : 'Not contacted';
    html += `
      <tr>
        <td>
          <strong>${esc(l.full_name)}</strong>
          ${l.cnic ? `<br><span style="font-size: 11px; font-weight:700; color: #fb923c;"><i class="fas fa-id-card"></i> ${esc(l.cnic)}</span>` : ''}
        </td>
        <td>
          <i class="fas fa-phone-alt" style="font-size: 11px; color: var(--primary);"></i> ${esc(l.phone)}
          ${l.phone ? `
            <a href="https://wa.me/${(l.phone || '').replace(/[^0-9]/g, '')}" target="_blank" class="btn btn-xs" style="background:rgba(16,185,129,0.15);color:#34d399;border:1px solid rgba(16,185,129,0.3);text-decoration:none;padding:1px 6px;margin-left:6px;font-size:10px;">
              <i class="fab fa-whatsapp"></i> WA
            </a>
          ` : ''}
        </td>
        <td>${esc(l.position_applied || '-')}</td>
        <td>${stageBadge(l.current_stage)}</td>
        <td style="font-size: 12px;"><i class="fas fa-clock"></i> ${lastContact}</td>
        <td><span class="badge" style="background: rgba(249,115,22,0.1); color: var(--primary);">${l.call_count || 0} calls</span></td>
        <td>
          <button class="btn btn-primary btn-sm" onclick="editLead(${l.id})">
            <i class="fas fa-arrow-right"></i> Work Lead
          </button>
        </td>
      </tr>`;
  });
  
  html += `</tbody>
    </table>
  </div>`;
  
  renderMainView(html);
  startAutoRefresh(() => {
    const s = document.getElementById('myLeadsSearch')?.value || '';
    const st = document.getElementById('myLeadsStageFilter')?.value || '';
    showMyLeads(s, st);
  });
}

function triggerMyLeadsFilter() {
  const s = document.getElementById('myLeadsSearch')?.value || '';
  const st = document.getElementById('myLeadsStageFilter')?.value || '';
  showMyLeads(s, st);
}

// --- RECRUITER VIEW (Super Admin) ---
async function viewRecruiterLeads(recId, name) {
  if (!isSuperAdmin) return;
  setLoading();
  
  const res = await apiFetch(`${API.myLeads}?recruiter_id=${recId}`);
  if (!res.success) return toast('Failed to load', 'error');
  clearInterval(refreshTimer);
  
  const leads = res.data.leads || [];
  const s = res.data.stats || {};
  
  // Calculate conversion
  const conversionRate = s.total > 0 ? Math.round((s.hired / s.total) * 100) : 0;
  
  let html = `
  <div class="top-bar">
    <div class="page-title">
      <h1><i class="fas fa-user-tie"></i> ${esc(name)}'s Performance Dashboard</h1>
      <p>Detailed analytics and lead management</p>
    </div>
    <div class="top-actions">
      <button class="btn btn-secondary" onclick="showDashboard()">
        <i class="fas fa-arrow-left"></i> Back to Overview
      </button>
    </div>
  </div>
  
  <div class="stats-grid stats-4" style="margin-bottom: 20px;">
    <div class="stat-card" style="padding: 14px;">
      <div class="stat-icon blue" style="width: 36px; height: 36px;"><i class="fas fa-users"></i></div>
      <div class="stat-value" style="font-size: 24px;">${s.total || 0}</div>
      <div class="stat-label">Assigned Leads</div>
    </div>
    <div class="stat-card" style="padding: 14px;">
      <div class="stat-icon yellow" style="width: 36px; height: 36px;"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-value" style="font-size: 24px;">${s.pending || 0}</div>
      <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card" style="padding: 14px;">
      <div class="stat-icon purple" style="width: 36px; height: 36px;"><i class="fas fa-calendar"></i></div>
      <div class="stat-value" style="font-size: 24px;">${s.scheduled || 0}</div>
      <div class="stat-label">Scheduled</div>
    </div>
    <div class="stat-card" style="padding: 14px;">
      <div class="stat-icon green" style="width: 36px; height: 36px;"><i class="fas fa-trophy"></i></div>
      <div class="stat-value" style="font-size: 24px;">${s.hired || 0}</div>
      <div class="stat-label">Hired</div>
    </div>
  </div>
  
  <!-- Conversion Progress -->
  <div class="chart-container" style="margin-bottom: 20px; padding: 16px;">
    <div class="chart-header">
      <h4><i class="fas fa-chart-line"></i> Conversion Performance</h4>
      <span class="badge badge-active">${conversionRate}% Success Rate</span>
    </div>
    <div class="progress-bar-bg" style="background: rgba(255,255,255,0.1); border-radius: 30px; height: 12px; overflow: hidden;">
      <div class="progress-bar-fill" style="width: ${conversionRate}%; height: 12px; background: var(--gradient-primary); border-radius: 30px; transition: width 0.5s ease;"></div>
    </div>
    <div style="display: flex; justify-content: space-between; margin-top: 12px;">
      <span style="font-size: 11px; color: #fff;">📊 ${conversionRate}% of leads converted to hires</span>
      <span style="font-size: 11px; color: #fff;">🎯 ${s.hired || 0} / ${s.total || 0} converted</span>
    </div>
  </div>
  
  <div class="table-wrap">
    <div class="table-header">
      <h3><i class="fas fa-list"></i> Lead Details</h3>
      <span class="badge badge-active">${leads.length} leads</span>
    </div>
    <table style="width: 100%;">
      <thead>
        <tr>
          <th>Candidate</th>
          <th>Contact</th>
          <th>Status</th>
          <th>Last Activity</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>`;
  
  if (!leads.length) {
    html += `<tr><td colspan="5" class="empty-state"><i class="fas fa-inbox"></i><p>No leads assigned to this recruiter</p><\/td><\/tr>`;
  }
  
  leads.forEach(l => {
    const lastActivity = l.updated_at ? fmtTime(l.updated_at) : fmtTime(l.created_at);
    html += `
      <tr>
        <td><strong>${esc(l.full_name)}</strong><\/td>
        <td><i class="fas fa-phone-alt"></i> ${esc(l.phone)}<\/td>
        <td>${stageBadge(l.current_stage)}<\/td>
        <td style="font-size: 11px;"><i class="fas fa-clock"></i> ${ago(lastActivity)}<\/td>
        <td>
          <button class="btn btn-primary btn-xs" onclick="editLead(${l.id})">
            <i class="fas fa-eye"></i> View
          </button>
        <\/td>
      </tr>`;
  });
  
  html += `</tbody>
    </table>
  </div>`;
  
  document.getElementById('mainContent').innerHTML = html;
}

// ==========================================================================
// UNIFIED: TEAM PERFORMANCE & LEAD MANAGEMENT
// ==========================================================================
let teamPerformanceData = null;

async function showTeamPerformance() {
  if (!isSuperAdmin) {
    return showMyLeads();
  }
  setActiveNav('teamPerformance');
  setLoading();

  const bParam = encodeURIComponent(window.dashBranch || 'all');
  const res = await apiFetch(`${API.performance}?branch=${bParam}`);

  if (!res.success) {
    toast(res.error || 'Failed to load team performance data', 'error');
    return;
  }

  teamPerformanceData = res.data || {};
  const data = teamPerformanceData;
  const sum = data.summary || {};
  const recs = data.recruiters || [];
  window.cachedRecruiterList = recs;
  const unassigned = sum.unassigned_pool || 0;
  const staleCount = sum.stale_pool || 0;

  // Branch selector for Admin
  let branchSelectHtml = '';
  if (isSuperAdmin && data.available_branches) {
    const branches = data.available_branches;
    branchSelectHtml = `
      <select class="branch-select-badge" onchange="window.dashBranch = this.value; showTeamPerformance();" title="Filter by Branch">
        <option value="all" ${window.dashBranch === 'all' ? 'selected' : ''}>🏢 All Branches</option>
        ${Object.keys(branches).map(k => `
          <option value="${k}" ${window.dashBranch === k ? 'selected' : ''}>${esc(branches[k].label)}</option>
        `).join('')}
      </select>
    `;
  }

  let html = `
    <div class="top-bar">
      <div class="page-title">
        <h1>👥 Team Performance & Leads</h1>
        <p style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span style="color:var(--text-secondary);"><i class="fas fa-building" style="color:var(--primary);"></i> ${esc(data.branch_label || 'All Branches')}</span>
          <span style="color:rgba(255,255,255,0.25);">•</span>
          <span class="live-indicator">
            <span class="live-dot"></span>
            <span>Real-time Team Activity</span>
          </span>
        </p>
      </div>
      <div class="top-actions">
        ${branchSelectHtml}
        <button class="btn btn-info" onclick="showExportPerformanceModal()" title="Download Excel performance reports">
          <i class="fas fa-file-excel"></i> Export Report
        </button>
        <button class="btn btn-warning" onclick="distributeEquallyTeam()" ${unassigned > 0 ? '' : 'disabled'} title="Distribute all unassigned leads equally among active recruiters">
          <i class="fas fa-balance-scale"></i> Distribute Pool (${unassigned})
        </button>
        <button class="btn btn-danger" onclick="reassignStaleTeam()" ${staleCount > 0 ? '' : 'disabled'} title="Reassign leads uncalled for 3+ days to other recruiters">
          <i class="fas fa-redo"></i> Reassign Stale (${staleCount})
        </button>
        <button class="btn btn-success" onclick="showAddRecruiterModal()">
          <i class="fas fa-user-plus"></i> Add Recruiter
        </button>
        <button class="btn btn-primary" onclick="showTeamPerformance()">
          <i class="fas fa-sync-alt"></i> Refresh
        </button>
      </div>
    </div>

    <!-- Summary Strip -->
    <div class="stats-grid stats-4" style="margin-bottom: 20px;">
      <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
        <div class="stat-value">${sum.active_recruiters || 0} <span style="font-size: 14px; font-weight: 500; color: var(--text-muted);">/ ${sum.total_recruiters || 0}</span></div>
        <div class="stat-label">Active Recruiters</div>
        <div class="stat-today-badge">${sum.inactive_recruiters || 0} Inactive</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-inbox"></i></div>
        <div class="stat-value">${unassigned}</div>
        <div class="stat-label">Unassigned Pool</div>
        <div class="stat-today-badge ${unassigned > 0 ? 'has-today' : ''}">
          ${unassigned > 0 ? '⚡ Needs Distribution' : '✅ Pool Clear'}
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-phone-slash"></i></div>
        <div class="stat-value">${staleCount}</div>
        <div class="stat-label">Stale Leads (3D+ No Call)</div>
        <div class="stat-today-badge" style="color: var(--danger); background: rgba(239,68,68,0.1); border-color: rgba(239,68,68,0.2);">
          Action Required
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-chart-line"></i></div>
        <div class="stat-value">${sum.avg_conversion || 0}%</div>
        <div class="stat-label">Avg Team Conversion</div>
        <div class="stat-today-badge has-today">Hired / Assigned</div>
      </div>
    </div>

    <!-- Quick Lead Allocation Strip -->
    ${unassigned > 0 ? `
      <div class="dash-control-bar" style="background: linear-gradient(135deg, rgba(249,115,22,0.12), rgba(17,23,38,0.9)); border-color: rgba(249,115,22,0.3); margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <i class="fas fa-bolt" style="color: var(--primary); font-size: 18px;"></i>
          <div>
            <strong style="color: #fff; font-size: 13px;">Fast Lead Allocation:</strong>
            <span style="font-size: 12px; color: var(--text-secondary); margin-left: 6px;">Assign from the pool of ${unassigned} unassigned leads:</span>
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
          <select id="fastAssignRecSelect" class="form-control" style="width: 190px; padding: 6px 12px; font-size: 12px;">
            <option value="">-- Choose Recruiter --</option>
            ${recs.filter(r => r.status === 'active').map(r => `
              <option value="${r.id}">${esc(r.full_name)} (${esc(r.branch_label)})</option>
            `).join('')}
          </select>
          <input type="number" id="fastAssignCount" class="form-control" style="width: 90px; padding: 6px 10px; font-size: 12px;" min="1" max="${unassigned}" value="${Math.min(10, unassigned)}" placeholder="Count">
          <button class="btn btn-primary btn-sm" onclick="executeFastAssign()">
            <i class="fas fa-share"></i> Assign Leads
          </button>
        </div>
      </div>
    ` : ''}

    <!-- Recruiter Performance Directory Table -->
    <div class="table-wrap">
      <div class="table-header">
        <div style="display: flex; align-items: center; gap: 14px;">
          <h3><i class="fas fa-users-cog" style="color: var(--primary);"></i> Recruiter Performance & Pipeline</h3>
          <span class="badge badge-active">${recs.length} Members</span>
        </div>
        <div>
          <input type="text" id="teamSearchInput" class="form-control" placeholder="Search recruiter..." style="width: 220px; padding: 6px 12px; font-size: 12px;" oninput="filterTeamRecruitersTable(this.value)">
        </div>
      </div>
      <table style="width: 100%;">
        <thead>
          <tr>
            <th>Recruiter</th>
            <th>Assigned</th>
            <th style="min-width: 120px;">Dialed Rate</th>
            <th>Remaining</th>
            <th>Scheduled</th>
            <th>Appeared</th>
            <th>Hired</th>
            <th>Stale</th>
            <th>Conversion %</th>
            <th>Last Active</th>
            <th style="text-align: right;">Actions</th>
          </tr>
        </thead>
        <tbody id="teamRecruitersTableBody">`;

  if (!recs.length) {
    html += `<tr><td colspan="11" class="empty-state"><i class="fas fa-users-slash"></i><p>No recruiters found in this branch.</p></td></tr>`;
  }

  recs.forEach(r => {
    const isAct = r.status === 'active';
    const convScore = r.conversion_rate >= 20 ? 'perf-score-high' : r.conversion_rate >= 10 ? 'perf-score-mid' : 'perf-score-low';
    const convIcon = r.conversion_rate >= 20 ? '🟢' : r.conversion_rate >= 10 ? '🟡' : '🔴';
    const dialPct = r.assigned > 0 ? Math.round((r.dialed / r.assigned) * 100) : 0;
    const initial = r.full_name ? r.full_name.charAt(0).toUpperCase() : '?';

    html += `
      <tr class="team-rec-row" data-name="${esc(r.full_name).toLowerCase()}">
        <td>
          <div class="rec-tbl-user">
            <div class="rec-avatar-circle">${initial}</div>
            <div>
              <div class="rec-tbl-name">
                ${esc(r.full_name)}
                ${isAct ? '<span style="color:#10b981;font-size:8px;margin-left:4px;">●</span>' : '<span style="color:#ef4444;font-size:8px;margin-left:4px;">●</span>'}
              </div>
              <span class="rec-tbl-branch">${esc(r.branch_label)}</span>
            </div>
          </div>
        </td>
        <td><strong>${r.assigned}</strong></td>
        <td>
          <div style="font-size: 12px; font-weight: 600; color: #fff;">
            ${r.dialed} <span style="font-size: 10px; color: var(--text-muted);">(${dialPct}%)</span>
          </div>
          <div class="tbl-prog-wrap">
            <div class="tbl-prog-fill" style="width: ${dialPct}%;"></div>
          </div>
        </td>
        <td><span style="color: ${r.remaining > 0 ? 'var(--warning)' : 'var(--text-muted)'}; font-weight: 600;">${r.remaining}</span></td>
        <td><span style="color: var(--purple); font-weight: 700;">${r.scheduled}</span></td>
        <td><span style="color: var(--info); font-weight: 700;">${r.appeared}</span></td>
        <td><span style="color: var(--secondary); font-weight: 800;">${r.hired}</span></td>
        <td>
          ${r.stale_leads > 0 ? `
            <span class="badge" style="background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3);" title="Leads uncalled for 3+ days">
              <i class="fas fa-clock"></i> ${r.stale_leads}
            </span>
          ` : `<span style="color: var(--text-dim); font-size: 11px;">0</span>`}
        </td>
        <td>
          <span class="perf-score-pill ${convScore}">
            ${convIcon} ${r.conversion_rate}%
          </span>
        </td>
        <td style="font-size: 11px; color: var(--text-muted);">
          ${r.last_active ? ago(r.last_active) : 'Never'}
        </td>
        <td style="text-align: right;">
          <div style="display: inline-flex; gap: 6px; align-items: center;">
            <button class="btn btn-info btn-xs" onclick="downloadSingleRecruiterReport(${r.id}, '${esc(r.full_name)}')" title="Download Excel report for ${esc(r.full_name)}">
              <i class="fas fa-file-excel"></i> Report
            </button>
            <button class="btn btn-warning btn-xs" onclick="openQuickAssignModal(${r.id}, '${esc(r.full_name)}', ${unassigned})" title="Assign leads to this recruiter">
              <i class="fas fa-plus"></i> Assign
            </button>
            <button class="btn btn-primary btn-xs" onclick="viewRecruiterLeads(${r.id}, '${esc(r.full_name)}')" title="View this recruiter's pipeline">
              <i class="fas fa-eye"></i> Leads
            </button>
            ${isAct ? `
              <button class="btn btn-danger btn-xs" onclick="toggleRecruiterStatusTeam(${r.id}, 'inactive')" title="Deactivate account">
                <i class="fas fa-user-slash"></i>
              </button>
            ` : `
              <button class="btn btn-success btn-xs" onclick="toggleRecruiterStatusTeam(${r.id}, 'active')" title="Activate account">
                <i class="fas fa-user-check"></i>
              </button>
            `}
          </div>
        </td>
      </tr>`;
  });

  html += `
        </tbody>
      </table>
    </div>

    <!-- Quick Assign Modal Container -->
    <div id="quickAssignModalContainer"></div>
  `;

  renderMainView(html);
  clearInterval(refreshTimer);
}

// Fast lead assign from top strip
async function executeFastAssign() {
  const sel = document.getElementById('fastAssignRecSelect');
  const countInp = document.getElementById('fastAssignCount');
  const recId = sel ? parseInt(sel.value) : 0;
  const count = countInp ? parseInt(countInp.value) : 0;

  if (!recId || count <= 0) {
    return toast('Select a recruiter and specify valid number of leads', 'warning');
  }

  const res = await apiFetch(API.performance, {
    method: 'POST',
    body: JSON.stringify({
      action: 'assign_leads',
      recruiter_id: recId,
      count: count,
      branch: window.dashBranch || 'all'
    })
  });

  if (res.success) {
    toast(`✅ ${res.message || 'Leads assigned successfully!'}`, 'success');
    showTeamPerformance();
  } else {
    toast(res.error || 'Assignment failed', 'error');
  }
}

// Modal for quick assigning leads to a specific recruiter
function openQuickAssignModal(recId, recName, unassignedMax) {
  const max = unassignedMax || 0;
  const html = `
    <div class="modal-overlay" id="quickAssignModal">
      <div class="modal" style="max-width: 440px;">
        <div class="modal-header">
          <h3><i class="fas fa-user-plus"></i> Assign Leads to ${esc(recName)}</h3>
          <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
          <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 14px;">
            Available Unassigned Pool: <strong>${max} leads</strong>
          </p>
          <div class="form-group" style="margin-bottom: 16px;">
            <label>Number of Leads to Assign *</label>
            <input type="number" id="modalAssignCount" class="form-control" min="1" max="${max}" value="${Math.min(10, max)}" placeholder="e.g. 15">
          </div>
          <div style="display: flex; gap: 8px; justify-content: flex-end;">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" onclick="submitModalAssign(${recId})">
              <i class="fas fa-check"></i> Confirm Assignment
            </button>
          </div>
        </div>
      </div>
    </div>
  `;
  document.getElementById('quickAssignModalContainer').innerHTML = html;
}

async function submitModalAssign(recId) {
  const inp = document.getElementById('modalAssignCount');
  const count = inp ? parseInt(inp.value) : 0;
  if (!count || count <= 0) return toast('Enter valid number of leads', 'warning');

  const res = await apiFetch(API.performance, {
    method: 'POST',
    body: JSON.stringify({
      action: 'assign_leads',
      recruiter_id: recId,
      count: count,
      branch: window.dashBranch || 'all'
    })
  });

  if (res.success) {
    toast(`✅ ${res.message || 'Leads assigned successfully!'}`, 'success');
    closeModal();
    showTeamPerformance();
  } else {
    toast(res.error || 'Failed to assign leads', 'error');
  }
}

// Distribute Unassigned Pool Equally
async function distributeEquallyTeam() {
  if (!confirm('⚡ Distribute all unassigned pool leads equally among all active recruiters in this branch?')) return;

  const res = await apiFetch(API.distribute, {
    method: 'POST',
    body: JSON.stringify({ mode: 'equal' })
  });

  if (res.success) {
    toast(`✅ ${res.message || 'Leads distributed equally!'}`, 'success');
    showTeamPerformance();
  } else {
    toast(res.error || 'Distribution failed', 'error');
  }
}

// Reassign Stale Leads (3+ Days No Call)
async function reassignStaleTeam() {
  if (!confirm('🔄 Reassign stale leads (uncalled for 3+ days) to active recruiters in this branch?')) return;

  const res = await apiFetch(API.performance, {
    method: 'POST',
    body: JSON.stringify({
      action: 'reassign_stale',
      branch: window.dashBranch || 'all'
    })
  });

  if (res.success) {
    toast(`✅ ${res.message || 'Stale leads reassigned successfully!'}`, 'success');
    showTeamPerformance();
  } else {
    toast(res.error || 'Reassignment failed', 'error');
  }
}

// Toggle Recruiter Active/Inactive Status
async function toggleRecruiterStatusTeam(recId, newStatus) {
  const verb = newStatus === 'active' ? 'ACTIVATE' : 'DEACTIVATE';
  if (!confirm(`Are you sure you want to ${verb} this recruiter account?`)) return;

  const res = await apiFetch(API.toggleRec, {
    method: 'POST',
    body: JSON.stringify({ recruiter_id: recId, status: newStatus })
  });

  if (res.success) {
    toast(`✅ Recruiter account set to ${newStatus}`, 'success');
    showTeamPerformance();
  } else {
    toast(res.error || 'Failed to update recruiter status', 'error');
  }
}

// Filter Recruiter rows by search query
function filterTeamRecruitersTable(query) {
  const q = (query || '').toLowerCase().trim();
  const rows = document.querySelectorAll('.team-rec-row');
  rows.forEach(r => {
    const name = r.getAttribute('data-name') || '';
    if (!q || name.includes(q)) {
      r.style.display = '';
    } else {
      r.style.display = 'none';
    }
  });
}

// Aliases for seamless legacy support
window.showTeamPerformance = showTeamPerformance;
window.showDistributeLeads = showTeamPerformance;
window.showRecruitersList = showTeamPerformance;

// --- Show Add Recruiter Modal ---
function showAddRecruiterModal() {
  const modalHtml = `
  <div class="modal-overlay" id="addRecruiterModal">
    <div class="modal" style="max-width: 500px;">
      <div class="modal-header">
        <h3><i class="fas fa-user-plus"></i> Add New Recruiter</h3>
        <button class="modal-close" onclick="closeModal()">&times;</button>
      </div>
      <form id="addRecruiterForm" onsubmit="createNewRecruiter(event)">
        <div class="modal-body">
          <div class="form-group" style="margin-bottom: 15px;">
            <label>Full Name *</label>
            <input type="text" id="new_rec_name" class="form-control" placeholder="e.g., John Doe" required>
          </div>
          <div class="form-group" style="margin-bottom: 15px;">
            <label>Email *</label>
            <input type="email" id="new_rec_email" class="form-control" placeholder="recruiter@balitech.com" required>
          </div>
          <div class="form-group" style="margin-bottom: 15px;">
            <label>Username *</label>
            <input type="text" id="new_rec_username" class="form-control" placeholder="username" required>
          </div>
          <div class="form-group" style="margin-bottom: 15px;">
            <label>Phone Number</label>
            <input type="tel" id="new_rec_phone" class="form-control" placeholder="03001234567">
          </div>
          <div class="form-group" style="margin-bottom: 15px;">
            <label>Employee ID (BID) *</label>
            <input type="text" id="new_rec_bid" class="form-control" placeholder="e.g. 508 - from biometric / roster" required>
            <small style="color: var(--text-dim);">Required for attendance &amp; payroll in portal</small>
          </div>
          <div class="form-group" style="margin-bottom: 15px;">
            <label>Branch Assignment *</label>
            <select id="new_rec_branch" class="form-control">
              ${COMPANY_BRANCH_OPTIONS.map(b => `<option value="${b.key}" ${window.dashBranch === b.key ? 'selected' : ''}>${esc(b.label)}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="text" id="new_rec_password" class="form-control" value="Recruiter@123" readonly>
            <small style="color: var(--text-dim);">Default: Recruiter@123 (can be changed by user)</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
          <button type="submit" class="btn btn-success">Create Recruiter</button>
        </div>
      </form>
    </div>
  </div>`;
  
  openModal(modalHtml);
}

// --- Create New Recruiter ---
async function createNewRecruiter(e) {
  e.preventDefault();
  
  const full_name = document.getElementById('new_rec_name').value.trim();
  const email = document.getElementById('new_rec_email').value.trim();
  const username = document.getElementById('new_rec_username').value.trim();
  const phone = document.getElementById('new_rec_phone').value.trim();
  const employee_code = document.getElementById('new_rec_bid').value.trim();
  const password = document.getElementById('new_rec_password').value;
  const company_branch = document.getElementById('new_rec_branch')?.value || '';
  
  if (!full_name || !email || !username || !employee_code) {
    toast('Please fill all required fields (including BID)', 'warning');
    return;
  }
  
  const btn = document.querySelector('#addRecruiterForm button[type="submit"]');
  const originalText = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
  btn.disabled = true;
  
  const res = await apiFetch(API.createRec, {
    method: 'POST',
    body: JSON.stringify({ full_name, email, username, phone, password, employee_code, company_branch })
  });
  
  btn.innerHTML = originalText;
  btn.disabled = false;
  
  if (res.success) {
    toast('✅ Recruiter created successfully!', 'success');
    closeModal();
    showRecruitersList();
    if (typeof showDashboard === 'function') showDashboard();
  } else {
    toast(res.error || res.message || 'Failed to create recruiter', 'error');
  }
}

// --- Deactivate Recruiter ---
async function deactivateRecruiter(recruiterId) {
  if (!confirm('⚠️ Are you sure you want to DEACTIVATE this recruiter?\n\nThey will not be able to login until activated again.')) return;
  
  const res = await apiFetch(API.toggleRec, {
    method: 'POST',
    body: JSON.stringify({ recruiter_id: recruiterId, status: 'inactive' })
  });
  
  if (res.success) {
    toast('✅ Recruiter deactivated successfully!', 'success');
    showRecruitersList();
    if (typeof showDashboard === 'function') showDashboard();
  } else {
    toast(res.error || res.message || 'Failed to deactivate recruiter', 'error');
  }
}

// --- Activate Recruiter ---
async function activateRecruiter(recruiterId) {
  if (!confirm('✅ Are you sure you want to ACTIVATE this recruiter?\n\nThey will be able to login again.')) return;
  
  const res = await apiFetch(API.toggleRec, {
    method: 'POST',
    body: JSON.stringify({ recruiter_id: recruiterId, status: 'active' })
  });
  
  if (res.success) {
    toast('✅ Recruiter activated successfully!', 'success');
    showRecruitersList();
    if (typeof showDashboard === 'function') showDashboard();
  } else {
    toast(res.error || res.message || 'Failed to activate recruiter', 'error');
  }
}

// ==================== ASSIGN FUNCTIONS ====================
async function assignCount(recId) {
  const count = parseInt(document.getElementById(`dist_${recId}`).value) || 0;
  if (count < 1) return toast('Enter a valid number', 'warning');
  const res = await apiFetch(API.distribute, {
    method: 'POST',
    body: JSON.stringify({ mode: 'count', recruiter_id: recId, count: count })
  });
  if (res.success) {
    toast(res.data || 'Assigned successfully');
    showDistributeLeads();
  } else toast(res.error, 'error');
}

async function distributeEqually() {
  if (!confirm('Distribute all unassigned leads equally among all active recruiters?')) return;
  const res = await apiFetch(API.distribute, {
    method: 'POST',
    body: JSON.stringify({ mode: 'equal' })
  });
  if (res.success) {
    toast(res.data || 'Distributed successfully');
    showDistributeLeads();
  } else toast(res.error, 'error');
}

// ==================== DISTRIBUTION & WORK AUDIT LOGS ====================
async function showDistributionAuditLogs(search = '', recruiterId = 0, offset = 0) {
  setActiveNav('auditLogs');
  if (offset === 0) setLoading();

  let url = `${API.distributionLogs}?limit=50&offset=${offset}`;
  if (search) url += `&search=${encodeURIComponent(search)}`;
  if (recruiterId) url += `&recruiter_id=${recruiterId}`;

  const [res, recsRes] = await Promise.all([
    apiFetch(url),
    apiFetch(API.recruiters)
  ]);

  if (!res.success) {
    toast(res.error || 'Failed to load audit logs', 'error');
    return;
  }

  const logs = res.data?.logs || [];
  const total = res.data?.total || 0;
  const summary = res.data?.summary || {};
  const recruiters = recsRes.success ? (recsRes.data || []) : [];

  let html = `
    <div class="top-bar">
      <div class="page-title">
        <h1>📋 Audit & Lead Work History</h1>
        <p><i class="fas fa-history" style="color:var(--primary)"></i> Live audit trail of leads assigned by HR and recruiter actions</p>
      </div>
      <div class="top-actions">
        <button class="btn btn-primary" onclick="showDistributionAuditLogs('${esc(search)}', ${recruiterId}, ${offset})">
          <i class="fas fa-sync-alt"></i> Refresh Logs
        </button>
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="auditSearchInput" placeholder="Search Candidate / HR / Recruiter..." value="${esc(search)}" onkeypress="if(event.key==='Enter') filterAuditLogs()">
        </div>
        <select id="auditRecFilter" class="form-control" style="width: 170px;" onchange="filterAuditLogs()">
          <option value="0">👥 All Recruiters</option>
          ${recruiters.map(r => `<option value="${r.id}" ${recruiterId == r.id ? 'selected' : ''}>${esc(r.full_name)}</option>`).join('')}
        </select>
        ${(search || recruiterId) ? `<button class="btn btn-secondary" onclick="showDistributionAuditLogs()"><i class="fas fa-times"></i> Clear</button>` : ''}
      </div>
    </div>

    <!-- Summary Metrics -->
    <div class="stats-grid stats-4">
      <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-share-alt"></i></div>
        <div class="stat-value">${formatNumber(summary.total_distributions || 0)}</div>
        <div class="stat-label">Total Leads Assigned</div>
        <div class="stat-trend up"><i class="fas fa-check"></i> Distributed by HR</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-users-cog"></i></div>
        <div class="stat-value">${formatNumber(summary.total_recruiters_assigned || 0)}</div>
        <div class="stat-label">Active Recruiters</div>
        <div class="stat-trend info"><i class="fas fa-user-check"></i> In this branch</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-phone-volume"></i></div>
        <div class="stat-value">${formatNumber(summary.total_calls_on_assigned || 0)}</div>
        <div class="stat-label">Calls Logged On Leads</div>
        <div class="stat-trend warning"><i class="fas fa-headset"></i> Outreach effort</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-calendar-check"></i></div>
        <div class="stat-value">${formatNumber(summary.scheduled_count || 0)}</div>
        <div class="stat-label">Interviews Scheduled</div>
        <div class="stat-trend up"><i class="fas fa-arrow-right"></i> Sent to Reception</div>
      </div>
    </div>

    <!-- Audit Table Wrap -->
    <div class="table-wrap">
      <div class="table-header">
        <h3><i class="fas fa-list-check" style="color:var(--primary)"></i> Assignment & Work Trail (${total} Records)</h3>
      </div>
      <table>
        <thead>
          <tr>
            <th>Assigned Date</th>
            <th>Assigned By (HR)</th>
            <th>Assigned To (Recruiter)</th>
            <th>Candidate Details</th>
            <th>Method</th>
            <th>Current Stage</th>
            <th>Work Done / Outreach</th>
            <th style="text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody>
          ${logs.length > 0 ? logs.map(l => `
            <tr>
              <td>
                <div style="font-weight:600; color:#fff;">${fmt(l.created_at)}</div>
                <div style="font-size:11px; color:var(--text-muted);">${fmtTime(l.created_at).split(',')[1] || ''}</div>
              </td>
              <td>
                <span class="badge" style="background:rgba(59, 130, 246, 0.15); color:#60a5fa; border:1px solid rgba(59, 130, 246, 0.3);">
                  <i class="fas fa-user-shield" style="margin-right:5px;"></i> ${esc(l.assigned_by_name)}
                </span>
              </td>
              <td>
                <span class="badge" style="background:rgba(16, 185, 129, 0.15); color:#34d399; border:1px solid rgba(16, 185, 129, 0.3);">
                  <i class="fas fa-user-tie" style="margin-right:5px;"></i> ${esc(l.assigned_to_name)}
                </span>
              </td>
              <td>
                <div style="font-weight:600; color:#fff;">${esc(l.lead_name || 'N/A')}</div>
                <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                  <i class="fas fa-phone-alt"></i> ${esc(l.lead_phone || '-')} 
                  ${l.position_applied ? ` &bull; <i class="fas fa-briefcase"></i> ${esc(l.position_applied)}` : ''}
                </div>
              </td>
              <td>
                <span class="badge" style="background:rgba(255,255,255,0.06); color:var(--text-secondary); border:1px solid rgba(255,255,255,0.1);">
                  ${esc(l.distribution_mode.toUpperCase())}
                </span>
              </td>
              <td>
                ${stageBadge(l.current_stage)}
              </td>
              <td>
                <div style="font-size:12.5px; font-weight:600; color:#fff;">
                  <span style="color:${(l.call_count > 0 ? '#10b981' : '#f59e0b')}">${l.call_count || 0} Calls</span> 
                  <span style="color:var(--text-dim);">|</span> 
                  <span style="color:var(--text-muted);">${l.total_remarks_count || 0} Notes</span>
                </div>
                ${l.latest_remark ? `<div style="font-size:11px; color:var(--text-muted); max-width:220px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:3px;" title="${esc(l.latest_remark)}"><i class="fas fa-comment-dots" style="color:var(--primary)"></i> ${esc(l.latest_remark)}</div>` : '<div style="font-size:11px; color:var(--text-dim); margin-top:3px;">No remarks logged</div>'}
              </td>
              <td style="text-align:right;">
                <button class="btn btn-sm btn-info" onclick="editLead(${l.lead_id})">
                  <i class="fas fa-eye"></i> View Lead
                </button>
              </td>
            </tr>
          `).join('') : `
            <tr>
              <td colspan="8" style="text-align:center; padding:50px 20px; color:var(--text-muted);">
                <i class="fas fa-clipboard" style="font-size:36px; margin-bottom:12px; display:block; opacity:0.3;"></i>
                No lead distribution records found for this branch.
              </td>
            </tr>
          `}
        </tbody>
      </table>
    </div>
  `;

  renderMainView(html);
}

function filterAuditLogs() {
  const s = document.getElementById('auditSearchInput')?.value || '';
  const r = document.getElementById('auditRecFilter')?.value || 0;
  showDistributionAuditLogs(s, r, 0);
}

// ==================== WEBSITE LEADS SYNC MODAL ====================
async function syncWebsiteLeadsModal() {
  const res = await apiFetch(`${API.syncWebsiteLeads}?action=status`);
  const statusData = res.success ? res.data : {};
  const lastSync = statusData.last_sync || {};

  const html = `
    <div class="modal-overlay" id="syncWebsiteModal">
      <div class="modal modal-md" style="max-width:550px;">
        <div class="modal-header">
          <h3><i class="fas fa-globe" style="color:var(--info); margin-right:8px;"></i> Sync Balitech.org Leads</h3>
          <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:24px;">
          <div style="background:rgba(59, 130, 246, 0.08); border:1px solid rgba(59, 130, 246, 0.25); border-radius:12px; padding:16px; margin-bottom:20px;">
            <div style="font-size:13px; color:#fff; font-weight:600; margin-bottom:6px;">
              <i class="fas fa-info-circle" style="color:var(--info)"></i> Automatic Website Ingestion
            </div>
            <p style="font-size:12px; color:var(--text-muted); margin:0; line-height:1.5;">
              Fetch live applications directly from <strong>balitech.org</strong> website. Automatically deduplicates candidates, extracts CV links, and places them into your branch's intake queue.
            </p>
          </div>

          <div style="margin-bottom:18px;">
            <div style="font-size:12px; color:var(--text-muted); margin-bottom:6px; display:flex; justify-content:space-between;">
              <span>Last Synced: <strong>${lastSync.created_at ? fmtTime(lastSync.created_at) : 'Never'}</strong></span>
              <span>By: <strong>${esc(lastSync.synced_by_name || 'N/A')}</strong></span>
            </div>
            <div style="font-size:12px; color:var(--text-muted);">
              Total Synced in this Branch: <strong style="color:var(--secondary);">${statusData.total_synced_in_branch || 0} Leads</strong>
            </div>
          </div>

          <div class="form-grid" style="grid-template-columns:1fr 1fr; gap:14px; margin-bottom:20px;">
            <div class="form-group">
              <label>Queue Category</label>
              <select id="syncQueueFilter" class="form-control">
                <option value="">All Queues</option>
                <option value="recruitment" selected>Recruitment Queue</option>
                <option value="hr-employment-check">HR Employment Check</option>
              </select>
            </div>
            <div class="form-group">
              <label>Fetch Depth (Pages)</label>
              <select id="syncPagesFilter" class="form-control">
                <option value="2">Latest 2 Pages (~100 Leads)</option>
                <option value="5" selected>Latest 5 Pages (~250 Leads)</option>
                <option value="10">Deep Sync (10 Pages)</option>
                <option value="20">Full Archive (20 Pages)</option>
              </select>
            </div>
          </div>

          <div id="syncProgressArea" style="display:none; text-align:center; padding:16px; background:rgba(0,0,0,0.2); border-radius:10px; margin-bottom:16px;">
            <div class="loading-spinner" style="width:36px; height:36px; margin:0 auto 10px;"></div>
            <p style="font-size:12px; color:var(--primary); margin:0;">Connecting to Balitech Website API & importing leads...</p>
          </div>

          <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="btnRunSync" onclick="runWebsiteSync()">
              <i class="fas fa-cloud-download-alt"></i> Start Live Sync
            </button>
          </div>
        </div>
      </div>
    </div>
  `;

  openModal(html);
}

async function runWebsiteSync() {
  const queue = document.getElementById('syncQueueFilter')?.value || '';
  const pages = document.getElementById('syncPagesFilter')?.value || 5;
  const progress = document.getElementById('syncProgressArea');
  const btn = document.getElementById('btnRunSync');

  if (progress) progress.style.display = 'block';
  if (btn) btn.disabled = true;

  try {
    const res = await apiFetch(`${API.syncWebsiteLeads}?action=sync&pages=${pages}&queue=${encodeURIComponent(queue)}`);
    if (res.success) {
      toast(res.message || 'Website leads synced successfully!', 'success');
      closeModal();
      if (typeof showAllLeads === 'function') showAllLeads();
      if (typeof showDashboard === 'function') showDashboard();
    } else {
      toast(res.error || 'Sync failed', 'error');
      if (progress) progress.style.display = 'none';
      if (btn) btn.disabled = false;
    }
  } catch (err) {
    toast('Network error during sync', 'error');
    if (progress) progress.style.display = 'none';
    if (btn) btn.disabled = false;
  }
}

// ==================== EXPORTS ====================
window.showDashboard = showDashboard;
window.changeDashBranch = changeDashBranch;
window.changeDashRange = changeDashRange;
window.showAllLeads = showAllLeads;
window.triggerAllLeadsFilter = triggerAllLeadsFilter;
window.showMyLeads = showMyLeads;
window.triggerMyLeadsFilter = triggerMyLeadsFilter;
window.viewRecruiterLeads = viewRecruiterLeads;
window.showDistributeLeads = showDistributeLeads;
window.showDistributionAuditLogs = showDistributionAuditLogs;
window.filterAuditLogs = filterAuditLogs;
window.syncWebsiteLeadsModal = syncWebsiteLeadsModal;
window.runWebsiteSync = runWebsiteSync;
window.quickAssignSingleLead = quickAssignSingleLead;
window.assignCount = assignCount;
window.distributeEqually = distributeEqually;
window.exportDashboardReport = exportDashboardReport;
window.showExportPerformanceModal = showExportPerformanceModal;
window.toggleExportScope = toggleExportScope;
window.toggleExportCustomDates = toggleExportCustomDates;
window.executeReportDownload = executeReportDownload;
window.downloadSingleRecruiterReport = downloadSingleRecruiterReport;
window.initSuperAdminCharts = initSuperAdminCharts;
window.initRecruiterCharts = initRecruiterCharts;
window.initDistributionCharts = initDistributionCharts;

// Recruiter Management Exports
window.showTeamPerformance = showTeamPerformance;
window.executeFastAssign = executeFastAssign;
window.openQuickAssignModal = openQuickAssignModal;
window.submitModalAssign = submitModalAssign;
window.distributeEquallyTeam = distributeEquallyTeam;
window.reassignStaleTeam = reassignStaleTeam;
window.toggleRecruiterStatusTeam = toggleRecruiterStatusTeam;
window.filterTeamRecruitersTable = filterTeamRecruitersTable;
window.showRecruitersList = showRecruitersList;
window.showAddRecruiterModal = showAddRecruiterModal;
window.createNewRecruiter = createNewRecruiter;
window.deactivateRecruiter = deactivateRecruiter;
window.activateRecruiter = activateRecruiter;