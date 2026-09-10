/**
 * Balitech Employee Self Service - Request Hub Module
 * Department Routing, Multi-Tagging, Ticket Tracking, and Remarks Trail
 */
const RequestHubModule = {
    allEmployees: [],
    selectedTags: [], // [{id: 1, name: '...', role: '...'}]
    currentTab: 'all',
    currentDept: 'all',
    currentStatus: 'all',
    currentSearch: '',
    activeTicketId: null,

    deptCategories: {
        'IT': [
            'Hardware / PC / Monitor Issue',
            'Software Installation / License',
            'Network / Wi-Fi / VPN Access',
            'Email / Login Credentials Issue',
            'Vicidial / Dialer / VoIP Issue',
            'Headset / Peripheral Request',
            'Other IT Support'
        ],
        'Finance': [
            'Salary Disbursement Query',
            'Overtime / Bonus / Commission Inquiry',
            'Expense Claim / Reimbursement',
            'Tax / Salary Deduction Clarification',
            'Bank Account / Payment Details Update',
            'Advance Salary Request',
            'Other Finance Matter'
        ],
        'HR': [
            'Attendance Discrepancy / Punch Correction',
            'Leave Policy / Quota Clarification',
            'Experience / Salary Letter Request',
            'Work Environment / Workplace Grievance',
            'Employee Profile / CNIC Data Correction',
            'Probation / Appraisal Inquiry',
            'Other HR Support'
        ],
        'Operations': [
            'Shift Timing / Schedule Adjustment',
            'Seat / Workstation Allocation',
            'Transport / Route Query',
            'Floor / Facility Management',
            'Other Operations Query'
        ],
        'General': [
            'General Administrative Request',
            'Stationery / Equipment Requirement',
            'Miscellaneous Issue'
        ]
    },

    init: function() {
        this.fetchTaggableUsers();
        this.bindEvents();
    },

    bindEvents: function() {
        // Department change in new request modal
        const deptSelect = document.getElementById('rhDepartmentSelect');
        if (deptSelect) {
            deptSelect.addEventListener('change', (e) => {
                this.updateCategoryOptions(e.target.value);
                this.suggestAutoTags(e.target.value);
            });
        }

        // Search in tickets
        const searchInput = document.getElementById('rhSearchInput');
        if (searchInput) {
            let timeout = null;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    this.currentSearch = e.target.value.trim();
                    this.loadTickets();
                }, 300);
            });
        }

        // Tag search typing
        const tagInput = document.getElementById('rhTagSearchInput');
        if (tagInput) {
            tagInput.addEventListener('input', (e) => {
                this.filterTagDropdown(e.target.value);
            });
            tagInput.addEventListener('focus', () => {
                this.filterTagDropdown(tagInput.value);
            });
        }

        // Close dropdown when clicked outside
        document.addEventListener('click', (e) => {
            const dropdown = document.getElementById('rhTagDropdown');
            const container = document.getElementById('rhTagContainer');
            if (dropdown && container && !container.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    },

    getApiUrl: function(endpoint) {
        // Detect if running inside workfromhome subdirectory
        const isWfh = window.location.pathname.includes('/workfromhome/');
        const prefix = isWfh ? '../' : '';
        return prefix + endpoint;
    },

    fetchTaggableUsers: async function() {
        try {
            const res = await fetch(this.getApiUrl('api/request_hub_api.php?action=get_taggable_users'));
            const data = await res.json();
            if (data.success) {
                this.allEmployees = data.data || [];
            }
        } catch (e) {
            console.error("Failed to load taggable employees", e);
        }
    },

    suggestAutoTags: async function(dept) {
        // Fetch auto-suggested tags (Reporting Manager + Department Specialists)
        const empId = (typeof HRMS !== 'undefined' && HRMS.user && HRMS.user.id) ? HRMS.user.id : 0;
        let branch = 'main';
        if (typeof HRMS !== 'undefined' && HRMS.user) {
            branch = HRMS.user.company_branch || HRMS.user.branch || 'main';
        }

        try {
            const url = this.getApiUrl(`api/request_hub_api.php?action=get_auto_tags&employee_id=${empId}&department=${encodeURIComponent(dept)}&branch=${encodeURIComponent(branch)}`);
            const res = await fetch(url);
            const data = await res.json();

            if (data.success && Array.isArray(data.auto_tags)) {
                // Keep existing manual tags that were not auto-tagged from previous department
                const manualTags = this.selectedTags.filter(t => !t.isAuto);

                // Build new auto-tagged list
                const newAutoTags = data.auto_tags.map(t => ({
                    id: t.id,
                    name: t.name,
                    role: t.role,
                    tag_type: t.tag_type,
                    tag_badge: t.tag_badge || 'Auto',
                    isAuto: true
                }));

                // Merge: Reporting Manager + Dept Auto Tags + User's manual tags (avoiding duplicate IDs)
                const merged = [...newAutoTags];
                manualTags.forEach(mt => {
                    if (!merged.some(m => m.id === mt.id)) {
                        merged.push(mt);
                    }
                });

                this.selectedTags = merged.slice(0, 8); // allow up to 8 stakeholders
                this.renderSelectedTags();
            }
        } catch (e) {
            console.warn("Could not load auto-tags:", e);
        }
    },

    updateCategoryOptions: function(dept) {
        const grid = document.getElementById('rhCatVisualGrid');
        const countBadge = document.getElementById('rhCategoryCountBadge');
        const catInput = document.getElementById('rhCategorySelect');
        const cats = this.deptCategories[dept] || this.deptCategories['General'];

        if (countBadge) countBadge.textContent = `${cats.length} Categories`;

        if (grid) {
            grid.innerHTML = cats.map((cat, idx) => `
                <div class="rh-cat-chip ${idx === 0 ? 'active' : ''}" onclick="RequestHubModule.selectCategoryChip('${escHtml(cat)}', this)">
                    <i class="fas fa-tag"></i>
                    <span>${escHtml(cat)}</span>
                    <i class="fas fa-check rh-cat-check"></i>
                </div>
            `).join('');
        }

        if (catInput && cats.length > 0) {
            catInput.value = cats[0];
        }
    },

    selectCategoryChip: function(cat, el) {
        const catInput = document.getElementById('rhCategorySelect');
        if (catInput) catInput.value = cat;

        document.querySelectorAll('.rh-cat-chip').forEach(c => c.classList.remove('active'));
        if (el) el.classList.add('active');
    },

    filterTagDropdown: function(query) {
        const dropdown = document.getElementById('rhTagDropdown');
        if (!dropdown) return;
        const q = (query || '').toLowerCase().trim();
        
        const selectedIds = this.selectedTags.map(t => t.id);
        const filtered = this.allEmployees.filter(emp => {
            if (selectedIds.includes(emp.id)) return false;
            if (!q) return true;
            return (emp.name && emp.name.toLowerCase().includes(q)) ||
                   (emp.role && emp.role.toLowerCase().includes(q)) ||
                   (emp.department && emp.department.toLowerCase().includes(q));
        }).slice(0, 15);

        if (filtered.length === 0) {
            dropdown.innerHTML = '<div style="padding:14px; color:#94a3b8; font-size:12.5px; text-align:center;"><i class="fas fa-user-slash" style="margin-right:6px;"></i> No matching employees found</div>';
            dropdown.style.display = 'block';
            return;
        }

        dropdown.innerHTML = filtered.map(emp => `
            <div class="rh-tag-option-v2" onclick="RequestHubModule.selectTag(${emp.id}, '${escHtml(emp.name)}', '${escHtml(emp.role || emp.department || 'Staff')}')">
                <div class="rh-tag-opt-left">
                    <div class="rh-tag-avatar">${escHtml(emp.name.charAt(0).toUpperCase())}</div>
                    <div>
                        <strong>${escHtml(emp.name)}</strong>
                        <div class="rh-tag-opt-role">${escHtml(emp.role || '')} • ${escHtml(emp.department || 'General')}</div>
                    </div>
                </div>
                <span class="rh-tag-add-btn"><i class="fas fa-plus"></i> Tag</span>
            </div>
        `).join('');

        dropdown.style.display = 'block';
    },

    selectDepartmentCard: function(dept) {
        const deptInput = document.getElementById('rhDepartmentSelect');
        if (deptInput) deptInput.value = dept;

        const label = document.getElementById('rhSelectedDeptLabel');
        const labelsMap = {
            'IT': 'IT Support',
            'Finance': 'Finance',
            'HR': 'HR Desk',
            'Operations': 'Operations',
            'General': 'General Admin'
        };
        if (label) label.textContent = labelsMap[dept] || dept;

        // Toggle active tile
        document.querySelectorAll('.rh-dept-tile').forEach(card => {
            const isMatch = card.dataset.dept === dept;
            card.classList.toggle('active', isMatch);
        });

        this.updateCategoryOptions(dept);
        this.suggestAutoTags(dept);
    },

    selectPriorityPill: function(priority) {
        const priInput = document.getElementById('rhPrioritySelect');
        if (priInput) priInput.value = priority;

        document.querySelectorAll('.rh-pri-card').forEach(pill => {
            const isMatch = pill.dataset.priority === priority;
            pill.classList.toggle('active', isMatch);
        });
    },

    handleFileSelected: function(input) {
        const promptText = document.getElementById('rhFilePromptText');
        const display = document.getElementById('rhFileNameDisplay');
        if (input && input.files && input.files[0]) {
            const file = input.files[0];
            const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
            if (promptText) promptText.textContent = file.name;
            if (display) display.textContent = `${sizeMb} MB • Ready to upload`;
        } else {
            if (promptText) promptText.textContent = 'Drag & Drop or Click to Upload';
            if (display) display.textContent = 'Supports PNG, JPG, PDF, DOCX, ZIP (Max 15MB)';
        }
    },

    selectTag: function(id, name, role) {
        if (this.selectedTags.length >= 8) {
            showToast('You can tag up to 8 members per request.', 'error');
            return;
        }
        if (!this.selectedTags.some(t => t.id === id)) {
            this.selectedTags.push({ id, name, role, isAuto: false, tag_badge: 'Manual' });
            this.renderSelectedTags();
        }
        const tagInput = document.getElementById('rhTagSearchInput');
        if (tagInput) {
            tagInput.value = '';
            tagInput.focus();
        }
        const dropdown = document.getElementById('rhTagDropdown');
        if (dropdown) dropdown.style.display = 'none';
    },

    removeTag: function(id) {
        this.selectedTags = this.selectedTags.filter(t => t.id !== id);
        this.renderSelectedTags();
    },

    renderSelectedTags: function() {
        const container = document.getElementById('rhSelectedTagsWrap');
        const counter = document.getElementById('rhTagCounter');
        if (counter) {
            counter.textContent = `${this.selectedTags.length}/8 Tagged`;
        }
        if (!container) return;

        if (this.selectedTags.length === 0) {
            container.innerHTML = '<span style="font-size:12px; color:#64748b; font-style:italic; padding:4px 0;">No stakeholders tagged yet</span>';
            return;
        }

        container.innerHTML = this.selectedTags.map(t => {
            let iconClass = 'fa-user-check';
            let badgeBg = '#334155';
            let badgeColor = '#f8fafc';

            if (t.tag_type === 'manager') {
                iconClass = 'fa-crown';
                badgeBg = 'rgba(245, 158, 11, 0.2)';
                badgeColor = '#fbbf24';
            } else if (t.tag_type === 'finance') {
                iconClass = 'fa-coins';
                badgeBg = 'rgba(16, 185, 129, 0.2)';
                badgeColor = '#34d399';
            } else if (t.tag_type === 'hr') {
                iconClass = 'fa-user-shield';
                badgeBg = 'rgba(99, 102, 241, 0.2)';
                badgeColor = '#a5b4fc';
            } else if (t.tag_type === 'it') {
                iconClass = 'fa-laptop-code';
                badgeBg = 'rgba(56, 189, 248, 0.2)';
                badgeColor = '#38bdf8';
            } else if (t.tag_type === 'ops') {
                iconClass = 'fa-network-wired';
                badgeBg = 'rgba(249, 115, 22, 0.2)';
                badgeColor = '#fb923c';
            }

            const badgeHtml = t.tag_badge ? `<span style="background:${badgeBg}; color:${badgeColor}; font-size:9.5px; font-weight:700; padding:1px 6px; border-radius:4px; margin-left:4px; text-transform:uppercase; letter-spacing:0.3px;">${escHtml(t.tag_badge)}</span>` : '';

            return `
                <span class="rh-selected-chip-v2 ${t.isAuto ? 'rh-auto-tagged' : ''}" style="${t.isAuto ? 'border: 1px solid rgba(255,255,255,0.15);' : ''}">
                    <i class="fas ${iconClass}" style="font-size:10px; color:${badgeColor};"></i>
                    <span><strong>${escHtml(t.name)}</strong> <small>(${escHtml(t.role || 'Staff')})</small> ${badgeHtml}</span>
                    <i class="fas fa-times remove-chip" onclick="RequestHubModule.removeTag(${t.id})" title="Remove Tag"></i>
                </span>
            `;
        }).join('');
    },

    openNewRequestModal: function() {
        this.selectedTags = [];
        this.renderSelectedTags();
        
        const form = document.getElementById('formNewRequest');
        if (form) form.reset();

        this.selectDepartmentCard('IT');
        this.selectPriorityPill('normal');
        this.handleFileSelected(null);

        const modal = document.getElementById('modalNewRequest');
        if (modal) modal.classList.add('active');
    },

    closeNewRequestModal: function() {
        const modal = document.getElementById('modalNewRequest');
        if (modal) modal.classList.remove('active');
    },

    submitNewRequest: async function(e) {
        if (e) e.preventDefault();
        
        const form = document.getElementById('formNewRequest');
        const submitBtn = document.getElementById('btnSubmitRequest');
        const origText = submitBtn ? submitBtn.innerHTML : 'Submit Ticket';

        const department = document.getElementById('rhDepartmentSelect').value;
        const request_type = document.getElementById('rhCategorySelect').value;
        const priority = document.getElementById('rhPrioritySelect').value;
        const subject = document.getElementById('rhSubjectInput').value.trim();
        const description = document.getElementById('rhDescriptionInput').value.trim();
        const fileInput = document.getElementById('rhAttachmentInput');

        if (!subject || !description) {
            showToast('Please provide a subject and detailed description.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'create_request');
        formData.append('employee_id', (HRMS.user && HRMS.user.id) ? HRMS.user.id : 0);
        formData.append('employee_name', (HRMS.user && HRMS.user.full_name) ? HRMS.user.full_name : 'Employee');
        formData.append('department', department);
        formData.append('request_type', request_type);
        formData.append('priority', priority);
        formData.append('subject', subject);
        formData.append('description', description);
        formData.append('tagged_users', JSON.stringify(this.selectedTags));

        if (fileInput && fileInput.files[0]) {
            formData.append('attachment', fileInput.files[0]);
        }

        try {
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            }

            const res = await fetch(this.getApiUrl('api/request_hub_api.php'), {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                showToast(`Ticket ${data.ticket_code} created successfully!`, 'success');
                this.closeNewRequestModal();
                this.loadTickets();
            } else {
                showToast(data.error || 'Failed to create request.', 'error');
            }
        } catch (err) {
            showToast('Network error while submitting request.', 'error');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = origText;
            }
        }
    },

    setTab: function(tab) {
        this.currentTab = tab;
        document.querySelectorAll('.rh-tab-pill').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });
        this.loadTickets();
    },

    setDepartmentFilter: function(dept) {
        this.currentDept = dept;
        this.loadTickets();
    },

    setStatusFilter: function(status) {
        this.currentStatus = status;
        this.loadTickets();
    },

    loadTickets: async function() {
        const listContainer = document.getElementById('rhTicketsList');
        if (!listContainer) return;

        listContainer.innerHTML = '<div style="text-align:center; padding:30px; color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x"></i><p style="margin-top:10px;">Loading requests...</p></div>';

        const empId = (HRMS.user && HRMS.user.id) ? HRMS.user.id : 0;
        let url = this.getApiUrl(`api/request_hub_api.php?action=get_requests&employee_id=${empId}&tab=${this.currentTab}&department=${encodeURIComponent(this.currentDept)}&status=${encodeURIComponent(this.currentStatus)}&search=${encodeURIComponent(this.currentSearch)}`);

        try {
            const res = await fetch(url);
            const data = await res.json();

            if (data.success) {
                this.renderTickets(data.data || []);
                this.updateStats(data.data || []);
            } else {
                listContainer.innerHTML = `<div class="rh-empty-state"><i class="fas fa-exclamation-triangle"></i><p>${data.error || 'Failed to load tickets'}</p></div>`;
            }
        } catch (e) {
            listContainer.innerHTML = `<div class="rh-empty-state"><i class="fas fa-wifi"></i><p>Network error loading tickets</p></div>`;
        }
    },

    updateStats: function(tickets) {
        let total = tickets.length;
        let pending = 0;
        let inProgress = 0;
        let resolved = 0;

        tickets.forEach(t => {
            if (t.status === 'pending') pending++;
            else if (t.status === 'in_progress') inProgress++;
            else if (t.status === 'resolved' || t.status === 'closed') resolved++;
        });

        const elTotal = document.getElementById('rhStatTotal');
        const elPending = document.getElementById('rhStatPending');
        const elProgress = document.getElementById('rhStatProgress');
        const elResolved = document.getElementById('rhStatResolved');

        if (elTotal) elTotal.textContent = total;
        if (elPending) elPending.textContent = pending;
        if (elProgress) elProgress.textContent = inProgress;
        if (elResolved) elResolved.textContent = resolved;
    },

    renderTickets: function(tickets) {
        const listContainer = document.getElementById('rhTicketsList');
        if (!listContainer) return;

        if (tickets.length === 0) {
            listContainer.innerHTML = `
                <div class="rh-empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h4 style="margin: 0 0 6px 0; color: #f1f5f9; font-size: 16px;">No Requests Found</h4>
                    <p style="margin: 0; font-size: 13px;">No tickets match your active filter criteria. Click "Create Request" to raise a new inquiry.</p>
                </div>
            `;
            return;
        }

        listContainer.innerHTML = tickets.map(t => {
            const tags = t.tagged_users_list || [];
            const tagsHtml = tags.length > 0 ? tags.map(tag => {
                let icon = 'fa-user-tag';
                let typeClass = 'general';
                if (tag.tag_type === 'manager') { icon = 'fa-crown'; typeClass = 'mgr'; }
                else if (tag.tag_type === 'finance') { icon = 'fa-coins'; typeClass = 'fin'; }
                else if (tag.tag_type === 'hr') { icon = 'fa-user-shield'; typeClass = 'hr'; }
                else if (tag.tag_type === 'it') { icon = 'fa-laptop-code'; typeClass = 'it'; }

                return `
                    <span class="rh-tag-chip-v2 ${typeClass}" title="${escHtml(tag.role || 'Stakeholder')}">
                        <i class="fas ${icon}"></i> ${escHtml(tag.name)}
                    </span>
                `;
            }).join('') : '<span class="rh-empty-dash">—</span>';

            const statusClass = t.status || 'pending';
            let statusLabel = 'Submitted';
            let pillClass = 'status-submitted';

            if (statusClass === 'pending') {
                statusLabel = 'Pending';
                pillClass = 'status-waiting';
            } else if (statusClass === 'in_progress') {
                statusLabel = 'Processing';
                pillClass = 'status-processing';
            } else if (statusClass === 'resolved' || statusClass === 'closed') {
                statusLabel = 'Submitted / Done';
                pillClass = 'status-submitted';
            } else if (statusClass === 'rejected') {
                statusLabel = 'Waiting For Details';
                pillClass = 'status-rejected';
            }

            const commentsCount = t.remarks_count > 0 ? `<span class="rh-comment-count-badge"><i class="far fa-comment-dots"></i> ${t.remarks_count}</span>` : '<span class="rh-empty-dash">—</span>';

            return `
                <div class="rh-table-row" onclick="RequestHubModule.viewTicketDetails(${t.id})">
                    <div class="rh-td col-code">
                        <span class="rh-inquiry-code">${escHtml(t.ticket_code)}</span>
                        <div class="rh-requester-info" title="Requested by: ${escHtml(t.employee_name || 'Staff')}">
                            <i class="far fa-user rh-req-user-icon"></i>
                            <span class="rh-req-user-name">${escHtml(t.employee_name || 'Staff')}</span>
                        </div>
                    </div>

                    <div class="rh-td col-title">
                        <div class="rh-inquiry-subject">${escHtml(t.subject)}</div>
                        <div class="rh-inquiry-snippet">${escHtml(t.description)}</div>
                    </div>

                    <div class="rh-td col-dept">
                        <span class="rh-dept-tag ${escHtml(t.department)}">${escHtml(t.department)}</span>
                    </div>

                    <div class="rh-td col-status">
                        <span class="rh-nordic-pill ${pillClass}">
                            <span class="rh-pill-dot"></span>
                            <span>${statusLabel}</span>
                        </span>
                    </div>

                    <div class="rh-td col-tags">
                        <div class="rh-tags-flow">
                            ${tagsHtml}
                        </div>
                    </div>

                    <div class="rh-td col-comments">
                        ${commentsCount}
                    </div>

                    <div class="rh-td col-date">
                        <span class="rh-inquiry-date">${formatRelativeDate(t.created_at)}</span>
                    </div>

                    <div class="rh-td col-actions">
                        <button type="button" class="rh-btn-details-link" onclick="event.stopPropagation(); RequestHubModule.viewTicketDetails(${t.id})">
                            DETAILS
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    },

    viewTicketDetails: async function(id) {
        this.activeTicketId = id;
        const modal = document.getElementById('modalTicketDetails');
        const modalBody = document.getElementById('rhTicketDetailsBody');

        if (!modal || !modalBody) return;
        modal.classList.add('active');
        modalBody.innerHTML = '<div style="text-align:center; padding:40px;"><i class="fas fa-spinner fa-spin fa-2x" style="color:#6366f1;"></i><p style="margin-top:10px; color:#cbd5e1;">Loading ticket discussion & history...</p></div>';

        try {
            const empId = (HRMS.user && HRMS.user.id) ? HRMS.user.id : 0;
            const res = await fetch(this.getApiUrl(`api/request_hub_api.php?action=get_request_details&id=${id}&employee_id=${empId}`));
            const data = await res.json();

            if (data.success) {
                this.renderTicketDetailsView(data.ticket, data.remarks || [], data.permissions || {});
            } else {
                modalBody.innerHTML = `<div class="rh-empty-state"><i class="fas fa-exclamation-triangle"></i><p>${data.error || 'Failed to load details'}</p></div>`;
            }
        } catch (e) {
            modalBody.innerHTML = `<div class="rh-empty-state"><i class="fas fa-wifi"></i><p>Network error loading details</p></div>`;
        }
    },

    closeTicketDetailsModal: function() {
        const modal = document.getElementById('modalTicketDetails');
        if (modal) modal.classList.remove('active');
        this.activeTicketId = null;
    },

    quickFilterDept: function(dept, btn) {
        document.querySelectorAll('.rh-hero-dept-pill').forEach(p => p.classList.remove('active'));
        if (btn) btn.classList.add('active');

        const deptFilter = document.getElementById('rhDeptFilter');
        if (deptFilter) deptFilter.value = dept;

        this.setDepartmentFilter(dept);
    },

    renderTicketDetailsView: function(ticket, remarks, permissions = {}) {
        const modalBody = document.getElementById('rhTicketDetailsBody');
        if (!modalBody) return;

        const canChangeStatus = !!permissions.can_change_status;

        const tags = ticket.tagged_users_list || [];
        const tagsHtml = tags.length > 0 ? tags.map(tag => {
            let icon = 'fa-user-tag';
            let color = '#38bdf8';
            if (tag.tag_type === 'manager') { icon = 'fa-crown'; color = '#fbbf24'; }
            else if (tag.tag_type === 'finance') { icon = 'fa-coins'; color = '#34d399'; }
            else if (tag.tag_type === 'hr') { icon = 'fa-user-shield'; color = '#a5b4fc'; }
            else if (tag.tag_type === 'it') { icon = 'fa-laptop-code'; color = '#38bdf8'; }

            const badgeText = tag.tag_badge ? `<span style="font-size:9.5px; opacity:0.85; margin-left:2px; font-weight:700;">[${escHtml(tag.tag_badge)}]</span>` : '';

            return `
                <span class="rh-tag-chip-mini" style="margin-right:4px; margin-bottom:4px;" title="${escHtml(tag.role || 'Staff')}">
                    <i class="fas ${icon}" style="color:${color};"></i> ${escHtml(tag.name)} <small>(${escHtml(tag.role || 'Staff')})</small> ${badgeText}
                </span>
            `;
        }).join('') : '<span style="color:#64748b; font-size:12px;">None</span>';

        const isWfh = window.location.pathname.includes('/workfromhome/');
        const prefix = isWfh ? '../' : '';

        const attachHtml = ticket.attachment_path ? `
            <a href="${prefix}${escHtml(ticket.attachment_path)}" target="_blank" class="rh-timeline-attach">
                <i class="fas fa-paperclip"></i> View Ticket Attachment (${escHtml(ticket.attachment_name || 'Download')})
            </a>
        ` : '';

        const remarksListHtml = remarks.map(r => {
            const isSys = r.author_role && r.author_role.includes('System');
            const rAttach = r.attachment_path ? `
                <a href="${prefix}${escHtml(r.attachment_path)}" target="_blank" class="rh-timeline-attach">
                    <i class="fas fa-paperclip"></i> ${escHtml(r.attachment_name || 'Attachment')}
                </a>
            ` : '';

            return `
                <div class="rh-timeline-item" style="${isSys ? 'border-left: 3px solid #6366f1;' : ''}">
                    <div class="rh-timeline-header">
                        <span class="rh-timeline-author">
                            <i class="fas ${isSys ? 'fa-robot' : 'fa-user-circle'}" style="color:${isSys ? '#818cf8' : '#38bdf8'}; font-size:16px;"></i>
                            ${escHtml(r.author_name)}
                            <span class="rh-timeline-role">${escHtml(r.author_role || 'Staff')}</span>
                        </span>
                        <span class="rh-timeline-time"><i class="far fa-clock"></i> ${formatRelativeDate(r.created_at)}</span>
                    </div>
                    <p class="rh-timeline-text">${escHtml(r.remark)}</p>
                    ${rAttach}
                </div>
            `;
        }).join('');

        modalBody.innerHTML = `
            <div class="rh-detail-grid">
                <!-- Left: Ticket Conversation & Remarks -->
                <div>
                    <div class="rh-detail-main-card">
                        <div class="rh-detail-main-head">
                            <span class="rh-dept-badge ${escHtml(ticket.department)}">
                                ${escHtml(ticket.department)} Department
                            </span>
                            <span class="rh-status-pill ${escHtml(ticket.status)}">
                                ${escHtml(ticket.status.replace('_', ' '))}
                            </span>
                        </div>
                        <h3 class="rh-detail-title">${escHtml(ticket.subject)}</h3>
                        <p class="rh-detail-desc">${escHtml(ticket.description)}</p>
                        ${attachHtml}
                    </div>

                    <h4 style="font-size:16px; font-weight:800; color:#f1f5f9; margin: 24px 0 14px 0; display:flex; align-items:center; gap:10px;">
                        <i class="fas fa-comments" style="color:#f97316;"></i> Remarks & Discussion Trail (${remarks.length})
                    </h4>
                    
                    <div class="rh-timeline" id="rhRemarksTimeline">
                        ${remarksListHtml.length > 0 ? remarksListHtml : '<p style="color:#94a3b8; font-size:13px; text-align:center; padding:20px; background:rgba(255,255,255,0.03); border-radius:14px;">No remarks posted yet. Be the first to start the conversation!</p>'}
                    </div>

                    <!-- Post Remark Box -->
                    <form class="rh-quick-remark-box" id="formAddRemark" onsubmit="event.preventDefault(); RequestHubModule.submitRemark();">
                        <label style="font-size:13.5px; font-weight:700; color:#e2e8f0; display:flex; align-items:center; gap:8px;">
                            <i class="fas fa-pen" style="color:#38bdf8;"></i> Add Remark / Discussion Update
                        </label>
                        <textarea id="rhRemarkText" class="rh-form-control rh-textarea" rows="3" placeholder="Type your remarks, resolution notes, or reply here..." required></textarea>
                        
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <label class="rh-btn-browse" style="margin:0; display:inline-flex; align-items:center; gap:6px;">
                                    <i class="fas fa-paperclip"></i> Attach File
                                    <input type="file" id="rhRemarkAttachment" hidden onchange="document.getElementById('rhRemarkAttachName').textContent = this.files[0] ? this.files[0].name : '';">
                                </label>
                                <span id="rhRemarkAttachName" style="font-size:12px; color:#a5b4fc; font-weight:600;"></span>
                            </div>

                            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                ${canChangeStatus ? `
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span style="font-size:11.5px; color:#38bdf8; font-weight:700;"><i class="fas fa-shield-alt"></i> Resolver Action:</span>
                                        <select id="rhRemarkStatusChange" class="rh-select" style="padding:8px 14px; font-size:12.5px; border-color:#38bdf8;">
                                            <option value="">Keep Current Status</option>
                                            <option value="in_progress">Mark In Progress</option>
                                            <option value="resolved">Mark Resolved / Done</option>
                                            <option value="rejected">Mark Rejected</option>
                                            <option value="closed">Mark Closed</option>
                                        </select>
                                    </div>
                                ` : `
                                    <input type="hidden" id="rhRemarkStatusChange" value="">
                                    <div style="font-size:11.5px; color:#94a3b8; background:rgba(255,255,255,0.05); padding:6px 12px; border-radius:8px; border:1px solid rgba(255,255,255,0.08);">
                                        <i class="fas fa-info-circle" style="color:#fbbf24; margin-right:4px;"></i> Remarks Only (Case Resolution reserved for <strong>${escHtml(permissions.required_handler_label || 'Specialist')}</strong>)
                                    </div>
                                `}
                                <button type="submit" class="rh-btn-primary" id="btnPostRemark" style="padding:10px 20px; font-size:13.5px;">
                                    <i class="fas fa-paper-plane"></i> Post Remark
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Right: Ticket Metadata Sidebar -->
                <div class="rh-detail-sidebar">
                    <div class="rh-detail-item">
                        <label>Ticket Reference</label>
                        <span style="font-family:'JetBrains Mono', monospace; font-weight:800; color:#fb923c; font-size:15px;">${escHtml(ticket.ticket_code)}</span>
                    </div>
                    <div class="rh-detail-item">
                        <label>Requested By</label>
                        <span><i class="fas fa-user-circle" style="color:#38bdf8;"></i> ${escHtml(ticket.employee_name)}</span>
                    </div>
                    <div class="rh-detail-item">
                        <label>Category</label>
                        <span><i class="fas fa-layer-group" style="color:#818cf8;"></i> ${escHtml(ticket.request_type)}</span>
                    </div>
                    <div class="rh-detail-item">
                        <label>Priority</label>
                        <span style="display:inline-flex; align-items:center; gap:8px;">
                            <span class="rh-priority-dot ${escHtml(ticket.priority)}"></span>
                            ${escHtml(ticket.priority.toUpperCase())}
                        </span>
                    </div>
                    <div class="rh-detail-item">
                        <label>Tagged Stakeholders</label>
                        <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:4px;">
                            ${tagsHtml}
                        </div>
                    </div>
                    <div class="rh-detail-item">
                        <label>Created On</label>
                        <span><i class="far fa-calendar-alt" style="color:#64748b;"></i> ${ticket.created_at}</span>
                    </div>
                    ${ticket.resolved_by ? `
                        <div class="rh-detail-item" style="border-top:1px solid rgba(255,255,255,0.08); padding-top:12px;">
                            <label>Resolved By</label>
                            <span style="color:#34d399;"><i class="fas fa-check-circle"></i> ${escHtml(ticket.resolved_by)}</span>
                            <small style="display:block; color:#94a3b8; font-size:11px; margin-top:2px;">${ticket.resolved_at || ''}</small>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    },

    submitRemark: async function() {
        if (!this.activeTicketId) return;

        const remarkText = document.getElementById('rhRemarkText').value.trim();
        const statusChange = document.getElementById('rhRemarkStatusChange').value;
        const fileInput = document.getElementById('rhRemarkAttachment');
        const submitBtn = document.getElementById('btnPostRemark');

        if (!remarkText) {
            showToast('Please enter a remark message.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'add_remark');
        formData.append('request_id', this.activeTicketId);
        formData.append('author_id', (HRMS.user && HRMS.user.id) ? HRMS.user.id : 0);
        formData.append('author_name', (HRMS.user && HRMS.user.full_name) ? HRMS.user.full_name : 'Employee');
        formData.append('author_role', (HRMS.user && HRMS.user.role) ? HRMS.user.role : 'Employee');
        formData.append('remark', remarkText);
        if (statusChange) {
            formData.append('status_change', statusChange);
        }
        if (fileInput && fileInput.files[0]) {
            formData.append('attachment', fileInput.files[0]);
        }

        try {
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting...';
            }

            const res = await fetch(this.getApiUrl('api/request_hub_api.php'), {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                showToast('Remark posted successfully!', 'success');
                this.viewTicketDetails(this.activeTicketId);
                this.loadTickets();
            } else {
                showToast(data.error || 'Failed to post remark.', 'error');
            }
        } catch (e) {
            showToast('Network error while posting remark.', 'error');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Post Remark';
            }
        }
    }
};

function formatRelativeDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(/-/g, '/'));
    if (isNaN(d.getTime())) return dateStr;
    const now = new Date();
    const diffSec = Math.floor((now - d) / 1000);

    if (diffSec < 60) return 'Just now';
    if (diffSec < 3600) return Math.floor(diffSec / 60) + 'm ago';
    if (diffSec < 86400) return Math.floor(diffSec / 3600) + 'h ago';
    if (diffSec < 604800) return Math.floor(diffSec / 86400) + 'd ago';
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

if (typeof escHtml !== 'function') {
    window.escHtml = function(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };
}

if (typeof showToast !== 'function') {
    window.showToast = function(msg, type) {
        console.log(`[Toast ${type || 'info'}]:`, msg);
    };
}

window.RequestHubModule = RequestHubModule;
