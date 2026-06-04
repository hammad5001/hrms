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
  createRec: 'api/create_recruiter_account.php',
  toggleRec: 'api/toggle_recruiter_status.php',
  bulkImport:'api/bulk_import_leads.php',
  createInterview: 'api/create_interview.php',
  leadTimeline: 'api/get_lead_timeline.php',
  distribute:'api/distribute_leads.php',
};

let currentUser = null, isSuperAdmin = false, refreshTimer = null, lastRefresh = null;

const STATUS_OPTIONS = [
  {value:'new',label:'Lead Intake',cls:'stage-new'},
  {value:'assigned',label:'Assigned',cls:'stage-assigned'},
  {value:'outreach_phone',label:'Phone Call',cls:'stage-contacted'},
  {value:'outreach_whatsapp_call',label:'WhatsApp Call',cls:'stage-contacted'},
  {value:'outreach_whatsapp_msg',label:'WhatsApp Message',cls:'stage-contacted'},
  {value:'interview_scheduled',label:'Interview Scheduled',cls:'stage-interview_scheduled'},
  {value:'not_appeared',label:'Not Appeared',cls:'stage-hr_rejected'},
  {value:'receptionist',label:'At Reception',cls:'stage-assigned'},
  {value:'interview_conducted',label:'Interview Conducted',cls:'stage-assigned'},
  {value:'selected',label:'Selected',cls:'stage-hr_passed'},
  {value:'pending',label:'Pending',cls:'stage-callback'},
  {value:'rejected',label:'Rejected',cls:'stage-rejected'},
  {value:'training',label:'Training',cls:'stage-training'},
  {value:'deployed',label:'Deployed',cls:'stage-hired'},
  {value:'mock_rejected',label:'Mock Rejected',cls:'stage-rejected'},
  {value:'left',label:'Left',cls:'stage-rejected'},
  // Legacy support
  {value:'contacted',label:'Contacted',cls:'stage-contacted'},
  {value:'interested',label:'Interested',cls:'stage-interested'},
  {value:'callback',label:'Call Back',cls:'stage-callback'},
  {value:'hired',label:'Hired',cls:'stage-hired'},
  {value:'hr_passed',label:'HR Passed',cls:'stage-hr_passed'},
  {value:'hr_rejected',label:'HR Rejected',cls:'stage-hr_rejected'},
  {value:'gm_passed',label:'GM Passed',cls:'stage-gm_passed'},
  {value:'gm_rejected',label:'GM Rejected',cls:'stage-gm_rejected'},
];

// ===== UTILITIES =====
function esc(s){if(!s)return'';return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function fmt(d){if(!d)return'-';return new Date(d).toLocaleDateString('en-PK',{day:'2-digit',month:'short',year:'numeric'});}
function fmtTime(d){if(!d)return'-';return new Date(d).toLocaleString('en-PK',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});}
function ago(d){if(!d)return'';const s=Math.floor((Date.now()-new Date(d))/1000);if(s<60)return s+'s ago';if(s<3600)return Math.floor(s/60)+'m ago';if(s<86400)return Math.floor(s/3600)+'h ago';return Math.floor(s/86400)+'d ago';}

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
  document.getElementById('mainContent').innerHTML=html;
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
  isSuperAdmin=(currentUser.recruiter_type==='super'||currentUser.portal_role==='admin'||currentUser.portal_role==='super_admin');

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

function statusSelectHtml(selected=''){
  return STATUS_OPTIONS.map(o=>`<option value="${o.value}"${selected===o.value?' selected':''}>${o.label}</option>`).join('');
}

window.closeModal=closeModal;
window.logout=logout;
window.init=init;
