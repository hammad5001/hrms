const API = {
  session:   'api/check_session.php',
  employeeHrms: 'api/employee_self_service.php',
  stats:     'api/recruiter_stats.php',
  myLeads:   'api/get_recruiter_leads.php',
  allLeads:  'api/get_all_leads.php',
  leadDetail:'api/get_lead_details.php',
  updateLead:'api/update_lead.php',
  addLead:   'api/add_lead.php',
  recruiters:'api/get_recruiters_list.php',
  performance:'api/recruiter_performance.php',
  createRec: 'api/create_recruiter_account.php',
  toggleRec: 'api/toggle_recruiter_status.php',
  bulkImport:'api/bulk_import_leads.php',
  createInterview: 'api/create_interview.php',
  leadTimeline: 'api/get_lead_timeline.php',
  distribute:'api/distribute_leads.php',
  distributionLogs: 'api/get_lead_distribution_logs.php',
  syncWebsiteLeads: 'api/sync_website_leads.php',
  exportReport: 'api/export_recruiter_report.php',
};

let currentUser = null, isSuperAdmin = false, refreshTimer = null, lastRefresh = null;

const COMPANY_BRANCH_OPTIONS = [
  { key: 'main', label: 'Main Branch' },
  { key: 'v2', label: '2.0 Branch' },
  { key: 'v3', label: '3.0 Branch' },
  { key: 'commercial', label: 'Commercial Branch' },
  { key: 'I9', label: 'I-9 Branch' },
  { key: 'workfromhome', label: 'Work From Home' }
];

const STATUS_OPTIONS = [
  // Outreach & Calling
  { value: 'outreach_phone', label: 'Phone Call', group: 'Outreach', icon: 'fa-phone', cls: 'stage-contacted' },
  { value: 'outreach_whatsapp_call', label: 'WhatsApp Call', group: 'Outreach', icon: 'fab fa-whatsapp', cls: 'stage-contacted' },
  { value: 'outreach_whatsapp_msg', label: 'Message Dropped', group: 'Outreach', icon: 'fas fa-comment-dots', cls: 'stage-contacted' },
  { value: 'not_answered', label: 'Not Answered', group: 'Outreach', icon: 'fas fa-phone-slash', cls: 'stage-callback' },
  { value: 'callback', label: 'Call Back', group: 'Outreach', icon: 'fas fa-clock-rotate-left', cls: 'stage-callback' },

  // Interview & Decision
  { value: 'interview_scheduled', label: 'Interview Scheduled', group: 'Pipeline', icon: 'fas fa-calendar-check', cls: 'stage-interview_scheduled' },
  { value: 'pending', label: 'Pending Decision', group: 'Pipeline', icon: 'fas fa-hourglass-half', cls: 'stage-callback' },
  { value: 'selected', label: 'Selected', group: 'Pipeline', icon: 'fas fa-star', cls: 'stage-hr_passed' },
  { value: 'rejected', label: 'Rejected', group: 'Pipeline', icon: 'fas fa-times-circle', cls: 'stage-rejected' },

  // Branch Transfer / Referral
  { value: 'referred_branch', label: 'Referred to Another Branch', group: 'Branch', icon: 'fas fa-building-circle-arrow-right', cls: 'stage-interested' },

  // Reception & Training
  { value: 'receptionist', label: 'Appeared (At Reception)', group: 'Reception', icon: 'fas fa-building-user', cls: 'stage-hr_passed' },
  { value: 'not_appeared', label: 'Not Appeared', group: 'Reception', icon: 'fas fa-user-slash', cls: 'stage-hr_rejected' },
  { value: 'training', label: 'In Training', group: 'Training', icon: 'fas fa-graduation-cap', cls: 'stage-training' },
  { value: 'deployed', label: 'Deployed / Joined', group: 'Training', icon: 'fas fa-briefcase', cls: 'stage-hired' },

  // Base Intake
  { value: 'new', label: 'Lead Intake', group: 'Intake', icon: 'fas fa-inbox', cls: 'stage-new' },
  { value: 'assigned', label: 'Assigned', group: 'Intake', icon: 'fas fa-user-tag', cls: 'stage-assigned' },

  // Legacy mappings for backward display
  { value: 'contacted', label: 'Phone Call (Legacy)', group: 'Legacy', icon: 'fa-phone', cls: 'stage-contacted' },
  { value: 'interested', label: 'Interested (Legacy)', group: 'Legacy', icon: 'fa-thumbs-up', cls: 'stage-interested' },
  { value: 'hired', label: 'Hired (Legacy)', group: 'Legacy', icon: 'fa-check', cls: 'stage-hired' },
  { value: 'interview_conducted', label: 'Interview Conducted', group: 'Legacy', icon: 'fa-check-double', cls: 'stage-assigned' },
  { value: 'hr_passed', label: 'HR Passed (Legacy)', group: 'Legacy', icon: 'fa-check', cls: 'stage-hr_passed' },
  { value: 'hr_rejected', label: 'HR Rejected (Legacy)', group: 'Legacy', icon: 'fa-times', cls: 'stage-rejected' },
  { value: 'gm_passed', label: 'GM Passed (Legacy)', group: 'Legacy', icon: 'fa-check', cls: 'stage-gm_passed' },
  { value: 'gm_rejected', label: 'GM Rejected (Legacy)', group: 'Legacy', icon: 'fa-times', cls: 'stage-rejected' },
  { value: 'mock_rejected', label: 'Mock Rejected (Legacy)', group: 'Legacy', icon: 'fa-times', cls: 'stage-rejected' },
  { value: 'left', label: 'Left (Legacy)', group: 'Legacy', icon: 'fa-door-open', cls: 'stage-rejected' },
];

