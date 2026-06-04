// ===== RECRUITER PORTAL ACTIONS & MODALS =====

// --- LEAD EDIT MODAL ---
let activeLeadId=null, recruitersListCache=[];

async function editLead(id){
  activeLeadId=id;
  const res=await apiFetch(`${API.leadDetail}?lead_id=${id}`);
  if(!res.success)return toast(res.error||'Lead not found','error');
  
  const l=res.data;
  const timelineRes=await apiFetch(`${API.leadTimeline}?lead_id=${id}`);
  const timeline=(timelineRes.success&&timelineRes.data)?timelineRes.data:null;
  
  if(isSuperAdmin&&!recruitersListCache.length){
    const rRes=await apiFetch(API.recruiters);
    if(rRes.success) recruitersListCache=rRes.data.filter(x=>x.status==='active');
  }

  let html=`
  <div class="modal-overlay" id="leadModal"><div class="modal modal-lg">
    <div class="modal-header">
      <h3>Edit Lead - ${esc(l.full_name)}</h3>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="display:flex;gap:20px">
      <div style="flex:1">
        <form id="editLeadForm" onsubmit="saveLead(event)">
          <div class="form-grid">
            <div class="form-group"><label>Full Name</label><input type="text" id="l_name" class="form-control" value="${esc(l.full_name)}" required></div>
            <div class="form-group"><label>Phone</label><input type="text" id="l_phone" class="form-control" value="${esc(l.phone)}" required></div>
            <div class="form-group"><label>Position</label><input type="text" id="l_pos" class="form-control" value="${esc(l.position_applied)}"></div>
            <div class="form-group"><label>City</label><input type="text" id="l_city" class="form-control" value="${esc(l.city)}"></div>
            <div class="form-group"><label>Status</label><select id="l_status" class="form-control">${statusSelectHtml(l.current_stage)}</select></div>
            ${isSuperAdmin ? `
              <div class="form-group"><label>Assign To</label><select id="l_rec" class="form-control">
                <option value="">-- Unassigned --</option>
                ${recruitersListCache.map(r=>`<option value="${r.id}"${l.assigned_recruiter_id==r.id?' selected':''}>${esc(r.full_name)}</option>`).join('')}
              </select></div>
            `:''}
            <div class="form-group full"><label>New Remark / Note</label><textarea id="l_note" class="form-control" placeholder="Add a note..."></textarea></div>
          </div>
        </form>
        <div class="quick-actions">
          <span style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-right:10px;align-self:center">Log Outreach:</span>
          <button class="btn btn-sm btn-info" onclick="quickLog('Phone Call Made','outreach_phone')"><i class="fas fa-phone"></i> Phone</button>
          <button class="btn btn-sm btn-success" onclick="quickLog('WhatsApp Call','outreach_whatsapp_call')"><i class="fab fa-whatsapp"></i> WA Call</button>
          <button class="btn btn-sm btn-success" onclick="quickLog('WhatsApp Message Sent','outreach_whatsapp_msg')"><i class="fas fa-comment-dots"></i> WA Msg</button>
          
          <div style="width:100%; height:1px; background:rgba(255,255,255,0.05); margin:8px 0;"></div>
          
          <span style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-right:10px;align-self:center">Interview:</span>
          <button class="btn btn-sm btn-purple" onclick="scheduleInterviewPrompt()"><i class="fas fa-calendar"></i> Schedule</button>
          <button class="btn btn-sm btn-info" onclick="quickLog('Arrived at Reception','receptionist')"><i class="fas fa-building"></i> Arrived</button>
          <button class="btn btn-sm btn-warning" onclick="quickLog('Not Appeared','not_appeared')"><i class="fas fa-user-slash"></i> No Show</button>
          
          <div style="width:100%; height:1px; background:rgba(255,255,255,0.05); margin:8px 0;"></div>

          <span style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-right:10px;align-self:center">Outcome:</span>
          <button class="btn btn-sm btn-success" onclick="quickLog('Selected for Training','selected')"><i class="fas fa-star"></i> Selected</button>
          <button class="btn btn-sm btn-warning" onclick="quickLog('Pending Decision','pending')"><i class="fas fa-clock"></i> Pending</button>
          <button class="btn btn-sm btn-danger" onclick="quickLog('Rejected','rejected')"><i class="fas fa-times"></i> Reject</button>
        </div>
      </div>
      <div style="flex:0 0 300px;background:rgba(255,255,255,.02);border-radius:12px;padding:16px;border:1px solid var(--border-light)">
        ${timeline?`<div style="margin-bottom:14px;padding:10px 12px;border-radius:8px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2)">
          <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Pipeline Status</div>
          <div style="font-size:14px;font-weight:700;color:var(--secondary)">${esc(timeline.stage_label||l.current_stage)}</div>
        </div>`:''}
        <h4 style="font-size:12px;color:var(--text-muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:1px">Pipeline Timeline</h4>
        <div class="call-list" style="max-height:220px;overflow-y:auto;margin-bottom:14px">`;
        const events=(timeline&&timeline.events)?timeline.events:[];
        if(!events.length){
          html+=`<div class="empty-state" style="padding:12px 0"><p>No pipeline events yet</p></div>`;
        }else{
          events.slice().reverse().forEach(ev=>{
            html+=`<div class="call-item"><div class="call-dot"><i class="fas fa-route"></i></div><div>
              <div class="call-text">${esc(ev.title)}</div>
              <div class="call-meta">${esc(ev.by||'System')} · ${fmtTime(ev.at)}</div>
              ${ev.detail?`<div style="font-size:11px;color:var(--text-muted);margin-top:4px">${esc(ev.detail)}</div>`:''}
            </div></div>`;
          });
        }
        html+=`</div>
        <h4 style="font-size:12px;color:var(--text-muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:1px">Remarks (${l.remarks_count})</h4>
        <div class="call-list" style="max-height:160px;overflow-y:auto">`;
        if(!l.remarks||!l.remarks.length){
          html+=`<div class="empty-state" style="padding:12px 0"><p>No remarks</p></div>`;
        }else{
          l.remarks.forEach(r=>{
            html+=`<div class="call-item"><div class="call-dot"><i class="fas fa-phone-alt"></i></div><div>
              <div class="call-text">${esc(r.remark)}</div>
              <div class="call-meta">${esc(r.author_name||'System')} · ${fmtTime(r.created_at)}</div>
            </div></div>`;
          });
        }
        html+=`</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" onclick="document.getElementById('editLeadForm').requestSubmit()">Save Lead</button>
    </div>
  </div></div>`;
  openModal(html);
}

