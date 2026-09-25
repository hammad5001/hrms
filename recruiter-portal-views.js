// ===== RECRUITER PORTAL VIEWS - COMPLETE =====

let charts = {};

// --- DASHBOARD with Charts ---
async function showDashboard() {
  setActiveNav('dashboard');
  if (!document.getElementById('dashView')) setLoading();

  const res = await apiFetch(API.stats);
  if (!res.success) {
    toast(res.error || 'Failed to load stats', 'error');
    return;
  }
  const s = res.data || {};
  lastRefresh = new Date();

  // Calculate percentages for progress bars
  const conversionRate = s.total_leads > 0 
    ? Math.round((s.hired_leads / s.total_leads) * 100) 
    : 0;
  const pendingRate = s.total_leads > 0 
    ? Math.round((s.pending_leads / s.total_leads) * 100) 
    : 0;

  let html = `
    <div class="top-bar" id="dashView">
      <div class="page-title">
        <h1>🔮 Intelligence Hub</h1>
        <p><i class="fas fa-chart-line"></i> Real-time analytics & predictive insights - 
          <span id="liveUpdated" class="live-indicator">
            <div class="live-dot"></div> Live
          </span>
        </p>
      </div>
      <div class="top-actions">
        <button class="btn btn-primary" onclick="showDashboard()">
          <i class="fas fa-sync-alt"></i> Refresh Data
        </button>
        <button class="btn btn-info" onclick="exportDashboardReport()">
          <i class="fas fa-download"></i> Export Report
        </button>
      </div>
    </div>`;

  if (isSuperAdmin) {
    // Super Admin Dashboard
    html += `
    <div class="stats-grid stats-5">
      <div class="stat-card" onclick="showAllLeads()">
        <div class="stat-icon blue"><i class="fas fa-database"></i></div>
        <div class="stat-value">${formatNumber(s.total_leads || 0)}</div>
        <div class="stat-label">Total Leads</div>
        <div class="stat-trend up"><i class="fas fa-arrow-up"></i> +12% growth</div>
      </div>
      <div class="stat-card" onclick="showDistributeLeads()">
        <div class="stat-icon yellow"><i class="fas fa-funnel-dollar"></i></div>
        <div class="stat-value">${formatNumber(s.pending_leads || 0)}</div>
        <div class="stat-label">Active Pipeline</div>
        <div class="stat-trend warning"><i class="fas fa-clock"></i> Action required</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-calendar-check"></i></div>
        <div class="stat-value">${formatNumber(s.scheduled_leads || 0)}</div>
        <div class="stat-label">Interviews Today</div>
        <div class="stat-trend up"><i class="fas fa-user-clock"></i> Busy day</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-check-double"></i></div>
        <div class="stat-value">${formatNumber(s.hired_this_month || 0)}</div>
        <div class="stat-label">Hired (MTD)</div>
        <div class="stat-trend up"><i class="fas fa-chart-line"></i> Above target</div>
      </div>
      <div class="stat-card" onclick="showDistributeLeads()">
        <div class="stat-icon red"><i class="fas fa-user-plus"></i></div>
        <div class="stat-value">${formatNumber(s.unassigned_leads || 0)}</div>
        <div class="stat-label">New Unassigned</div>
        <div class="stat-trend down"><i class="fas fa-exclamation-circle"></i> Needs sorting</div>
      </div>
    </div>

    <div class="dashboard-advanced-grid">
      <!-- Left Column: Activity & Priority -->
      <div class="dash-col">
        <div class="chart-container activity-feed">
          <div class="chart-header">
            <h4><i class="fas fa-bolt" style="color:var(--primary)"></i> Real-time Nexus Feed</h4>
            <div class="live-indicator">
              <div class="live-dot"></div>
              <span>Live Activity</span>
            </div>
          </div>
          <div class="activity-list">
            ${(s.recent_activity || []).map(a => `
              <div class="activity-item">
                <div class="activity-icon ${a.action==='update'?'blue':'green'}">
                  <i class="fas ${a.action==='update'?'fa-edit':'fa-plus'}"></i>
                </div>
                <div class="activity-content">
                  <p><strong>${esc(a.user_name || 'System')}</strong> ${esc(a.notes || 'performed an action')}</p>
                  <div class="activity-meta">
                    <span><i class="fas fa-user-tie"></i> ${esc(a.lead_name || 'N/A')}</span>
                    <span><i class="fas fa-clock"></i> ${ago(a.created_at)}</span>
                  </div>
                </div>
              </div>
            `).join('') || '<div class="empty-state">No recent activity</div>'}
          </div>
        </div>

        <div class="chart-container">
          <div class="chart-header">
            <h4><i class="fas fa-fire" style="color:var(--danger)"></i> Priority Outreach</h4>
            <span class="priority-count-badge">${s.priority_leads?.length || 0}</span>
          </div>
          <div class="priority-list">
            ${(s.priority_leads || []).map(l => `
              <div class="priority-item" onclick="editLead(${l.id})">
                <div class="priority-info">
                  <h5>${esc(l.full_name)}</h5>
                  <p>${l.phone} - ${esc(l.current_stage)}</p>
                </div>
                <div class="priority-actions">
                  <span class="priority-tag">${l.call_count === 0 ? 'NEVER CALLED' : 'STALE LEAD'}</span>
                  <button class="btn btn-primary btn-xs"><i class="fas fa-phone"></i></button>
                </div>
              </div>
            `).join('') || '<div class="empty-state">No priority leads</div>'}
          </div>
        </div>
      </div>

      <!-- Center Column: Visual Analytics -->
      <div class="dash-col main-charts">
        <div class="chart-container">
          <div class="chart-header">
            <h4><i class="fas fa-chart-area"></i> Recruitment Conversion Funnel</h4>
            <div class="chart-actions">
              <span class="badge badge-active">Efficiency: ${conversionRate}%</span>
            </div>
          </div>
          <div id="funnelChart" style="height: 350px;"></div>
        </div>
        
        <div class="chart-container">
          <div class="chart-header">
            <h4><i class="fas fa-users-viewfinder"></i> Team Deployment Metrics</h4>
          </div>
          <div id="weeklyBarChart" style="height: 350px;"></div>
        </div>
      </div>

      <!-- Right Column: Interviews & Efficiency -->
      <div class="dash-col">
        <div class="chart-container upcoming-interviews">
          <div class="chart-header">
            <h4><i class="fas fa-calendar-day" style="color:var(--purple)"></i> Next Interviews</h4>
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
                  <p><i class="fas fa-clock"></i> ${fmt(i.interview_date)}</p>
                </div>
                <button class="btn btn-secondary btn-xs" onclick="editLead(${i.id})"><i class="fas fa-chevron-right"></i></button>
              </div>
            `).join('') || '<div class="empty-state">No upcoming interviews</div>'}
          </div>
        </div>

        <div class="chart-container">
          <div class="chart-header">
            <h4><i class="fas fa-gauge-high"></i> Global Efficiency</h4>
          </div>
          <div id="conversionGauge" style="height: 250px;"></div>
          <div class="efficiency-stats">
            <div class="eff-item">
              <span class="label">Total Leads</span>
              <span class="value">${s.total_leads}</span>
            </div>
            <div class="eff-item">
              <span class="label">Assigned</span>
              <span class="value">${s.assigned_leads}</span>
            </div>
            <div class="eff-item">
              <span class="label">Avg Time to Hire</span>
              <span class="value">4.2 Days</span>
            </div>
          </div>
        </div>
      </div>
    </div>
    `;

    if (s.recruiter_breakdown && s.recruiter_breakdown.length) {
      html += `
      <div class="top-bar" style="margin: 20px 0 16px; padding: 16px 24px;">
        <div class="page-title">
          <h1 style="font-size: 18px;">🏆 Team Performance Leaderboard</h1>
          <p>Real-time ranking based on conversions</p>
        </div>
        <div class="top-actions">
          <button class="btn btn-success btn-sm" onclick="showAddRecruiterModal()">
            <i class="fas fa-plus"></i> Add Recruiter
          </button>
          <button class="btn btn-info btn-sm" onclick="showRecruitersList()">
            <i class="fas fa-users-cog"></i> Manage Team
          </button>
        </div>
      </div>
      <div class="stats-grid stats-4">`;
      
      // Sort by hired count
      const sortedRecruiters = [...s.recruiter_breakdown].sort((a, b) => (b.hired || 0) - (a.hired || 0));
      
      sortedRecruiters.forEach((r, idx) => {
        const rankIcon = idx === 0 ? '🥇' : idx === 1 ? '🥈' : idx === 2 ? '🥉' : '📊';
        const rankColor = idx === 0 ? '#FFD700' : idx === 1 ? '#C0C0C0' : idx === 2 ? '#CD7F32' : 'var(--text-muted)';
        const statusBadge = r.status === 'inactive' 
          ? '<span class="badge badge-inactive" style="margin-left: 8px;"><i class="fas fa-circle"></i> Inactive</span>' 
          : '<span class="badge badge-active" style="margin-left: 8px;"><i class="fas fa-circle"></i> Active</span>';
        
        html += `
        <div class="rec-card" onclick="viewRecruiterLeads(${r.id}, '${esc(r.full_name)}')">
          <div class="rec-status-bar ${r.status === 'inactive' ? 'inactive' : ''}"></div>
          <div class="rec-card-header">
            <div class="rec-avatar" style="position: relative;">
              ${r.full_name.charAt(0)}
              <span style="position: absolute; bottom: -5px; right: -5px; font-size: 14px;">${rankIcon}</span>
            </div>
            <div style="flex: 1;">
              <h4 style="font-size: 14px; color: #fff; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                ${esc(r.full_name)}
                ${statusBadge}
                <span style="color: ${rankColor}; font-size: 12px;">${idx === 0 ? 'Top Performer' : idx === 1 ? 'Rising Star' : idx === 2 ? 'Strong Contributor' : ''}</span>
              </h4>
              <p style="font-size: 11px; color: var(--text-dim);">
                ${r.status === 'inactive' ? '⚫ Account Deactivated' : '🟢 Active Recruiter'}
              </p>
            </div>
          </div>
          <div class="rec-card-stats">
            <div class="rec-stat-item">
              <div class="v" style="color: var(--info);">${r.total || 0}</div>
              <div class="l">Assigned</div>
            </div>
            <div class="rec-stat-item">
              <div class="v" style="color: var(--warning);">${r.pending || 0}</div>
              <div class="l">Pending</div>
            </div>
            <div class="rec-stat-item">
              <div class="v" style="color: var(--secondary);">${r.hired || 0}</div>
              <div class="l">Hired</div>
            </div>
          </div>
          <div style="margin-top: 12px;">
            <div class="progress-bar-bg" style="background: rgba(255,255,255,0.1); border-radius: 20px; height: 4px;">
              <div class="progress-bar-fill" style="width: ${r.total > 0 ? Math.round((r.hired / r.total) * 100) : 0}%; height: 4px; background: var(--gradient-primary); border-radius: 20px;"></div>
            </div>
            <div style="display: flex; justify-content: space-between; margin-top: 6px;">
              <span style="font-size: 9px; color: var(--text-dim);">Conversion Rate</span>
              <span style="font-size: 10px; font-weight: 600; color: var(--primary);">${r.total > 0 ? Math.round((r.hired / r.total) * 100) : 0}%</span>
            </div>
          </div>
          <div style="margin-top: 12px; display: flex; gap: 8px;">
            <button class="btn btn-info btn-xs" onclick="event.stopPropagation(); viewRecruiterLeads(${r.id}, '${esc(r.full_name)}')">
              <i class="fas fa-eye"></i> View Leads
            </button>
            ${r.status === 'active' ? 
              `<button class="btn btn-danger btn-xs" onclick="event.stopPropagation(); deactivateRecruiter(${r.id})">
                <i class="fas fa-user-slash"></i> Deactivate
              </button>` :
              `<button class="btn btn-success btn-xs" onclick="event.stopPropagation(); activateRecruiter(${r.id})">
                <i class="fas fa-user-check"></i> Activate
              </button>`
            }
          </div>
        </div>`;
      });
      html += `</div>`;
    }

  } else {
    // Regular Recruiter Dashboard
    html += `
    <div class="stats-grid stats-4">
      <div class="stat-card" onclick="showMyLeads()">
        <div class="stat-icon blue"><i class="fas fa-clipboard-list"></i></div>
        <div class="stat-value">${formatNumber(s.total_leads || 0)}</div>
        <div class="stat-label">My Assigned Leads</div>
        <div class="stat-trend"><i class="fas fa-tasks"></i> Active pipeline</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
        <div class="stat-value">${formatNumber(s.pending_leads || 0)}</div>
        <div class="stat-label">Pending Actions</div>
        <div class="stat-trend warning"><i class="fas fa-hourglass"></i> Needs follow-up</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-phone-alt"></i></div>
        <div class="stat-value">${formatNumber(s.calls_today || 0)}</div>
        <div class="stat-label">Calls Today</div>
        <div class="stat-trend up"><i class="fas fa-chart-line"></i> Daily activity</div>
      </div>
      <div class="stat-card" onclick="showMyLeads()">
        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
        <div class="stat-value">${formatNumber(s.hired_leads || 0)}</div>
        <div class="stat-label">My Hired Candidates</div>
        <div class="stat-trend up"><i class="fas fa-trophy"></i> Success stories</div>
      </div>
    </div>

    <!-- Performance Metrics -->
    <div class="stats-grid stats-2" style="grid-template-columns: 1fr 1fr;">
      <div class="chart-container">
        <div class="chart-header">
          <h4><i class="fas fa-chart-line"></i> My Performance Trend</h4>
          <span class="badge badge-active">Last 30 days</span>
        </div>
        <div id="performanceTrendChart" style="height: 300px;"></div>
      </div>
      <div class="chart-container">
        <div class="chart-header">
          <h4><i class="fas fa-chart-pie"></i> My Lead Status</h4>
          <span class="badge badge-active">Current pipeline</span>
        </div>
        <div id="myStatusChart" style="height: 300px;"></div>
      </div>
    </div>

    <!-- Quick Actions Widget -->
    <div class="top-bar" style="margin-top: 10px; background: linear-gradient(135deg, rgba(249,115,22,0.1), rgba(139,92,246,0.05));">
      <div class="page-title">
        <h1 style="font-size: 16px;">⚡ Quick Actions</h1>
        <p>Accelerate your workflow</p>
      </div>
      <div class="top-actions">
        <button class="btn btn-success btn-sm" onclick="showAddLeadModal()">
          <i class="fas fa-plus"></i> Add Lead
        </button>
        <button class="btn btn-info btn-sm" onclick="showMyLeads()">
          <i class="fas fa-list"></i> View All Leads
        </button>
        <button class="btn btn-primary btn-sm" onclick="showDashboard()">
          <i class="fas fa-sync-alt"></i> Refresh
        </button>
      </div>
    </div>`;

    // Initialize charts for regular recruiter
    setTimeout(() => {
      initRecruiterCharts(s);
    }, 100);
  }

  document.getElementById('mainContent').innerHTML = html;
  
  // Initialize charts for super admin if needed
  if (isSuperAdmin && s.recruiter_breakdown) {
    setTimeout(() => {
      initSuperAdminCharts(s);
    }, 100);
  }
  
  startAutoRefresh(showDashboard);
}

// Initialize Super Admin Charts
function initSuperAdminCharts(stats) {
  // Funnel Chart Data
  const funnelData = [
    stats.total_leads || 0,
    stats.scheduled_leads || 0,
    stats.hired_this_month || 0
  ];

  if (typeof ApexCharts !== 'undefined') {
    // Funnel Chart
    const funnelOptions = {
      series: funnelData,
      chart: {
        type: 'bar',
        height: 320,
        toolbar: { show: false },
        background: 'transparent'
      },
      plotOptions: {
        bar: {
          borderRadius: 8,
          horizontal: true,
          barHeight: '60%',
          colors: {
            ranges: [{
              from: 0,
              to: 1000,
              color: '#f97316'
            }]
          }
        }
      },
      dataLabels: { enabled: true, style: { colors: ['#fff'], fontSize: '12px' } },
      xaxis: {
        categories: ['Total Leads', 'Interviews Scheduled', 'Hired'],
        labels: { style: { colors: '#94a3b8', fontSize: '11px' } },
        axisBorder: { show: false },
        axisTicks: { show: false }
      },
      yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '11px' } } },
      grid: { show: false },
      tooltip: { theme: 'dark', x: { show: true } },
      fill: {
        type: 'gradient',
        gradient: { shadeIntensity: 1, opacityFrom: 0.9, opacityTo: 0.7, stops: [0, 100] }
      }
    };

    const funnelChart = new ApexCharts(document.querySelector("#funnelChart"), funnelOptions);
    funnelChart.render();
    charts.funnel = funnelChart;

    // Status Pie Chart
    const statusOptions = {
      series: [
        stats.pending_leads || 0,
        stats.scheduled_leads || 0,
        stats.hired_this_month || 0,
        (stats.total_leads - (stats.pending_leads + stats.scheduled_leads + stats.hired_this_month)) || 0
      ],
      chart: {
        type: 'donut',
        height: 320,
        toolbar: { show: false },
        background: 'transparent'
      },
      labels: ['Pending', 'Scheduled', 'Hired This Month', 'Other'],
      colors: ['#f59e0b', '#8b5cf6', '#10b981', '#64748b'],
      legend: {
        position: 'bottom',
        labels: { colors: '#94a3b8', fontSize: '11px' },
        markers: { width: 10, height: 10, radius: 6 }
      },
      plotOptions: {
        pie: {
          donut: {
            size: '65%',
            labels: {
              show: true,
              total: {
                show: true,
                label: 'Total',
                fontSize: '14px',
                color: '#fff',
                formatter: () => stats.total_leads || 0
              }
            }
          }
        }
      },
      stroke: { show: false },
      tooltip: { theme: 'dark' },
      dataLabels: { enabled: false }
    };

    const pieChart = new ApexCharts(document.querySelector("#statusPieChart"), statusOptions);
    pieChart.render();
    charts.statusPie = pieChart;

    // Weekly Bar Chart (mock data - would come from API)
    const weeklyOptions = {
      series: [{
        name: 'New Leads',
        data: [12, 18, 15, 22, 28, 35, 42]
      }, {
        name: 'Interviews',
        data: [5, 8, 10, 12, 15, 18, 22]
      }, {
        name: 'Hires',
        data: [2, 3, 4, 5, 7, 9, 12]
      }],
      chart: {
        type: 'bar',
        height: 320,
        stacked: false,
        toolbar: { show: false },
        background: 'transparent'
      },
      plotOptions: {
        bar: {
          borderRadius: 8,
          columnWidth: '60%',
          dataLabels: { position: 'top' }
        }
      },
      colors: ['#f97316', '#8b5cf6', '#10b981'],
      xaxis: {
        categories: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        labels: { style: { colors: '#94a3b8', fontSize: '11px' } }
      },
      yaxis: {
        labels: { style: { colors: '#94a3b8', fontSize: '11px' } },
        title: { text: 'Count', style: { color: '#94a3b8' } }
      },
      legend: {
        position: 'top',
        labels: { colors: '#94a3b8' },
        markers: { width: 8, height: 8, radius: 4 }
      },
      grid: { borderColor: 'rgba(255,255,255,0.05)' },
      tooltip: { theme: 'dark' }
    };

    const barChart = new ApexCharts(document.querySelector("#weeklyBarChart"), weeklyOptions);
    barChart.render();
    charts.weeklyBar = barChart;

    // Conversion Gauge
    const conversionRate = stats.total_leads > 0 
      ? Math.round((stats.hired_this_month / stats.total_leads) * 100) 
      : 0;
      
    const gaugeOptions = {
      series: [conversionRate],
      chart: {
        type: 'radialBar',
        height: 320,
        offsetY: -20,
        toolbar: { show: false },
        background: 'transparent'
      },
      plotOptions: {
        radialBar: {
          startAngle: -90,
          endAngle: 90,
          track: { background: 'rgba(255,255,255,0.1)', startAngle: -90, endAngle: 90 },
          dataLabels: {
            name: { show: true, fontSize: '14px', color: '#94a3b8', offsetY: -10 },
            value: { fontSize: '32px', fontWeight: 700, color: '#f97316', offsetY: 10, formatter: (val) => `${val}%` }
          }
        }
      },
      fill: {
        colors: ['#f97316'],
        type: 'gradient',
        gradient: { shade: 'dark', type: 'horizontal', shadeIntensity: 0.5, stops: [0, 100] }
      },
      stroke: { lineCap: 'round' },
      labels: ['Conversion Rate'],
      tooltip: { theme: 'dark' }
    };

    const gaugeChart = new ApexCharts(document.querySelector("#conversionGauge"), gaugeOptions);
    gaugeChart.render();
    charts.gauge = gaugeChart;
  }
}

// Initialize Recruiter Charts
function initRecruiterCharts(stats) {
  if (typeof ApexCharts === 'undefined') return;
  
  // Performance Trend Chart
  const perfOptions = {
    series: [{
      name: 'Leads Processed',
      data: [5, 8, 12, 10, 15, 18, 22, 25, 28, 30]
    }, {
      name: 'Interviews',
      data: [2, 3, 5, 6, 8, 10, 12, 14, 16, 18]
    }],
    chart: {
      type: 'area',
      height: 300,
      toolbar: { show: false },
      background: 'transparent'
    },
    colors: ['#f97316', '#8b5cf6'],
    fill: {
      type: 'gradient',
      gradient: {
        shadeIntensity: 1,
        opacityFrom: 0.5,
        opacityTo: 0.1,
        stops: [0, 90, 100]
      }
    },
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    xaxis: {
      categories: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6', 'Week 7', 'Week 8', 'Week 9', 'Week 10'],
      labels: { style: { colors: '#94a3b8', fontSize: '10px' } }
    },
    yaxis: { labels: { style: { colors: '#94a3b8' } } },
    grid: { borderColor: 'rgba(255,255,255,0.05)' },
    legend: {
      position: 'top',
      labels: { colors: '#94a3b8' },
      markers: { width: 8, height: 8, radius: 4 }
    },
    tooltip: { theme: 'dark' }
  };

  const perfChart = new ApexCharts(document.querySelector("#performanceTrendChart"), perfOptions);
  perfChart.render();

  // Status Pie Chart
  const statusOptions = {
    series: [
      stats.pending_leads || 0,
      stats.scheduled_leads || 0,
      stats.hired_leads || 0,
      (stats.total_leads - (stats.pending_leads + stats.scheduled_leads + stats.hired_leads)) || 0
    ],
    chart: {
      type: 'donut',
      height: 300,
      toolbar: { show: false },
      background: 'transparent'
    },
    labels: ['Pending', 'Scheduled', 'Hired', 'Other'],
    colors: ['#f59e0b', '#8b5cf6', '#10b981', '#64748b'],
    legend: {
      position: 'bottom',
      labels: { colors: '#94a3b8', fontSize: '11px' },
      markers: { width: 10, height: 10, radius: 6 }
    },
    plotOptions: {
      pie: {
        donut: {
          size: '65%',
          labels: {
            show: true,
            total: {
              show: true,
              label: 'Total',
              fontSize: '14px',
              color: '#fff',
              formatter: () => stats.total_leads || 0
            }
          }
        }
      }
    },
    stroke: { show: false },
    tooltip: { theme: 'dark' },
    dataLabels: { enabled: false }
  };

  const statusChart = new ApexCharts(document.querySelector("#myStatusChart"), statusOptions);
  statusChart.render();
  
  charts.myStatus = statusChart;
  charts.myPerformance = perfChart;
}

// Format numbers with K/M suffix
function formatNumber(num) {
  if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
  if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
  return num.toString();
}

// Export Dashboard Report
async function exportDashboardReport() {
  const res = await apiFetch(API.stats);
  if (!res.success) {
    toast('Failed to fetch data', 'error');
    return;
  }
  
  const stats = res.data;
  const reportData = {
    exportDate: new Date().toISOString(),
    stats: stats,
    generatedBy: currentUser?.full_name || 'System'
  };
  
  const blob = new Blob([JSON.stringify(reportData, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `nexus-report-${new Date().toISOString().split('T')[0]}.json`;
  a.click();
  URL.revokeObjectURL(url);
  
  toast('📊 Report exported successfully', 'success');
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
        <select id="branchFilter" class="form-control" style="width: 150px;" onchange="triggerAllLeadsFilter()">
          <option value="" ${!branch ? 'selected' : ''}>🏢 All Branches</option>
          <option value="main" ${branch === 'main' ? 'selected' : ''}>Main Branch</option>
          <option value="commercial" ${branch === 'commercial' ? 'selected' : ''}>Commercial</option>
          <option value="I9" ${branch === 'I9' ? 'selected' : ''}>I-9 Branch</option>
          <option value="v2" ${branch === 'v2' ? 'selected' : ''}>2.0 Branch</option>
          <option value="v3" ${branch === 'v3' ? 'selected' : ''}>3.0 Branch</option>
        </select>
      ` : ''}
      <select id="stageFilter" class="form-control" style="width: 140px;" onchange="triggerAllLeadsFilter()">
        <option value="">📋 All Statuses</option>
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
          <th>Position/City</th>
          <th>Status</th>
          <th>Last Activity</th>
          <th>Assigned To</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>`;
  
  if (!leads.length) {
    html += `<tr><td colspan="7" class="empty-state"><i class="fas fa-folder-open"></i><p>No leads found</p><\/td><\/tr>`;
  }
  
  leads.forEach(l => {
    const rawActivity = l.updated_at || l.created_at;
    html += `
      <tr>
        <td>
          <strong>${esc(l.full_name)}</strong>
          ${l.source === 'website' ? `<span class="badge" style="background:rgba(59,130,246,0.15);color:#60a5fa;border:1px solid rgba(59,130,246,0.3);font-size:9px;margin-left:4px;padding:2px 6px;"><i class="fas fa-globe"></i> Web</span>` : ''}
          <br>
          <span style="font-size: 10px; color: var(--text-dim);">ID: #${l.id}</span>
        <\/td>
        <td>
          <i class="fas fa-phone-alt" style="font-size: 10px; color: var(--primary);"></i> ${esc(l.phone)}<br>
          ${l.email ? `<i class="fas fa-envelope" style="font-size: 10px; color: var(--text-dim);"></i> ${esc(l.email).substring(0, 20)}` : ''}
        <\/td>
        <td>
          <strong>${esc(l.position_applied || 'N/A')}</strong><br>
          <span style="font-size: 11px; color: var(--text-dim);"><i class="fas fa-map-marker-alt"></i> ${esc(l.city || 'N/A')}</span>
        <\/td>
        <td>${stageBadge(l.current_stage)}<\/td>
        <td style="font-size: 11px;">
          <i class="fas fa-clock" style="color:var(--primary);"></i> ${ago(rawActivity)}<br>
          <span style="color: var(--text-dim);">Created: ${fmt(l.created_at)}</span>
        <\/td>
        <td>
          ${l.recruiter_name ? 
            `<span class="badge badge-active"><i class="fas fa-user-check"></i> ${esc(l.recruiter_name)}</span>` : 
            '<span class="badge badge-inactive" style="background:rgba(239,68,68,0.12);color:#ef4444;"><i class="fas fa-user-clock"></i> Unassigned</span>'}
        <\/td>
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
        <\/td>
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
  
  document.getElementById('mainContent').innerHTML = html;
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

