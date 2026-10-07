// ===== RECRUITER PORTAL ACTIONS & MODALS =====

// // --- LEAD EDIT & WORKFLOW MODAL ---
let activeLeadId = null, recruitersListCache = [];

window.selectModalStatus = function(stage) {
  const sel = document.getElementById('l_status');
  if (sel) {
    sel.value = stage;
  }
  syncActiveStatusButton(stage);
  toggleDynamicFields(stage);
  setSmartDefaultRemark(stage);
};

window.syncModalDropdown = function(stage) {
  syncActiveStatusButton(stage);
  toggleDynamicFields(stage);
  setSmartDefaultRemark(stage);
};

function syncActiveStatusButton(stage) {
  document.querySelectorAll('.status-pill-btn').forEach(btn => {
    if (btn.getAttribute('data-status') === stage) {
      btn.classList.add('active-status-btn');
    } else {
      btn.classList.remove('active-status-btn');
    }
  });
}

function toggleDynamicFields(stage) {
  const refBox = document.getElementById('branchReferralBox');
  const intBox = document.getElementById('interviewScheduleBox');
  const cbBox  = document.getElementById('callbackBox');
  const rejBox = document.getElementById('rejectBox');

  if (refBox) {
    refBox.style.display = (stage === 'referred_branch') ? 'block' : 'none';
    if (stage === 'referred_branch') {
      const targetSel = document.getElementById('l_target_branch');
      if (targetSel) targetSel.focus();
    }
  }
  if (intBox) {
    intBox.style.display = (stage === 'interview_scheduled') ? 'block' : 'none';
    if (stage === 'interview_scheduled') {
      const intDateInput = document.getElementById('l_int_date');
      if (intDateInput && !intDateInput.value) {
        intDateInput.value = new Date().toISOString().split('T')[0];
      }
    }
  }
  if (cbBox) {
    cbBox.style.display = (stage === 'callback') ? 'block' : 'none';
  }
  if (rejBox) {
    rejBox.style.display = (stage === 'rejected') ? 'block' : 'none';
  }
}

function setSmartDefaultRemark(stage) {
  const noteEl = document.getElementById('l_note');
  if (noteEl && !noteEl.value.trim()) {
    const defaultNotes = {
      'outreach_phone': 'Phone call made to candidate.',
      'outreach_whatsapp_call': 'WhatsApp call placed to candidate.',
      'outreach_whatsapp_msg': 'Job details & requirements message dropped on WhatsApp.',
      'not_answered': 'Candidate did not answer phone call.',
      'callback': 'Follow-up / call back requested by candidate.',
      'interview_scheduled': 'Interview scheduled at office reception.',
      'referred_branch': 'Candidate referred to another company branch.',
      'pending': 'Candidate placed on hold / pending decision.',
      'selected': 'Candidate qualified and selected.',
      'rejected': 'Candidate does not meet criteria.'
    };
    if (defaultNotes[stage]) {
      noteEl.value = defaultNotes[stage];
    }
  }
}

