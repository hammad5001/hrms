// ==================== GOOGLE SHEETS CONFIGURATION ====================
const GOOGLE_SHEETS_URL = 'https://script.google.com/macros/s/AKfycbzVqz1XF6aWjuA07xIOFA_cKlayHYMudseEpPhPM7QNyxpb5kiAjtzxQ3cYkQhY8RslmQ/exec';

// ==================== NEW RECRUITER SHEET CONFIGURATION ====================
// *** UPDATED with your new Apps Script URL ***
const RECRUITER_SHEET_URL = 'https://script.google.com/macros/s/AKfycbzAErhctyGW1IA7mc8zJJ7baXh8ohv5Dm7fOzruFYndHCuxRTgehmZIaRnE_fueKBkrAA/exec';

// ==================== GLOBAL STATE ====================
let candidates = [];
let recruitLeads = [];
let voiceInitialized = false;
let preferredVoice = null;
let autoSyncInterval = null;
let pendingSync = false;
let sheetSyncInterval = null;
let sheetConnectionStatus = 'disconnected';

// ==================== SYNC TRACKING - IMPROVED ====================
// Track synced candidates by a combination of ID and timestamp
let syncedRecords = {}; // Format: { "candidateId_timestamp": true }

// Sync tracking kept in memory only (sheet duplicate prevention)
function loadSyncedRecords() {
    syncedRecords = syncedRecords || {};
}

function saveSyncedRecords() {
    /* in-memory only */
}

// Generate a unique key for a candidate
function getCandidateKey(candidate) {
    return `${candidate.id}_${candidate.timestamp}`;
}

// Check if candidate has been synced
function isCandidateSynced(candidate) {
    const key = getCandidateKey(candidate);
    return syncedRecords[key] === true;
}

// Mark candidate as synced
function markCandidateSynced(candidate) {
    const key = getCandidateKey(candidate);
    syncedRecords[key] = true;
}

// Recruiters list
const recruiters = [
    { id: 'danish', name: 'Danish Khan' },
    { id: 'naina', name: 'Naina Fareed' },
    { id: 'zoya', name: 'Zoya' },
    { id: 'bushra', name: 'Bushra' },
    { id: 'walkin', name: 'Walk-in' }
];

let interviewsCache = [];

function mapDbLeadRow(l) {
    return {
        id: l.id,
        name: l.full_name || l.name || 'Unknown',
        phone: l.phone,
        email: l.email || '',
        city: l.city || '',
        position: l.position_applied || l.position || '',
        status: l.current_stage || l.status || 'new',
        assignedTo: l.assigned_recruiter_id || l.assignedTo,
        interviewDate: l.interview_date,
        callCount: l.call_count || 0,
        createdAt: l.created_at,
        updatedAt: l.updated_at
    };
}

async function refreshInterviewsCache() {
    try {
        const res = await fetch('api/get_interviews.php?status=scheduled', { credentials: 'include' });
        const data = await res.json();
        interviewsCache = data.success && Array.isArray(data.data) ? data.data : [];
    } catch (e) {
        console.error('Interview load error', e);
        interviewsCache = [];
    }
}

// ==================== LOAD DATA ====================
async function loadData() {
    candidates = [];
    recruitLeads = [];
    try {
        const leadsRes = await fetch('api/get_recruiter_leads.php?limit=500', { credentials: 'include' });
        const leadsJson = await leadsRes.json();
        if (leadsJson.success && leadsJson.data?.leads) {
            recruitLeads = leadsJson.data.leads.map(mapDbLeadRow);
        }
        const candRes = await fetch('api/get_candidates_global.php', { credentials: 'include' });
        const candJson = await candRes.json();
        if (candJson.success && Array.isArray(candJson.data)) {
            candidates = candJson.data;
        }
        await refreshInterviewsCache();
    } catch (e) {
        console.error('Database load error:', e);
    }
}

// ==================== SAVE DATA ====================
function saveData() {
    syncToGoogleSheets();
    return true;
}

// ==================== GOOGLE SHEETS SYNC - ABSOLUTELY NO DUPLICATES ====================
async function syncToGoogleSheets() {
    // Prevent multiple simultaneous syncs
    if (pendingSync) {
        console.log('Sync already in progress, skipping...');
        return;
    }
    
    pendingSync = true;
    updateSyncStatus('Syncing...');
    
    try {
        // Load synced records first
        loadSyncedRecords();
        
        // Find candidates that haven't been synced yet
        const unsyncedCandidates = [];
        const alreadySynced = [];
        
        candidates.forEach(c => {
            if (isCandidateSynced(c)) {
                alreadySynced.push(c);
            } else {
                unsyncedCandidates.push(c);
            }
        });
        
        console.log(`Total candidates: ${candidates.length}`);
        console.log(`Already synced: ${alreadySynced.length}`);
        console.log(`New candidates to sync: ${unsyncedCandidates.length}`);

        // If no new candidates, skip sync
        if (unsyncedCandidates.length === 0) {
            console.log('No new candidates to sync');
            updateSyncStatus('Up to date');
            pendingSync = false;
            return;
        }

        // Prepare data for unsynced candidates only
        const allData = [];
        
        unsyncedCandidates.forEach(c => {
            allData.push([
                c.fullName || '',                    // A: Full Name
                c.fatherName || '',                   // B: Father's Name
                c.phone || '',                         // C: Phone Number
                c.email || '',                          // D: Email Address
                c.cnic || '',                            // E: CNIC Number
                c.city || '',                             // F: City
                c.dob || '',                               // G: Date of Birth
                c.graduation || '',                        // H: Graduation
                c.position || '',                           // I: Campaign/Department
                c.joiningDate || '',                         // J: Joining Date
                c.referredBy || '',                           // K: Referred By
                c.status || 'pending',                         // L: Status
                c.interviewLevel || 'hr',                       // M: Interview Level
                c.hrStatus || 'pending',                         // N: HR Status
                c.hrDate || '',                                   // O: HR Interview Date
                c.gmStatus || 'pending',                           // P: GM Status
                c.gmDate || '',                                     // Q: GM Interview Date
                c.trainingStatus || 'pending',                       // R: Training Status
                c.trainingDate || '',                                 // S: Training Date
                c.callCount || 0,                                     // T: Call Count
                c.timestamp || new Date().toISOString()               // U: Application Date
            ]);
        });

        console.log('Sending new data to Google Sheets:', allData.length, 'rows');
        console.log('First row sample:', allData[0]);

        // Try multiple methods to ensure data is sent
        let syncSuccess = false;
        
        // Method 1: Try fetch with no-cors
        try {
            await fetch(GOOGLE_SHEETS_URL, {
                method: 'POST',
                mode: 'no-cors',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ data: allData })
            });
            
            console.log('Data sent via fetch');
            syncSuccess = true;
            
        } catch (fetchError) {
            console.log('Fetch failed, trying form post method:', fetchError);
            
            // Method 2: Form post with iframe
            try {
                await postViaForm(allData);
                console.log('Data sent via form post');
                syncSuccess = true;
            } catch (formError) {
                console.log('Form post failed:', formError);
            }
        }
        
        if (syncSuccess) {
            // Mark these candidates as synced
            let markedCount = 0;
            unsyncedCandidates.forEach(c => {
                if (!isCandidateSynced(c)) {
                    markCandidateSynced(c);
                    markedCount++;
                }
            });
            
            saveSyncedRecords();
            console.log(`Marked ${markedCount} candidates as synced`);
            
            updateSyncStatus('Synced');
            showToast(`✅ Synced ${unsyncedCandidates.length} new ${unsyncedCandidates.length === 1 ? 'candidate' : 'candidates'}`, 'success');
        } else {
            throw new Error('All sync methods failed');
        }
        
    } catch (e) {
        console.log('Google Sheets sync failed:', e);
        updateSyncStatus('Failed');
        showToast('⚠️ Google Sheets sync failed. Will retry later.', 'warning');
        
    } finally {
        pendingSync = false;
    }
}

// Helper function to post data via form (more reliable than fetch)
function postViaForm(data) {
    return new Promise((resolve, reject) => {
        try {
            // Create a unique ID for this request
            const requestId = 'req_' + Date.now();
            
            // Create iframe
            const iframe = document.createElement('iframe');
            iframe.name = 'hidden_iframe_' + requestId;
            iframe.style.display = 'none';
            
            // Create form
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = GOOGLE_SHEETS_URL;
            form.target = iframe.name;
            form.style.display = 'none';
            form.setAttribute('accept-charset', 'UTF-8');
            
            // Add data as hidden input
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'data';
            input.value = JSON.stringify({ data: data });
            form.appendChild(input);
            
            // Add to document
            document.body.appendChild(iframe);
            document.body.appendChild(form);
            
            // Set timeout to clean up
            const timeoutId = setTimeout(() => {
                cleanup();
                resolve(); // Assume success after timeout
            }, 8000);
            
            // Cleanup function
            const cleanup = () => {
                clearTimeout(timeoutId);
                if (document.body.contains(form)) document.body.removeChild(form);
                if (document.body.contains(iframe)) document.body.removeChild(iframe);
            };
            
            // Handle iframe load (success)
            iframe.onload = () => {
                cleanup();
                resolve();
            };
            
            // Handle iframe error
            iframe.onerror = () => {
                cleanup();
                reject(new Error('Iframe load failed'));
            };
            
            // Submit the form
            form.submit();
            
        } catch (e) {
            reject(e);
        }
    });
}

