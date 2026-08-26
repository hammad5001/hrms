/**
 * Balitech HRMS — Employee Performance / Daily Transfer Reporting
 * Advanced module: period tabs, auto-refresh, mini-stats, streak, CSV export,
 * D1/D2 toggle, call notes/duration/outcome, and advanced analytics charts.
 */

// ─── State ────────────────────────────────────────────────────────────────────
let drpCurrentPeriod     = 'today';
let drpCurrentData       = [];
let drpAutoRefreshTimer  = null;
let drpLiveTimeTimer     = null;
let perfTrendChartInstance  = null;
let perfRatioChartInstance  = null;
let drpTrendChartInstance   = null;
let drpHourChartInstance    = null;
let drpWeeklyChartInstance  = null;
let drpDowChartInstance     = null;
let drpD1D2ChartInstance    = null;
let drpQaStatusChartInstance = null;
let drpQaTrendChartInstance   = null;

// ─── Helper: API call ─────────────────────────────────────────────────────────
async function drpApi(action, extra = '') {
    try {
        const res  = await fetch(`api/get_agent_performance.php?action=${encodeURIComponent(action)}${extra}`, { credentials: 'include' });
        const text = await res.text();
        try { return JSON.parse(text); }
        catch { console.error('Invalid JSON from', action, text.substring(0, 200)); return { success: false, message: 'Invalid JSON' }; }
    } catch (err) {
        console.error('drpApi error:', err);
        return { success: false, message: 'Network error' };
    }
}

// ─── Live clock ───────────────────────────────────────────────────────────────
function drpStartLiveClock() {
    function tick() {
        const el = document.getElementById('drpLiveTime');
        if (el) el.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    tick();
    if (drpLiveTimeTimer) clearInterval(drpLiveTimeTimer);
    drpLiveTimeTimer = setInterval(tick, 1000);
}

// ─── Transfer Option Toggle ───────────────────────────────────────────────────
window.drpSelectTransfer = function(opt) {
    document.getElementById('drpTransferOn').value = opt;
    const btnD1 = document.getElementById('drpBtnD1');
    const btnD2 = document.getElementById('drpBtnD2');
    if (!btnD1 || !btnD2) return;
    if (opt === 'D1') {
        btnD1.classList.add('drc-tog-active');
        btnD2.classList.remove('drc-tog-active');
    } else {
        btnD2.classList.add('drc-tog-active');
        btnD1.classList.remove('drc-tog-active');
    }
};

// ─── Duration Quick Set ───────────────────────────────────────────────────────
window.drcSetDuration = function(val) {
    const el = document.getElementById('drpCallDuration');
    if (el) el.value = val;
    document.querySelectorAll('.drc-dur-chip').forEach(c => {
        c.classList.toggle('active', parseInt(c.textContent) === val);
    });
};

// ─── Ring Progress (60s countdown) ───────────────────────────────────────────
let drcRingInterval = null;
function drcStartRing() {
    const ring = document.getElementById('drcRingProgress');
    if (!ring) return;
    if (drcRingInterval) clearInterval(drcRingInterval);
    let elapsed = 0;
    const total = 60;
    ring.style.strokeDashoffset = 100;
    drcRingInterval = setInterval(() => {
        elapsed++;
        const prog = (elapsed / total) * 100;
        ring.style.strokeDashoffset = 100 - prog;
        if (elapsed >= total) { elapsed = 0; ring.style.strokeDashoffset = 100; }
    }, 1000);
}

// ─── Populate HRMS Verifiers Dropdown ─────────────────────────────────────────
function drpPopulateVerifiers(verifiers) {
    const sel = document.getElementById('drpVerifierRealName');
    if (!sel) return;

    const currentVal = sel.value;
    sel.innerHTML = '<option value="">- Select Verifier (HRMS) -</option>';

    if (verifiers && verifiers.length > 0) {
        verifiers.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v.full_name;
            opt.textContent = `${v.full_name} (${v.employee_code || 'HRMS'})`;
            sel.appendChild(opt);
        });
    } else {
        const opt = document.createElement('option');
        opt.value = "";
        opt.textContent = "No active verifiers found in HRMS";
        sel.appendChild(opt);
    }

    if (currentVal) sel.value = currentVal;
}

// ─── Restore Agent Memory (Pseudo & Team) ──────────────────────────────────────
function drpRestoreAgentMemory() {
    const savedPseudo = localStorage.getItem('drp_agent_pseudo');
    const savedTeam   = localStorage.getItem('drp_team_name');

    const pseudoEl = document.getElementById('drpAgentPseudo');
    const teamEl   = document.getElementById('drpTeamName');

    if (pseudoEl && savedPseudo) pseudoEl.value = savedPseudo;
    if (teamEl && savedTeam) teamEl.value = savedTeam;
}

// ─── Period Tab Switch ────────────────────────────────────────────────────────
window.drpSwitchPeriod = function(period) {
    drpCurrentPeriod = period;
    ['today','week','month'].forEach(p => {
        const tab = document.getElementById('drpTab' + p.charAt(0).toUpperCase() + p.slice(1));
        if (tab) {
            tab.classList.toggle('drc-tab-active', p === period);
            // Legacy support
            tab.classList.toggle('active', p === period);
        }
    });
    loadPerformanceView();
};