async function editLead(id){
  activeLeadId = id;
  const res = await apiFetch(`${API.leadDetail}?lead_id=${id}`);
  if(!res.success) return toast(res.error || 'Lead not found', 'error');

  const l = res.data;
  const timelineRes = await apiFetch(`${API.leadTimeline}?lead_id=${id}`);
  const timeline = (timelineRes.success && timelineRes.data) ? timelineRes.data : null;

  if (isSuperAdmin && !recruitersListCache.length) {
    const rRes = await apiFetch(API.recruiters);
    if(rRes.success) recruitersListCache = rRes.data.filter(x => x.status === 'active');
  }

  const currentStage = l.current_stage || 'new';
  const currentBranch = l.company_branch || 'main';
  const branchObj = COMPANY_BRANCH_OPTIONS.find(x => x.key.toLowerCase() === currentBranch.toLowerCase()) || { label: currentBranch };
  const currentBranchLabel = branchObj.label;
  const todayDate = new Date().toISOString().split('T')[0];
  const cleanPhone = (l.phone || '').replace(/[^0-9]/g, '');
  const waPhone = cleanPhone.startsWith('92') ? cleanPhone : (cleanPhone.startsWith('0') ? '92' + cleanPhone.slice(1) : '92' + cleanPhone);

  let html = `
  <div class="modal-overlay" id="leadModal"><div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <h3 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <span>Edit Lead - ${esc(l.full_name)}</span>
          <span class="badge" style="background:rgba(249,115,22,0.15);color:#fb923c;border:1px solid rgba(249,115,22,0.3);font-size:11px;">
            <i class="fas fa-building"></i> ${esc(currentBranchLabel)}
          </span>
          ${l.source === 'website' ? `<span class="badge" style="background:rgba(59,130,246,0.15);color:#60a5fa;border:1px solid rgba(59,130,246,0.3);font-size:11px;"><i class="fas fa-globe"></i> Website Lead</span>` : ''}
        </h3>
      </div>
      <div style="display:flex;align-items:center;gap:10px;">
        ${(l.cv_file_url || l.external_lead_id) ? `
          <a href="api/fetch_lead_cv.php?external_id=${encodeURIComponent(l.external_lead_id || l.id)}" target="_blank" class="btn btn-sm btn-info" style="text-decoration:none;">
            <i class="fas fa-file-pdf"></i> View / Download CV
          </a>
        ` : ''}
        <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <div class="modal-body" style="display:flex;gap:20px;flex-wrap:wrap;">
      <div style="flex:1;min-width:320px;">
        <form id="editLeadForm" onsubmit="saveLead(event)">
          <div class="form-grid">
            <div class="form-group">
              <label>Full Name <span style="font-size:10px;color:var(--text-muted);">(Locked)</span></label>
              <input type="text" id="l_name" class="form-control" value="${esc(l.full_name)}" readonly style="opacity:0.85;background:rgba(255,255,255,0.03);cursor:not-allowed;">
            </div>
            <div class="form-group">
              <label>CNIC Number <span style="font-size:10px;color:var(--primary);font-weight:700;">(Official ID)</span></label>
              <input type="text" id="l_cnic" class="form-control" value="${esc(l.cnic || 'Not Provided')}" readonly style="opacity:0.95;background:rgba(249,115,22,0.08);border-color:rgba(249,115,22,0.3);color:#fb923c;font-weight:700;cursor:not-allowed;">
            </div>
            <div class="form-group">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <label style="margin:0;">Phone <span style="font-size:10px;color:var(--text-muted);">(Locked)</span></label>
                ${cleanPhone ? `
                  <a href="https://wa.me/${waPhone}" target="_blank" class="btn btn-xs" style="background:rgba(16,185,129,0.15);color:#34d399;border:1px solid rgba(16,185,129,0.3);text-decoration:none;padding:2px 8px;font-size:11px;" title="Open WhatsApp Chat">
                    <i class="fab fa-whatsapp"></i> Chat
                  </a>
                ` : ''}
              </div>
              <input type="text" id="l_phone" class="form-control" value="${esc(l.phone)}" readonly style="opacity:0.85;background:rgba(255,255,255,0.03);cursor:not-allowed;">
            </div>
            <div class="form-group">
              <label>Position <span style="font-size:10px;color:var(--text-muted);">(Locked)</span></label>
              <input type="text" id="l_pos" class="form-control" value="${esc(l.position_applied || 'Dialer')}" readonly style="opacity:0.85;background:rgba(255,255,255,0.03);cursor:not-allowed;">
            </div>
            <div class="form-group">
              <label>City <span style="font-size:10px;color:var(--text-muted);">(Locked)</span></label>
              <input type="text" id="l_city" class="form-control" value="${esc(l.city || 'Islamabad')}" readonly style="opacity:0.85;background:rgba(255,255,255,0.03);cursor:not-allowed;">
            </div>
            ${isSuperAdmin ? `
              <div class="form-group">
                <label>Assign To Recruiter</label>
                <select id="l_rec" class="form-control">
                  <option value="">-- Unassigned --</option>
                  ${recruitersListCache.map(r => `<option value="${r.id}"${l.assigned_recruiter_id == r.id ? ' selected' : ''}>${esc(r.full_name)}</option>`).join('')}
                </select>
              </div>
            ` : `
              <div class="form-group">
                <label>Recruiter</label>
                <input type="text" class="form-control" value="${esc(l.recruiter_name || 'Assigned to You')}" readonly style="opacity:0.85;background:rgba(255,255,255,0.03);cursor:not-allowed;">
              </div>
            `}
          </div>

          <!-- PIPELINE STATUS & QUICK ACTION BUTTONS (SYNCED) -->
          <div style="margin-top:16px;padding:14px;background:rgba(255,255,255,0.02);border:1px solid var(--border-light);border-radius:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
              <label style="font-size:12px;font-weight:700;color:var(--text-primary);margin:0;display:flex;align-items:center;gap:6px;">
                <i class="fas fa-sliders-h" style="color:var(--primary);"></i> Pipeline Status
              </label>
              <span style="font-size:10px;color:var(--text-muted);"><i class="fas fa-sync-alt"></i> Dropdown & Buttons Auto-Synced</span>
            </div>
            
            <select id="l_status" class="form-control" style="font-size:13px;font-weight:600;margin-bottom:12px;" onchange="syncModalDropdown(this.value)">
              ${statusSelectHtml(currentStage)}
            </select>

            <!-- CALLING & OUTREACH BUTTONS -->
            <div class="status-action-group-label"><i class="fas fa-phone-alt"></i> Calling & Outreach:</div>
            <div class="status-pills-row">
              <button type="button" class="status-pill-btn" data-status="outreach_phone" onclick="selectModalStatus('outreach_phone')">
                <i class="fas fa-phone"></i> Phone Call
              </button>
              <button type="button" class="status-pill-btn" data-status="outreach_whatsapp_call" onclick="selectModalStatus('outreach_whatsapp_call')">
                <i class="fab fa-whatsapp"></i> WA Call
              </button>
              <button type="button" class="status-pill-btn" data-status="outreach_whatsapp_msg" onclick="selectModalStatus('outreach_whatsapp_msg')">
                <i class="fas fa-comment-dots"></i> Message Dropped
              </button>
              <button type="button" class="status-pill-btn" data-status="not_answered" onclick="selectModalStatus('not_answered')">
                <i class="fas fa-phone-slash"></i> Not Answered
              </button>
              <button type="button" class="status-pill-btn" data-status="callback" onclick="selectModalStatus('callback')">
                <i class="fas fa-clock-rotate-left"></i> Call Back
              </button>
            </div>

            <!-- PIPELINE, DECISION & BRANCH TRANSFER BUTTONS -->
            <div class="status-action-group-label" style="margin-top:6px;"><i class="fas fa-route"></i> Decision & Transfer:</div>
            <div class="status-pills-row" style="margin-bottom:0;">
              <button type="button" class="status-pill-btn" data-status="interview_scheduled" onclick="selectModalStatus('interview_scheduled')">
                <i class="fas fa-calendar-check"></i> Schedule Interview
              </button>
              <button type="button" class="status-pill-btn" data-status="referred_branch" onclick="selectModalStatus('referred_branch')">
                <i class="fas fa-building-circle-arrow-right"></i> Refer to Branch
              </button>
              <button type="button" class="status-pill-btn" data-status="pending" onclick="selectModalStatus('pending')">
                <i class="fas fa-hourglass-half"></i> Pending
              </button>
              <button type="button" class="status-pill-btn" data-status="selected" onclick="selectModalStatus('selected')">
                <i class="fas fa-star"></i> Selected
              </button>
              <button type="button" class="status-pill-btn" data-status="rejected" onclick="selectModalStatus('rejected')">
                <i class="fas fa-times-circle"></i> Reject
              </button>
            </div>
          </div>

          <!-- DYNAMIC PANEL 1: BRANCH REFERRAL -->
          <div id="branchReferralBox" class="dynamic-context-card" style="display:none;margin-top:14px;background:linear-gradient(135deg,rgba(236,72,153,0.08),rgba(147,51,234,0.05));border:1px solid rgba(236,72,153,0.35);border-radius:12px;padding:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
              <label style="font-size:12px;font-weight:700;color:#f472b6;display:flex;align-items:center;gap:6px;margin:0;">
                <i class="fas fa-building-circle-arrow-right"></i> Refer Candidate to Another Branch <span style="color:#ef4444;">*</span>
              </label>
              <span class="badge" style="background:rgba(236,72,153,0.18);color:#f472b6;font-size:10px;">Branch Transfer</span>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">
              Current Branch: <strong style="color:var(--text-primary);">${esc(currentBranchLabel)}</strong>. Agar candidate is branch ke liye suit nahi karta to doosri branch muntakhab karein. Lead doosri branch ke pool me transfer ho jayegi:
            </div>
            <select id="l_target_branch" class="form-control" style="background:#131127;border-color:rgba(236,72,153,0.5);color:#fbcfe8;font-weight:600;">
              <option value="">-- Choose Target Branch to Transfer Lead --</option>
              ${COMPANY_BRANCH_OPTIONS.map(b => `<option value="${b.key}" ${b.key.toLowerCase() === currentBranch.toLowerCase() ? 'disabled' : ''}>${esc(b.label)} ${b.key.toLowerCase() === currentBranch.toLowerCase() ? '(Current Branch)' : ''}</option>`).join('')}
            </select>
          </div>

          <!-- DYNAMIC PANEL 2: INTERVIEW SCHEDULE -->
          <div id="interviewScheduleBox" class="dynamic-context-card" style="display:none;margin-top:14px;background:linear-gradient(135deg,rgba(168,85,247,0.08),rgba(59,130,246,0.05));border:1px solid rgba(168,85,247,0.35);border-radius:12px;padding:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
              <label style="font-size:12px;font-weight:700;color:#d8b4fe;display:flex;align-items:center;gap:6px;margin:0;">
                <i class="fas fa-calendar-alt"></i> Interview Date & Time <span style="color:#ef4444;">*</span>
              </label>
              <span class="badge" style="background:rgba(168,85,247,0.18);color:#d8b4fe;font-size:10px;">Reception Sync</span>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">
              Interview schedule karne se candidate Reception Portal ke Scheduled Queue me automatically add ho jayega.
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div>
                <label style="font-size:11px;color:var(--text-muted);margin-bottom:4px;display:block;">Interview Date</label>
                <input type="date" id="l_int_date" class="form-control" value="${esc(l.interview_date || todayDate)}">
              </div>
              <div>
                <label style="font-size:11px;color:var(--text-muted);margin-bottom:4px;display:block;">Interview Time</label>
                <input type="time" id="l_int_time" class="form-control" value="10:00">
              </div>
            </div>
          </div>

          <!-- DYNAMIC PANEL 3: CALL BACK REMINDER -->
          <div id="callbackBox" class="dynamic-context-card" style="display:none;margin-top:14px;background:linear-gradient(135deg,rgba(139,92,246,0.08),rgba(245,158,11,0.05));border:1px solid rgba(139,92,246,0.35);border-radius:12px;padding:14px;">
            <label style="font-size:12px;font-weight:700;color:#c084fc;display:flex;align-items:center;gap:6px;margin-bottom:6px;">
              <i class="fas fa-clock-rotate-left"></i> Next Follow-up / Callback Date & Time
            </label>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">
              Candidate ko dobara kab call karni hai (Follow-up reminder):
            </div>
            <input type="datetime-local" id="l_next_callback" class="form-control" value="${esc(l.next_callback_date ? l.next_callback_date.replace(' ', 'T') : '')}">
          </div>

          <!-- DYNAMIC PANEL 4: REJECTION REASON -->
          <div id="rejectBox" class="dynamic-context-card" style="display:none;margin-top:14px;background:linear-gradient(135deg,rgba(239,68,68,0.08),rgba(185,28,28,0.05));border:1px solid rgba(239,68,68,0.35);border-radius:12px;padding:14px;">
            <label style="font-size:12px;font-weight:700;color:#fca5a5;display:flex;align-items:center;gap:6px;margin-bottom:6px;">
              <i class="fas fa-times-circle"></i> Rejection Reason
            </label>
            <select id="l_reject_reason" class="form-control" style="border-color:rgba(239,68,68,0.4);">
              <option value="">-- Select Rejection Reason --</option>
              <option value="Not Interested / Declined" ${l.rejection_reason === 'Not Interested / Declined' ? 'selected' : ''}>Not Interested / Declined</option>
              <option value="Salary Expectation Mismatch" ${l.rejection_reason === 'Salary Expectation Mismatch' ? 'selected' : ''}>Salary Expectation Mismatch</option>
              <option value="Underqualified / Skill Mismatch" ${l.rejection_reason === 'Underqualified / Skill Mismatch' ? 'selected' : ''}>Underqualified / Skill Mismatch</option>
              <option value="Overqualified" ${l.rejection_reason === 'Overqualified' ? 'selected' : ''}>Overqualified</option>
              <option value="Location / Transport Issue" ${l.rejection_reason === 'Location / Transport Issue' ? 'selected' : ''}>Location / Transport Issue</option>
              <option value="Language / Communication Barrier" ${l.rejection_reason === 'Language / Communication Barrier' ? 'selected' : ''}>Language / Communication Barrier</option>
              <option value="Unreachable / Invalid Number" ${l.rejection_reason === 'Unreachable / Invalid Number' ? 'selected' : ''}>Unreachable / Invalid Number</option>
              <option value="Other" ${l.rejection_reason === 'Other' ? 'selected' : ''}>Other</option>
            </select>
          </div>

          <!-- REMARK / NOTE -->
          <div class="form-group full" style="margin-top:14px;">
            <label style="font-size:12px;font-weight:700;">New Remark / Note</label>
            <textarea id="l_note" class="form-control" rows="3" placeholder="Add interviewer evaluation, notes, or remarks..."></textarea>
          </div>
        </form>
      </div>

      <!-- RIGHT COLUMN: STATUS & TIMELINE -->
      <div style="flex:0 0 320px;background:rgba(255,255,255,.02);border-radius:14px;padding:16px;border:1px solid var(--border-light)">
        ${timeline ? `
          <div style="margin-bottom:14px;padding:10px 12px;border-radius:10px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2)">
            <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;">Current Pipeline Status</div>
            <div style="font-size:14px;font-weight:700;color:var(--secondary);margin-top:2px;">${esc(timeline.stage_label || l.current_stage)}</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Calls Logged: <strong style="color:var(--text-primary);">${l.call_count || 0}</strong></div>
          </div>
        ` : ''}
        <h4 style="font-size:11px;font-weight:800;color:var(--text-muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:1px">Pipeline Timeline</h4>
        <div class="call-list" style="max-height:200px;overflow-y:auto;margin-bottom:14px">`;
        const events = (timeline && timeline.events) ? timeline.events : [];
        if (!events.length) {
          html += `<div class="empty-state" style="padding:12px 0"><p>No pipeline events yet</p></div>`;
        } else {
          events.slice().reverse().forEach(ev => {
            html += `<div class="call-item"><div class="call-dot"><i class="fas fa-route"></i></div><div>
              <div class="call-text">${esc(ev.title)}</div>
              <div class="call-meta">${esc(ev.by || 'System')} - ${fmtTime(ev.at)}</div>
              ${ev.detail ? `<div style="font-size:11px;color:var(--text-muted);margin-top:4px">${esc(ev.detail)}</div>` : ''}
            </div></div>`;
          });
        }
        html += `</div>
        <h4 style="font-size:11px;font-weight:800;color:var(--text-muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:1px">Remarks (${l.remarks_count || 0})</h4>
        <div class="call-list" style="max-height:160px;overflow-y:auto">`;
        if (!l.remarks || !l.remarks.length) {
          html += `<div class="empty-state" style="padding:12px 0"><p>No remarks</p></div>`;
        } else {
          l.remarks.forEach(r => {
            html += `<div class="call-item"><div class="call-dot"><i class="fas fa-phone-alt"></i></div><div>
              <div class="call-text">${esc(r.remark)}</div>
              <div class="call-meta">${esc(r.author_name || 'System')} - ${fmtTime(r.created_at)}</div>
            </div></div>`;
          });
        }
        html += `</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" id="saveLeadBtn" onclick="document.getElementById('editLeadForm').requestSubmit()">
        <i class="fas fa-check"></i> Save Lead
      </button>
    </div>
  </div></div>`;

  openModal(html);

  // Initialize active button and dynamic panels
  setTimeout(() => {
    syncActiveStatusButton(currentStage);
    toggleDynamicFields(currentStage);
  }, 10);
}