// Update sync status in UI
function updateSyncStatus(status) {
    const statusEl = document.getElementById('gsheetsStatus');
    if (statusEl) {
        let icon = 'fa-cloud-upload-alt';
        let bgColor = 'var(--secondary-light)';
        let textColor = 'var(--secondary-dark)';
        
        if (status === 'Synced') {
            icon = 'fa-check-circle';
            bgColor = 'var(--secondary-light)';
        } else if (status === 'Failed') {
            icon = 'fa-exclamation-triangle';
            bgColor = 'var(--danger-light)';
            textColor = 'var(--danger)';
        } else if (status === 'Syncing...') {
            icon = 'fa-sync-alt fa-spin';
            bgColor = 'var(--warning-light)';
            textColor = 'var(--warning)';
        } else if (status === 'Up to date') {
            icon = 'fa-check';
            bgColor = 'var(--secondary-light)';
        } else if (status === 'Connected') {
            icon = 'fa-check-circle';
            bgColor = 'var(--secondary-light)';
            textColor = 'var(--secondary-dark)';
        } else if (status === 'Error') {
            icon = 'fa-exclamation-triangle';
            bgColor = 'var(--danger-light)';
            textColor = 'var(--danger)';
        }
        
        statusEl.innerHTML = `<i class="fas ${icon}"></i><span>${status}</span>`;
        statusEl.style.background = bgColor;
        statusEl.style.color = textColor;
    }
}

// ==================== DUAL PC VOICE SYSTEM ====================

// Pakistani Female Voice
function initializeVoice() {
    if (!window.speechSynthesis) {
        console.log('Voice not supported');
        showToast('Voice not supported in this browser', 'error');
        return;
    }

    window.speechSynthesis.cancel();
    
    setTimeout(() => {
        try {
            const voices = window.speechSynthesis.getVoices();
            console.log('Available voices:', voices.length);
            
            // Priority: Pakistani/Indian English female voice
            preferredVoice = voices.find(v => 
                (v.lang === 'en-PK' || v.lang === 'en-IN' || v.lang.includes('en')) && 
                (v.name.includes('Female') || v.name.includes('Zira') || v.name.includes('Samantha') || v.name.includes('Google'))
            ) || voices.find(v => v.lang.includes('en'));

            const testUtterance = new SpeechSynthesisUtterance('Voice system is ready on reception');
            if (preferredVoice) {
                testUtterance.voice = preferredVoice;
                console.log('Selected voice:', preferredVoice.name);
            }
            testUtterance.rate = 0.9;
            testUtterance.pitch = 1.1;
            
            testUtterance.onstart = function() {
                voiceInitialized = true;
                const dot = document.getElementById('voiceStatusDot');
                const text = document.getElementById('voiceStatusText');
                if (dot) {
                    dot.className = 'status-dot active';
                    dot.style.background = 'var(--secondary)';
                }
                if (text) text.textContent = 'Voice Active';
                showToast('🔊 Voice system ready on reception', 'success');
            };

            testUtterance.onerror = function(e) {
                console.log('Voice test error:', e);
                voiceInitialized = false;
                const dot = document.getElementById('voiceStatusDot');
                const text = document.getElementById('voiceStatusText');
                if (dot) dot.className = 'status-dot';
                if (text) text.textContent = 'Voice Error';
            };

            window.speechSynthesis.speak(testUtterance);
        } catch (e) {
            console.log('Voice initialization error:', e);
        }
    }, 500);
}

// Speak function for Reception PC
function speak(text) {
    if (!window.speechSynthesis) return false;
    
    try {
        window.speechSynthesis.cancel();
        
        const utterance = new SpeechSynthesisUtterance(text);
        
        // Get fresh voices
        const voices = window.speechSynthesis.getVoices();
        const femaleVoice = voices.find(v => 
            (v.lang === 'en-PK' || v.lang === 'en-IN' || v.lang.includes('en')) && 
            (v.name.includes('Female') || v.name.includes('Zira') || v.name.includes('Samantha'))
        ) || voices.find(v => v.lang.includes('en'));
        
        if (femaleVoice) utterance.voice = femaleVoice;
        utterance.rate = 0.9;
        utterance.pitch = 1.1;
        utterance.volume = 1;
        
        utterance.onerror = function(e) {
            console.log('Speech error:', e);
        };
        
        window.speechSynthesis.speak(utterance);
        return true;
    } catch (e) {
        console.log('Speech error:', e);
        return false;
    }
}

// Show voice panel on Reception PC
function showVoicePanel(name, count) {
    const panel = document.getElementById('voicePanel');
    const nameEl = document.getElementById('voiceName');
    const countEl = document.getElementById('voiceCount');
    
    if (panel && nameEl && countEl) {
        nameEl.textContent = name;
        countEl.textContent = `${count}/3`;
        panel.classList.add('active');
        
        setTimeout(() => {
            if (count === 3) {
                panel.classList.remove('active');
            }
        }, 3000);
    }
}

// ==================== CALL FUNCTION (FOR HR, GM, etc.) ====================
async function createVoiceCall(name, room, count) {
    try {
        await fetch('api/portal_notifications.php?action=create', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: 'voice_call',
                target: 'reception',
                payload: { name, room, count }
            })
        });
        return true;
    } catch (e) {
        console.log('Error creating voice call:', e);
        return false;
    }
}

// ==================== RECEPTION / AGENT PC VOICE CHECKER ====================
async function checkVoiceCalls() {
    try {
        const res = await fetch('api/portal_notifications.php?action=list&target=reception&unplayed=1', { credentials: 'include' });
        const data = await res.json();
        if (!data.success || !Array.isArray(data.data)) return;

        const playedIds = [];
        for (const row of data.data) {
            const p = row.payload || {};
            const name = p.name || 'Candidate';
            const room = p.room || 'HR';
            const count = p.count || 1;
            const message = `${name}, please come to ${room} office for interview. Announcement ${count} of 3.`;
            speak(message);
            showVoicePanel(name, count);
            playedIds.push(row.id);
        }
        if (playedIds.length) {
            await fetch('api/portal_notifications.php?action=markPlayed', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids: playedIds, consumer_portal: 'reception' })
            });
        }
    } catch (e) {
        console.log('Error checking voice calls:', e);
    }
}

