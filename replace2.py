import re
with open('employee-portal.html', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update the sidebar profile section to include the location display
sidebar_target = r'(<div class="ess-timer" id="profileTimer" aria-live="polite">00 : 00 : 00</div>\s*<button type="button" class="ess-bio-btn" onclick="showView\(\'attendance\'\)"><i class="fas \nfa-fingerprint"></i> Biometric Attendance</button>)'

sidebar_replacement = r'''<div class="ess-timer" id="profileTimer" aria-live="polite">00 : 00 : 00</div>
                              <p id="wfhLocationDisplay" style="display:none; color:#10b981; font-size:12px; margin-top:8px; font-weight:600;"><i class="fas fa-map-marker-alt"></i> Checked in: <span id="wfhCityName"></span></p>
                              <button type="button" class="ess-bio-btn" onclick="showView('attendance')"><i class="fas fa-fingerprint"></i> Biometric Attendance</button>'''
                              
# Fallback if the newline was different
content = content.replace(
    '<div class="ess-timer" id="profileTimer" aria-live="polite">00 : 00 : 00</div>\n                            <button type="button" class="ess-bio-btn" onclick="showView(\'attendance\')"><i class="fas fa-fingerprint"></i> Biometric Attendance</button>',
    '''<div class="ess-timer" id="profileTimer" aria-live="polite">00 : 00 : 00</div>
                            <p id="wfhLocationDisplay" style="display:none; color:#10b981; font-size:12px; margin-top:8px; font-weight:600; text-align:center;"><i class="fas fa-map-marker-alt"></i> <span id="wfhCityName"></span></p>
                            <button type="button" class="ess-bio-btn" onclick="showView('attendance')"><i class="fas fa-fingerprint"></i> Biometric Attendance</button>'''
)

# 2. Add the wfhAttendanceContainer HTML
wfh_html = '''<!-- WFH Location Attendance removed -->
                            <div id="wfhAttendanceContainer" class="hidden">
                                <div class="ess-card" style="margin-bottom: 20px;">
                                    <div class="ess-card-head" style="margin-bottom: 20px;">
                                        <h3><i class="fas fa-globe" style="color:var(--primary);"></i> Web Attendance Status</h3>
                                    </div>
                                    <div style="text-align: center; padding: 30px;">
                                        <h4 id="wfhCurrentStatusText" style="font-size: 22px; margin-bottom: 10px; color: #9ca3af;">You are currently Checked Out</h4>
                                        <button type="button" id="wfhMainCheckBtn" onclick="startWebCheckIn()" style="background: var(--primary); color: #fff; border: none; padding: 15px 40px; font-size: 18px; border-radius: 8px; cursor: pointer; font-weight: 600; transition: 0.3s; box-shadow: 0 4px 15px rgba(249, 115, 22, 0.4);"><i class="fas fa-map-marker-alt"></i> Check In</button>
                                    </div>
                                </div>
                                <div class="ess-card">
                                    <div class="ess-card-head">
                                        <h3><i class="fas fa-history"></i> Web Attendance History</h3>
                                        <div class="ess-date-nav">
                                            <button type="button" class="ess-icon-btn" onclick="loadMyAttendance()"><i class="fas fa-sync-alt"></i></button>
                                        </div>
                                    </div>
                                    <div class="ess-table-responsive" style="margin-top:20px;">
                                        <table class="ess-table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Check In</th>
                                                    <th>Check Out</th>
                                                    <th>Total Hours</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody id="wfhHistoryTableBody">
                                                <tr><td colspan="5" style="text-align:center; padding:20px;">Loading records...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>'''

content = content.replace('<!-- WFH Location Attendance removed -->', wfh_html)

# 3. Update the initialization logic
init_target = '''if (branch === 'workfromhome') {
                const leftBtn = document.querySelector('.ess-bio-btn');
                if (leftBtn) {
                    leftBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Web Check-In';
                    leftBtn.onclick = startWebCheckIn;
                    leftBtn.id = 'webCheckInBtn';
                }
            }'''
            
init_replacement = '''if (branch === 'workfromhome') {
                document.getElementById('standardAttendanceContainer').style.display = 'none';
                document.getElementById('wfhAttendanceContainer').classList.remove('hidden');
                
                const leftBtn = document.querySelector('.ess-bio-btn');
                if (leftBtn) {
                    leftBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Web Check-In';
                    leftBtn.onclick = startWebCheckIn;
                    leftBtn.id = 'webCheckInBtn';
                }
            }'''
content = content.replace(init_target, init_replacement)


# 4. Update the Javascript logic to handle Check Out toggle and rendering
js_target = '''let wfhCheckedIn = false;

        function startWebCheckIn() {'''
        
js_replacement = '''let wfhCheckedIn = false;

        function updateWFHUI() {
            const btn = document.getElementById('webCheckInBtn');
            const mainBtn = document.getElementById('wfhMainCheckBtn');
            const statusText = document.getElementById('wfhCurrentStatusText');
            
            if (wfhCheckedIn) {
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-sign-out-alt"></i> Web Check-Out';
                    btn.style.backgroundColor = '#ef4444';
                }
                if (mainBtn) {
                    mainBtn.innerHTML = '<i class="fas fa-sign-out-alt"></i> Check Out';
                    mainBtn.style.backgroundColor = '#ef4444';
                    mainBtn.style.boxShadow = '0 4px 15px rgba(239, 68, 68, 0.4)';
                }
                if (statusText) {
                    statusText.innerHTML = '<span style="color:#10b981;"><i class="fas fa-check-circle"></i> You are currently Checked In</span>';
                }
                document.getElementById('wfhLocationDisplay').style.display = 'block';
            } else {
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Web Check-In';
                    btn.style.backgroundColor = '';
                }
                if (mainBtn) {
                    mainBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Check In';
                    mainBtn.style.backgroundColor = 'var(--primary)';
                    mainBtn.style.boxShadow = '0 4px 15px rgba(249, 115, 22, 0.4)';
                }
                if (statusText) {
                    statusText.innerHTML = 'You are currently Checked Out';
                }
                document.getElementById('wfhLocationDisplay').style.display = 'none';
            }
        }

        // Override renderWeeklyAttendance to populate WFH table if it's WFH branch
        const originalRenderWeeklyAttendance = renderWeeklyAttendance;
        renderWeeklyAttendance = function(data) {
            originalRenderWeeklyAttendance(data); // call original for dashboard stats
            
            if (localStorage.getItem('companyBranch') === 'workfromhome') {
                const tbody = document.getElementById('wfhHistoryTableBody');
                if (!tbody) return;
                
                tbody.innerHTML = '';
                if (!data || data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px;">No web attendance records found for this week.</td></tr>';
                    return;
                }
                
                // Determine current status based on today's last punch
                const todayStr = new Date().toISOString().split('T')[0];
                let todayRecord = data.find(r => r.date === todayStr);
                if (todayRecord && todayRecord.in_time && todayRecord.in_time !== '---' && (todayRecord.out_time === '---' || !todayRecord.out_time)) {
                    wfhCheckedIn = true;
                } else {
                    wfhCheckedIn = false;
                }
                updateWFHUI();
                
                data.forEach(r => {
                    const statusColor = r.status.toLowerCase() === 'present' ? '#10b981' : (r.status.toLowerCase() === 'absent' ? '#ef4444' : '#f59e0b');
                    const tr = document.createElement('tr');
                    tr.innerHTML = 
                        <td><strong></strong><br><small style="color:#9ca3af;"></small></td>
                        <td><span style="background:rgba(16,185,129,0.1); color:#10b981; padding:4px 8px; border-radius:4px; font-size:13px;"><i class="fas fa-sign-in-alt"></i> </span></td>
                        <td><span style="background:rgba(239,68,68,0.1); color:#ef4444; padding:4px 8px; border-radius:4px; font-size:13px;"><i class="fas fa-sign-out-alt"></i> </span></td>
                        <td><strong></strong></td>
                        <td><span style="color:; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:1px;"></span></td>
                    ;
                    tbody.appendChild(tr);
                });
            }
        };

        function startWebCheckIn() {'''
content = content.replace(js_target, js_replacement)

# 5. Update the success callback to use updateWFHUI
success_target = '''if (!wfhCheckedIn) {
                                showToast(Checked in successfully from !, 'success');
                                btn.innerHTML = '<i class="fas fa-sign-out-alt"></i> Web Check-Out';
                                btn.style.backgroundColor = '#ef4444';
                                wfhCheckedIn = true;
                            } else {
                                showToast(Checked out successfully from !, 'success');
                                btn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Web Check-In';
                                btn.style.backgroundColor = ''; 
                                wfhCheckedIn = false;
                            }'''
success_replacement = '''document.getElementById('wfhCityName').innerText = city;
                            if (!wfhCheckedIn) {
                                showToast(Checked in successfully from !, 'success');
                                wfhCheckedIn = true;
                            } else {
                                showToast(Checked out successfully from !, 'success');
                                wfhCheckedIn = false;
                            }
                            updateWFHUI();'''
content = content.replace(success_target, success_replacement)

# Fix startWebCheckIn originalHtml logic so it targets the correct clicked button
checkin_target = '''const btn = document.getElementById('webCheckInBtn');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;'''
checkin_replacement = '''const btn = document.getElementById('webCheckInBtn');
            const mainBtn = document.getElementById('wfhMainCheckBtn');
            const originalHtml = btn ? btn.innerHTML : '';
            const originalMainHtml = mainBtn ? mainBtn.innerHTML : '';
            if (btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...'; btn.disabled = true; }
            if (mainBtn) { mainBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...'; mainBtn.disabled = true; }'''
content = content.replace(checkin_target, checkin_replacement)

# Fix error restores
err_rest_target = '''btn.innerHTML = originalHtml;
                btn.disabled = false;'''
err_rest_replacement = '''if (btn) { btn.innerHTML = originalHtml; btn.disabled = false; }
                if (mainBtn) { mainBtn.innerHTML = originalMainHtml; mainBtn.disabled = false; }'''
content = content.replace(err_rest_target, err_rest_replacement)

# There are multiple instances of the error restore, let's just do a regex replace
content = re.sub(r'btn\.innerHTML = originalHtml;\s*btn\.disabled = false;', r'if (btn) { btn.innerHTML = originalHtml; btn.disabled = false; } if (mainBtn) { mainBtn.innerHTML = originalMainHtml; mainBtn.disabled = false; }', content)
content = re.sub(r'btn\.innerHTML = originalHtml;', r'if (btn) { btn.innerHTML = originalHtml; } if (mainBtn) { mainBtn.innerHTML = originalMainHtml; }', content)


with open('employee-portal.html', 'w', encoding='utf-8') as f:
    f.write(content)