// ─── Render Transfer Table ────────────────────────────────────────────────────
function drpRenderTable(transfers) {
    const tbody    = document.getElementById('todayTransfersTableBody');
    const countEl  = document.getElementById('drpTableCount');
    const d1El     = document.getElementById('drcSumD1');
    const d2El     = document.getElementById('drcSumD2');
    const summaryEl= document.getElementById('drpTableSummary');
    if (!tbody) return;

    if (!transfers || transfers.length === 0) {
        tbody.innerHTML = `<tr class="drc-empty-row"><td colspan="11">
            <i class="fas fa-phone-slash drc-empty-icon"></i>
            <strong class="drc-empty-title">No Medicare transfers logged yet</strong>
            <span class="drc-empty-sub">Log your first transfer call using the form above</span>
        </td></tr>`;
        if (countEl) countEl.textContent = '0';
        if (d1El) d1El.textContent = '0';
        if (d2El) d2El.textContent = '0';
        const tabId = 'drcTabCount' + drpCurrentPeriod.charAt(0).toUpperCase() + drpCurrentPeriod.slice(1);
        const tabCount = document.getElementById(tabId); if (tabCount) tabCount.textContent = '0';
        return;
    }

    const d1 = transfers.filter(r => (r.transfer_on || '').toUpperCase() === 'D1').length;
    const d2 = transfers.filter(r => (r.transfer_on || '').toUpperCase() === 'D2').length;

    tbody.innerHTML = transfers.map((r, i) => {
        const dt   = new Date(r.created_at);
        const time = dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const dateStr = drpCurrentPeriod !== 'today'
            ? `<span class="drc-time-date">${dt.toLocaleDateString([], { month: 'short', day: 'numeric' })}</span>` : '';

        const linePill = `<span class="drc-opt-d1" style="font-weight:700;"><i class="fas fa-satellite-dish" style="font-size:10px; margin-right:4px;"></i> ${r.transfer_on || 'D1'}</span>`;

        const nameVal = r.customer_name || (r.customer_first_name ? `${r.customer_first_name} ${r.customer_last_name || ''}`.trim() : '');
        const stateZip = [r.customer_state, r.customer_zip].filter(Boolean).join(' / ') || '\u2014';
        const verifier = r.verifier_real_name ? `<span style="color:#60a5fa; font-weight:600;"><i class="fas fa-user-shield" style="font-size:10px;"></i> ${r.verifier_real_name}</span>` : '<span style="color:rgba(255,255,255,0.25);">\u2014</span>';
        const pseudoTeam = [r.agent_pseudo ? `Pseudo: ${r.agent_pseudo}` : '', r.team_name ? `[${r.team_name}]` : ''].filter(Boolean).join(' ') || '\u2014';

        const notes = r.call_notes
            ? `<span title="${r.call_notes.replace(/"/g,'&quot;')}" style="font-size:11px; color:rgba(255,255,255,0.6); cursor:help;">${r.call_notes.length > 30 ? r.call_notes.substring(0,30)+'\u2026' : r.call_notes}</span>`
            : '<span style="color:rgba(255,255,255,0.18);">\u2014</span>';
        const offline = r.is_offline_sync ? `<span class="drc-offline-badge">OFFLINE</span>` : '';

        // QA Status Badge
        let qaBadge = '';
        if (r.qa_status === 'approved') {
            qaBadge = `<span style="background:rgba(16,185,129,0.15); color:#10b981; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:800; display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-check-circle"></i> Approved</span>`;
        } else if (r.qa_status === 'rejected') {
            qaBadge = `<span style="background:rgba(239,68,68,0.15); color:#ef4444; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:800; display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-times-circle"></i> Rejected</span>`;
        } else {
            qaBadge = `<span style="background:rgba(245,158,11,0.15); color:#f59e0b; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:800; display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-clock"></i> Pending</span>`;
        }

        // Source badge
        const sourceBadge = (r.source === 'dialer')
            ? `<span style="background:rgba(243,149,41,0.15);color:#F39529;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;letter-spacing:0.04em;display:inline-flex;align-items:center;gap:3px;"><i class="fas fa-phone-volume" style="font-size:9px;"></i>Dialer/QA</span>`
            : `<span style="background:rgba(148,163,184,0.12);color:#94a3b8;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;letter-spacing:0.04em;">Manual</span>`;

        return `<tr>
            <td class="drc-num-col">${transfers.length - i}</td>
            <td><span class="drc-time-strong">${time}</span>${dateStr}${offline}</td>
            <td class="drc-phone-col">${r.customer_number || '\u2014'}</td>
            <td>${nameVal || '<span style="color:rgba(255,255,255,0.2);">\u2014</span>'}</td>
            <td style="font-size:12px; color:rgba(255,255,255,0.5);">${stateZip}</td>
            <td style="font-size:12px; color:rgba(255,255,255,0.5);">${r.customer_age || '\u2014'}</td>
            <td>${linePill}</td>
            <td>${verifier}</td>
            <td style="font-size:11px; color:rgba(255,255,255,0.5);">${pseudoTeam}</td>
            <td>${qaBadge}</td>
            <td>${sourceBadge}</td>
        </tr>`;
    }).join('');

    if (countEl) countEl.textContent = `${transfers.length}`;

    const dispositionCounts = {};
    (transfers || []).forEach(row => {
        const disp = String(row.transfer_on || row.disposition || row.line || '').trim().toUpperCase();
        if (!disp) return;
        dispositionCounts[disp] = (dispositionCounts[disp] || 0) + 1;
    });

    const dispositionOrder = ['D1','D2','D3','D4','D5','D5B','D6','D6CPL','D7','D8','HI','HI2','HIB','HIC','HIMAIN'];
    const dispositionList = [];

    dispositionOrder.forEach(disp => {
        if (dispositionCounts[disp] > 0) {
            dispositionList.push({ disposition: disp, total: dispositionCounts[disp] });
        }
    });

    Object.keys(dispositionCounts).sort().forEach(disp => {
        if (!dispositionOrder.includes(disp) && dispositionCounts[disp] > 0) {
            dispositionList.push({ disposition: disp, total: dispositionCounts[disp] });
        }
    });

    const chipForValue = (valueEl) => {
        if (!valueEl) return null;
        return valueEl.closest('.drc-dispo-chip') || valueEl.parentElement;
    };

    const d1Chip = chipForValue(d1El);
    const d2Chip = chipForValue(d2El);
    const chipParent = d2Chip ? d2Chip.parentElement : null;

    if (chipParent) {
        chipParent.querySelectorAll('.drc-table-dynamic-disposition-chip').forEach(el => el.remove());
    }

    const setChip = (chip, label, total, valueId) => {
        if (!chip) return;
        chip.style.removeProperty('display');
        chip.innerHTML = `${label}: <span${valueId ? ` id="${valueId}"` : ''}>${Number(total) || 0}</span>`;
    };

    const hideChip = (chip) => {
        if (chip) chip.style.display = 'none';
    };

    if (!dispositionList.length) {
        hideChip(d1Chip);
        hideChip(d2Chip);
    } else {
        setChip(d1Chip, dispositionList[0].disposition, dispositionList[0].total, 'drpMiniD1Today');

        if (dispositionList[1]) {
            setChip(d2Chip, dispositionList[1].disposition, dispositionList[1].total, 'drpMiniD2Today');
        } else {
            hideChip(d2Chip);
        }

        if (chipParent) {
            let insertAfter = d2Chip || d1Chip;

            dispositionList.slice(2).forEach(item => {
                const clone = (d1Chip || d2Chip).cloneNode(false);
                clone.classList.add('drc-table-dynamic-disposition-chip');
                clone.style.removeProperty('display');
                clone.innerHTML = `${item.disposition}: <span>${Number(item.total) || 0}</span>`;
                insertAfter.insertAdjacentElement('afterend', clone);
                insertAfter = clone;
            });
        }
    }

    if (summaryEl) summaryEl.textContent = '';

    // Update active tab count badge
    const tabId = 'drcTabCount' + drpCurrentPeriod.charAt(0).toUpperCase() + drpCurrentPeriod.slice(1);
    const tabCount = document.getElementById(tabId); if (tabCount) tabCount.textContent = transfers.length;

    // Update call counter
    const cc = document.getElementById('drcCallCounter');
    if (cc && drpCurrentPeriod === 'today') cc.textContent = transfers.length;
}