async function saveLead(e){
  e.preventDefault();
  const stage = document.getElementById('l_status').value;
  const data = {
    lead_id: activeLeadId,
    full_name: document.getElementById('l_name').value,
    phone: document.getElementById('l_phone').value,
    position_applied: document.getElementById('l_pos').value,
    city: document.getElementById('l_city').value,
    current_stage: stage,
    remark: document.getElementById('l_note').value.trim()
  };

  // Branch Referral Validation & Data
  if (stage === 'referred_branch') {
    const targetBranch = document.getElementById('l_target_branch')?.value;
    if (!targetBranch) {
      return toast('Barah-e-karam candidate ko refer karne ke liye target branch select karein', 'warning');
    }
    data.target_branch = targetBranch;
  }

  // Interview Scheduling Validation & Data
  if (stage === 'interview_scheduled') {
    const intDate = document.getElementById('l_int_date')?.value;
    const intTime = document.getElementById('l_int_time')?.value || '10:00';
    if (!intDate) {
      return toast('Barah-e-karam interview date select karein', 'warning');
    }
    data.interview_date = intDate;
    data.interview_time = intTime;
  }

  // Follow-up / Callback Date
  if (stage === 'callback') {
    const cbDate = document.getElementById('l_next_callback')?.value;
    if (cbDate) data.next_callback_date = cbDate;
  }

  // Rejection Reason
  if (stage === 'rejected') {
    const rejReason = document.getElementById('l_reject_reason')?.value;
    if (rejReason) data.rejection_reason = rejReason;
  }

  if (isSuperAdmin && document.getElementById('l_rec')) {
    data.assigned_recruiter_id = document.getElementById('l_rec').value;
  }

  const saveBtn = document.getElementById('saveLeadBtn');
  if (saveBtn) {
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
  }

  const res = await apiFetch(API.updateLead, {
    method: 'POST',
    body: JSON.stringify(data)
  });

  if (saveBtn) {
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<i class="fas fa-check"></i> Save Lead';
  }

  if (res.success) {
    toast('Lead updated successfully');
    closeModal();

    // Auto-sync interview to Google Sheets if scheduled
    if (stage === 'interview_scheduled') {
      try {
        const leadRes = await apiFetch(`${API.leadDetail}?lead_id=${activeLeadId}`);
        if (leadRes.success && leadRes.data) {
          const l = leadRes.data;
          fetch('https://script.google.com/macros/s/AKfycbzAErhctyGW1IA7mc8zJJ7baXh8ohv5Dm7fOzruFYndHCuxRTgehmZIaRnE_fueKBkrAA/exec', {
            method: 'POST',
            mode: 'no-cors',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
              action: 'addCandidate',
              fullName: l.full_name,
              phone: l.phone,
              position: l.position_applied || '',
              city: l.city || '',
              status: 'interview_scheduled',
              timestamp: new Date().toISOString()
            })
          });
        }
      } catch(e) { console.error('G-Sheet Sync failed', e); }
    }

    if (document.getElementById('dashView')) showDashboard();
    else if (document.querySelector('.nav-item[data-view="myLeads"]')?.classList.contains('active')) showMyLeads();
    else if (document.querySelector('.nav-item[data-view="allLeads"]')?.classList.contains('active')) showAllLeads(0, document.getElementById('searchInput')?.value||'', document.getElementById('stageFilter')?.value||'');
    else if (document.querySelector('.nav-item[data-view="performance"]')?.classList.contains('active')) showTeamPerformance();
  } else {
    toast(res.error || 'Failed to update lead', 'error');
  }
}