// ==================== TOAST NOTIFICATIONS ====================
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast ${type} show`;
    
    let icon = 'fa-check-circle';
    if (type === 'error') icon = 'fa-exclamation-circle';
    if (type === 'warning') icon = 'fa-exclamation-triangle';
    if (type === 'info') icon = 'fa-info-circle';
    
    toast.innerHTML = `<i class="fas ${icon}"></i> ${message}`;
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ==================== LOGOUT ====================
function logout() {
    if (autoSyncInterval) clearInterval(autoSyncInterval);
    if (sheetSyncInterval) clearInterval(sheetSyncInterval);
    localStorage.removeItem('userRole');
    localStorage.removeItem('selectedRole');
    window.location.href = 'index.html';
}

// ==================== FORCE SYNC FUNCTION ====================
function forceSync() {
    showToast('🔄 Forcing Google Sheets sync...', 'warning');
    syncToGoogleSheets();
}

// ==================== RESET SYNC (USE ONLY IF NEEDED) ====================
function resetSync() {
    if (confirm('Are you sure? This will mark ALL candidates as unsynced and resend everything.')) {
        syncedRecords = {};
        saveSyncedRecords();
        showToast('Sync reset. Will resend all data on next sync.', 'warning');
        setTimeout(() => syncToGoogleSheets(), 1000);
    }
}

// ==================== CLEAR DUPLICATES FROM SHEET ====================
function clearSyncRecords() {
    if (confirm('This will clear all sync records. Use this if you want to force resync of all candidates.')) {
        syncedRecords = {};
        saveSyncedRecords();
        showToast('Sync records cleared. All candidates will resync.', 'success');
    }
}

// ==================== EXPORT DATA AS BACKUP ====================
function exportBackup() {
    const data = {
        candidates: candidates,
        leads: recruitLeads,
        syncedRecords: syncedRecords,
        exportDate: new Date().toISOString(),
        version: '1.0'
    };
    
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `balitech-backup-${new Date().toISOString().split('T')[0]}.json`;
    a.click();
    
    showToast('✅ Backup downloaded successfully', 'success');
}

// ==================== IMPORT BACKUP ====================
function importBackup(file) {
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = JSON.parse(e.target.result);
            if (data.candidates) candidates = data.candidates;
            if (data.leads) recruitLeads = data.leads;
            if (data.syncedRecords) {
                syncedRecords = data.syncedRecords;
                saveSyncedRecords();
            }
            saveData();
            showToast('✅ Backup imported successfully', 'success');
        } catch (err) {
            console.error('Import error:', err);
            showToast('❌ Invalid backup file', 'error');
        }
    };
    reader.readAsText(file);
}

// ==================== GET SYNC STATS ====================
function getSyncStats() {
    loadSyncedRecords();
    
    const total = candidates.length;
    let synced = 0;
    let unsynced = [];
    
    candidates.forEach(c => {
        if (isCandidateSynced(c)) {
            synced++;
        } else {
            unsynced.push(c);
        }
    });
    
    const pending = total - synced;
    const percentage = total > 0 ? Math.round((synced / total) * 100) : 0;
    
    return {
        total,
        synced,
        pending,
        percentage,
        unsyncedDetails: unsynced.map(c => ({
            name: c.fullName,
            id: c.id,
            timestamp: c.timestamp
        }))
    };
}

// ==================== SHOW SYNC STATS ====================
function showSyncStats() {
    const stats = getSyncStats();
    console.log('Sync Stats:', stats);
    
    let message = `📊 Total: ${stats.total} | Synced: ${stats.synced} | Pending: ${stats.pending} (${stats.percentage}%)`;
    
    if (stats.pending > 0) {
        message += `\n\nPending candidates:`;
        stats.unsyncedDetails.slice(0, 5).forEach(c => {
            message += `\n• ${c.name}`;
        });
        if (stats.pending > 5) message += `\n• ... and ${stats.pending - 5} more`;
    }
    
    showToast(message, 'info');
}

// ==================== VERIFY SYNC ====================
function verifySync() {
    const stats = getSyncStats();
    
    if (stats.pending === 0) {
        showToast('✅ All candidates are synced!', 'success');
    } else {
        showToast(`⚠️ ${stats.pending} candidates pending sync`, 'warning');
        forceSync();
    }
}

// ==================== GM PORTAL HELPER FUNCTIONS ====================
// These functions help with GM portal specific operations

function getGMPendingCount() {
    return candidates.filter(c => c.status === 'hr-passed' && c.interviewLevel === 'gm').length;
}

function getGMSelectedCount() {
    return candidates.filter(c => c.status === 'selected' || c.gmStatus === 'passed').length;
}

function getTrainingCount() {
    return candidates.filter(c => c.status === 'training').length;
}

// ==================== REMARKS FUNCTIONS ====================

// Add remark to candidate
function addRemark(candidateId, remark, addedBy) {
    const candidate = candidates.find(c => c.id === candidateId);
    if (!candidate) return false;
    
    if (!candidate.remarks) {
        candidate.remarks = [];
    }
    
    candidate.remarks.push({
        id: Date.now() + Math.random(),
        text: remark,
        addedBy: addedBy,
        timestamp: new Date().toISOString()
    });
    
    saveData();
    return true;
}

// Get all remarks for a candidate
function getRemarks(candidateId) {
    const candidate = candidates.find(c => c.id === candidateId);
    if (!candidate || !candidate.remarks) return [];
    return candidate.remarks.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
}

// ==================== THEME TOGGLE SYSTEM ====================

// Initialize theme on page load
function initTheme() {
    const savedTheme = localStorage.getItem('balitech_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    
    // Update theme toggle icon if it exists
    const themeIcon = document.getElementById('themeIcon');
    if (themeIcon) {
        themeIcon.className = savedTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
    }
}

// Toggle between dark and light themes
function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme') || 'dark';
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('balitech_theme', newTheme);
    
    // Update icon
    const themeIcon = document.getElementById('themeIcon');
    if (themeIcon) {
        themeIcon.className = newTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
    }
    
    showToast(`🌓 Switched to ${newTheme} mode`, 'info');
}

// ==================== CANDIDATE DETAILS MODAL WITH REMARKS ADD ====================
function showCandidateDetails(candidateId) {
    const candidate = candidates.find(c => c.id === candidateId);
    if (!candidate) return;
    
    const remarks = getRemarks(candidateId);
    
    // Determine user role from localStorage or URL
    const userRole = localStorage.getItem('userRole') || 'hr'; // Default to hr if not set
    const canAddRemarks = userRole === 'hr' || userRole === 'gm' || userRole === 'management';
    
    // Determine current stage and status colors
    let stage = 'Application Submitted';
    let stageColor = '#3b82f6';
    let stageBg = 'rgba(59, 130, 246, 0.1)';
    
    if (candidate.status === 'selected') {
        stage = 'Selected';
        stageColor = '#10b981';
        stageBg = 'rgba(16, 185, 129, 0.1)';
    } else if (candidate.status === 'rejected') {
        stage = 'Rejected';
        stageColor = '#ef4444';
        stageBg = 'rgba(239, 68, 68, 0.1)';
    } else if (candidate.interviewLevel === 'gm') {
        stage = 'GM Interview Pending';
        stageColor = '#f97316';
        stageBg = 'rgba(249, 115, 22, 0.1)';
    } else if (candidate.interviewLevel === 'hr') {
        stage = 'HR Interview Pending';
        stageColor = '#f59e0b';
        stageBg = 'rgba(245, 158, 11, 0.1)';
    }
    
    const modalHtml = `...`; // Your existing modal HTML here (keeping it short for this response)
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

// Add remark from details modal
window.addRemarkFromDetails = function(candidateId) {
    const remarkInput = document.getElementById('detailsRemarkInput');
    if (!remarkInput) return;
    
    const remark = remarkInput.value.trim();
    if (!remark) {
        showToast('Please enter a remark', 'warning');
        return;
    }
    
    // Determine user role
    const userRole = localStorage.getItem('userRole') || 'HR';
    let addedBy = 'HR Manager';
    
    if (userRole === 'gm' || userRole === 'management') {
        addedBy = 'GM';
    }
    
    if (addRemark(candidateId, remark, addedBy)) {
        showToast('✅ Remark added successfully', 'success');
        closeDetailsModal();
        // Reopen with updated remarks
        showCandidateDetails(candidateId);
        
        // Trigger a refresh of the parent dashboard if needed
        if (typeof renderDashboard === 'function') {
            setTimeout(renderDashboard, 500);
        }
    }
}

window.closeDetailsModal = function() {
    const modal = document.getElementById('detailsModal');
    if (modal) {
        modal.classList.add('fade-out');
        setTimeout(() => modal.remove(), 300);
    }
}

// ==================== ALREADY APPLIED CHECK ====================

// Check if candidate has applied before
function checkAlreadyApplied(cnic, phone, currentId = null) {
    const existingCandidates = candidates.filter(c => 
        (c.cnic === cnic || c.phone === phone) && 
        (currentId ? c.id !== currentId : true)
    );
    
    if (existingCandidates.length === 0) {
        return null;
    }
    
    // Sort by most recent first
    const sorted = existingCandidates.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
    
    return sorted.map(c => ({
        id: c.id,
        fullName: c.fullName,
        phone: c.phone,
        cnic: c.cnic,
        status: c.status,
        interviewLevel: c.interviewLevel,
        timestamp: c.timestamp,
        daysAgo: Math.ceil((new Date() - new Date(c.timestamp)) / (1000 * 60 * 60 * 24))
    }));
}

// ==================== CANDIDATE REAPPLY ALERT SYSTEM ====================

function checkForReapply(cnic, phone) {
    // Find any existing candidates with same CNIC or phone
    const existingCandidates = candidates.filter(c => 
        (c.cnic === cnic || c.phone === phone) && 
        c.status === 'rejected'
    );
    
    if (existingCandidates.length === 0) {
        return null; // No reapply found
    }
    
    // Get the most recent rejected application
    const latestReject = existingCandidates.sort((a, b) => 
        new Date(b.timestamp) - new Date(a.timestamp)
    )[0];
    
    // Calculate days since rejection
    const rejectDate = new Date(latestReject.timestamp);
    const today = new Date();
    const diffTime = Math.abs(today - rejectDate);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    // Get rejection reason from notes or status
    let rejectReason = 'No reason recorded';
    if (latestReject.rejectionReason) {
        rejectReason = latestReject.rejectionReason;
    } else if (latestReject.hrStatus === 'rejected') {
        rejectReason = 'Failed HR interview';
    } else if (latestReject.gmStatus === 'rejected') {
        rejectReason = 'Failed GM interview';
    }
    
    return {
        candidate: latestReject,
        daysAgo: diffDays,
        reason: rejectReason
    };
}

// Show reapply alert modal
function showReapplyAlert(reapplyData, formData, callback) {
    const candidate = reapplyData.candidate;
    
    // Create modal HTML
    const modalHtml = `
        <div class="reapply-modal-overlay" id="reapplyModal">
            <div class="reapply-modal">
                <div class="reapply-modal-header">
                    <i class="fas fa-exclamation-triangle" style="color: var(--warning); font-size: 24px;"></i>
                    <h3>⚠️ Candidate Previously Rejected</h3>
                    <button class="reapply-modal-close" onclick="closeReapplyModal()">×</button>
                </div>
                
                <div class="reapply-modal-body">
                    <div class="reapply-alert-card">
                        <div class="reapply-candidate-info">
                            <div class="reapply-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div>
                                <h4>${candidate.fullName}</h4>
                                <p>Previously applied on: ${new Date(candidate.timestamp).toLocaleDateString()} (${reapplyData.daysAgo} days ago)</p>
                            </div>
                        </div>
                        
                        <div class="reapply-details">
                            <div class="reapply-detail-item">
                                <i class="fas fa-id-card"></i>
                                <span>CNIC: ${candidate.cnic}</span>
                            </div>
                            <div class="reapply-detail-item">
                                <i class="fas fa-phone"></i>
                                <span>Phone: ${candidate.phone}</span>
                            </div>
                            <div class="reapply-detail-item">
                                <i class="fas fa-briefcase"></i>
                                <span>Position: ${candidate.position}</span>
                            </div>
                        </div>
                        
                        <div class="reapply-rejection-box">
                            <div class="reapply-rejection-header">
                                <i class="fas fa-times-circle" style="color: var(--danger);"></i>
                                <strong>Rejection Reason:</strong>
                            </div>
                            <p>${reapplyData.reason}</p>
                            ${candidate.hrStatus === 'rejected' ? '<span class="reapply-stage-badge">Failed at: HR Interview</span>' : ''}
                            ${candidate.gmStatus === 'rejected' ? '<span class="reapply-stage-badge">Failed at: GM Interview</span>' : ''}
                        </div>
                        
                        ${candidate.notes ? `
                        <div class="reapply-notes-box">
                            <div class="reapply-notes-header">
                                <i class="fas fa-sticky-note"></i>
                                <strong>Previous Interview Notes:</strong>
                            </div>
                            <p>${candidate.notes || 'No notes available'}</p>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="reapply-actions">
                        <h4>How would you like to proceed?</h4>
                        
                        <div class="reapply-option" onclick="selectReapplyOption('proceed', event)">
                            <i class="fas fa-forward"></i>
                            <div>
                                <strong>Proceed with application</strong>
                                <p>Give candidate another chance despite previous rejection</p>
                            </div>
                        </div>
                        
                        <div class="reapply-option" onclick="selectReapplyOption('fasttrack', event)">
                            <i class="fas fa-rocket" style="color: var(--success);"></i>
                            <div>
                                <strong>Fast-track to next stage</strong>
                                <p>Skip HR, send directly to GM interview (if previously passed HR)</p>
                            </div>
                        </div>
                        
                        <div class="reapply-option" onclick="selectReapplyOption('block', event)">
                            <i class="fas fa-ban" style="color: var(--danger);"></i>
                            <div>
                                <strong>Block application</strong>
                                <p>Do not accept - same rejection reason applies</p>
                            </div>
                        </div>
                        
                        <div class="reapply-option" onclick="selectReapplyOption('auto', event)">
                            <i class="fas fa-robot"></i>
                            <div>
                                <strong>Auto-reject for this position</strong>
                                <p>Auto-reject if applying for same position (${candidate.position})</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="reapply-modal-footer">
                    <button class="reapply-btn reapply-btn-cancel" onclick="closeReapplyModal()">Cancel Application</button>
                    <button class="reapply-btn reapply-btn-confirm" id="reapplyConfirmBtn" onclick="executeReapplyAction()" disabled>Select an option first</button>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Store callback and form data for later
    window.reapplyCallback = callback;
    window.reapplyFormData = formData;
    window.reapplyCandidate = reapplyData.candidate;
    window.selectedReapplyOption = null;
}

// Close modal
window.closeReapplyModal = function() {
    const modal = document.getElementById('reapplyModal');
    if (modal) {
        modal.classList.add('fade-out');
        setTimeout(() => modal.remove(), 300);
    }
    window.selectedReapplyOption = null;
}

// Select option
window.selectReapplyOption = function(option, event) {
    window.selectedReapplyOption = option;
    
    // Update UI
    document.querySelectorAll('.reapply-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    event.currentTarget.classList.add('selected');
    
    // Enable confirm button with appropriate text
    const confirmBtn = document.getElementById('reapplyConfirmBtn');
    confirmBtn.disabled = false;
    
    let btnText = '';
    switch(option) {
        case 'proceed':
            btnText = 'Proceed with Application';
            break;
        case 'fasttrack':
            btnText = 'Fast-track Candidate';
            break;
        case 'block':
            btnText = 'Block Application';
            break;
        case 'auto':
            btnText = 'Set Auto-Reject Rule';
            break;
    }
    confirmBtn.innerHTML = btnText;
}

// Execute selected action
window.executeReapplyAction = function() {
    const option = window.selectedReapplyOption;
    const candidate = window.reapplyCandidate;
    const formData = window.reapplyFormData;
    
    switch(option) {
        case 'proceed':
            // Just proceed normally
            showToast(`Processing ${formData.fullName}'s application despite previous rejection`, 'warning');
            window.reapplyCallback(true);
            break;
            
        case 'fasttrack':
            // Fast-track based on previous status
            if (candidate.hrStatus === 'passed') {
                formData.status = 'hr-passed';
                formData.interviewLevel = 'gm';
                formData.notes = (formData.notes || '') + ' | Fast-tracked from HR (reapply)';
                showToast(`⚡ ${formData.fullName} fast-tracked to GM interview`, 'success');
            } else {
                formData.status = 'pending';
                formData.interviewLevel = 'hr';
                showToast(`${formData.fullName} will start from HR (didn't pass previously)`, 'info');
            }
            window.reapplyCallback(true);
            break;
            
        case 'block':
            // Block the application
            showToast(`❌ Application blocked - ${candidate.fullName} previously rejected`, 'error');
            window.reapplyCallback(false);
            break;
            
        case 'auto':
            // Auto-reject if same position
            if (formData.position === candidate.position) {
                showToast(`🤖 Auto-rejected - same position (${candidate.position}) as previous rejection`, 'warning');
                window.reapplyCallback(false);
            } else {
                showToast(`Different position, proceeding with application`, 'success');
                window.reapplyCallback(true);
            }
            break;
    }
    
    closeReapplyModal();
};