// ─── Render Mini Stats ────────────────────────────────────────────────────────
function drpRenderMiniStats(miniStats, streak) {
    const s = miniStats || {};
    const t = s.today || { total: 0, dispositions: [] };
    const w = s.week  || { total: 0, dispositions: [] };
    const m = s.month || { total: 0, dispositions: [] };

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[ch]));

    const setEl = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    };

    const animNum = (id, newVal) => {
        const el = document.getElementById(id);
        if (!el) return;

        const old = parseInt(el.textContent) || 0;

        if (old !== newVal) {
            el.textContent = newVal;

            const pill = el.closest('.drc-kpi-pill');
            if (pill) {
                pill.classList.remove('drc-pop');
                void pill.offsetWidth;
                pill.classList.add('drc-pop');
            }
        }
    };

    const setChip = (chip, label, count, valueId = '') => {
        if (!chip) return;

        const idAttr = valueId ? ` id="${valueId}"` : '';

        chip.style.display = '';
        chip.innerHTML = `${escapeHtml(label)}: <span${idAttr}>${Number(count) || 0}</span>`;
    };

    const renderTodayDispositionChips = () => {
        const d1Value = document.getElementById('drpMiniD1Today');
        const d2Value = document.getElementById('drpMiniD2Today');

        if (!d1Value || !d2Value) return;

        const d1Chip = d1Value.closest('.drc-kpi-pill') || d1Value.parentElement;
        const d2Chip = d2Value.closest('.drc-kpi-pill') || d2Value.parentElement;

        if (!d1Chip || !d2Chip || !d2Chip.parentElement) return;

        const parent = d2Chip.parentElement;

        parent.querySelectorAll('.drc-dynamic-disposition-chip').forEach(el => el.remove());

        const list = Array.isArray(t.dispositions)
            ? t.dispositions
                .map(x => ({
                    disposition: String(x.disposition || '').toUpperCase(),
                    total: Number(x.total || 0)
                }))
                .filter(x => x.disposition && x.total > 0)
            : [];

        // Important:
        // Koi disposition nahi hai to D1/D2 bhi hide rahenge.
        // Zero fallback show nahi hoga.
        if (!list.length) {
            d1Chip.style.display = 'none';
            d2Chip.style.display = 'none';
            return;
        }

        // First disposition existing D1 chip mein same style ke sath
        setChip(d1Chip, list[0].disposition, list[0].total, 'drpMiniD1Today');

        // Second disposition existing D2 chip mein same style ke sath
        if (list[1]) {
            setChip(d2Chip, list[1].disposition, list[1].total, 'drpMiniD2Today');
        } else {
            d2Chip.style.display = 'none';
        }

        // Baqi dispositions D2 chip ka same style clone karke
        let insertAfter = d2Chip;

        list.slice(2).forEach(item => {
            const clone = d2Chip.cloneNode(false);
            clone.classList.add('drc-dynamic-disposition-chip');
            clone.removeAttribute('id');
            clone.style.display = '';

            setChip(clone, item.disposition, item.total);

            insertAfter.insertAdjacentElement('afterend', clone);
            insertAfter = clone;
        });
    };

    animNum('drpMiniToday',  Number(t.total || 0));
    animNum('drpMiniWeek',   Number(w.total || 0));
    animNum('drpMiniMonth',  Number(m.total || 0));

    renderTodayDispositionChips();

    setEl('drcTabCountToday',  Number(t.total || 0));
    setEl('drcTabCountWeek',   Number(w.total || 0));
    setEl('drcTabCountMonth',  Number(m.total || 0));

    const mtdTotal = Number(m.total || 0);
    setEl('drcGoalMTD', mtdTotal);

    const mtdProgBar = document.getElementById('drcGoalProgressBar');
    if (mtdProgBar) {
        const mtdPercent = Math.min((mtdTotal / 360) * 100, 100);
        mtdProgBar.style.width = mtdPercent + '%';
    }

    const badge   = document.getElementById('drpStreakBadge');
    const countEl = document.getElementById('drpStreakCount');

    if (badge && countEl) {
        badge.style.display = streak >= 2 ? 'inline-flex' : 'none';
        countEl.textContent = streak;
    }
}

// ─── Render Leaderboard List (compatibility stub — drpLoadTopAgents is the live source) ──
function drpRenderLeaderboard(list) {
    // Dialer Top 5 is loaded by drpLoadTopAgents; this stub is kept for compatibility.
    // Only fall back to HRMS list if no dialer data has been loaded yet.
    const box = document.getElementById('drcLeaderboardBody');
    if (!box) return;
    if (box.dataset.dialerLoaded === '1') return; // dialer data already shown
    if (!list || list.length === 0) {
        box.innerHTML = `<div style="text-align:center; padding:20px; color:rgba(255,255,255,0.25); font-size:11px;">No agent logged calls yet.</div>`;
        return;
    }
    box.innerHTML = list.map((item, i) => {
        return `<div class="drc-leaderboard-row">
            <div class="drc-leaderboard-rank">${i + 1}</div>
            <div class="drc-leaderboard-name">${item.name}</div>
            <div class="drc-leaderboard-val">${item.cnt}</div>
        </div>`;
    }).join('');
}