async function quickLog(note, stage){
  const res = await apiFetch(API.updateLead, {
    method: 'POST',
    body: JSON.stringify({ lead_id: activeLeadId, current_stage: stage, remark: note })
  });
  if(res.success){
    toast('Logged successfully');
    editLead(activeLeadId);
  } else toast(res.error, 'error');
}

function scheduleInterviewPrompt(){
  const today = new Date().toISOString().split('T')[0];
  openModal(`
  <div class="modal-overlay" id="scheduleModal"><div class="modal">
    <div class="modal-header"><h3>Schedule Interview</h3><button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button></div>
    <form id="scheduleForm" onsubmit="submitInterviewSchedule(event)">
      <div class="modal-body form-grid">
        <div class="form-group full"><label>Interview Date *</label><input type="date" id="si_date" class="form-control" value="${today}" required></div>
        <div class="form-group full"><label>Interview Time *</label><input type="time" id="si_time" class="form-control" value="10:00" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-purple"><i class="fas fa-calendar-check"></i> Schedule Now</button>
      </div>
    </form>
  </div></div>`);
}

async function submitInterviewSchedule(e){
  e.preventDefault();
  const d = document.getElementById('si_date').value;
  const t = document.getElementById('si_time').value;
  if(!d || !t) return toast('Please select date and time', 'warning');
  
  const datetime = `${d} ${t}`;
  const btn = e.target.querySelector('button[type="submit"]');
  const originalText = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scheduling...';
  btn.disabled = true;

  try {
    const res = await apiFetch(API.createInterview, {method:'POST', body:JSON.stringify({lead_id:activeLeadId, scheduled_at:datetime})});
    if(res.success){
      const interviewId = res.data.interview_id;
      toast('Interview scheduled successfully');
      closeModal();
      quickLog(`Interview scheduled for ${datetime}`, 'interview_scheduled');
    } else {
      toast(res.error || 'Failed to schedule interview', 'error');
      btn.innerHTML = originalText;
      btn.disabled = false;
    }
  } catch(e) {
    toast('Network error while scheduling interview', 'error');
    btn.innerHTML = originalText;
    btn.disabled = false;
  }
}