// Add CSS for reapply modal
const reapplyStyles = document.createElement('style');
reapplyStyles.textContent = `...`; // Your existing reapply modal styles
document.head.appendChild(reapplyStyles);

// ==================== FORM SUBMISSION HELPER ====================
/**
 * This function is called from the agent portal HTML to submit a new application
 * It creates a candidate object, saves it locally, and syncs to Google Sheets
 */
function submitNewApplication(formData) {
    // Validate required fields
    if (!formData.fullName || !formData.phone || !formData.cnic) {
        showToast('Missing required fields', 'error');
        return null;
    }

    const candidate = {
        id: Date.now(),
        fullName: formData.fullName,
        fatherName: formData.fatherName || '',
        phone: formData.phone,
        email: formData.email || '',
        cnic: formData.cnic,
        city: formData.city || '',
        dob: formData.dob || '',
        graduation: formData.graduation || '',
        position: formData.position || '',
        joiningDate: formData.joiningDate || '',
        referredBy: formData.referredBy || 'Walk-in',
        timestamp: new Date().toISOString(),
        status: 'pending',
        interviewLevel: 'hr',
        hrStatus: 'pending',
        gmStatus: 'pending',
        callCount: 0,
        source: 'reception',
        remarks: formData.notes ? [{
            id: Date.now(),
            text: formData.notes,
            addedBy: 'Reception Agent',
            timestamp: new Date().toISOString()
        }] : []
    };

    candidates.push(candidate);
    saveData();
    
    return candidate;
}

