import re
with open('employee-portal.html', 'r', encoding='latin-1') as f:
    content = f.read()

# 1. Remove wfhAttendanceContainer
content = re.sub(r'<div id="wfhAttendanceContainer" class="hidden">.*?<!-- Standard Attendance -->', '<!-- Standard Attendance -->', content, flags=re.DOTALL)

# 2. Update JS setup
content = re.sub(
    r'if \(branch === \'workfromhome\'\) \{.*?\}',
    '''if (branch === 'workfromhome') {
                const leftBtn = document.querySelector('.ess-bio-btn');
                if (leftBtn) {
                    leftBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Web Check-In';
                    leftBtn.onclick = startWebCheckIn;
                    leftBtn.id = 'webCheckInBtn';
                }
            }''',
    content,
    flags=re.DOTALL
)

# 3. Replace startWFHAttendance
new_func = '''let wfhCheckedIn = false;

        function startWebCheckIn() {
            const btn = document.getElementById('webCheckInBtn');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            if (!navigator.geolocation) {
                showToast('Geolocation is not supported by your browser.', 'error');
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                return;
            }

            navigator.geolocation.getCurrentPosition(
                async (position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    try {
                        const response = await fetch('api/wfh_attendance_api.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ lat, lng })
                        });
                        const data = await response.json();
                        
                        if (data.success) {
                            let city = 'your location';
                            try {
                                const geoRes = await fetch(https://nominatim.openstreetmap.org/reverse?format=json&lat=&lon=);
                                const geoData = await geoRes.json();
                                if (geoData && geoData.address) {
                                    city = geoData.address.city || geoData.address.town || geoData.address.village || geoData.address.state || city;
                                }
                            } catch (e) { }

                            if (!wfhCheckedIn) {
                                showToast(Checked in successfully from !, 'success');
                                btn.innerHTML = '<i class="fas fa-sign-out-alt"></i> Web Check-Out';
                                btn.style.backgroundColor = '#ef4444';
                                wfhCheckedIn = true;
                            } else {
                                showToast(Checked out successfully from !, 'success');
                                btn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Web Check-In';
                                btn.style.backgroundColor = ''; 
                                wfhCheckedIn = false;
                            }
                            
                            if (document.getElementById('view-attendance').classList.contains('active')) {
                                loadMyAttendance();
                            }
                        } else {
                            showToast(data.message || 'Server error. Could not record attendance.', 'error');
                            btn.innerHTML = originalHtml;
                        }
                    } catch (err) {
                        showToast('Network error. Check your connection.', 'error');
                        btn.innerHTML = originalHtml;
                    }
                    btn.disabled = false;
                },
                (error) => {
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                    showToast('Location access denied. You must allow location to check in.', 'error');
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        }

        function showToast(msg, type = 'success') {
            let container = document.getElementById('toastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toastContainer';
                container.style.position = 'fixed';
                container.style.bottom = '20px';
                container.style.right = '20px';
                container.style.zIndex = '9999';
                document.body.appendChild(container);
            }
            const t = document.createElement('div');
            t.className = 'toast show';
            t.style.borderLeftColor = type === 'success' ? '#10b981' : '#ef4444';
            t.style.backgroundColor = '#1f2937';
            t.style.color = '#fff';
            t.style.padding = '15px 20px';
            t.style.borderRadius = '8px';
            t.style.marginBottom = '10px';
            t.style.boxShadow = '0 4px 6px -1px rgba(0, 0, 0, 0.1)';
            t.style.display = 'flex';
            t.style.alignItems = 'center';
            t.style.gap = '10px';
            
            t.innerHTML = <div><i class="fas " style="color:"></i></div><div><div style="font-size:14px;"></div></div>;
            container.appendChild(t);
            setTimeout(() => { t.remove(); }, 3000);
        }'''

content = re.sub(r'let wfhMap = null;.*?function showWFHError\(msg\) \{.*?\n        \}', new_func, content, flags=re.DOTALL)

with open('employee-portal.html', 'w', encoding='utf-8') as f:
    f.write(content)
