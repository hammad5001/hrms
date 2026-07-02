import re
with open('employee-portal.html', 'r', encoding='utf-8') as f:
    content = f.read()

wfh_html = '''
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
                            </div>
                            <!-- Standard Attendance -->'''

if 'id="wfhAttendanceContainer"' not in content:
    content = content.replace('<!-- Standard Attendance -->', wfh_html)

with open('employee-portal.html', 'w', encoding='utf-8') as f:
    f.write(content)