// ===== UTILITIES =====
function esc(s){if(!s)return'';return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function fmt(d){
  if(!d) return '-';
  const t = typeof d === 'string' ? new Date(d.replace(/-/g, '/')) : new Date(d);
  if(isNaN(t)) return d;
  return t.toLocaleDateString('en-PK',{day:'2-digit',month:'short',year:'numeric'});
}
function fmtTime(d){
  if(!d) return '-';
  const t = typeof d === 'string' ? new Date(d.replace(/-/g, '/')) : new Date(d);
  if(isNaN(t)) return d;
  return t.toLocaleString('en-PK',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
function ago(d){
  if(!d) return '-';
  const t = typeof d === 'string' ? new Date(d.replace(/-/g, '/')) : new Date(d);
  if(isNaN(t)) return '-';
  const s = Math.floor((Date.now() - t.getTime()) / 1000);
  if(s < 0 || s < 60) return 'Just now';
  if(s < 3600) return Math.floor(s / 60) + 'm ago';
  if(s < 86400) return Math.floor(s / 3600) + 'h ago';
  if(s < 2592000) return Math.floor(s / 86400) + 'd ago';
  return fmt(d);
}

function stageBadge(s){const o=STATUS_OPTIONS.find(x=>x.value===s)||{label:s||'Unknown',cls:'stage-new'};return`<span class="badge ${o.cls}">${o.label}</span>`;}

function toast(msg,type='success'){
  const c=document.getElementById('toastContainer'),t=document.createElement('div');
  t.className='toast';
  const colors={success:'#10b981',error:'#ef4444',warning:'#f59e0b',info:'#3b82f6'};
  const icons={success:'fa-check-circle',error:'fa-exclamation-circle',warning:'fa-exclamation-triangle',info:'fa-info-circle'};
  t.style.borderLeftColor=colors[type]||colors.success;
  t.innerHTML=`<i class="fas ${icons[type]||icons.success}" style="color:${colors[type]||colors.success}"></i><span>${esc(msg)}</span>`;
  c.appendChild(t);setTimeout(()=>t.remove(),3500);
}

function setLoading(html='<div class="loading-state"><i class="fas fa-spinner fa-spin"></i><p>Loading...</p></div>'){
  const el = document.getElementById('mainContent');
  if (!el) return;
  if (!el.firstElementChild || el.querySelector('.loading-state')) {
    el.innerHTML = html;
  } else {
    el.classList.add('view-loading');
  }
}

function renderMainView(html){
  const el = document.getElementById('mainContent');
  if(!el) return;
  el.classList.remove('view-loading');
  el.innerHTML = html;
}

function closeModal(){const m=document.querySelector('.modal-overlay');if(m)m.remove();}

function openModal(html){
  document.body.insertAdjacentHTML('beforeend',html);
  document.querySelector('.modal-overlay').addEventListener('click',e=>{if(e.target.classList.contains('modal-overlay'))closeModal();});
}

async function apiFetch(url,opts={}){
  const method=(opts.method||'GET').toUpperCase();
  if(window.PortalFetch&&method==='GET'){
    try{
      return await PortalFetch.fetchJson(url,{ttlMs:15000});
    }catch(e){return{success:false,error:'Network error'};}
  }
  try{
    const r=await fetch(url,{credentials:'include',...opts});
    const data=await r.json();
    if(window.PortalFetch&&method!=='GET'&&data&&data.success!==false){
      PortalFetch.invalidatePipelineCaches();
    }
    return data;
  }catch(e){return{success:false,error:'Network error'};}
}

function setActiveNav(fn){
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  document.querySelectorAll(`.nav-item[data-view="${fn}"]`).forEach(n=>n.classList.add('active'));
}

function startAutoRefresh(fn,ms=45000){
  if(refreshTimer)clearInterval(refreshTimer);
  const run=()=>{if(!document.hidden)fn();};
  refreshTimer=setInterval(run,ms);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)run();});
}