// ─── Dialer Top 5 Agents Today ────────────────────────────────────────────────
let drpTop5Timer = null;

async function drpLoadTopAgents() {
    const box = document.getElementById('drcLeaderboardBody');
    if (!box) return;

    // Update card header to show Sales badge
    const cardHeader = box.closest('.drc-panel, .drc-widget-card')?.querySelector('.drc-widget-card-header, .drc-card-header');
    if (cardHeader && !cardHeader.dataset.dialerBadgeAdded) {
        cardHeader.dataset.dialerBadgeAdded = '1';
        const badge = document.createElement('span');
        badge.style.cssText = 'margin-left:auto;background:rgba(243,149,41,0.18);color:#F39529;padding:2px 9px;border-radius:999px;font-size:10px;font-weight:800;letter-spacing:0.04em;';
        badge.textContent = 'Sales';
        cardHeader.appendChild(badge);
    }

    try {
        const res = await fetch(`api/get_dialer_top_agents_today.php?_=${Date.now()}`, { credentials: 'include', cache: 'no-store' });
        const data = await res.json();
        if (!data || !data.success) return;

        const agents = data.agents || [];
        box.dataset.dialerLoaded = '1';

        if (!agents.length) {
            box.innerHTML = `<div style="text-align:center;padding:20px;color:rgba(255,255,255,0.3);font-size:12px;font-weight:600;">No sales data found for today.</div>`;
            return;
        }

        box.innerHTML = agents.map((a, i) => {
            const medals = ['🥇','🥈','🥉'];
            const rank = medals[i] ? `<span style="font-size:15px;">${medals[i]}</span>` : `<span style="width:24px;height:24px;border-radius:7px;background:rgba(243,149,41,0.15);color:#F39529;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;">${i+1}</span>`;
            const d5 = parseInt(a.d5_total)||0;
            const d4 = parseInt(a.d4_total)||0;
            return `<div class="drc-leaderboard-row" style="flex-direction:column;align-items:flex-start;gap:4px;padding:10px 14px;">
                <div style="display:flex;align-items:center;gap:8px;width:100%;">
                    ${rank}
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:800;color:#f8fafc;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${String(a.agent_name||'').replace(/</g,'&lt;')}</div>
                        <div style="font-size:10px;color:rgba(255,255,255,0.45);font-weight:700;">${String(a.employee_code||'').replace(/</g,'&lt;')}</div>
                    </div>
                    <div style="font-size:18px;font-weight:900;color:#818cf8;">${parseInt(a.total_transfers)||0}</div>
                </div>
                <div style="display:flex;gap:8px;padding-left:32px;">
                    <span style="font-size:10px;background:rgba(16,185,129,0.12);color:#10b981;padding:1px 7px;border-radius:999px;font-weight:700;">D5: ${d5}</span>
                    <span style="font-size:10px;background:rgba(99,102,241,0.12);color:#818cf8;padding:1px 7px;border-radius:999px;font-weight:700;">D4: ${d4}</span>
                </div>
            </div>`;
        }).join('');
    } catch (err) {
        console.warn('drpLoadTopAgents error:', err);
    }
}

function drpStartTop5AutoRefresh() {
    if (drpTop5Timer) clearInterval(drpTop5Timer);
    drpTop5Timer = setInterval(drpLoadTopAgents, 60000);
}

// ─── Calculate Speed/SLA average ──────────────────────────────────────────────
function drpCalculateSLA(transfers) {
    const durationEl   = document.getElementById('drcSlaDuration');
    const progressBar  = document.getElementById('drcSlaProgressBar');
    const statusText   = document.getElementById('drcSlaStatusText');
    if (!durationEl || !transfers) return;

    const validCalls = transfers.filter(c => (parseInt(c.call_duration_mins) || 0) > 0);
    if (validCalls.length === 0) {
        durationEl.textContent = '0 min';
        if (progressBar) progressBar.style.width = '0%';
        if (statusText) { statusText.textContent = 'No Call Data'; statusText.style.color = 'rgba(255,255,255,0.3)'; }
        return;
    }

    const totalDuration = validCalls.reduce((acc, c) => acc + (parseInt(c.call_duration_mins) || 0), 0);
    const avg = Math.round((totalDuration / validCalls.length) * 10) / 10;

    durationEl.textContent = `${avg} min`;

    if (progressBar) {
        const percent = Math.min((avg / 10) * 100, 100);
        progressBar.style.width = percent + '%';

        if (avg <= 5) {
            progressBar.style.backgroundColor = '#10b981'; // Green
            if (statusText) { statusText.textContent = 'Within SLA'; statusText.style.color = '#10b981'; }
        } else if (avg <= 8) {
            progressBar.style.backgroundColor = '#f59e0b'; // Amber
            if (statusText) { statusText.textContent = 'Warning threshold'; statusText.style.color = '#f59e0b'; }
        } else {
            progressBar.style.backgroundColor = '#ef4444'; // Red
            if (statusText) { statusText.textContent = 'SLA Violation'; statusText.style.color = '#ef4444'; }
        }
    }
}

// ─── Load Performance Dashboard ───────────────────────────────────────────────
window.loadPerformanceView = async function() {
    // Toggle body class for biometric button hide (CSS :has() fallback)
    document.body.classList.add('perf-view-active');
    const res = await drpApi('load_dashboard', `&period=${drpCurrentPeriod}`);
    if (res.success) {
        const data = res.data || res;

        // Auto-fill logged in user Real Name from HRMS
        const realNameInput = document.getElementById('drpVerifierRealName');
        const userFullName = data.user_full_name || res.user_full_name || '';
        if (realNameInput && userFullName) {
            realNameInput.value = userFullName;
        }

        // Restore Agent Memory (Pseudo & Team)
        drpRestoreAgentMemory();

        // Biometric ID
        const didEl = document.getElementById('drpBiometricId');
        if (didEl) didEl.textContent = data.biometric_id || res.biometric_id || 'Not Assigned';

        drpCurrentData = data.transfers || res.transfers || [];
        drpRenderTable(drpCurrentData);
        drpRenderMiniStats(data.mini_stats || res.mini_stats, data.streak || res.streak || 0);
        drpRenderLeaderboard(data.leaderboard || res.leaderboard || []);
        drpCalculateSLA(drpCurrentData);

        drpStartLiveClock();
        drpStartAutoRefresh();

        // Load dialer Top 5 Agents (separate from HRMS leaderboard)
        drpLoadTopAgents();
        drpStartTop5AutoRefresh();
    } else {
        window.showToast?.(res.message || 'Failed to load performance data', 'error');
    }
};