// --- ADD LEAD (Manual) ---
async function showAddLeadModal(){
  let recOptions = '';
  let branchOptions = '';
  if(isSuperAdmin){
    if(!recruitersListCache.length){
      const rRes=await apiFetch(API.recruiters);
      if(rRes.success) recruitersListCache=rRes.data.filter(x=>x.status==='active');
    }
    recOptions = `
      <div class="form-group"><label>Assign To (Optional)</label><select id="nl_rec" class="form-control">
        <option value="">-- Leave Unassigned --</option>
        ${recruitersListCache.map(r=>`<option value="${r.id}">${esc(r.full_name)}</option>`).join('')}
      </select></div>
    `;
    branchOptions = `
      <div class="form-group"><label>Company Branch</label><select id="nl_branch" class="form-control">
        ${COMPANY_BRANCH_OPTIONS.map(b=>`<option value="${b.key}">${esc(b.label)}</option>`).join('')}
      </select></div>
    `;
  }

  openModal(`
  <div class="modal-overlay" id="addLeadModal"><div class="modal">
    <div class="modal-header"><h3>Add New Lead</h3><button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button></div>
    <form id="addLeadForm" onsubmit="submitAddLead(event)">
      <div class="modal-body form-grid">
        <div class="form-group"><label>Full Name *</label><input type="text" id="nl_name" class="form-control" required></div>
        <div class="form-group"><label>Phone Number *</label><input type="text" id="nl_phone" class="form-control" required></div>
        <div class="form-group"><label>Position</label><input type="text" id="nl_pos" class="form-control" value="Dialer"></div>
        <div class="form-group"><label>City</label><input type="text" id="nl_city" class="form-control" value="Islamabad"></div>
        ${branchOptions}
        ${recOptions}
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Add Lead</button>
      </div>
    </form>
  </div></div>`);
}