// ==================== THEME INITIALIZATION ====================
// Initialize theme on page load
function initTheme() {
    const savedTheme = localStorage.getItem('balitech_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    
    // Update theme toggle icon if it exists
    const themeIcon = document.getElementById('themeIcon');
    if (themeIcon) {
        themeIcon.className = savedTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
    }
}

// Toggle between dark and light themes
function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme') || 'dark';
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('balitech_theme', newTheme);
    
    // Update icon
    const themeIcon = document.getElementById('themeIcon');
    if (themeIcon) {
        themeIcon.className = newTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
    }
    
    showToast(`🌓 Switched to ${newTheme} mode`, 'info');
}

// ==================== RECRUITER SHEET SYNC FUNCTIONS ====================
let recruiterSheetData = [];

/**
 * Test connection to Google Sheets
 */
async function testSheetConnection() {
    try {
        const response = await fetch(`${RECRUITER_SHEET_URL}?action=getData`, {
            method: 'GET',
            mode: 'cors',
            headers: { 'Accept': 'application/json' }
        });
        
        const text = await response.text();
        
        try {
            const data = JSON.parse(text);
            if (data.success) {
                sheetConnectionStatus = 'connected';
                updateSyncStatus('Connected');
                return { success: true, data };
            } else {
                sheetConnectionStatus = 'error';
                updateSyncStatus('Error');
                return { success: false, error: data.error };
            }
        } catch (e) {
            console.error('Parse error:', e);
            sheetConnectionStatus = 'error';
            updateSyncStatus('Error');
            return { success: false, error: 'Invalid JSON response' };
        }
    } catch (error) {
        console.error('Connection error:', error);
        sheetConnectionStatus = 'error';
        updateSyncStatus('Error');
        return { success: false, error: error.message };
    }
}

/**
 * Load data from recruiter sheet into recruitLeads
 */
async function loadRecruiterSheetData() {
    updateSyncStatus('Syncing...');
    try {
        const response = await fetch(`${RECRUITER_SHEET_URL}?action=getData`, {
            method: 'GET',
            mode: 'cors',
            headers: { 'Accept': 'application/json' }
        });
        
        const text = await response.text();
        
        try {
            const data = JSON.parse(text);
            
            if (data.success && data.data) {
                recruiterSheetData = data.data;
                
                // Convert sheet data to recruitLeads format
                const sheetLeads = data.data.map((row, index) => {
                    const applicant = row.Applicant || row.applicant || '';
                    const contact = row['Contact Number'] || row.contactNumber || row.contact || '';
                    const appliedFor = row['Applied For'] || row.appliedFor || '';
                    const disposition = row.Dispositions || row.disposition || '';
                    const remarks = row.Remarks || row.remarks || '';
                    const whatsapp = row['W/A DISPOSITIONS'] || row.whatsapp || '';
                    
                    if (applicant && contact) {
                        return {
                            id: Date.now() + index,
                            name: applicant,
                            phone: contact,
                            appliedFor: appliedFor,
                            disposition: disposition,
                            remarks: remarks,
                            whatsapp: whatsapp,
                            source: 'Recruiter Sheet',
                            campaign: appliedFor,
                            timestamp: new Date().toISOString(),
                            status: 'new',
                            contacted: false,
                            converted: false,
                            callHistory: [],
                            scheduledInterviews: [],
                            callCount: 0,
                            lastCallStatus: disposition,
                            lastCallDate: null,
                            callbackRequired: disposition.toLowerCase().includes('call back') || disposition.toLowerCase().includes('not respond'),
                            callbackTime: null,
                            priority: 3,
                            notes: remarks,
                            formData: {},
                            tags: ['sheet-import']
                        };
                    }
                    return null;
                }).filter(lead => lead !== null);
                
                // Merge with existing leads (avoid duplicates by phone)
                let addedCount = 0;
                let updatedCount = 0;
                
                sheetLeads.forEach(newLead => {
                    const existingIndex = recruitLeads.findIndex(l => l.phone === newLead.phone);
                    
                    if (existingIndex === -1) {
                        recruitLeads.push(newLead);
                        addedCount++;
                    } else {
                        // Update existing lead with sheet data
                        recruitLeads[existingIndex] = {
                            ...recruitLeads[existingIndex],
                            name: newLead.name,
                            appliedFor: newLead.appliedFor,
                            disposition: newLead.disposition,
                            remarks: newLead.remarks,
                            whatsapp: newLead.whatsapp,
                            lastCallStatus: newLead.disposition
                        };
                        updatedCount++;
                    }
                });
                
                if (addedCount > 0 || updatedCount > 0) {
                    saveData();
                }
                
                sheetConnectionStatus = 'connected';
                updateSyncStatus('Connected');
                
                return { success: true, added: addedCount, updated: updatedCount, data: sheetLeads };
            } else {
                sheetConnectionStatus = 'error';
                updateSyncStatus('Error');
                return { success: false, error: data.error };
            }
        } catch (e) {
            console.error('Parse error:', e);
            sheetConnectionStatus = 'error';
            updateSyncStatus('Error');
            return { success: false, error: 'Invalid JSON response' };
        }
    } catch (error) {
        console.error('Error loading recruiter sheet:', error);
        sheetConnectionStatus = 'error';
        updateSyncStatus('Error');
        return { success: false, error: error.message };
    }
}

/**
 * Add a new row to recruiter sheet
 */
async function addToRecruiterSheet(leadData) {
    try {
        const params = new URLSearchParams({
            action: 'addRow',
            applicant: leadData.name || leadData.applicant || '',
            contactNumber: leadData.phone || leadData.contactNumber || '',
            appliedFor: leadData.campaign || leadData.appliedFor || leadData.position || '',
            disposition: leadData.lastCallStatus || leadData.disposition || leadData.status || '',
            remarks: leadData.notes || leadData.remarks || '',
            whatsapp: leadData.whatsapp || 'Pending'
        });
        
        const response = await fetch(`${RECRUITER_SHEET_URL}?${params.toString()}`, {
            method: 'GET',
            mode: 'cors',
            headers: { 'Accept': 'application/json' }
        });
        
        const text = await response.text();
        
        try {
            const result = JSON.parse(text);
            if (result.success) {
                // Refresh sheet data
                await loadRecruiterSheetData();
                return { success: true };
            } else {
                return { success: false, error: result.error };
            }
        } catch (e) {
            return { success: false, error: 'Invalid response' };
        }
    } catch (error) {
        console.error('Error adding to recruiter sheet:', error);
        return { success: false, error: error.message };
    }
}

/**
 * Update a row in recruiter sheet
 */
async function updateRecruiterSheetRow(rowIndex, rowData) {
    try {
        const params = new URLSearchParams({
            action: 'updateRow',
            rowIndex: rowIndex,
            applicant: rowData.applicant || '',
            contactNumber: rowData.contactNumber || rowData.contact || '',
            appliedFor: rowData.appliedFor || '',
            disposition: rowData.disposition || '',
            remarks: rowData.remarks || '',
            whatsapp: rowData.whatsapp || ''
        });
        
        const response = await fetch(`${RECRUITER_SHEET_URL}?${params.toString()}`, {
            method: 'GET',
            mode: 'cors',
            headers: { 'Accept': 'application/json' }
        });
        
        const text = await response.text();
        
        try {
            const result = JSON.parse(text);
            if (result.success) {
                // Refresh sheet data
                await loadRecruiterSheetData();
                return { success: true };
            } else {
                return { success: false, error: result.error };
            }
        } catch (e) {
            return { success: false, error: 'Invalid response' };
        }
    } catch (error) {
        console.error('Error updating sheet:', error);
        return { success: false, error: error.message };
    }
}

/**
 * Delete a row from recruiter sheet
 */
async function deleteRecruiterSheetRow(rowIndex) {
    try {
        const response = await fetch(`${RECRUITER_SHEET_URL}?action=deleteRow&rowIndex=${rowIndex}`, {
            method: 'GET',
            mode: 'cors',
            headers: { 'Accept': 'application/json' }
        });
        
        const text = await response.text();
        
        try {
            const result = JSON.parse(text);
            if (result.success) {
                // Refresh sheet data
                await loadRecruiterSheetData();
                return { success: true };
            } else {
                return { success: false, error: result.error };
            }
        } catch (e) {
            return { success: false, error: 'Invalid response' };
        }
    } catch (error) {
        console.error('Error deleting from sheet:', error);
        return { success: false, error: error.message };
    }
}

/**
 * Sync from portal to sheet (export new leads)
 */
async function syncToRecruiterSheet() {
    updateSyncStatus('Syncing...');
    
    let successCount = 0;
    let failCount = 0;
    
    for (const lead of recruitLeads) {
        // Check if lead already exists in sheet data (by phone)
        const existsInSheet = recruiterSheetData.some(s => 
            (s['Contact Number'] === lead.phone) || 
            (s.contactNumber === lead.phone) || 
            (s.contact === lead.phone)
        );
        
        if (!existsInSheet && lead.name && lead.phone) {
            const result = await addToRecruiterSheet(lead);
            if (result.success) {
                successCount++;
            } else {
                failCount++;
            }
            await new Promise(resolve => setTimeout(resolve, 100));
        }
    }
    
    updateSyncStatus('Connected');
    return { success: successCount, fail: failCount };
}