// ─── Auto-refresh (60s) ───────────────────────────────────────────────────────
function drpStartAutoRefresh() {
    if (drpAutoRefreshTimer) clearInterval(drpAutoRefreshTimer);
    drcStartRing(); // start visual countdown ring
    drpAutoRefreshTimer = setInterval(() => {
        if (drpCurrentPeriod === 'today') loadPerformanceView();
        drcStartRing(); // reset ring on each refresh
    }, 60000);
}

// ─── CSV Export ───────────────────────────────────────────────────────────────
window.drpExportCSV = function() {
    if (!drpCurrentData || drpCurrentData.length === 0) {
        window.showToast?.('No data to export for this period.', 'error');
        return;
    }
    const headers = ['#', 'Date/Time', 'Customer Number', 'Customer Name', 'Zip', 'Age', 'Transfer Option', 'Duration (min)', 'Notes', 'Offline Sync'];
    let csv = headers.map(h => `"${h}"`).join(',') + '\n';
    drpCurrentData.forEach((r, i) => {
        const dt = new Date(r.created_at).toLocaleString();
        csv += [
            drpCurrentData.length - i,
            dt,
            r.customer_number || '',
            r.customer_name || '',
            r.customer_zip || '',
            r.customer_age || '',
            r.transfer_on,
            r.call_duration_mins || 0,
            (r.call_notes || '').replace(/"/g, '""'),
            r.is_offline_sync ? 'Yes' : 'No',
        ].map(v => `"${v}"`).join(',') + '\n';
    });

    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = `transfers_${drpCurrentPeriod}_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    window.showToast?.('✅ CSV exported!', 'success');
};

// ─── Form Submission Handler ──────────────────────────────────────────────────
async function submitDailyTransfer(e) {
    e.preventDefault();

    const numEl          = document.getElementById('drpCustomerNumber');
    const transferEl     = document.getElementById('drpTransferOn');
    const teamEl         = document.getElementById('drpTeamName');
    const verifierEl     = document.getElementById('drpVerifierRealName');
    const pseudoEl       = document.getElementById('drpAgentPseudo');
    const firstNameEl    = document.getElementById('drpCustomerFirstName');
    const lastNameEl     = document.getElementById('drpCustomerLastName');
    const stateEl        = document.getElementById('drpCustomerState');
    const zipEl          = document.getElementById('drpCustomerZip');
    const ageEl          = document.getElementById('drpCustomerAge');
    const durEl          = document.getElementById('drpCallDuration');
    const notesEl        = document.getElementById('drpCallNotes');
    const outcomeEl      = document.getElementById('drpCallOutcome');
    const btn            = e.target.querySelector('button[type="submit"]');

    if (!numEl || !transferEl) return;

    const payload = {
        customer_number:     numEl.value.trim(),
        transfer_on:         transferEl.value                     || 'D1',
        team_name:           teamEl?.value.trim()                 || '',
        verifier_real_name:  verifierEl?.value.trim()             || '',
        agent_pseudo:        pseudoEl?.value.trim()               || '',
        customer_first_name: firstNameEl?.value.trim()            || '',
        customer_last_name:  lastNameEl?.value.trim()             || '',
        customer_name:       `${firstNameEl?.value.trim() || ''} ${lastNameEl?.value.trim() || ''}`.trim(),
        customer_state:      stateEl?.value.trim()                || '',
        customer_zip:        zipEl?.value.trim()                  || '',
        customer_age:        ageEl?.value.trim()                  || '',
        call_duration_mins:  parseInt(durEl?.value)               || 0,
        call_notes:          ((outcomeEl?.value ? outcomeEl.value + ': ' : '') + (notesEl?.value.trim() || '')).trim(),
    };

    if (!payload.customer_number || !payload.transfer_on) {
        window.showToast?.('Phone Number and Line Option are required fields', 'error');
        return;
    }

    // Save Agent Memory in localStorage
    if (payload.agent_pseudo) localStorage.setItem('drp_agent_pseudo', payload.agent_pseudo);
    if (payload.team_name)    localStorage.setItem('drp_team_name', payload.team_name);

    const origHtml = btn ? btn.innerHTML : '';
    if (btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging Medicare Transfer…'; btn.disabled = true; }

    try {
        const response = await fetch('api/submit_daily_report.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body:    JSON.stringify(payload),
        });
        const res = await response.json();

        if (res.success) {
            window.showToast?.('✅ Medicare transfer logged successfully!', 'success');
            
            // Clear call fields, keeping pseudo & team for agent convenience
            if (numEl) numEl.value = '';
            if (firstNameEl) firstNameEl.value = '';
            if (lastNameEl) lastNameEl.value = '';
            if (stateEl) stateEl.value = '';
            if (zipEl) zipEl.value = '';
            if (ageEl) ageEl.value = '';
            if (durEl) durEl.value = '';
            if (notesEl) notesEl.value = '';
            if (outcomeEl) outcomeEl.value = '';

            drpCurrentPeriod = 'today';
            drpSwitchPeriod('today');
            loadPerformanceView();
        } else {
            window.showToast?.(res.message || 'Error submitting transfer', 'error');
        }
    } catch (err) {
        window.showToast?.('Network error. Check connection.', 'error');
    } finally {
        if (btn) { btn.innerHTML = origHtml; btn.disabled = false; }
    }
}

// ─── Analytics View ───────────────────────────────────────────────────────────
window.loadPerformanceAnalyticsView = async function() {
    const [analyticsRes, chartsRes] = await Promise.all([
        drpApi('load_analytics'),
        drpApi('load_charts'),
    ]);

    if (analyticsRes.success) {
        const data     = analyticsRes.data;
        const sales    = parseInt(data.qa_stats.sales)     || 0;
        const rejected = parseInt(data.qa_stats.rejected)  || 0;
        const transfers= parseInt(data.qa_stats.transfers) || 0;

        const setEl = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
        setEl('qaStatsSales',     sales);
        setEl('qaStatsRejected',  rejected);
        setEl('qaStatsTransfers', transfers);

        const convEl = document.getElementById('qaStatsConversion');
        if (convEl) convEl.textContent = transfers > 0 ? ((sales / transfers) * 100).toFixed(1) + '%' : '0%';

        // Legacy QA charts
        renderPerformanceCharts(data.history || []);
    }

    if (chartsRes.success) {
        renderAdvancedCharts(chartsRes.data);
    }
};

// ─── QA Legacy Charts ─────────────────────────────────────────────────────────
function renderPerformanceCharts(history) {
    if (typeof Chart === 'undefined') return;

    const trendCtx = document.getElementById('perfTrendChart');
    const ratioCtx = document.getElementById('perfRatioChart');
    if (!trendCtx || !ratioCtx) return;

    if (perfTrendChartInstance) perfTrendChartInstance.destroy();
    if (perfRatioChartInstance) perfRatioChartInstance.destroy();

    const labels         = history.map(h => new Date(h.date).toLocaleDateString([], { month: 'short', day: 'numeric' }));
    const transfersData  = history.map(h => parseInt(h.transfers) || 0);
    const salesData      = history.map(h => parseInt(h.sales)     || 0);
    const convRateData   = history.map(h => {
        const t = parseInt(h.transfers) || 0;
        const s = parseInt(h.sales)     || 0;
        return t > 0 ? ((s / t) * 100).toFixed(1) : 0;
    });

    const gridColor = 'rgba(255,255,255,0.05)';
    const tickColor = '#94a3b8';

    perfTrendChartInstance = new Chart(trendCtx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { type: 'bar',  label: 'Transfers', data: transfersData, backgroundColor: 'rgba(99,102,241,0.7)', borderColor: '#6366f1', borderWidth: 1, borderRadius: 4, yAxisID: 'y' },
                { type: 'bar',  label: 'QA Sales',  data: salesData,     backgroundColor: 'rgba(16,185,129,0.7)', borderColor: '#10b981', borderWidth: 1, borderRadius: 4, yAxisID: 'y' },
                { type: 'line', label: 'Conv. Rate (%)', data: convRateData, borderColor: '#f59e0b', backgroundColor: 'transparent', borderWidth: 3, pointBackgroundColor: '#1e1e2d', pointBorderColor: '#f59e0b', tension: 0.4, yAxisID: 'y1' },
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y:  { type: 'linear', position: 'left',  beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor } },
                y1: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { color: '#f59e0b', callback: v => v + '%' } },
                x:  { grid: { display: false }, ticks: { color: tickColor } },
            },
            plugins: { legend: { labels: { color: '#f8fafc', usePointStyle: true } } }
        }
    });

    const totalSales    = salesData.reduce((a, b) => a + b, 0);
    const totalRejected = history.map(h => parseInt(h.rejected) || 0).reduce((a, b) => a + b, 0);

    perfRatioChartInstance = new Chart(ratioCtx, {
        type: 'doughnut',
        data: {
            labels: ['Approved Sales', 'Rejected'],
            datasets: [{ data: [totalSales, totalRejected], backgroundColor: ['#10b981', '#ef4444'], borderColor: '#0f111a', borderWidth: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#f8fafc', usePointStyle: true } } }, cutout: '70%' }
    });
}

// ─── Advanced Transfer Charts ─────────────────────────────────────────────────
function renderAdvancedCharts(data) {
    if (typeof Chart === 'undefined' || !data) return;

    const gridColor = 'rgba(255,255,255,0.05)';
    const tickColor = '#94a3b8';

    // 1. Daily Trend (30 days)
    const trendCtx = document.getElementById('drpDailyTrendChart');
    if (trendCtx) {
        if (drpTrendChartInstance) drpTrendChartInstance.destroy();
        const trend = data.daily_trend || [];
        drpTrendChartInstance = new Chart(trendCtx, {
            type: 'bar',
            data: {
                labels: trend.map(d => new Date(d.day).toLocaleDateString([], { month: 'short', day: 'numeric' })),
                datasets: [
                    { label: 'D1', data: trend.map(d => parseInt(d.d1) || 0), backgroundColor: 'rgba(99,102,241,0.7)', borderRadius: 4 },
                    { label: 'D2', data: trend.map(d => parseInt(d.d2) || 0), backgroundColor: 'rgba(245,158,11,0.7)',  borderRadius: 4 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index' },
                scales: {
                    x: { stacked: false, grid: { display: false }, ticks: { color: tickColor, maxRotation: 45 } },
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor } }
                },
                plugins: { legend: { labels: { color: '#f8fafc', usePointStyle: true } } }
            }
        });
    }

    // 2. Hour Distribution
    const hourCtx = document.getElementById('drpHourDistChart');
    if (hourCtx) {
        if (drpHourChartInstance) drpHourChartInstance.destroy();
        const hours = data.hour_distribution || Array(24).fill(0);
        const hourLabels = hours.map((_, i) => {
            const h = i % 12 || 12; return `${h}${i < 12 ? 'am' : 'pm'}`;
        });
        drpHourChartInstance = new Chart(hourCtx, {
            type: 'bar',
            data: {
                labels: hourLabels,
                datasets: [{ label: 'Transfers', data: hours, backgroundColor: hours.map(v => v > 0 ? 'rgba(99,102,241,0.7)' : 'rgba(255,255,255,0.05)'), borderRadius: 4 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { color: tickColor, maxRotation: 45 } },
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor } }
                },
                plugins: { legend: { display: false } }
            }
        });
    }

    // 3. Weekly Comparison
    const weeklyCtx = document.getElementById('drpWeeklyChart');
    if (weeklyCtx) {
        if (drpWeeklyChartInstance) drpWeeklyChartInstance.destroy();
        const weekly = data.weekly_comparison || [];
        drpWeeklyChartInstance = new Chart(weeklyCtx, {
            type: 'bar',
            data: {
                labels: weekly.map(w => w.label),
                datasets: [{ label: 'Total Transfers', data: weekly.map(w => w.total), backgroundColor: ['rgba(99,102,241,0.4)', 'rgba(99,102,241,0.6)', 'rgba(99,102,241,0.8)', 'rgba(99,102,241,1)'], borderRadius: 8 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { color: tickColor } },
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor } }
                },
                plugins: { legend: { display: false } }
            }
        });
    }

    // 4. Best Day of Week (Radar)
    const dowCtx = document.getElementById('drpDowChart');
    if (dowCtx) {
        if (drpDowChartInstance) drpDowChartInstance.destroy();
        const dow = (data.best_day_of_week || []).filter(d => d.label);
        drpDowChartInstance = new Chart(dowCtx, {
            type: 'radar',
            data: {
                labels: dow.map(d => d.label),
                datasets: [{
                    label: 'Avg Transfers/Day',
                    data: dow.map(d => d.avg || 0),
                    backgroundColor: 'rgba(99,102,241,0.15)',
                    borderColor: '#6366f1',
                    borderWidth: 2,
                    pointBackgroundColor: '#6366f1',
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { r: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, backdropColor: 'transparent' }, pointLabels: { color: '#f8fafc' } } },
                plugins: { legend: { labels: { color: '#f8fafc', usePointStyle: true } } }
            }
        });
    }

    // 5. D1 vs D2 (Doughnut - all time)
    const d1d2Ctx = document.getElementById('drpD1D2Chart');
    if (d1d2Ctx) {
        if (drpD1D2ChartInstance) drpD1D2ChartInstance.destroy();
        const d1 = data.d1_vs_d2?.d1 || 0;
        const d2 = data.d1_vs_d2?.d2 || 0;
        drpD1D2ChartInstance = new Chart(d1d2Ctx, {
            type: 'doughnut',
            data: {
                labels: ['D1 Transfers', 'D2 Transfers'],
                datasets: [{ data: [d1, d2], backgroundColor: ['#6366f1', '#f59e0b'], borderColor: '#0f111a', borderWidth: 4 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '65%',
                plugins: { legend: { position: 'bottom', labels: { color: '#f8fafc', usePointStyle: true } } }
            }
        });

        // Update D1/D2 labels
        ['drpAdvD1Total','drpAdvD2Total'].forEach((id, i) => {
            const el = document.getElementById(id);
            if (el) el.textContent = i === 0 ? d1 : d2;
        });
    }

    // 6. QA Verification Status
    const statusCtx = document.getElementById('drpQaStatusChart');
    if (statusCtx) {
        if (drpQaStatusChartInstance) drpQaStatusChartInstance.destroy();
        const pending  = data.qa_status_dist?.pending || 0;
        const approved = data.qa_status_dist?.approved || 0;
        const rejected = data.qa_status_dist?.rejected || 0;
        drpQaStatusChartInstance = new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: ['Approved Sales', 'Rejected Calls', 'Pending Verification'],
                datasets: [{
                    data: [approved, rejected, pending],
                    backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                    borderColor: '#0f111a',
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#f8fafc', usePointStyle: true, font: { size: 11 } }
                    }
                }
            }
        });
    }

    // 7. Logged vs Approved Daily Comparison Trend
    const qaTrendCtx = document.getElementById('drpQaTrendChart');
    if (qaTrendCtx) {
        if (drpQaTrendChartInstance) drpQaTrendChartInstance.destroy();
        const trend = data.qa_trend || [];
        drpQaTrendChartInstance = new Chart(qaTrendCtx, {
            type: 'line',
            data: {
                labels: trend.map(d => new Date(d.day).toLocaleDateString([], { month: 'short', day: 'numeric' })),
                datasets: [
                    {
                        label: 'Logged Transfers',
                        data: trend.map(d => parseInt(d.logged) || 0),
                        borderColor: '#818cf8',
                        backgroundColor: 'rgba(129, 140, 248, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Approved Sales',
                        data: trend.map(d => parseInt(d.approved) || 0),
                        borderColor: '#10b981',
                        backgroundColor: 'transparent',
                        borderWidth: 3,
                        pointBackgroundColor: '#10b981',
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { color: tickColor } },
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor } }
                },
                plugins: {
                    legend: {
                        labels: { color: '#f8fafc', usePointStyle: true }
                    }
                }
            }
        });
    }
}

// ─── Inject Advanced Charts Section into Analytics View ───────────────────────
function drpInjectAdvancedChartSection() {
    const section = document.getElementById('view-performance-analytics');
    if (!section || document.getElementById('drpAdvChartsGrid')) return;

    const html = `
    <div id="drpAdvChartsGrid" style="margin-top:28px;">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--border-color);">
            <h3 style="font-size:16px; font-weight:800; color:var(--text-color);">
                <i class="fas fa-chart-bar" style="color:var(--primary); margin-right:8px;"></i>
                Transfer Analytics (My Data)
            </h3>
        </div>

        <!-- Row 1: Daily Trend + D1 vs D2 -->
        <div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; margin-bottom:20px;">
            <div class="ess-card">
                <div class="ess-card-head" style="margin-bottom:16px;"><h3><i class="fas fa-chart-column" style="color:var(--primary);"></i> 30-Day Transfer Trend (D1 vs D2)</h3></div>
                <div style="height:260px; position:relative;"><canvas id="drpDailyTrendChart"></canvas></div>
            </div>
            <div class="ess-card">
                <div class="ess-card-head" style="margin-bottom:16px;"><h3><i class="fas fa-chart-pie" style="color:var(--primary);"></i> All-Time D1 vs D2</h3></div>
                <div style="height:200px; position:relative;"><canvas id="drpD1D2Chart"></canvas></div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:12px;">
                    <div style="background:rgba(99,102,241,0.1); border:1px solid rgba(99,102,241,0.2); border-radius:10px; padding:10px; text-align:center;">
                        <div style="font-size:10px; color:var(--text-muted); font-weight:700; text-transform:uppercase;">D1 Total</div>
                        <div style="font-size:22px; font-weight:900; color:#818cf8;" id="drpAdvD1Total">0</div>
                    </div>
                    <div style="background:rgba(245,158,11,0.1); border:1px solid rgba(245,158,11,0.2); border-radius:10px; padding:10px; text-align:center;">
                        <div style="font-size:10px; color:var(--text-muted); font-weight:700; text-transform:uppercase;">D2 Total</div>
                        <div style="font-size:22px; font-weight:900; color:#f59e0b;" id="drpAdvD2Total">0</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2: Hour Heatmap + Weekly + Radar -->
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-bottom:20px;">
            <div class="ess-card">
                <div class="ess-card-head" style="margin-bottom:16px;"><h3><i class="fas fa-clock" style="color:var(--primary);"></i> Peak Transfer Hours</h3></div>
                <div style="height:220px; position:relative;"><canvas id="drpHourDistChart"></canvas></div>
            </div>
            <div class="ess-card">
                <div class="ess-card-head" style="margin-bottom:16px;"><h3><i class="fas fa-calendar-week" style="color:var(--primary);"></i> Week-over-Week</h3></div>
                <div style="height:220px; position:relative;"><canvas id="drpWeeklyChart"></canvas></div>
            </div>
            <div class="ess-card">
                <div class="ess-card-head" style="margin-bottom:16px;"><h3><i class="fas fa-star" style="color:var(--primary);"></i> Best Days (Avg)</h3></div>
                <div style="height:220px; position:relative;"><canvas id="drpDowChart"></canvas></div>
            </div>
        </div>

        <!-- Row 3: QA Verification Status + Logged vs Approved Comparative Line Chart -->
        <div style="display:grid; grid-template-columns:1fr 2fr; gap:20px;">
            <div class="ess-card">
                <div class="ess-card-head" style="margin-bottom:16px;"><h3><i class="fas fa-check-double" style="color:var(--primary);"></i> QA Status Breakdown</h3></div>
                <div style="height:240px; position:relative;"><canvas id="drpQaStatusChart"></canvas></div>
            </div>
            <div class="ess-card">
                <div class="ess-card-head" style="margin-bottom:16px;"><h3><i class="fas fa-chart-line" style="color:var(--primary);"></i> Transfers Logged vs QA Approved Trend</h3></div>
                <div style="height:240px; position:relative;"><canvas id="drpQaTrendChart"></canvas></div>
            </div>
        </div>
    </div>`;

    section.insertAdjacentHTML('beforeend', html);
}

// ─── Zip Code Auto-State Lookup ──────────────────────────────────────────────
function drpInitZipLookup() {
    const zipEl  = document.getElementById('drpCustomerZip');
    const nameEl = document.getElementById('drpCustomerName');
    const noteEl = document.getElementById('drpCallNotes');
    if (!zipEl) return;

    zipEl.addEventListener('input', async (e) => {
        const val = e.target.value.trim();
        // US Zip codes are 5 digits
        if (/^\d{5}$/.test(val)) {
            const originalBorder = zipEl.style.borderColor;
            zipEl.style.borderColor = '#10b981'; // highlight green while fetching

            try {
                const res = await fetch(`https://api.zippopotam.us/us/${val}`);
                if (res.ok) {
                    const data = await res.json();
                    const place = data.places?.[0];
                    if (place) {
                        const city  = place['place name'];
                        const state = place['state abbreviation'];
                        const locText = `${city}, ${state}`;

                        // Prefill Name if empty
                        if (nameEl && !nameEl.value.trim()) {
                            nameEl.value = `Client (${locText})`;
                            nameEl.dispatchEvent(new Event('input'));
                        }

                        // Prefill Notes if empty or prepend
                        if (noteEl) {
                            const curNotes = noteEl.value.trim();
                            if (!curNotes) {
                                noteEl.value = `Location: ${locText}`;
                            } else if (!curNotes.includes(locText)) {
                                noteEl.value = `Location: ${locText}\n${curNotes}`;
                            }
                            noteEl.dispatchEvent(new Event('input'));
                        }

                        window.showToast?.(`📍 Location detected: ${locText}`, 'success');
                    }
                }
            } catch (err) {
                console.warn('Zip lookup failed:', err);
            } finally {
                zipEl.style.borderColor = originalBorder;
            }
        }
    });
}