async function submitAddLead(e){
  e.preventDefault();
  const data = {
    full_name: document.getElementById('nl_name').value,
    phone: document.getElementById('nl_phone').value,
    position_applied: document.getElementById('nl_pos').value,
    city: document.getElementById('nl_city').value
  };
  if(isSuperAdmin && document.getElementById('nl_branch')) {
    data.company_branch = document.getElementById('nl_branch').value;
  }
  if(isSuperAdmin && document.getElementById('nl_rec')) {
    data.assigned_recruiter_id = document.getElementById('nl_rec').value;
  }
  
  const res = await apiFetch(API.addLead, {method:'POST', body:JSON.stringify(data)});
  if(res.success){
    toast('Lead added successfully!');
    closeModal();
    if(document.getElementById('dashView')) showDashboard();
    else if(document.querySelector('.nav-item[data-view="myLeads"]')?.classList.contains('active')) showMyLeads();
    else if(document.querySelector('.nav-item[data-view="allLeads"]')?.classList.contains('active')) showAllLeads(0,document.getElementById('searchInput')?.value||'',document.getElementById('stageFilter')?.value||'');
  } else {
    toast(res.error || 'Failed to add lead', 'error');
  }
}


// --- IMPORT LEADS (Super Admin) ---
function showImportLeads(){
  if(!isSuperAdmin)return;
  setActiveNav('import');
  clearInterval(refreshTimer);
  document.getElementById('mainContent').innerHTML=`
  <div class="top-bar"><div class="page-title"><h1>Import Leads</h1><p>Upload Excel (.xlsx) or CSV files</p></div></div>
  <div class="upload-zone" onclick="document.getElementById('fileInput').click()" id="dropZone">
    <i class="fas fa-cloud-upload-alt"></i>
    <h3 style="color:var(--text-primary);margin-bottom:8px">Click or Drag File Here</h3>
    <p style="color:var(--text-dim);font-size:13px">Columns needed: Name, Phone (Position, City optional)</p>
    <input type="file" id="fileInput" accept=".xlsx,.xls,.csv" style="display:none" onchange="handleFileSelect(event)">
  </div>
  <div id="importPreview" style="margin-top:20px;display:none"></div>
  <script src="https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js"></script>
  `;
  
  const d=document.getElementById('dropZone');
  d.ondragover=(e)=>{e.preventDefault();d.classList.add('drag-over');};
  d.ondragleave=(e)=>{e.preventDefault();d.classList.remove('drag-over');};
  d.ondrop=(e)=>{e.preventDefault();d.classList.remove('drag-over');if(e.dataTransfer.files[0]){document.getElementById('fileInput').files=e.dataTransfer.files;handleFileSelect({target:document.getElementById('fileInput')});}};
}