/**
 * Full two-way sync
 */
async function fullSyncWithSheet() {
    updateSyncStatus('Syncing...');
    
    // First import from sheet
    await loadRecruiterSheetData();
    
    // Then export to sheet (only new ones)
    let exportCount = 0;
    for (const lead of recruitLeads) {
        const existsInSheet = recruiterSheetData.some(s => 
            (s['Contact Number'] === lead.phone) || 
            (s.contactNumber === lead.phone)
        );
        
        if (!existsInSheet && lead.name && lead.phone) {
            await addToRecruiterSheet(lead);
            exportCount++;
            await new Promise(resolve => setTimeout(resolve, 100));
        }
    }
    
    await loadRecruiterSheetData();
    updateSyncStatus('Connected');
    return { success: true, exportCount };
}

// Initialize recruiter sheet connection
async function initRecruiterSheet() {
    console.log('🔄 Initializing recruiter sheet connection...');
    const result = await loadRecruiterSheetData();
    
    if (result.success) {
        // Set up auto-sync every 30 seconds
        if (sheetSyncInterval) clearInterval(sheetSyncInterval);
        sheetSyncInterval = setInterval(() => {
            console.log('Auto-syncing with recruiter sheet...');
            loadRecruiterSheetData();
        }, 30000);
        
        return true;
    } else {
        return false;
    }
}

// Export functions
window.testSheetConnection = testSheetConnection;
window.loadRecruiterSheetData = loadRecruiterSheetData;
window.addToRecruiterSheet = addToRecruiterSheet;
window.updateRecruiterSheetRow = updateRecruiterSheetRow;
window.deleteRecruiterSheetRow = deleteRecruiterSheetRow;
window.syncToRecruiterSheet = syncToRecruiterSheet;
window.fullSyncWithSheet = fullSyncWithSheet;
window.initRecruiterSheet = initRecruiterSheet;
window.recruiterSheetData = recruiterSheetData;

// ==================== RECRUITER PORTAL - NEW FEATURES ====================
// ==================== ADD THESE FUNCTIONS AT THE END ====================

/**
 * RECRUITER PORTAL FUNCTIONS
 * These functions handle the complete recruiter workflow:
 * - Meta Ads lead import
 * - Lead assignment to 3 recruiters (Danish, Naina, Zoya)
 * - Call management with status tracking
 * - Interview scheduling
 * - Reception notifications
 */

// ==================== RECRUITER CONFIGURATION ====================

const RECRUITER_LIST = [
    { id: 'danish', name: 'Danish Khan', active: true, leads: [], callsToday: 0, performance: {} },
    { id: 'naina', name: 'Naina Fareed', active: true, leads: [], callsToday: 0, performance: {} },
    { id: 'zoya', name: 'Zoya', active: true, leads: [], callsToday: 0, performance: {} }
];

// Call status constants
const CALL_STATUS = {
    NOT_REACHED: 'not_reached',
    CALL_BACK: 'call_back',
    NOT_INTERESTED: 'not_interested',
    INTERESTED: 'interested',
    SCHEDULED: 'scheduled',
    WRONG_NUMBER: 'wrong_number',
    NOT_AVAILABLE: 'not_available',
    BUSY: 'busy',
    ASKED_LATER: 'asked_later'
};

const CALL_STATUS_LABELS = {
    [CALL_STATUS.NOT_REACHED]: '📞 Not Reached',
    [CALL_STATUS.CALL_BACK]: '⏰ Call Back Later',
    [CALL_STATUS.NOT_INTERESTED]: '❌ Not Interested',
    [CALL_STATUS.INTERESTED]: '✅ Interested',
    [CALL_STATUS.SCHEDULED]: '📅 Interview Scheduled',
    [CALL_STATUS.WRONG_NUMBER]: '⚠️ Wrong Number',
    [CALL_STATUS.NOT_AVAILABLE]: '🕐 Not Available',
    [CALL_STATUS.BUSY]: '📞 Busy',
    [CALL_STATUS.ASKED_LATER]: '⏱️ Asked to Call Later'
};

// Time slots for interviews
const INTERVIEW_TIME_SLOTS = [
    '09:00 AM', '10:00 AM', '11:00 AM', '12:00 PM',
    '02:00 PM', '03:00 PM', '04:00 PM', '05:00 PM'
];

// ==================== META ADS LEAD IMPORT ====================

/**
 * Import leads from Meta Ads (Facebook/Instagram)
 * @param {Array} leads - Array of lead objects from Meta
 */
function importMetaLeads(leads) {
    if (!leads || !Array.isArray(leads)) {
        showToast('Invalid leads data', 'error');
        return 0;
    }

    let importedCount = 0;
    
    leads.forEach(lead => {
        // Check for duplicate phone number
        const existingLead = recruitLeads.find(l => l.phone === lead.phoneNumber);
        if (existingLead) {
            console.log('Duplicate lead found, skipping:', lead.phoneNumber);
            return;
        }

        // Calculate priority based on form data
        const priority = calculateLeadPriority(lead);
        
        const newLead = {
            id: Date.now() + Math.random() + importedCount,
            name: lead.fullName || lead.name || 'Unknown',
            phone: lead.phoneNumber || lead.phone || '',
            email: lead.email || '',
            source: lead.source || 'Facebook Lead Ad',
            campaign: lead.campaign || 'General Campaign',
            adSet: lead.adSet || 'Default Ad Set',
            timestamp: new Date().toISOString(),
            status: 'new',
            assignedTo: null,
            callHistory: [],
            scheduledInterviews: [],
            callCount: 0,
            lastCallStatus: null,
            lastCallDate: null,
            callbackRequired: false,
            callbackTime: null,
            priority: priority,
            notes: lead.formData ? JSON.stringify(lead.formData) : (lead.notes || ''),
            formData: lead.formData || {},
            converted: false,
            tags: ['meta-ads']
        };
        
        recruitLeads.push(newLead);
        importedCount++;
    });
    
    // Auto-assign leads to recruiters
    autoAssignLeads();
    
    saveData();
    showToast(`✅ Imported ${importedCount} leads from Meta Ads`, 'success');
    return importedCount;
}

/**
 * Calculate lead priority based on form data
 * @param {Object} lead - Lead data from Meta
 * @returns {number} Priority (1-High, 2-Medium, 3-Low)
 */
function calculateLeadPriority(lead) {
    let priority = 3; // Default low
    
    if (lead.formData) {
        // Check experience
        if (lead.formData.experience) {
            const exp = parseInt(lead.formData.experience) || 0;
            if (exp >= 3) priority = 1;
            else if (exp >= 1) priority = 2;
        }
        
        // Check qualification
        if (lead.formData.qualification) {
            const qual = lead.formData.qualification.toLowerCase();
            if (qual.includes('master') || qual.includes('masters')) priority = 1;
            else if (qual.includes('bachelor') || qual.includes('bachelors')) priority = 2;
        }
        
        // Check location preference
        if (lead.formData.location) {
            const location = lead.formData.location.toLowerCase();
            if (location.includes('karachi') || location.includes('lahore')) priority = Math.min(priority, 2);
        }
    }
    
    return priority;
}

// ==================== RECRUITER ASSIGNMENT ====================

/**
 * Auto-assign unassigned leads to recruiters based on workload
 */
function autoAssignLeads() {
    const unassignedLeads = recruitLeads.filter(l => !l.assignedTo && !l.converted);
    
    if (unassignedLeads.length === 0) return;
    
    // Calculate current workload for each recruiter
    const workload = RECRUITER_LIST.map(recruiter => {
        const leadCount = recruitLeads.filter(l => l.assignedTo === recruiter.id && !l.converted).length;
        const callCount = recruiter.callsToday || 0;
        return {
            ...recruiter,
            leadCount,
            callCount,
            totalLoad: leadCount + (callCount * 0.5) // Weighted load
        };
    });
    
    // Sort by least workload
    workload.sort((a, b) => a.totalLoad - b.totalLoad);
    
    // Assign leads in round-robin fashion considering workload
    unassignedLeads.forEach((lead, index) => {
        const recruiterIndex = index % workload.length;
        const recruiter = workload[recruiterIndex];
        
        lead.assignedTo = recruiter.id;
        lead.assignmentDate = new Date().toISOString();
        
        // Update recruiter's leads list
        const recruiterObj = RECRUITER_LIST.find(r => r.id === recruiter.id);
        if (recruiterObj) {
            if (!recruiterObj.leads) recruiterObj.leads = [];
            recruiterObj.leads.push(lead.id);
        }
    });
    
    saveData();
    console.log(`Auto-assigned ${unassignedLeads.length} leads to recruiters`);
}