// --- MY LEADS with Enhanced UI ---
async function showMyLeads() {
  setActiveNav('myLeads');
  setLoading();
  
  const res = await apiFetch(API.myLeads);
  if (!res.success) return toast('Failed to load', 'error');
  
  lastRefresh = new Date();
  const leads = res.data.leads || [];
  
  // Calculate stats
  const total = leads.length;
  const pending = leads.filter(l => ['new','assigned','outreach_phone','outreach_whatsapp_call','outreach_whatsapp_msg'].includes(l.current_stage)).length;
  const scheduled = leads.filter(l => l.current_stage === 'interview_scheduled').length;
  const completed = leads.filter(l => l.current_stage === 'hired' || l.current_stage === 'deployed').length;
  
  let html = `
  <div class="top-bar">
    <div class="page-title">
      <h1>📋 Pipeline Manager</h1>
      <p><span class="live-indicator"><div class="live-dot"></div> Live updates</span></p>
    </div>
    <div class="top-actions">
      <button class="btn btn-success" onclick="showAddLeadModal()">
        <i class="fas fa-plus"></i> Add Lead
      </button>
      <button class="btn btn-primary" onclick="showMyLeads()">
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
      <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card" style="padding: 14px;">
      <div class="stat-icon purple" style="width: 36px; height: 36px; font-size: 14px;"><i class="fas fa-calendar"></i></div>
      <div class="stat-value" style="font-size: 24px;">${scheduled}</div>
      <div class="stat-label">Scheduled</div>
    </div>
    <div class="stat-card" style="padding: 14px;">
      <div class="stat-icon green" style="width: 36px; height: 36px; font-size: 14px;"><i class="fas fa-check-circle"></i></div>
      <div class="stat-value" style="font-size: 24px;">${completed}</div>
      <div class="stat-label">Completed</div>
    </div>
  </div>
  
  <div class="table-wrap">
    <div class="table-header">
      <h3><i class="fas fa-list"></i> My Lead Pipeline</h3>
      <span class="badge badge-active">${total} active leads</span>
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
    html += `<tr><td colspan="7" class="empty-state"><i class="fas fa-inbox"></i><p>You have no assigned leads</p><p style="margin-top: 10px;"><button class="btn btn-primary btn-sm" onclick="showAddLeadModal()">+ Add Your First Lead</button></p><\/td><\/tr>`;
  }
  
  leads.forEach(l => {
    const lastContact = l.last_call_date ? fmtTime(l.last_call_date) : 'Not contacted';
    html += `
      <tr>
        <td><strong>${esc(l.full_name)}</strong><\/td>
        <td><i class="fas fa-phone-alt" style="font-size: 11px; color: var(--primary);"></i> ${esc(l.phone)}<\/td>
        <td>${esc(l.position_applied || '-')}<\/td>
        <td>${stageBadge(l.current_stage)}<\/td>
        <td style="font-size: 12px;"><i class="fas fa-clock"></i> ${lastContact}<\/td>
        <td><span class="badge" style="background: rgba(249,115,22,0.1); color: var(--primary);">${l.call_count || 0} calls</span><\/td>
        <td>
          <button class="btn btn-primary btn-sm" onclick="editLead(${l.id})">
            <i class="fas fa-arrow-right"></i> Work Lead
          </button>
        <\/td>
      </tr>`;
  });
  
  html += `</tbody>
    </table>
  </div>`;
  
  document.getElementById('mainContent').innerHTML = html;
  startAutoRefresh(showMyLeads);
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

// --- DISTRIBUTE LEADS with Enhanced UI ---
async function showDistributeLeads() {
  if (!isSuperAdmin) return;
  setActiveNav('distribute');
  setLoading();
  
  const [res, unassignedRes] = await Promise.all([
    apiFetch(API.recruiters),
    apiFetch(API.distribute)
  ]);
  
  const recs = res.data?.filter(r => r.status === 'active') || [];
  const unassignedList = unassignedRes.data?.unassigned_leads || [];
  const unassigned = unassignedList.length;
  
  let html = `
  <div class="top-bar">
    <div class="page-title">
      <h1>🎯 Smart Distribution Center</h1>
      <p><span style="color: var(--warning); font-weight: 700;">${unassigned}</span> leads ready for intelligent distribution</p>
    </div>
    <div class="top-actions">
      <button class="btn btn-warning" onclick="distributeEqually()" ${unassigned ? '' : 'disabled'}>
        <i class="fas fa-balance-scale"></i> Distribute Equally
      </button>
      <button class="btn btn-info" onclick="showRecruitersList()">
        <i class="fas fa-users-cog"></i> Manage Recruiters
      </button>
    </div>
  </div>`;

  if (unassigned === 0) {
    html += `<div class="empty-state">
      <i class="fas fa-check-circle" style="color: var(--secondary);"></i>
      <h3>All Caught Up! 🎉</h3>
      <p>There are zero unassigned leads in the system. Great job team!</p>
    </div>`;
  } else {
    html += `
    <div class="stats-grid stats-2" style="grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));">
      <div class="chart-container" style="padding: 20px;">
        <div class="chart-header">
          <h4><i class="fas fa-chart-pie"></i> Distribution Preview</h4>
          <span class="badge badge-active">${unassigned} leads to assign</span>
        </div>
        <div id="distributionPreview" style="height: 200px;"></div>
        <p style="text-align: center; font-size: 11px; color: var(--text-dim); margin-top: 12px;">
          <i class="fas fa-users"></i> ${recs.length} active recruiters available
        </p>
      </div>
      
      <div class="chart-container" style="padding: 20px;">
        <div class="chart-header">
          <h4><i class="fas fa-tachometer-alt"></i> Team Workload</h4>
          <span class="badge badge-active">Current load</span>
        </div>
        <div id="workloadChart" style="height: 200px;"></div>
        <div style="margin-top: 12px; text-align: center;">
          <button class="btn btn-primary btn-sm" onclick="showRecruitersList()">
            <i class="fas fa-user-plus"></i> Manage Team Members
          </button>
        </div>
      </div>
    </div>
    
    <div class="top-bar" style="margin: 10px 0 16px; padding: 16px;">
      <div class="page-title">
        <h1 style="font-size: 16px;">📋 Batch Distribution (By Count)</h1>
        <p>Assign specific number of leads to each recruiter</p>
      </div>
    </div>
    
    <div class="form-grid" style="grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); margin-bottom: 28px;">
      ${recs.map(r => `
        <div class="dist-recruiter-card">
          <div class="rec-avatar" style="width: 44px; height: 44px; font-size: 18px;">${r.full_name.charAt(0)}</div>
          <div class="info">
            <h4>${esc(r.full_name)}</h4>
            <p><i class="fas fa-tasks"></i> ${r.pending_leads || 0} pending - <i class="fas fa-trophy"></i> ${r.hired_leads || 0} hired</p>
          </div>
          <input type="number" id="dist_${r.id}" class="dist-input" placeholder="0" min="0" max="${unassigned}" value="0">
          <button class="btn btn-primary btn-sm" onclick="assignCount(${r.id})">
            <i class="fas fa-arrow-right"></i> Assign
          </button>
        </div>
      `).join('')}
    </div>

    <!-- Unassigned Leads Master Table -->
    <div class="table-wrap">
      <div class="table-header">
        <h3><i class="fas fa-user-plus" style="color:var(--primary)"></i> Unassigned Candidate Queue (${unassignedList.length} Leads)</h3>
        <span class="badge badge-inactive" style="background:rgba(239,68,68,0.12);color:#ef4444;">Needs Assignment</span>
      </div>
      <table>
        <thead>
          <tr>
            <th>Candidate</th>
            <th>Contact</th>
            <th>Position / City</th>
            <th>Source</th>
            <th>Received Date</th>
            <th style="text-align:right;">Quick Assign to Recruiter</th>
          </tr>
        </thead>
        <tbody>
          ${unassignedList.map(l => `
            <tr>
              <td>
                <strong>${esc(l.full_name)}</strong><br>
                <span style="font-size:10px; color:var(--text-dim);">ID: #${l.id}</span>
              </td>
              <td>
                <i class="fas fa-phone-alt" style="font-size:10px; color:var(--primary);"></i> ${esc(l.phone)}<br>
                ${l.email ? `<span style="font-size:11px; color:var(--text-muted);">${esc(l.email)}</span>` : ''}
              </td>
              <td>
                <strong>${esc(l.position_applied || 'N/A')}</strong><br>
                <span style="font-size:11px; color:var(--text-dim);"><i class="fas fa-map-marker-alt"></i> ${esc(l.city || 'N/A')}</span>
              </td>
              <td>
                ${l.source === 'website' ? `<span class="badge" style="background:rgba(59,130,246,0.15);color:#60a5fa;border:1px solid rgba(59,130,246,0.3);"><i class="fas fa-globe"></i> Website</span>` : `<span class="badge" style="background:rgba(255,255,255,0.06);">${esc(l.source || 'Manual')}</span>`}
              </td>
              <td style="font-size:11px; color:var(--text-muted);">
                ${fmt(l.created_at)}
              </td>
              <td style="text-align:right;">
                <div style="display:inline-flex; gap:8px; align-items:center;">
                  <select id="quick_assign_${l.id}" class="form-control" style="width:180px; padding:6px 12px; font-size:12px;">
                    <option value="">-- Choose Recruiter --</option>
                    ${recs.map(r => `<option value="${r.id}">${esc(r.full_name)}</option>`).join('')}
                  </select>
                  <button class="btn btn-primary btn-sm" onclick="quickAssignSingleLead(${l.id})">
                    <i class="fas fa-user-check"></i> Assign
                  </button>
                  ${(l.external_lead_id || l.cv_file_url) ? `
                    <a href="api/fetch_lead_cv.php?external_id=${encodeURIComponent(l.external_lead_id || l.id)}" target="_blank" class="btn btn-info btn-sm" title="View CV" style="text-decoration:none; padding:6px 10px;">
                      <i class="fas fa-file-pdf"></i>
                    </a>
                  ` : ''}
                </div>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>`;
    
    // Initialize distribution charts
    setTimeout(() => {
      initDistributionCharts(recs, unassigned);
    }, 100);
  }
  
  document.getElementById('mainContent').innerHTML = html;
  clearInterval(refreshTimer);
}

async function quickAssignSingleLead(leadId) {
  const recSelect = document.getElementById(`quick_assign_${leadId}`);
  const recId = recSelect ? parseInt(recSelect.value) : 0;
  if (!recId) return toast('Please select a recruiter first', 'warning');

  const res = await apiFetch(API.distribute, {
    method: 'POST',
    body: JSON.stringify({
      mode: 'manual',
      assignments: [{ lead_id: leadId, recruiter_id: recId }]
    })
  });

  if (res.success) {
    toast('Lead assigned successfully', 'success');
    showDistributeLeads();
  } else {
    toast(res.error || 'Failed to assign lead', 'error');
  }
}

// Distribution Charts
function initDistributionCharts(recruiters, unassigned) {
  if (typeof ApexCharts === 'undefined') return;
  
  // Preview Chart
  const previewOptions = {
    series: [unassigned],
    chart: {
      type: 'radialBar',
      height: 200,
      toolbar: { show: false },
      background: 'transparent'
    },
    plotOptions: {
      radialBar: {
        hollow: { size: '60%' },
        track: { background: 'rgba(255,255,255,0.1)' },
        dataLabels: {
          name: { show: true, fontSize: '12px', color: '#94a3b8' },
          value: { fontSize: '28px', fontWeight: 700, color: '#f97316', formatter: (val) => `${val}` }
        }
      }
    },
    fill: { colors: ['#f97316'], type: 'gradient', gradient: { shade: 'dark', stops: [0, 100] } },
    labels: ['Unassigned'],
    tooltip: { theme: 'dark' }
  };
  
  const previewChart = new ApexCharts(document.querySelector("#distributionPreview"), previewOptions);
  previewChart.render();
  
  // Workload Chart
  const workloadData = recruiters.map(r => ({
    name: r.full_name.split(' ')[0],
    leads: r.pending_leads || 0
  }));
  
  const workloadOptions = {
    series: [{
      name: 'Pending Leads',
      data: workloadData.map(w => w.leads)
    }],
    chart: {
      type: 'bar',
      height: 200,
      toolbar: { show: false },
      background: 'transparent'
    },
    plotOptions: {
      bar: {
        borderRadius: 8,
        columnWidth: '50%',
        distributed: true
      }
    },
    colors: ['#f97316', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899'],
    xaxis: {
      categories: workloadData.map(w => w.name),
      labels: { style: { colors: '#94a3b8', fontSize: '11px' } }
    },
    yaxis: { labels: { style: { colors: '#94a3b8' } } },
    grid: { borderColor: 'rgba(255,255,255,0.05)' },
    tooltip: { theme: 'dark' }
  };
  
  const workloadChart = new ApexCharts(document.querySelector("#workloadChart"), workloadOptions);
  workloadChart.render();
}

// ==================== RECRUITER MANAGEMENT FUNCTIONS ====================

// --- Show Recruiters List with Active/Inactive Management ---
async function showRecruitersList() {
  if (!isSuperAdmin) return;
  setActiveNav('manageRec');
  setLoading();
  
  const res = await apiFetch(API.recruiters);
  if (!res.success) return toast('Failed to load recruiters', 'error');
  clearInterval(refreshTimer);
  
  const list = res.data || [];
  const activeCount = list.filter(r => r.status === 'active').length;
  const inactiveCount = list.filter(r => r.status === 'inactive').length;
  
  let html = `
  <div class="top-bar">
    <div class="page-title">
      <h1><i class="fas fa-users-cog"></i> Recruiter Management</h1>
      <p>${activeCount} active - ${inactiveCount} inactive - Total ${list.length} recruiters</p>
    </div>
    <div class="top-actions">
      <button class="btn btn-success" onclick="showAddRecruiterModal()">
        <i class="fas fa-plus"></i> Add New Recruiter
      </button>
      <button class="btn btn-primary" onclick="showRecruitersList()">
        <i class="fas fa-sync-alt"></i> Refresh
      </button>
    </div>
  </div>
  
  <div class="table-wrap">
    <div class="table-header">
      <h3><i class="fas fa-list"></i> Recruiters Directory</h3>
    </div>
    <table style="width: 100%;">
      <thead>
        <tr>
          <th>Recruiter</th>
          <th>Contact</th>
          <th>Leads</th>
          <th>Performance</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>`;
  
  if (!list.length) {
    html += `<tr><td colspan="6" class="empty-state">No recruiters found<\/td><\/tr>`;
  }
  
  list.forEach(r => {
    const conversionRate = r.total_leads > 0 ? Math.round((r.hired_leads / r.total_leads) * 100) : 0;
    const statusBadge = r.status === 'active' 
      ? '<span class="badge badge-active">🟢 Active</span>' 
      : '<span class="badge badge-inactive">⚫ Inactive</span>';
    
    html += `
      <tr>
        <td>
          <strong>${esc(r.full_name)}</strong><br>
          <span style="font-size: 10px; color: var(--text-dim);">ID: ${r.employee_code || 'N/A'}</span>
        <\/td>
        <td>
          <i class="fas fa-envelope"></i> ${esc(r.email)}<br>
          <i class="fas fa-phone"></i> ${r.phone || 'N/A'}
        <\/td>
        <td>
          <strong>${r.total_leads || 0}</strong> Total<br>
          <span style="color: var(--warning);">${r.pending_leads || 0} Pending</span>
        <\/td>
        <td>
          <span style="color: var(--secondary);">${r.hired_leads || 0} Hired</span><br>
          ${r.total_calls || 0} Calls - ${conversionRate}% Conv
        <\/td>
        <td>${statusBadge}<\/td>
        <td>
          <div style="display: flex; gap: 6px;">
            <button class="btn btn-info btn-xs" onclick="viewRecruiterLeads(${r.id}, '${esc(r.full_name)}')">
              <i class="fas fa-eye"></i> Leads
            </button>
            ${r.status === 'active' ? 
              `<button class="btn btn-danger btn-xs" onclick="deactivateRecruiter(${r.id})">
                <i class="fas fa-user-slash"></i> Deactivate
              </button>` :
              `<button class="btn btn-success btn-xs" onclick="activateRecruiter(${r.id})">
                <i class="fas fa-user-check"></i> Activate
              </button>`
            }
          </div>
        <\/td>
      </tr>`;
  });
  
  html += `</tbody>
    </table>
  </div>`;
  
  document.getElementById('mainContent').innerHTML = html;
}

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
    body: JSON.stringify({ full_name, email, username, phone, password, employee_code })
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

  document.getElementById('mainContent').innerHTML = html;
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
window.showAllLeads = showAllLeads;
window.triggerAllLeadsFilter = triggerAllLeadsFilter;
window.showMyLeads = showMyLeads;
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
window.initSuperAdminCharts = initSuperAdminCharts;
window.initRecruiterCharts = initRecruiterCharts;
window.initDistributionCharts = initDistributionCharts;

// Recruiter Management Exports
window.showRecruitersList = showRecruitersList;
window.showAddRecruiterModal = showAddRecruiterModal;
window.createNewRecruiter = createNewRecruiter;
window.deactivateRecruiter = deactivateRecruiter;
window.activateRecruiter = activateRecruiter;