async function saveLead(e){
  e.preventDefault();
  const data={
    lead_id: activeLeadId,
    full_name: document.getElementById('l_name').value,
    phone: document.getElementById('l_phone').value,
    position_applied: document.getElementById('l_pos').value,
    city: document.getElementById('l_city').value,
    current_stage: document.getElementById('l_status').value,
    remark: document.getElementById('l_note').value
  };
  if(isSuperAdmin) data.assigned_recruiter_id = document.getElementById('l_rec').value;

  const res=await apiFetch(API.updateLead,{method:'POST',body:JSON.stringify(data)});
  if(res.success){
    toast('Lead updated successfully');
    closeModal();
    if(document.getElementById('dashView')) showDashboard();
    else if(document.querySelector('.nav-item[data-view="myLeads"]').classList.contains('active')) showMyLeads();
    else if(document.querySelector('.nav-item[data-view="allLeads"]').classList.contains('active')) showAllLeads(0,document.getElementById('searchInput')?.value||'',document.getElementById('stageFilter')?.value||'');
  }else toast(res.error,'error');
}

async function quickLog(note, stage){
  if(!confirm(`Log "${note}" and set status to ${stage}?`))return;
  const res=await apiFetch(API.updateLead,{method:'POST',body:JSON.stringify({lead_id:activeLeadId,current_stage:stage,remark:note})});
  if(res.success){
    toast('Logged successfully');
    
    // Auto-push to Google Sheets if it's an interview schedule
    if (stage === 'interview_scheduled') {
      try {
        const leadRes = await apiFetch(`${API.leadDetail}?lead_id=${activeLeadId}`);
        if(leadRes.success && leadRes.data) {
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
    editLead(activeLeadId);
  }
  else toast(res.error,'error');
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
  if(isSuperAdmin){
    if(!recruitersListCache.length){
      const rRes=await apiFetch(API.recruiters);
      if(rRes.success) recruitersListCache=rRes.data.filter(x=>x.status==='active');
    }
    recOptions = `
      <div class="form-group full"><label>Assign To (Optional)</label><select id="nl_rec" class="form-control">
        <option value="">-- Leave Unassigned --</option>
        ${recruitersListCache.map(r=>`<option value="${r.id}">${esc(r.full_name)}</option>`).join('')}
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
        <div class="form-group"><label>Position</label><input type="text" id="nl_pos" class="form-control"></div>
        <div class="form-group"><label>City</label><input type="text" id="nl_city" class="form-control"></div>
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

// --- MANAGE RECRUITERS (Super Admin) ---
async function showRecruitersList(){
  if(!isSuperAdmin)return;
  setActiveNav('manageRec');
  setLoading();
  const res=await apiFetch(API.recruiters);
  if(!res.success)return toast('Failed to load','error');
  clearInterval(refreshTimer);
  const list=res.data||[];
  
  let html=`
  <div class="top-bar">
    <div class="page-title"><h1>Manage Recruiters</h1><p>Active and inactive team members</p></div>
    <div class="top-actions">
      <button class="btn btn-primary" onclick="showAddRecruiterModal()"><i class="fas fa-plus"></i> Add Recruiter</button>
    </div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Recruiter</th><th>Contact</th><th>Leads</th><th>Performance</th><th>Status</th></tr></thead>
      <tbody>`;
  
  if(!list.length)html+=`<tr><td colspan="5" class="empty-state">No recruiters found</td></tr>`;
  list.forEach(r=>{
    html+=`<tr>
      <td><strong>${esc(r.full_name)}</strong><br><span style="font-size:10px;color:var(--text-dim)">Emp ID: ${esc(r.employee_code)}</span></td>
      <td>${esc(r.email)}<br><span style="font-size:10px;color:var(--text-dim)">${esc(r.phone)}</span></td>
      <td><strong>${r.total_leads}</strong> Total<br><span style="font-size:10px;color:var(--warning)">${r.pending_leads||0} Pending</span></td>
      <td><span style="color:var(--secondary);font-weight:600">${r.hired_leads||0} Hired</span><br><span style="font-size:10px;color:var(--text-dim)">${r.total_calls||0} Calls</span></td>
      <td>
        <div class="toggle-wrap">
          <label class="toggle"><input type="checkbox" ${r.status==='active'?'checked':''} onchange="toggleRecStatus(${r.id},this.checked)"><span class="toggle-slider"></span></label>
          <span style="font-size:11px;font-weight:600;color:${r.status==='active'?'#10b981':'#ef4444'}">${r.status.toUpperCase()}</span>
        </div>
      </td>
    </tr>`;
  });
  html+=`</tbody></table></div>`;
  document.getElementById('mainContent').innerHTML=html;
}

async function toggleRecStatus(id,isActive){
  const status=isActive?'active':'inactive';
  if(!confirm(`Change recruiter status to ${status}?`))return showRecruitersList();
  const res=await apiFetch(API.toggleRec,{method:'POST',body:JSON.stringify({recruiter_id:id,status:status})});
  if(res.success){toast(`Recruiter is now ${status}`);showRecruitersList();}
  else{toast(res.error,'error');showRecruitersList();}
}

function showAddRecruiterModal(){
  openModal(`
  <div class="modal-overlay" id="addRecModal"><div class="modal">
    <div class="modal-header"><h3>Add New Recruiter</h3><button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button></div>
    <form id="addRecForm" onsubmit="createRecruiter(event)">
      <div class="modal-body form-grid">
        <div class="form-group"><label>Full Name</label><input type="text" id="nr_name" class="form-control" required></div>
        <div class="form-group"><label>Email</label><input type="email" id="nr_email" class="form-control" required></div>
        <div class="form-group"><label>Username</label><input type="text" id="nr_user" class="form-control" required></div>
        <div class="form-group"><label>Phone</label><input type="text" id="nr_phone" class="form-control"></div>
        <div class="form-group full"><label>Password</label><input type="text" id="nr_pass" class="form-control" value="Recruiter@123" required></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button><button type="submit" class="btn btn-primary">Create Account</button></div>
    </form>
  </div></div>`);
}

async function createRecruiter(e){
  e.preventDefault();
  const data={
    full_name:document.getElementById('nr_name').value,
    email:document.getElementById('nr_email').value,
    username:document.getElementById('nr_user').value,
    phone:document.getElementById('nr_phone').value,
    password:document.getElementById('nr_pass').value
  };
  const res=await apiFetch(API.createRec,{method:'POST',body:JSON.stringify(data)});
  if(res.success){toast('Recruiter Created!');closeModal();showRecruitersList();}
  else toast(res.error||res.message||'Error','error');
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
window.showRecruitersList=showRecruitersList;
window.toggleRecStatus=toggleRecStatus;
window.showAddRecruiterModal=showAddRecruiterModal;
window.createRecruiter=createRecruiter;
window.showImportLeads=showImportLeads;
window.handleFileSelect=handleFileSelect;
window.commitImport=commitImport;
window.showAddLeadModal=showAddLeadModal;
window.submitAddLead=submitAddLead;
window.submitInterviewSchedule=submitInterviewSchedule;