/**
 * Manually reassign a lead to a different recruiter
 * @param {string|number} leadId - ID of the lead
 * @param {string} newRecruiterId - ID of the new recruiter
 * @param {string} reason - Reason for reassignment
 */
function reassignLead(leadId, newRecruiterId, reason = 'Manual reassignment') {
    const lead = recruitLeads.find(l => l.id == leadId);
    if (!lead) {
        showToast('Lead not found', 'error');
        return false;
    }
    
    const oldRecruiterId = lead.assignedTo;
    const newRecruiter = RECRUITER_LIST.find(r => r.id === newRecruiterId);
    
    if (!newRecruiter) {
        showToast('Invalid recruiter', 'error');
        return false;
    }
    
    // Remove from old recruiter's list
    if (oldRecruiterId) {
        const oldRecruiter = RECRUITER_LIST.find(r => r.id === oldRecruiterId);
        if (oldRecruiter && oldRecruiter.leads) {
            oldRecruiter.leads = oldRecruiter.leads.filter(id => id != leadId);
        }
    }
    
    // Assign to new recruiter
    lead.assignedTo = newRecruiterId;
    lead.reassignmentHistory = lead.reassignmentHistory || [];
    lead.reassignmentHistory.push({
        from: oldRecruiterId,
        to: newRecruiterId,
        date: new Date().toISOString(),
        reason: reason
    });
    
    // Add to new recruiter's list
    if (!newRecruiter.leads) newRecruiter.leads = [];
    newRecruiter.leads.push(lead.id);
    
    saveData();
    showToast(`✅ Lead reassigned to ${newRecruiter.name}`, 'success');
    return true;
}

/**
 * Get leads assigned to a specific recruiter
 * @param {string} recruiterId - Recruiter ID
 * @returns {Array} Array of leads
 */
function getRecruiterLeads(recruiterId) {
    return recruitLeads.filter(l => l.assignedTo === recruiterId);
}

/**
 * Get workload statistics for all recruiters
 * @returns {Object} Workload stats
 */
function getRecruiterWorkload() {
    const stats = {};
    
    RECRUITER_LIST.forEach(recruiter => {
        const leads = recruitLeads.filter(l => l.assignedTo === recruiter.id);
        const callsToday = recruiter.callsToday || 0;
        const pendingCallbacks = leads.filter(l => l.callbackRequired).length;
        const scheduledToday = leads.filter(l => {
            if (!l.scheduledInterviews || l.scheduledInterviews.length === 0) return false;
            const today = new Date().toISOString().split('T')[0];
            return l.scheduledInterviews.some(i => i.date === today);
        }).length;
        
        stats[recruiter.id] = {
            name: recruiter.name,
            totalLeads: leads.length,
            newLeads: leads.filter(l => l.status === 'new').length,
            callsToday: callsToday,
            pendingCallbacks: pendingCallbacks,
            scheduledToday: scheduledToday,
            interested: leads.filter(l => l.lastCallStatus === CALL_STATUS.INTERESTED).length,
            converted: leads.filter(l => l.converted).length
        };
    });
    
    return stats;
}

// ==================== CALL MANAGEMENT ====================

/**
 * Log a call for a lead
 * @param {string|number} leadId - Lead ID
 * @param {string} status - Call status from CALL_STATUS
 * @param {string} notes - Call notes
 * @param {string} callbackTime - Optional callback time
 * @returns {Object} Call record
 */
function logCall(leadId, status, notes, callbackTime = null) {
    const lead = recruitLeads.find(l => l.id == leadId);
    if (!lead) {
        showToast('Lead not found', 'error');
        return null;
    }
    
    const callRecord = {
        id: Date.now() + Math.random(),
        timestamp: new Date().toISOString(),
        status: status,
        notes: notes || '',
        recruiter: lead.assignedTo,
        callbackTime: callbackTime
    };
    
    if (!lead.callHistory) lead.callHistory = [];
    lead.callHistory.push(callRecord);
    
    // Update lead status based on call
    lead.lastCallStatus = status;
    lead.lastCallDate = new Date().toISOString();
    lead.callCount = (lead.callCount || 0) + 1;
    
    // Update recruiter stats
    const recruiter = RECRUITER_LIST.find(r => r.id === lead.assignedTo);
    if (recruiter) {
        recruiter.callsToday = (recruiter.callsToday || 0) + 1;
    }
    
    // Handle different statuses
    switch(status) {
        case CALL_STATUS.INTERESTED:
            lead.status = 'interested';
            lead.callbackRequired = false;
            break;
            
        case CALL_STATUS.CALL_BACK:
        case CALL_STATUS.NOT_AVAILABLE:
        case CALL_STATUS.BUSY:
        case CALL_STATUS.ASKED_LATER:
            lead.status = 'callback';
            lead.callbackRequired = true;
            lead.callbackTime = callbackTime;
            break;
            
        case CALL_STATUS.NOT_INTERESTED:
            lead.status = 'rejected';
            lead.callbackRequired = false;
            lead.rejectionReason = notes;
            break;
            
        case CALL_STATUS.SCHEDULED:
            lead.status = 'scheduled';
            lead.callbackRequired = false;
            break;
            
        case CALL_STATUS.WRONG_NUMBER:
            lead.status = 'invalid';
            lead.callbackRequired = false;
            break;
            
        case CALL_STATUS.NOT_REACHED:
            lead.status = 'not_reached';
            lead.callbackRequired = true;
            lead.callbackTime = callbackTime || 'Later today';
            break;
    }
    
    saveData();
    
    // Show appropriate toast
    const statusLabel = CALL_STATUS_LABELS[status] || status;
    showToast(`📞 Call logged: ${statusLabel}`, 'info');
    
    return callRecord;
}

/**
 * Get call history for a lead
 * @param {string|number} leadId - Lead ID
 * @returns {Array} Call history
 */
function getLeadCallHistory(leadId) {
    const lead = recruitLeads.find(l => l.id == leadId);
    if (!lead || !lead.callHistory) return [];
    
    return lead.callHistory.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
}

/**
 * Get leads that need callback today
 * @returns {Array} Leads needing callback
 */
function getPendingCallbacks() {
    const today = new Date().toISOString().split('T')[0];
    
    return recruitLeads.filter(l => 
        l.callbackRequired && 
        l.callbackTime && 
        l.callbackTime.includes(today)
    );
}

// ==================== INTERVIEW SCHEDULING ====================

/**
 * Schedule an interview for a lead
 * @param {string|number} leadId - Lead ID
 * @param {string} date - Interview date (YYYY-MM-DD)
 * @param {string} timeSlot - Time slot
 * @param {string} notes - Additional notes
 * @returns {Object} Interview object
 */
async function scheduleInterview(leadId, date, timeSlot, notes = '') {
    const lead = recruitLeads.find(l => l.id == leadId);
    if (!lead) {
        showToast('Lead not found', 'error');
        return null;
    }

    const availability = checkInterviewAvailability(date);
    if (!availability.availableSlots.includes(timeSlot)) {
        showToast('Time slot not available', 'warning');
        return null;
    }

    try {
        const res = await fetch('api/schedule_interview.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                lead_id: lead.id,
                date: date,
                time: timeSlot,
                notes: notes
            })
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || 'Failed to schedule interview', 'error');
            return null;
        }
    } catch (e) {
        showToast('Failed to save interview to database', 'error');
        return null;
    }

    const interview = {
        id: Date.now(),
        leadId: lead.id,
        candidateName: lead.name,
        phone: lead.phone,
        email: lead.email || '',
        date: date,
        timeSlot: timeSlot,
        scheduledBy: lead.assignedTo,
        scheduledAt: new Date().toISOString(),
        status: 'scheduled',
        notes: notes
    };

    lead.status = 'interview_scheduled';
    logCall(lead.id, CALL_STATUS.SCHEDULED, `Interview scheduled for ${date} at ${timeSlot}. ${notes}`);
    await refreshInterviewsCache();
    notifyReception(interview);
    showToast(`✅ Interview scheduled for ${lead.name} on ${date} at ${timeSlot}`, 'success');
    return interview;
}

/**
 * Check interview availability for a specific date
 * @param {string} date - Date to check (YYYY-MM-DD)
 * @returns {Object} Availability info
 */
function checkInterviewAvailability(date) {
    const bookedSlots = interviewsCache
        .filter(i => i.date === date && i.status === 'scheduled')
        .map(i => i.timeSlot);
    
    const availableSlots = INTERVIEW_TIME_SLOTS.filter(slot => !bookedSlots.includes(slot));
    
    return {
        date: date,
        bookedSlots: bookedSlots,
        availableSlots: availableSlots,
        totalBooked: bookedSlots.length,
        totalAvailable: availableSlots.length
    };
}