let parsedLeads=[], importFileName='';
function handleFileSelect(e){
  const f=e.target.files[0];if(!f)return;
  importFileName=f.name;
  if(typeof XLSX==='undefined'){toast('Loading Excel parser, try again in 2s','warning');return;}
  const reader=new FileReader();
  reader.onload=(e)=>{
    const wb=XLSX.read(e.target.result,{type:'array'});
    const data=XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]]);
    parsedLeads=[];
    data.forEach(r=>{
      const name=r.Name||r.name||r['Full Name']||r.FullName||'';
      const phone=r.Phone||r.phone||r.Contact||r['Contact Number']||'';
      if(name&&phone) parsedLeads.push({
        full_name:name, phone:String(phone), position_applied:r.Position||r.position||'',
        city:r.City||r.city||'', email:r.Email||r.email||''
      });
    });
    
    const p=document.getElementById('importPreview');
    p.style.display='block';
    if(parsedLeads.length===0){
      p.innerHTML=`<div class="empty-state"><i class="fas fa-exclamation-triangle" style="color:var(--danger)"></i><p>No valid rows found. Ensure 'Name' and 'Phone' columns exist.</p></div>`;
    }else{
      p.innerHTML=`
      <div class="top-bar" style="background:rgba(16,185,129,.1);border-color:#10b981">
        <div class="page-title"><h1 style="background:none;color:#10b981"><i class="fas fa-check-circle"></i> Ready to Import</h1><p>${parsedLeads.length} valid leads found in ${importFileName}</p></div>
        <button class="btn btn-success" onclick="commitImport()"><i class="fas fa-upload"></i> Import to Database</button>
      </div>`;
    }
  };
  reader.readAsArrayBuffer(f);
}

async function commitImport(){
  if(!parsedLeads.length)return;
  document.getElementById('importPreview').innerHTML=setLoading('Importing... please wait.');
  const res=await apiFetch(API.bulkImport,{method:'POST',body:JSON.stringify({file_name:importFileName,leads:parsedLeads})});
  if(res.success){toast(`Imported: ${res.data.inserted} | Skipped/Dups: ${res.data.skipped}`);parsedLeads=[];showDashboard();}
  else toast(res.error,'error');
}

window.editLead=editLead;
window.saveLead=saveLead;
window.quickLog=quickLog;
window.scheduleInterviewPrompt=scheduleInterviewPrompt;
window.showImportLeads=showImportLeads;
window.handleFileSelect=handleFileSelect;
window.commitImport=commitImport;
window.showAddLeadModal=showAddLeadModal;
window.submitAddLead=submitAddLead;
window.submitInterviewSchedule=submitInterviewSchedule;