// ─── Bootstrap ────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // Form submit
    const form = document.getElementById('dailyReportingForm');
    if (form) form.addEventListener('submit', submitDailyTransfer);

    // Inject advanced chart section lazily
    drpInjectAdvancedChartSection();

    // Initialize Zip lookup helper
    drpInitZipLookup();
});


// ─── My Performance QA Cards Updater ──────────────────────────────────────────
// Updates existing cards only. No extra UI, no extra CSS, no buttons.
(function () {
    async function updateMyPerformanceQaCards() {
        const salesEl = document.getElementById('qaStatsSales');
        const rejectedEl = document.getElementById('qaStatsRejected');
        const transfersEl = document.getElementById('qaStatsTransfers');
        const conversionEl = document.getElementById('qaStatsConversion');

        if (!salesEl || !rejectedEl || !transfersEl || !conversionEl) {
            return;
        }

        try {
            const response = await fetch('/api/get_agent_performance.php?action=load_analytics&_=' + Date.now(), {
                credentials: 'include',
                cache: 'no-store'
            });

            const result = await response.json();

            if (!result.success || !result.data || !result.data.qa_stats) {
                return;
            }

            const stats = result.data.qa_stats;

            const sales = parseInt(stats.sales || stats.approved || 0, 10) || 0;
            const rejected = parseInt(stats.rejected || 0, 10) || 0;
            const transfers = parseInt(stats.transfers || 0, 10) || 0;
            const conversion = transfers > 0 ? ((sales / transfers) * 100) : 0;

            salesEl.textContent = sales;
            rejectedEl.textContent = rejected;
            transfersEl.textContent = transfers;
            conversionEl.textContent = conversion.toFixed(1).replace('.0', '') + '%';

            if (typeof renderPerformanceCharts === 'function') {
                renderPerformanceCharts(result.data.history || []);
            }
        } catch (error) {
            console.warn('My Performance QA cards update failed:', error);
        }
    }

    window.updateMyPerformanceQaCards = updateMyPerformanceQaCards;

    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(updateMyPerformanceQaCards, 500);
        setTimeout(updateMyPerformanceQaCards, 1500);
        setTimeout(updateMyPerformanceQaCards, 3000);
    });

    document.addEventListener('click', function () {
        setTimeout(updateMyPerformanceQaCards, 500);
    });

    setInterval(updateMyPerformanceQaCards, 30000);
})();