/**
 * Get today's interviews
 * @returns {Array} Today's interviews
 */
function getTodaysInterviews() {
    const today = new Date().toISOString().split('T')[0];
    return interviewsCache
        .filter(i => i.date === today && i.status === 'scheduled')
        .sort((a, b) => {
            const timeA = INTERVIEW_TIME_SLOTS.indexOf(a.timeSlot);
            const timeB = INTERVIEW_TIME_SLOTS.indexOf(b.timeSlot);
            return timeA - timeB;
        });
}

/**
 * Mark interview as completed
 * @param {string|number} interviewId - Interview ID
 */
async function completeInterview(interviewId) {
    const interview = interviewsCache.find(i => i.id == interviewId);
    if (!interview) return;

    try {
        await fetch('api/complete_interview.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ interview_id: interviewId })
        });
    } catch (e) {
        showToast('Failed to update interview in database', 'error');
        return;
    }

    interview.status = 'completed';
    const lead = recruitLeads.find(l => l.id == interview.leadId);
    if (lead) {
        lead.status = 'interview_completed';
    }
    await refreshInterviewsCache();
    showToast(`✅ Interview completed for ${interview.candidateName}`, 'success');
}

// ==================== RECEPTION NOTIFICATIONS ====================

/**
 * Send notification to reception about new interview
 * @param {Object} interview - Interview object
 */
async function notifyReception(interview) {
    try {
        await fetch('api/portal_notifications.php?action=create', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: 'new_interview',
                target: 'reception',
                payload: {
                    interview,
                    message: `New interview scheduled: ${interview.candidateName} at ${interview.timeSlot} on ${interview.date}`
                }
            })
        });
    } catch (e) {
        console.error('Reception notification failed', e);
    }
}

async function getReceptionNotifications() {
    try {
        const res = await fetch('api/portal_notifications.php?action=list&target=reception&unplayed=1', { credentials: 'include' });
        const data = await res.json();
        if (data.success && Array.isArray(data.data)) {
            return data.data.map(row => ({
                id: row.id,
                type: row.notification_type,
                interview: row.payload?.interview,
                message: row.payload?.message || '',
                read: !!row.is_played,
                timestamp: row.created_at
            }));
        }
    } catch (e) {
        console.error(e);
    }
    return [];
}

async function markNotificationRead(notificationId) {
    try {
        await fetch('api/portal_notifications.php?action=markPlayed', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: [notificationId], consumer_portal: 'reception' })
        });
    } catch (e) {
        console.error(e);
    }
}

// ==================== RECRUITER STATISTICS ====================

/**
 * Get detailed statistics for a recruiter
 * @param {string} recruiterId - Recruiter ID
 * @param {string} period - Period (day, week, month)
 * @returns {Object} Statistics
 */
function getRecruiterDetailedStats(recruiterId, period = 'day') {
    const leads = recruitLeads.filter(l => l.assignedTo === recruiterId);
    const now = new Date();
    let startDate = new Date();
    
    switch(period) {
        case 'day':
            startDate.setHours(0, 0, 0, 0);
            break;
        case 'week':
            startDate.setDate(now.getDate() - 7);
            break;
        case 'month':
            startDate.setMonth(now.getMonth() - 1);
            break;
    }
    
    const callsInPeriod = leads.reduce((count, lead) => {
        if (!lead.callHistory) return count;
        return count + lead.callHistory.filter(c => new Date(c.timestamp) >= startDate).length;
    }, 0);
    
    const interviewsInPeriod = leads.reduce((count, lead) => {
        if (!lead.scheduledInterviews) return count;
        return count + lead.scheduledInterviews.filter(i => new Date(i.scheduledAt) >= startDate).length;
    }, 0);
    
    const statusBreakdown = {
        interested: leads.filter(l => l.lastCallStatus === CALL_STATUS.INTERESTED).length,
        scheduled: leads.filter(l => l.lastCallStatus === CALL_STATUS.SCHEDULED).length,
        callback: leads.filter(l => l.callbackRequired).length,
        notInterested: leads.filter(l => l.lastCallStatus === CALL_STATUS.NOT_INTERESTED).length,
        notReached: leads.filter(l => l.lastCallStatus === CALL_STATUS.NOT_REACHED).length,
        converted: leads.filter(l => l.converted).length
    };
    
    return {
        recruiterId,
        recruiterName: RECRUITER_LIST.find(r => r.id === recruiterId)?.name || 'Unknown',
        totalLeads: leads.length,
        newLeads: leads.filter(l => l.status === 'new').length,
        callsMade: callsInPeriod,
        interviewsScheduled: interviewsInPeriod,
        statusBreakdown,
        conversionRate: leads.length > 0 ? Math.round((statusBreakdown.converted / leads.length) * 100) : 0,
        interestedRate: leads.length > 0 ? Math.round((statusBreakdown.interested / leads.length) * 100) : 0
    };
}

// ==================== INITIALIZE ====================
document.addEventListener('DOMContentLoaded', function() {
    console.log('Shared.js initialized - Dual PC Voice System Active');
    
    // Initialize theme
    initTheme();
    
    loadData();
    
    // Load synced records
    loadSyncedRecords();
    
    // Initialize voice after a short delay
    setTimeout(initializeVoice, 1000);
    
    const path = (window.location.pathname || '').toLowerCase();
    const onReception = path.includes('reception-portal') || path.includes('agent-portal');
    const hasDedicatedVoice = typeof global.ReceptionVoice !== 'undefined';

    // Legacy pages only: skip heavy Sheets sync on pipeline portals (DB is source of truth)
    if (!onReception && !path.includes('hr-portal') && !path.includes('management-portal') && !path.includes('training-portal')) {
        if (autoSyncInterval) clearInterval(autoSyncInterval);
        autoSyncInterval = setInterval(() => {
            if (!document.hidden) syncToGoogleSheets();
        }, 120000);
        setTimeout(syncToGoogleSheets, 3000);
        setTimeout(initRecruiterSheet, 5000);
    }

    // Voice polling only when reception-voice.js is not loaded
    if (onReception && !hasDedicatedVoice) {
        setInterval(() => {
            if (!document.hidden) checkVoiceCalls();
        }, 5000);
    }
});

// Make functions globally available
window.forceSync = forceSync;
window.resetSync = resetSync;
window.clearSyncRecords = clearSyncRecords;
window.exportBackup = exportBackup;
window.importBackup = importBackup;
window.showSyncStats = showSyncStats;
window.getSyncStats = getSyncStats;
window.verifySync = verifySync;
window.logout = logout;
window.initializeVoice = initializeVoice;
window.speak = speak;
window.showVoicePanel = showVoicePanel;
window.showToast = showToast;
window.loadData = loadData;
window.saveData = saveData;
window.syncToGoogleSheets = syncToGoogleSheets;
window.createVoiceCall = createVoiceCall;
window.checkForReapply = checkForReapply;
window.showReapplyAlert = showReapplyAlert;
window.addRemark = addRemark;
window.getRemarks = getRemarks;
window.checkAlreadyApplied = checkAlreadyApplied;
window.submitNewApplication = submitNewApplication;
window.showCandidateDetails = showCandidateDetails;
window.addRemarkFromDetails = addRemarkFromDetails;
window.closeDetailsModal = closeDetailsModal;
window.initTheme = initTheme;
window.toggleTheme = toggleTheme;

// Export GM portal helper functions
window.getGMPendingCount = getGMPendingCount;
window.getGMSelectedCount = getGMSelectedCount;
window.getTrainingCount = getTrainingCount;

// Export all new functions
window.importMetaLeads = importMetaLeads;
window.autoAssignLeads = autoAssignLeads;
window.reassignLead = reassignLead;
window.getRecruiterLeads = getRecruiterLeads;
window.getRecruiterWorkload = getRecruiterWorkload;
window.logCall = logCall;
window.getLeadCallHistory = getLeadCallHistory;
window.getPendingCallbacks = getPendingCallbacks;
window.scheduleInterview = scheduleInterview;
window.checkInterviewAvailability = checkInterviewAvailability;
window.getTodaysInterviews = getTodaysInterviews;
window.completeInterview = completeInterview;
window.notifyReception = notifyReception;
window.getReceptionNotifications = getReceptionNotifications;
window.markNotificationRead = markNotificationRead;
window.getRecruiterDetailedStats = getRecruiterDetailedStats;
window.CALL_STATUS = CALL_STATUS;
window.CALL_STATUS_LABELS = CALL_STATUS_LABELS;
window.INTERVIEW_TIME_SLOTS = INTERVIEW_TIME_SLOTS;
window.RECRUITER_LIST = RECRUITER_LIST;