function updateLiveBar(){
  const el=document.getElementById('liveUpdated');
  if(el&&lastRefresh)el.textContent=ago(lastRefresh);
}

// ===== INIT =====
async function init(){
  const res=await apiFetch(API.session);
  if(!res.success||!res.authenticated){
    window.location.href='index.html';return;
  }
  currentUser=res.user;
  isSuperAdmin=(currentUser.recruiter_type==='super'||currentUser.portal_role==='admin'||currentUser.portal_role==='super_admin'||currentUser.portal_role==='hr');

  if(currentUser.company_branch){
    localStorage.setItem('companyBranch',currentUser.company_branch);
    localStorage.setItem('companyBranchLabel',currentUser.company_branch_label||'');
  }

  document.getElementById('sidebarName').textContent=currentUser.full_name;
  document.getElementById('sidebarRole').textContent=isSuperAdmin?'Super Admin':'Recruiter';
  document.getElementById('sidebarAvatar').textContent=(currentUser.full_name||'?').charAt(0).toUpperCase();

  if(isSuperAdmin){
    document.querySelectorAll('.super-only').forEach(el=>el.style.display='flex');
  }

  setInterval(()=>{if(!document.hidden)updateLiveBar();},30000);
  showDashboard();
}

function logout(){
  if(!confirm('Are you sure you want to logout?'))return;
  fetch('logout.php').finally(()=>{
    localStorage.clear();sessionStorage.clear();
    window.location.href='index.html';
  });
}

function statusSelectHtml(selected = '', includeLegacy = false) {
  const groups = [
    { label: '📞 Calling & Outreach', keys: ['outreach_phone', 'outreach_whatsapp_call', 'outreach_whatsapp_msg', 'not_answered', 'callback'] },
    { label: '📅 Pipeline & Interview', keys: ['interview_scheduled', 'pending', 'selected', 'rejected'] },
    { label: '🏢 Branch Referral', keys: ['referred_branch'] },
    { label: '🏛 Reception & Training', keys: ['receptionist', 'not_appeared', 'training', 'deployed'] },
    { label: '📥 Intake Status', keys: ['new', 'assigned'] }
  ];

  let html = '';
  const renderedKeys = new Set();
  
  groups.forEach(g => {
    html += `<optgroup label="${g.label}">`;
    g.keys.forEach(k => {
      const opt = STATUS_OPTIONS.find(x => x.value === k);
      if (opt) {
        renderedKeys.add(opt.value);
        html += `<option value="${opt.value}" ${selected === opt.value ? 'selected' : ''}>${esc(opt.label)}</option>`;
      }
    });
    html += `</optgroup>`;
  });

  // If the lead currently has a legacy status that is not yet rendered, preserve it
  if (selected && !renderedKeys.has(selected)) {
    const legacyOpt = STATUS_OPTIONS.find(x => x.value === selected);
    if (legacyOpt) {
      html += `<optgroup label="⚠️ Legacy Status">`;
      html += `<option value="${legacyOpt.value}" selected>${esc(legacyOpt.label)}</option>`;
      html += `</optgroup>`;
    }
  }

  return html;
}

window.renderMainView=renderMainView;
window.setLoading=setLoading;
window.closeModal=closeModal;
window.logout=logout;
window.init=init;
