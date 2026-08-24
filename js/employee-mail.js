/* Balitech HRMS Internal Mail Module Controller */

const MailModule = {
    currentFolder: 'inbox',
    currentFilter: 'all',
    currentSort: 'newest',
    currentPage: 1,
    totalPages: 1,
    limit: 25,
    activeMailId: null,
    mails: [],
    drafts: [],
    selectedRecipients: [], // Array of { id, full_name, email, type }
    uploadedAttachments: [], // Array of { file_name, file_path, file_size, file_type }
    searchDebounceTimer: null,
    currentDraftId: null,
    replyParentId: null,
    unreadCount: 0,
    folderCounts: { inbox: 0, unread: 0, starred: 0, important: 0, sent: 0, drafts: 0, archive: 0, trash: 0 },
    userSettings: {
        signature_text: '',
        is_enabled: 1,
        default_importance: 'normal'
    },

    getApiUrl(pathWithQuery = '') {
        const isWfh = window.location.pathname.includes('/workfromhome/');
        const base = isWfh ? '../api/mail_api.php' : 'api/mail_api.php';
        if (!pathWithQuery) return base;
        if (pathWithQuery.startsWith('?')) return base + pathWithQuery;
        if (pathWithQuery.startsWith('api/mail_api.php')) return isWfh ? '../' + pathWithQuery : pathWithQuery;
        return base + '?' + pathWithQuery;
    },

    init() {
        this.bindEvents();
        this.updateFolderCounts();
        this.loadUserSettings();
        this.loadFolderMails();
        // Periodically refresh folder counts
        setInterval(() => this.updateFolderCounts(), 20000);
    },

    async loadUserSettings() {
        try {
            const res = await fetch(this.getApiUrl('action=get_settings'));
            const data = await res.json();
            if (data.success && data.data) {
                this.userSettings = data.data;
            }
        } catch (err) {
            console.error('Failed to load user mail settings:', err);
        }
    },

    bindEvents() {
        document.addEventListener('click', (e) => {
            const dropdown = document.getElementById('essMailEmpDropdown');
            if (dropdown && !dropdown.contains(e.target) && !e.target.closest('#essMailToInput')) {
                dropdown.style.display = 'none';
            }
        });

        const toInput = document.getElementById('essMailToInput');
        if (toInput) {
            toInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    const val = toInput.value.trim().replace(/,/g, '');
                    if (val) {
                        const firstEmpItem = document.querySelector('.ess-mail-emp-item');
                        if (firstEmpItem) {
                            firstEmpItem.click();
                        } else {
                            this.addRecipient(0, val, val);
                        }
                    }
                }
            });
        }
    },

    async updateFolderCounts() {
        try {
            const res = await fetch(this.getApiUrl('action=folder_counts'));
            const data = await res.json();
            if (data.success && data.data) {
                this.folderCounts = data.data;
                this.unreadCount = data.data.unread_count || 0;
                this.renderFolderBadges();
            }
        } catch (err) {
            console.error('Failed to fetch folder counts:', err);
        }
    },

    renderFolderBadges() {
        const badgeSide = document.getElementById('badgeSideMail');
        const badgeHeader = document.getElementById('badgeHeaderMail');
        const inboxBadge = document.getElementById('essMailInboxBadge');
        const starredBadge = document.getElementById('essMailStarredBadge');
        const importantBadge = document.getElementById('essMailImportantBadge');
        const draftsBadge = document.getElementById('essMailDraftsBadge');
        const trashBadge = document.getElementById('essMailTrashBadge');

        // Sidebar main badge
        [badgeSide, badgeHeader].forEach(b => {
            if (!b) return;
            if (this.unreadCount > 0) {
                b.textContent = this.unreadCount;
                b.classList.remove('hidden');
                b.style.display = 'inline-block';
            } else {
                b.classList.add('hidden');
                b.style.display = 'none';
            }
        });

        // Inbox badge
        if (inboxBadge) {
            inboxBadge.textContent = this.folderCounts.unread_count || 0;
            inboxBadge.style.display = this.folderCounts.unread_count > 0 ? 'inline-block' : 'none';
        }
        if (starredBadge) {
            starredBadge.textContent = this.folderCounts.starred || 0;
            starredBadge.style.display = this.folderCounts.starred > 0 ? 'inline-block' : 'none';
        }
        if (importantBadge) {
            importantBadge.textContent = this.folderCounts.important || 0;
            importantBadge.style.display = this.folderCounts.important > 0 ? 'inline-block' : 'none';
        }
        if (draftsBadge) {
            draftsBadge.textContent = this.folderCounts.drafts || 0;
            draftsBadge.style.display = this.folderCounts.drafts > 0 ? 'inline-block' : 'none';
        }
        if (trashBadge) {
            trashBadge.textContent = this.folderCounts.trash || 0;
            trashBadge.style.display = this.folderCounts.trash > 0 ? 'inline-block' : 'none';
        }

        // Metrics bar numbers
        const numInbox = document.getElementById('metricNumInbox');
        const numUnread = document.getElementById('metricNumUnread');
        const numStarred = document.getElementById('metricNumStarred');
        const numSent = document.getElementById('metricNumSent');
        const numDrafts = document.getElementById('metricNumDrafts');

        if (numInbox) numInbox.textContent = this.folderCounts.inbox || 0;
        if (numUnread) numUnread.textContent = this.folderCounts.unread_count || 0;
        if (numStarred) numStarred.textContent = this.folderCounts.starred || 0;
        if (numSent) numSent.textContent = this.folderCounts.sent || 0;
        if (numDrafts) numDrafts.textContent = this.folderCounts.drafts || 0;
    },

    switchFolder(folder) {
        this.currentFolder = folder;
        this.currentPage = 1;
        this.activeMailId = null;

        // Update active nav styling
        document.querySelectorAll('.ess-mail-nav-item').forEach(el => {
            if (el.dataset.folder === folder) {
                el.classList.add('active');
            } else {
                el.classList.remove('active');
            }
        });

        if (folder === 'settings') {
            this.renderSettingsPane();
        } else {
            this.clearReadingPane();
            this.loadFolderMails();
        }
    },

    setFilter(filter) {
        this.currentFilter = filter;
        this.currentPage = 1;

        // Update filter pills UI
        document.querySelectorAll('.ess-mail-pill').forEach(btn => {
            if (btn.dataset.filter === filter) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        this.loadFolderMails();
    },

    handleSortChange(sort) {
        this.currentSort = sort;
        this.currentPage = 1;
        this.loadFolderMails();
    },

    handleSearchInput(val) {
        const clearBtn = document.getElementById('essMailSearchClear');
        if (clearBtn) {
            clearBtn.style.display = val.trim() ? 'block' : 'none';
        }
        if (this.searchDebounceTimer) clearTimeout(this.searchDebounceTimer);
        this.searchDebounceTimer = setTimeout(() => {
            this.currentPage = 1;
            this.loadFolderMails();
        }, 300);
    },

    clearSearch() {
        const searchInput = document.getElementById('essMailSearchInput');
        const clearBtn = document.getElementById('essMailSearchClear');
        if (searchInput) searchInput.value = '';
        if (clearBtn) clearBtn.style.display = 'none';
        this.currentPage = 1;
        this.loadFolderMails();
    },

    prevPage() {
        if (this.currentPage > 1) {
            this.currentPage--;
            this.loadFolderMails();
        }
    },

    nextPage() {
        if (this.currentPage < this.totalPages) {
            this.currentPage++;
            this.loadFolderMails();
        }
    },

    async loadFolderMails() {
        const listContainer = document.getElementById('essMailItemsList');
        if (!listContainer) return;

        listContainer.innerHTML = '<div style="padding:30px;text-align:center;color:#64748b;"><i class="fas fa-spinner fa-spin fa-2x"></i><div style="margin-top:8px;">Loading emails...</div></div>';

        try {
            const searchInput = document.getElementById('essMailSearchInput');
            const searchQuery = searchInput ? searchInput.value.trim() : '';

            let query = `action=${this.currentFolder}&page=${this.currentPage}&limit=${this.limit}&sort=${this.currentSort}`;
            if (searchQuery) query += `&search=${encodeURIComponent(searchQuery)}`;
            if (this.currentFilter && this.currentFilter !== 'all') query += `&filter=${encodeURIComponent(this.currentFilter)}`;

            const res = await fetch(this.getApiUrl(query));
            const data = await res.json();

            if (!data.success) {
                listContainer.innerHTML = `<div style="padding:20px;text-align:center;color:#ef4444;">${data.message || 'Failed to load mails'}</div>`;
                return;
            }

            if (this.currentFolder === 'drafts') {
                this.drafts = data.data.drafts || [];
                this.renderDraftList(this.drafts);
            } else {
                this.mails = data.data.mails || [];
                this.currentPage = data.data.page || 1;
                this.totalPages = data.data.total_pages || 1;
                this.renderMailList(this.mails, data.data.total_items || 0);
            }

            this.updateFolderCounts();
        } catch (err) {
            console.error('Mail fetch error:', err);
            listContainer.innerHTML = '<div style="padding:20px;text-align:center;color:#ef4444;">Connection error loading emails</div>';
        }
    },

    sanitizeMailHtml(html) {
        if (!html) return '';
        if (!/<[a-z][\s\S]*>/i.test(html)) {
            return this.escapeHtml(html).replace(/\n/g, '<br>');
        }
        let clean = html.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
        clean = clean.replace(/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/gi, '');
        clean = clean.replace(/on\w+="[^"]*"/gi, '');
        clean = clean.replace(/on\w+='[^']*'/gi, '');
        clean = clean.replace(/javascript:/gi, '');
        return clean;
    },

    stripHtmlTags(html) {
        if (!html) return '';
        const tmp = document.createElement('DIV');
        tmp.innerHTML = html;
        return (tmp.textContent || tmp.innerText || '').trim();
    },

    renderMailList(mails, totalItems = 0) {
        const container = document.getElementById('essMailItemsList');
        if (!container) return;

        // Render pagination controls
        this.renderPaginationFooter(totalItems);

        if (!mails.length) {
            container.innerHTML = `
                <div class="ess-mail-empty-folder">
                    <i class="fas fa-inbox" style="font-size:36px;color:#475569;margin-bottom:10px;"></i>
                    <div style="font-weight:600;color:#94a3b8;">No emails found</div>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;">Try selecting another folder or clearing search filters</div>
                </div>
            `;
            return;
        }

        let html = '';
        mails.forEach(m => {
            const isUnread = !m.is_read && (this.currentFolder === 'inbox' || this.currentFolder === 'starred');
            const isActive = this.activeMailId === m.mail_id;
            const isStarred = m.is_starred;
            const isImportant = m.is_important || m.importance === 'high';
            const senderOrRecip = this.currentFolder === 'sent' 
                ? (m.recipients ? m.recipients.map(r => r.name).join(', ') : 'Recipients')
                : m.sender_name;

            const timeStr = this.formatMailTime(m.created_at);
            const snippetText = this.stripHtmlTags(m.snippet || m.body || '');

            html += `
                <div class="gmail-row ${isUnread ? 'unread' : ''} ${isActive ? 'active' : ''}" data-id="${m.mail_id}" onclick="MailModule.openMail(${m.mail_id}, this)">
                    <div class="gmail-row-left">
                        <input type="checkbox" class="gmail-checkbox" onclick="event.stopPropagation()">
                        <button type="button" class="gmail-star-btn ${isStarred ? 'starred' : ''}" onclick="MailModule.toggleStar(${m.mail_id}, event)" title="${isStarred ? 'Unstar' : 'Star'}">
                            <i class="${isStarred ? 'fas' : 'far'} fa-star"></i>
                        </button>
                        <button type="button" class="gmail-tag-btn ${isImportant ? 'important' : ''}" onclick="MailModule.toggleImportant(${m.mail_id}, event)" title="${isImportant ? 'Unmark important' : 'Mark important'}">
                            <i class="${isImportant ? 'fas' : 'far'} fa-bookmark"></i>
                        </button>
                    </div>

                    <div class="gmail-row-sender">${this.escapeHtml(senderOrRecip)}</div>
                    
                    <div class="gmail-row-body">
                        <span class="gmail-row-subject">${this.escapeHtml(m.subject || '(No Subject)')}</span>
                        <span class="gmail-row-snippet">- ${this.escapeHtml(snippetText)}</span>
                    </div>

                    <div class="gmail-row-right">
                        ${m.attachment_count > 0 ? `<span class="gmail-chip-attach" title="${m.attachment_count} attachment(s)"><i class="fas fa-paperclip"></i></span>` : ''}
                        <span class="gmail-row-date">${timeStr}</span>
                        <div class="gmail-row-hover-actions">
                            ${this.currentFolder === 'trash' ? `
                                <button type="button" class="gmail-action-btn" onclick="MailModule.restoreMail(${m.mail_id}, event)" title="Restore"><i class="fas fa-undo"></i></button>
                            ` : `
                                <button type="button" class="gmail-action-btn" onclick="MailModule.archiveMail(${m.mail_id}, event)" title="Archive"><i class="fas fa-archive"></i></button>
                                <button type="button" class="gmail-action-btn" onclick="MailModule.trashMail(${m.mail_id}, event)" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                <button type="button" class="gmail-action-btn" onclick="MailModule.toggleReadStatus(${m.mail_id}, event)" title="Mark as unread"><i class="fas fa-envelope"></i></button>
                            `}
                        </div>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    },

    renderPaginationFooter(totalItems) {
        const pageInfo = document.getElementById('essMailPageInfo');
        const prevBtn = document.getElementById('essMailPrevBtn');
        const nextBtn = document.getElementById('essMailNextBtn');
        const pageNum = document.getElementById('essMailPageNum');

        if (!totalItems) {
            if (pageInfo) pageInfo.textContent = 'Showing 0 of 0';
            if (pageNum) pageNum.textContent = 'Page 1 of 1';
            if (prevBtn) prevBtn.disabled = true;
            if (nextBtn) nextBtn.disabled = true;
            return;
        }

        const startItem = (this.currentPage - 1) * this.limit + 1;
        const endItem = Math.min(this.currentPage * this.limit, totalItems);

        if (pageInfo) pageInfo.textContent = `Showing ${startItem}-${endItem} of ${totalItems}`;
        if (pageNum) pageNum.textContent = `Page ${this.currentPage} of ${this.totalPages}`;
        if (prevBtn) prevBtn.disabled = (this.currentPage <= 1);
        if (nextBtn) nextBtn.disabled = (this.currentPage >= this.totalPages);
    },

    async toggleStar(mailId, event) {
        if (event) event.stopPropagation();
        try {
            const res = await fetch(this.getApiUrl('action=toggle_star'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `mail_id=${mailId}`
            });
            const data = await res.json();
            if (data.success) {
                this.updateFolderCounts();
                this.loadFolderMails();
            }
        } catch (err) {
            console.error('Star toggle error:', err);
        }
    },

    async toggleImportant(mailId, event) {
        if (event) event.stopPropagation();
        try {
            const res = await fetch(this.getApiUrl('action=toggle_important'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `mail_id=${mailId}`
            });
            const data = await res.json();
            if (data.success) {
                this.updateFolderCounts();
                this.loadFolderMails();
            }
        } catch (err) {
            console.error('Important toggle error:', err);
        }
    },

    async archiveMail(mailId, event) {
        if (event) event.stopPropagation();
        try {
            const res = await fetch(this.getApiUrl('action=archive_mail'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `mail_id=${mailId}`
            });
            const data = await res.json();
            if (data.success) {
                if (typeof showHrmsToast === 'function') showHrmsToast('Mail moved to Archive', 'success');
                if (this.activeMailId === mailId) this.clearReadingPane();
                this.updateFolderCounts();
                this.loadFolderMails();
            }
        } catch (err) {
            console.error('Archive error:', err);
        }
    },

    async trashMail(mailId, event) {
        if (event) event.stopPropagation();
        try {
            const res = await fetch(this.getApiUrl('action=trash_mail'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `mail_id=${mailId}`
            });
            const data = await res.json();
            if (data.success) {
                if (typeof showHrmsToast === 'function') showHrmsToast('Mail moved to Trash', 'info');
                if (this.activeMailId === mailId) this.clearReadingPane();
                this.updateFolderCounts();
                this.loadFolderMails();
            }
        } catch (err) {
            console.error('Trash error:', err);
        }
    },

    async restoreMail(mailId, event) {
        if (event) event.stopPropagation();
        try {
            const res = await fetch(this.getApiUrl('action=restore_mail'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `mail_id=${mailId}`
            });
            const data = await res.json();
            if (data.success) {
                if (typeof showHrmsToast === 'function') showHrmsToast('Mail restored to Inbox', 'success');
                if (this.activeMailId === mailId) this.clearReadingPane();
                this.updateFolderCounts();
                this.loadFolderMails();
            }
        } catch (err) {
            console.error('Restore error:', err);
        }
    },

    printMail(mailId) {
        const readingPane = document.getElementById('essMailReadingPane');
        if (!readingPane) return;
        const win = window.open('', '_blank');
        win.document.write(`
            <html>
                <head>
                    <title>Print Email</title>
                    <style>
                        body { font-family: 'Segoe UI', Tahoma, sans-serif; padding: 20px; color: #1e293b; line-height: 1.6; }
                        h2 { border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 16px; }
                        .meta { color: #64748b; font-size: 13px; margin-bottom: 20px; }
                        .body { background: #f8fafc; border: 1px solid #e2e8f0; padding: 16px; border-radius: 8px; }
                    </style>
                </head>
                <body>
                    ${readingPane.innerHTML}
                </body>
            </html>
        `);
        win.document.close();
        win.focus();
        setTimeout(() => win.print(), 500);
    },

    renderDraftList(drafts) {
        const container = document.getElementById('essMailItemsList');
        if (!container) return;

        this.renderPaginationFooter(drafts.length);

        if (!drafts.length) {
            container.innerHTML = `
                <div class="ess-mail-empty-folder">
                    <i class="fas fa-file-alt" style="font-size:36px;color:#475569;margin-bottom:10px;"></i>
                    <div style="font-weight:600;color:#94a3b8;">No draft emails</div>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;">Drafts are saved automatically when composing</div>
                </div>
            `;
            return;
        }

        let html = '';
        drafts.forEach(d => {
            const timeStr = this.formatMailTime(d.updated_at);
            const recipText = d.recipients && d.recipients.length ? d.recipients.map(r => r.name || r.email).join(', ') : 'No recipient';

            html += `
                <div class="ess-mail-card" onclick="MailModule.editDraft(${d.mail_id})">
                    <div class="ess-mail-card-left">
                        <div class="ess-mail-avatar-circle" style="background:#f59e0b;color:#fff;"><i class="fas fa-edit"></i></div>
                    </div>
                    <div class="ess-mail-card-content">
                        <div class="ess-mail-card-top">
                            <div class="ess-mail-card-sender" style="color:#f59e0b;">Draft: ${this.escapeHtml(recipText)}</div>
                            <div class="ess-mail-card-time">${timeStr}</div>
                        </div>
                        <div class="ess-mail-card-subline">
                            <span class="ess-mail-card-subject">${this.escapeHtml(d.subject || '(No Subject)')}</span>
                            <span class="ess-mail-card-snippet">- ${this.escapeHtml(d.body || '')}</span>
                        </div>
                    </div>
                    <div class="ess-mail-card-actions">
                        <button type="button" class="ess-mail-action-btn" onclick="MailModule.trashMail(${d.mail_id}, event)" title="Delete Draft"><i class="fas fa-trash-alt"></i></button>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    },

    getAvatarBg(name = '') {
        const colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#6366f1', '#14b8a6'];
        let hash = 0;
        for (let i = 0; i < name.length; i++) {
            hash = name.charCodeAt(i) + ((hash << 5) - hash);
        }
        return colors[Math.abs(hash) % colors.length];
    },

    async openMail(mailId, cardEl = null) {
        this.activeMailId = mailId;
        document.querySelectorAll('.gmail-row').forEach(c => c.classList.remove('active'));
        if (cardEl) {
            cardEl.classList.remove('unread');
            cardEl.classList.add('active');
        }

        const readingPane = document.getElementById('essMailReadingPane');
        const container = document.querySelector('.ess-mail-container');
        if (container) {
            container.classList.add('has-open-mail');
        }
        if (readingPane) {
            readingPane.style.display = 'flex';
            readingPane.innerHTML = '<div class="ess-mail-empty-state"><i class="fas fa-spinner fa-spin"></i><span>Loading email conversation...</span></div>';
        }

        try {
            const res = await fetch(this.getApiUrl(`action=read&mail_id=${mailId}`));
            const data = await res.json();

            if (!data.success) {
                if (readingPane) readingPane.innerHTML = `<div class="ess-mail-empty-state"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i><span>${data.message || 'Unable to open mail'}</span></div>`;
                return;
            }

            const mail = data.data.mail;
            const thread = data.data.thread || [];

            this.renderReadingPane(mail, thread);
            this.updateFolderCounts();

        } catch (err) {
            console.error('Read mail error:', err);
            if (readingPane) readingPane.innerHTML = '<div class="ess-mail-empty-state"><i class="fas fa-exclamation-circle"></i><span>Connection error</span></div>';
        }
    },

    renderReadingPane(mail, thread) {
        const readingPane = document.getElementById('essMailReadingPane');
        if (!readingPane) return;

        const senderInitials = this.getInitials(mail.sender_name);
        const avatarBg = this.getAvatarBg(mail.sender_name);
        const recipientListStr = mail.recipients ? mail.recipients.map(r => `${r.full_name} &lt;${r.email}&gt;`).join(', ') : '';

        let attachmentsHtml = '';
        if (mail.attachments && mail.attachments.length) {
            attachmentsHtml = `
                <div class="ess-mail-attachments-wrap">
                    <div class="ess-mail-attach-title"><i class="fas fa-paperclip"></i> Attachments (${mail.attachments.length})</div>
                    <div class="ess-mail-attach-grid">
                        ${mail.attachments.map(a => {
                            const isImg = a.file_type && a.file_type.startsWith('image/');
                            const dlUrl = `api/mail_api.php?action=download_attachment&id=${a.id}`;
                            return `
                                <a href="${dlUrl}" target="_blank" class="ess-mail-attach-chip">
                                    ${isImg ? `<img src="${a.file_path}" style="width:24px;height:24px;border-radius:4px;object-fit:cover;" alt="preview">` : '<i class="fas fa-file-download"></i>'}
                                    <span>${this.escapeHtml(a.file_name)}</span>
                                    <small style="color:#64748b;">(${this.formatFileSize(a.file_size)})</small>
                                </a>
                            `;
                        }).join('')}
                    </div>
                </div>
            `;
        }

        let threadHtml = '';
        if (thread.length > 1) {
            threadHtml = `
                <div class="ess-mail-thread-container">
                    <div class="ess-mail-thread-header"><i class="fas fa-comments"></i> Conversation Thread (${thread.length} messages)</div>
                    ${thread.map(t => `
                        <div class="ess-mail-thread-card">
                            <div class="ess-mail-sender-row">
                                <div class="ess-mail-avatar" style="background:${this.getAvatarBg(t.sender_name)};">${this.getInitials(t.sender_name)}</div>
                                <div class="ess-mail-sender-info">
                                    <div class="ess-mail-sender-name">${this.escapeHtml(t.sender_name)}</div>
                                    <div class="ess-mail-sender-email">${this.formatMailTime(t.created_at)}</div>
                                </div>
                            </div>
                             <div class="ess-mail-content-box">${this.sanitizeMailHtml(t.body)}</div>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        readingPane.innerHTML = `
            <div class="ess-mail-detail-header">
                <div class="ess-mail-detail-title-row">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <button type="button" class="ess-mail-icon-btn" onclick="MailModule.clearReadingPane()" title="Back to Inbox / List">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <h2 class="ess-mail-detail-subject" style="margin:0;">${this.escapeHtml(mail.subject || '(No Subject)')}</h2>
                    </div>
                    <div class="ess-mail-detail-actions">
                        <button type="button" class="ess-mail-icon-btn" onclick="MailModule.toggleStar(${mail.mail_id})" title="Star Email"><i class="far fa-star"></i></button>
                        <button type="button" class="ess-mail-icon-btn" onclick="MailModule.toggleImportant(${mail.mail_id})" title="Mark Important"><i class="far fa-bookmark"></i></button>
                        <button type="button" class="ess-mail-icon-btn" onclick="MailModule.archiveMail(${mail.mail_id})" title="Archive Email"><i class="fas fa-archive"></i></button>
                        <button type="button" class="ess-mail-icon-btn" onclick="MailModule.trashMail(${mail.mail_id})" title="Move to Trash"><i class="fas fa-trash-alt"></i></button>
                        <button type="button" class="ess-mail-icon-btn" onclick="MailModule.printMail(${mail.mail_id})" title="Print Email"><i class="fas fa-print"></i></button>
                        <button type="button" class="ess-mail-icon-btn" onclick="MailModule.replyMail(${mail.mail_id})" title="Reply"><i class="fas fa-reply"></i></button>
                        <button type="button" class="ess-mail-icon-btn" onclick="MailModule.clearReadingPane()" title="Close Reading Pane"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="ess-mail-sender-row">
                    <div class="ess-mail-avatar" style="background:${avatarBg};">${senderInitials}</div>
                    <div class="ess-mail-sender-info">
                        <div class="ess-mail-sender-name">${this.escapeHtml(mail.sender_name)}</div>
                        <div class="ess-mail-sender-email">${this.escapeHtml(mail.sender_email)} · ${this.formatMailTime(mail.created_at)}</div>
                        <div class="ess-mail-recipients-tag">To: ${recipientListStr}</div>
                    </div>
                </div>
            </div>

            <div class="ess-mail-detail-body">
                <div class="ess-mail-content-box">${this.sanitizeMailHtml(mail.body)}</div>
                ${attachmentsHtml}
                ${threadHtml}
            </div>

            <div class="ess-mail-reply-bar" style="display:flex;gap:10px;">
                <button type="button" class="ess-button primary" onclick="MailModule.replyMail(${mail.mail_id})">
                    <i class="fas fa-reply"></i> Reply
                </button>
                <button type="button" class="ess-button secondary" onclick="MailModule.forwardMail(${mail.mail_id})">
                    <i class="fas fa-share"></i> Forward
                </button>
            </div>
        `;
    },

    clearReadingPane() {
        this.activeMailId = null;
        const readingPane = document.getElementById('essMailReadingPane');
        const container = document.querySelector('.ess-mail-container');
        if (container) {
            container.classList.remove('has-open-mail');
        }
        if (readingPane) {
            readingPane.style.display = 'none';
            readingPane.innerHTML = '';
        }
    },

    openComposeModal() {
        this.selectedRecipients = [];
        this.uploadedAttachments = [];
        this.currentDraftId = null;
        this.replyParentId = null;

        const modal = document.getElementById('essMailComposeModal');
        if (!modal) {
            console.error('essMailComposeModal not found in DOM');
            return;
        }

        modal.classList.remove('minimized', 'maximized');

        const titleEl = document.getElementById('essMailComposeTitle');
        const subjEl = document.getElementById('essMailSubjectInput');
        const richEl = document.getElementById('essMailBodyRich');
        const impEl = document.getElementById('essMailImportanceInput');
        const toEl = document.getElementById('essMailToInput');

        if (titleEl) titleEl.textContent = 'New Message';
        if (subjEl) subjEl.value = '';

        let defaultBody = '';
        if (this.userSettings && this.userSettings.is_enabled && this.userSettings.signature_text) {
            defaultBody = '<br><br>-- <br>' + this.userSettings.signature_text;
        }
        if (richEl) richEl.innerHTML = defaultBody;
        if (impEl) impEl.value = (this.userSettings && this.userSettings.default_importance) || 'normal';
        if (toEl) toEl.value = '';

        this.renderRecipientChips();
        this.renderAttachmentChips();

        modal.style.display = 'flex';
    },

    closeComposeModal() {
        const modal = document.getElementById('essMailComposeModal');
        if (modal) modal.style.display = 'none';
    },

    toggleMinimizeCompose(e) {
        if (e) e.stopPropagation();
        const modal = document.getElementById('essMailComposeModal');
        if (modal) {
            modal.classList.toggle('minimized');
            modal.classList.remove('maximized');
        }
    },

    toggleMaximizeCompose() {
        const modal = document.getElementById('essMailComposeModal');
        const icon = document.getElementById('gmailComposeExpandIcon');
        if (modal) {
            const isMax = modal.classList.toggle('maximized');
            modal.classList.remove('minimized');
            if (icon) {
                icon.className = isMax ? 'fas fa-compress-alt' : 'fas fa-expand-alt';
            }
        }
    },

    toggleCcBcc(type) {
        if (type === 'cc') {
            const row = document.getElementById('gmailCcRow');
            if (row) row.classList.toggle('hidden');
        } else if (type === 'bcc') {
            const row = document.getElementById('gmailBccRow');
            if (row) row.classList.toggle('hidden');
        }
    },

    toggleFmtBar() {
        const toolbar = document.getElementById('gmailFmtToolbar');
        if (toolbar) {
            toolbar.style.display = (toolbar.style.display === 'none' || !toolbar.style.display) ? 'flex' : 'none';
        }
    },

    formatDoc(cmd, val = null) {
        document.execCommand(cmd, false, val);
    },

    toggleEmojiPicker(e) {
        if (e) e.stopPropagation();
        const picker = document.getElementById('gmailEmojiPicker');
        if (picker) {
            picker.classList.toggle('hidden');
        }
    },

    insertEmoji(emoji) {
        const richEl = document.getElementById('essMailBodyRich');
        if (richEl) {
            richEl.focus();
            document.execCommand('insertText', false, emoji);
        }
        const picker = document.getElementById('gmailEmojiPicker');
        if (picker) picker.classList.add('hidden');
    },

    promptInsertLink() {
        const url = prompt('Enter link URL (e.g. https://example.com):', 'https://');
        if (url && url !== 'https://') {
            this.formatDoc('createLink', url);
        }
    },

    insertSignature() {
        const richEl = document.getElementById('essMailBodyRich');
        if (richEl && this.userSettings && this.userSettings.signature_text) {
            richEl.focus();
            document.execCommand('insertHTML', false, '<br>-- <br>' + this.userSettings.signature_text);
        } else if (typeof showHrmsToast === 'function') {
            showHrmsToast('No signature saved in Mail Settings', 'info');
        }
    },

    getBodyContent() {
        const rich = document.getElementById('essMailBodyRich');
        if (rich) return rich.innerHTML.trim();
        const txt = document.getElementById('essMailBodyInput');
        return txt ? txt.value.trim() : '';
    },

    renderSettingsPane() {
        const container = document.querySelector('.ess-mail-container');
        if (container) {
            container.classList.remove('has-open-mail');
        }
        const readingPane = document.getElementById('essMailReadingPane');
        if (readingPane) {
            readingPane.style.display = 'none';
            readingPane.innerHTML = '';
        }

        const listContainer = document.getElementById('essMailItemsList');
        if (!listContainer) return;

        const sigHtml = (this.userSettings && this.userSettings.signature_text) || '';
        const isEnabled = this.userSettings && this.userSettings.is_enabled;
        const defImp = (this.userSettings && this.userSettings.default_importance) || 'normal';

        listContainer.innerHTML = `
            <div style="padding:24px;display:flex;flex-direction:column;gap:20px;width:100%;max-width:800px;margin:0 auto;overflow-y:auto;height:100%;">
                <div style="border-bottom:1px solid rgba(255,255,255,0.08);padding-bottom:12px;">
                    <h2 style="font-size:20px;font-weight:800;color:#f8fafc;margin:0;"><i class="fas fa-signature" style="color:#3b82f6;"></i> Gmail-Style Signature Studio</h2>
                    <p style="font-size:12.5px;color:#94a3b8;margin-top:4px;">Design a professional, rich HTML email signature for your company emails</p>
                </div>

                <!-- Template Presets -->
                <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:10px;">
                    <div style="font-size:13px;font-weight:700;color:#f1f5f9;"><i class="fas fa-magic" style="color:#f59e0b;"></i> Fast Signature Presets</div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;">
                        <button type="button" class="ess-sig-preset-btn" onclick="MailModule.applySigTemplate('corporate')">
                            <i class="fas fa-briefcase"></i> Balitech Corporate
                        </button>
                        <button type="button" class="ess-sig-preset-btn" onclick="MailModule.applySigTemplate('executive')">
                            <i class="fas fa-user-tie"></i> Executive Compact
                        </button>
                        <button type="button" class="ess-sig-preset-btn" onclick="MailModule.applySigTemplate('minimal')">
                            <i class="fas fa-align-left"></i> Minimal Clean
                        </button>
                    </div>
                </div>

                <!-- Signature Editor -->
                <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px;display:flex;flex-direction:column;gap:12px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <h3 style="font-size:14px;font-weight:700;color:#f1f5f9;margin:0;"><i class="fas fa-edit"></i> Signature Content</h3>
                        <span style="font-size:11.5px;color:#64748b;">Rich HTML Formatting</span>
                    </div>

                    <!-- WYSIWYG Toolbar -->
                    <div class="ess-sig-toolbar">
                        <button type="button" class="ess-sig-tool-btn" onclick="MailModule.execSigCmd('bold')" title="Bold"><i class="fas fa-bold"></i></button>
                        <button type="button" class="ess-sig-tool-btn" onclick="MailModule.execSigCmd('italic')" title="Italic"><i class="fas fa-italic"></i></button>
                        <button type="button" class="ess-sig-tool-btn" onclick="MailModule.execSigCmd('underline')" title="Underline"><i class="fas fa-underline"></i></button>
                        <button type="button" class="ess-sig-tool-btn" onclick="MailModule.execSigCmd('justifyLeft')" title="Align Left"><i class="fas fa-align-left"></i></button>
                        <button type="button" class="ess-sig-tool-btn" onclick="MailModule.execSigCmd('justifyCenter')" title="Align Center"><i class="fas fa-align-center"></i></button>
                        <button type="button" class="ess-sig-tool-btn" onclick="MailModule.execSigCmd('justifyRight')" title="Align Right"><i class="fas fa-align-right"></i></button>
                        <button type="button" class="ess-sig-tool-btn" onclick="MailModule.promptSigLink()" title="Insert Link"><i class="fas fa-link"></i></button>
                        <button type="button" class="ess-sig-tool-btn" onclick="MailModule.execSigCmd('insertHorizontalRule')" title="Divider Line"><i class="fas fa-minus"></i></button>
                        <button type="button" class="ess-sig-tool-btn" onclick="MailModule.execSigCmd('removeFormat')" title="Clear Formatting"><i class="fas fa-eraser"></i></button>
                    </div>

                    <!-- Canvas -->
                    <div id="settingSigCanvas" class="ess-sig-editor-canvas" contenteditable="true" oninput="MailModule.updateSigPreview()">${sigHtml || '<p>Type or paste your signature here...</p>'}</div>

                    <div style="display:flex;align-items:center;gap:10px;margin-top:4px;">
                        <input type="checkbox" id="settingSignatureEnabled" ${isEnabled ? 'checked' : ''} style="width:16px;height:16px;accent-color:#3b82f6;cursor:pointer;">
                        <label for="settingSignatureEnabled" style="font-size:13px;color:#e2e8f0;cursor:pointer;">Enable signature automatically in outgoing emails</label>
                    </div>
                </div>

                <!-- Live Preview Card -->
                <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px;display:flex;flex-direction:column;gap:10px;">
                    <div style="font-size:13px;font-weight:700;color:#94a3b8;"><i class="fas fa-eye"></i> Live Signature Preview</div>
                    <div id="settingSigPreview" class="ess-sig-preview-box">${sigHtml || '<em style="color:#64748b;">No signature created yet</em>'}</div>
                </div>

                <!-- Default Preferences -->
                <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <div style="font-size:14px;font-weight:700;color:#f1f5f9;"><i class="fas fa-sliders-h"></i> Default Importance</div>
                        <div style="font-size:12px;color:#94a3b8;margin-top:2px;">Initial priority assigned when composing new emails</div>
                    </div>
                    <select id="settingDefaultImportance" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#fff;border-radius:8px;padding:8px 14px;font-size:13px;outline:none;">
                        <option value="normal" ${defImp === 'normal' ? 'selected' : ''}>Normal</option>
                        <option value="high" ${defImp === 'high' ? 'selected' : ''}>High Importance</option>
                        <option value="low" ${defImp === 'low' ? 'selected' : ''}>Low Priority</option>
                    </select>
                </div>

                <div>
                    <button type="button" class="ess-button primary" onclick="MailModule.saveUserSettings()"><i class="fas fa-save"></i> Save Settings & Signature</button>
                </div>
            </div>
        `;
    },

    execSigCmd(cmd, val = null) {
        document.execCommand(cmd, false, val);
        this.updateSigPreview();
    },

    promptSigLink() {
        const url = prompt('Enter link URL (e.g. https://balitech.com):');
        if (url) {
            this.execSigCmd('createLink', url);
        }
    },

    updateSigPreview() {
        const canvas = document.getElementById('settingSigCanvas');
        const preview = document.getElementById('settingSigPreview');
        if (canvas && preview) {
            preview.innerHTML = canvas.innerHTML || '<em style="color:#64748b;">No signature created yet</em>';
        }
    },

    applySigTemplate(key) {
        const canvas = document.getElementById('settingSigCanvas');
        if (!canvas) return;

        const userName = (window.HRMS && HRMS.user && HRMS.user.full_name) ? HRMS.user.full_name : 'Employee Name';
        const userDesig = (window.HRMS && HRMS.user && HRMS.user.designation) ? HRMS.user.designation : 'Team Member';
        const userDept = (window.HRMS && HRMS.user && HRMS.user.department) ? HRMS.user.department : 'Balitech Team';
        const userEmail = (window.HRMS && HRMS.user && HRMS.user.email) ? HRMS.user.email : 'employee@balitech.com';
        const userCode = (window.HRMS && HRMS.user && HRMS.user.employee_code) ? HRMS.user.employee_code : '000';

        let tpl = '';
        if (key === 'corporate') {
            tpl = `
                <div style="border-left: 3px solid #3b82f6; padding-left: 12px; font-family: Inter, sans-serif; margin-top: 10px;">
                    <div style="font-weight: 800; font-size: 15px; color: #60a5fa;">${this.escapeHtml(userName)}</div>
                    <div style="font-size: 13px; color: #cbd5e1; font-weight: 600;">${this.escapeHtml(userDesig)} &bull; ${this.escapeHtml(userDept)}</div>
                    <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">
                        <span><i class="fas fa-id-badge"></i> Code: ${this.escapeHtml(userCode)}</span> &bull; 
                        <span><i class="fas fa-envelope"></i> ${this.escapeHtml(userEmail)}</span>
                    </div>
                    <div style="font-size: 11.5px; color: #3b82f6; font-weight: 700; margin-top: 6px;">
                        BALITECH PVT. LTD &bull; Internal HRMS Communication
                    </div>
                </div>
            `;
        } else if (key === 'executive') {
            tpl = `
                <div style="font-family: Inter, sans-serif; padding-top: 8px;">
                    <div style="font-size: 14px; font-weight: 800; color: #f8fafc;">${this.escapeHtml(userName)}</div>
                    <div style="font-size: 12.5px; color: #f59e0b; font-weight: 700;">${this.escapeHtml(userDesig)}</div>
                    <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">Email: <a href="mailto:${this.escapeHtml(userEmail)}" style="color:#60a5fa;text-decoration:none;">${this.escapeHtml(userEmail)}</a></div>
                </div>
            `;
        } else {
            tpl = `
                <div style="font-family: Inter, sans-serif; font-size: 13px; color: #cbd5e1;">
                    Regards,<br>
                    <strong>${this.escapeHtml(userName)}</strong> | ${this.escapeHtml(userDesig)}<br>
                    <small style="color:#64748b;">${this.escapeHtml(userEmail)}</small>
                </div>
            `;
        }

        canvas.innerHTML = tpl;
        this.updateSigPreview();
        showToast('Applied signature template preset', 'success');
    },

    async saveUserSettings() {
        const canvas = document.getElementById('settingSigCanvas');
        const sigText = canvas ? canvas.innerHTML.trim() : '';
        const isEnabled = document.getElementById('settingSignatureEnabled').checked ? 1 : 0;
        const defImp = document.getElementById('settingDefaultImportance').value;

        try {
            const res = await fetch(this.getApiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_settings',
                    signature_text: sigText,
                    is_enabled: isEnabled,
                    default_importance: defImp
                })
            });
            const data = await res.json();
            if (data.success) {
                this.userSettings = {
                    signature_text: sigText,
                    is_enabled: isEnabled,
                    default_importance: defImp
                };
                if (typeof showHrmsToast === 'function') showHrmsToast('Signature settings saved!', 'success');
            } else {
                if (typeof showHrmsToast === 'function') showHrmsToast(data.message || 'Failed to save settings', 'error');
            }
        } catch (err) {
            console.error('Save settings error:', err);
        }
    },

    replyMail(mailId) {
        fetch(this.getApiUrl(`action=read&mail_id=${mailId}`))
            .then(res => res.json())
            .then(data => {
                if (!data.success) return;
                const m = data.data.mail;
                this.selectedRecipients = [{
                    id: m.sender_id,
                    full_name: m.sender_name,
                    email: m.sender_email,
                    type: 'to'
                }];
                this.uploadedAttachments = [];
                this.currentDraftId = null;
                this.replyParentId = m.mail_id;

                document.getElementById('essMailComposeTitle').textContent = `Reply: ${m.subject}`;
                document.getElementById('essMailSubjectInput').value = m.subject.startsWith('Re:') ? m.subject : `Re: ${m.subject}`;
                document.getElementById('essMailBodyInput').value = `\n\n--- Original Message ---\nFrom: ${m.sender_name}\nSubject: ${m.subject}\n\n${m.body}`;
                document.getElementById('essMailImportanceInput').value = 'normal';

                this.renderRecipientChips();
                this.renderAttachmentChips();

                document.getElementById('essMailComposeModal').style.display = 'flex';
            });
    },

    forwardMail(mailId) {
        fetch(this.getApiUrl(`action=read&mail_id=${mailId}`))
            .then(res => res.json())
            .then(data => {
                if (!data.success) return;
                const m = data.data.mail;
                this.selectedRecipients = [];
                this.uploadedAttachments = m.attachments ? [...m.attachments] : [];
                this.currentDraftId = null;
                this.replyParentId = null;

                document.getElementById('essMailComposeTitle').textContent = `Forward: ${m.subject}`;
                document.getElementById('essMailSubjectInput').value = m.subject.startsWith('Fwd:') ? m.subject : `Fwd: ${m.subject}`;
                document.getElementById('essMailBodyInput').value = `\n\n---------- Forwarded message ---------\nFrom: ${m.sender_name} <${m.sender_email}>\nDate: ${m.created_at}\nSubject: ${m.subject}\n\n${m.body}`;
                document.getElementById('essMailImportanceInput').value = m.importance || 'normal';

                this.renderRecipientChips();
                this.renderAttachmentChips();

                document.getElementById('essMailComposeModal').style.display = 'flex';
            });
    },

    editDraft(draftId) {
        const draft = this.drafts.find(d => d.mail_id === draftId);
        if (!draft) return;

        this.currentDraftId = draft.mail_id;
        this.replyParentId = null;
        this.selectedRecipients = draft.recipients.map(r => ({
            id: r.id,
            full_name: r.name,
            email: r.email,
            type: r.type || 'to'
        }));
        this.uploadedAttachments = [];

        document.getElementById('essMailComposeTitle').textContent = 'Edit Draft';
        document.getElementById('essMailSubjectInput').value = draft.subject || '';
        document.getElementById('essMailBodyInput').value = draft.body || '';
        document.getElementById('essMailImportanceInput').value = draft.importance || 'normal';

        this.renderRecipientChips();
        this.renderAttachmentChips();

        document.getElementById('essMailComposeModal').style.display = 'flex';
    },

    closeComposeModal() {
        document.getElementById('essMailComposeModal').style.display = 'none';
    },

    handleEmployeeSearch(val) {
        clearTimeout(this.searchDebounceTimer);
        const dropdown = document.getElementById('essMailEmpDropdown');
        if (!dropdown) return;

        if (!val.trim()) {
            dropdown.style.display = 'none';
            return;
        }

        this.searchDebounceTimer = setTimeout(async () => {
            try {
                const res = await fetch(this.getApiUrl(`action=search_employees&q=${encodeURIComponent(val)}`));
                const data = await res.json();

                if (!data.success || !data.data.length) {
                    dropdown.innerHTML = '<div style="padding:10px;color:#94a3b8;font-size:12px;">No employees found</div>';
                    dropdown.style.display = 'block';
                    return;
                }

                let html = '';
                data.data.forEach(emp => {
                    html += `
                        <div class="ess-mail-emp-item" onclick="MailModule.addRecipient(${emp.id}, '${this.escapeHtml(emp.full_name)}', '${this.escapeHtml(emp.email)}')">
                            <div>
                                <div class="ess-mail-emp-name">${this.escapeHtml(emp.full_name)}</div>
                                <div class="ess-mail-emp-email">${this.escapeHtml(emp.email)} · ${this.escapeHtml(emp.designation || 'Employee')}</div>
                            </div>
                            <i class="fas fa-plus-circle" style="color:#3b82f6;"></i>
                        </div>
                    `;
                });
                dropdown.innerHTML = html;
                dropdown.style.display = 'block';
            } catch (err) {
                console.error(err);
            }
        }, 250);
    },

    addRecipient(id, name, email) {
        const isDup = this.selectedRecipients.some(r => (id > 0 ? r.id === id : r.email === email));
        if (!isDup) {
            this.selectedRecipients.push({ id: id || 0, full_name: name, email: email, type: 'to' });
            this.renderRecipientChips();
        }
        document.getElementById('essMailToInput').value = '';
        const dropdown = document.getElementById('essMailEmpDropdown');
        if (dropdown) dropdown.style.display = 'none';
    },

    removeRecipient(identifier) {
        this.selectedRecipients = this.selectedRecipients.filter(r => (typeof identifier === 'number' && identifier > 0 ? r.id !== identifier : r.email !== identifier));
        this.renderRecipientChips();
    },

    renderRecipientChips() {
        const wrap = document.getElementById('essMailRecipientsWrap');
        if (!wrap) return;

        let html = '';
        this.selectedRecipients.forEach(r => {
            html += `
                <div class="ess-mail-recipient-chip">
                    <span>${this.escapeHtml(r.full_name)}</span>
                    <i class="fas fa-times" onclick="MailModule.removeRecipient(${r.id})"></i>
                </div>
            `;
        });
        wrap.innerHTML = html;
    },

    async handleFileUpload(fileInput) {
        if (!fileInput.files || !fileInput.files.length) return;
        const file = fileInput.files[0];

        const formData = new FormData();
        formData.append('file', file);

        try {
            const res = await fetch(this.getApiUrl('action=upload_attachment'), {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                this.uploadedAttachments.push(data.data);
                this.renderAttachmentChips();
                if (typeof showHrmsToast === 'function') showHrmsToast('File attached successfully', 'success');
            } else {
                if (typeof showHrmsToast === 'function') showHrmsToast(data.message || 'File upload failed', 'error');
            }
        } catch (err) {
            console.error('File upload error:', err);
        }
        fileInput.value = '';
    },

    renderAttachmentChips() {
        const wrap = document.getElementById('essMailComposeAttachments');
        if (!wrap) return;

        if (!this.uploadedAttachments.length) {
            wrap.innerHTML = '';
            return;
        }

        let html = '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">';
        this.uploadedAttachments.forEach((att, idx) => {
            html += `
                <div class="ess-mail-recipient-chip" style="background:rgba(16,185,129,0.2);border-color:rgba(16,185,129,0.4);color:#a7f3d0;">
                    <i class="fas fa-paperclip"></i>
                    <span>${this.escapeHtml(att.file_name)}</span>
                    <i class="fas fa-times" onclick="MailModule.removeAttachment(${idx})"></i>
                </div>
            `;
        });
        html += '</div>';
        wrap.innerHTML = html;
    },

    removeAttachment(index) {
        this.uploadedAttachments.splice(index, 1);
        this.renderAttachmentChips();
    },

    async sendMail() {
        const subjectInput = document.getElementById('essMailSubjectInput');
        const subject = subjectInput ? subjectInput.value.trim() : '';
        const body = this.getBodyContent();
        const importanceInput = document.getElementById('essMailImportanceInput');
        const importance = importanceInput ? importanceInput.value : 'normal';

        if (!this.selectedRecipients.length) {
            if (typeof showHrmsToast === 'function') showHrmsToast('Please select at least one recipient', 'error');
            return;
        }

        if (!body) {
            if (typeof showHrmsToast === 'function') showHrmsToast('Email body cannot be empty', 'error');
            return;
        }

        const payload = {
            action: 'send',
            subject: subject,
            body: body,
            importance: importance,
            recipients: this.selectedRecipients,
            attachments: this.uploadedAttachments,
            parent_id: this.replyParentId,
            draft_id: this.currentDraftId
        };

        const btn = document.getElementById('btnSendMail');
        if (btn) btn.disabled = true;

        try {
            const res = await fetch(this.getApiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (data.success) {
                if (typeof showHrmsToast === 'function') showHrmsToast('Email sent successfully!', 'success');
                this.closeComposeModal();
                this.switchFolder('sent');
            } else {
                if (typeof showHrmsToast === 'function') showHrmsToast(data.message || 'Failed to send email', 'error');
            }
        } catch (err) {
            console.error('Send mail error:', err);
        } finally {
            if (btn) btn.disabled = false;
        }
    },

    async saveDraft() {
        const subjectInput = document.getElementById('essMailSubjectInput');
        const subject = subjectInput ? subjectInput.value.trim() : '';
        const body = this.getBodyContent();
        const importanceInput = document.getElementById('essMailImportanceInput');
        const importance = importanceInput ? importanceInput.value : 'normal';

        const payload = {
            action: 'save_draft',
            subject: subject,
            body: body,
            importance: importance,
            recipients: this.selectedRecipients,
            draft_id: this.currentDraftId
        };

        try {
            const res = await fetch(this.getApiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (data.success) {
                this.currentDraftId = data.data.draft_id;
                if (typeof showHrmsToast === 'function') showHrmsToast('Draft saved', 'success');
            }
        } catch (err) {
            console.error('Save draft error:', err);
        }
    },

    async toggleReadStatus(mailId) {
        try {
            await fetch(this.getApiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_unread', mail_id: mailId })
            });
            if (typeof showHrmsToast === 'function') showHrmsToast('Marked as unread', 'success');
            this.updateFolderCounts();
            this.loadFolderMails();
        } catch (err) {
            console.error(err);
        }
    },

    async deleteMail(mailId) {
        if (!confirm('Are you sure you want to delete this email?')) return;

        try {
            const res = await fetch(this.getApiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_mail', mail_id: mailId })
            });
            const data = await res.json();

            if (data.success) {
                if (typeof showHrmsToast === 'function') showHrmsToast('Email deleted', 'success');
                this.clearReadingPane();
                this.loadFolderMails();
                this.updateFolderCounts();
            }
        } catch (err) {
            console.error(err);
        }
    },

    // Utilities
    getInitials(name) {
        if (!name) return 'U';
        const parts = name.trim().split(' ');
        if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
        return parts[0][0].toUpperCase();
    },

    formatMailTime(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        const now = new Date();
        if (d.toDateString() === now.toDateString()) {
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    },

    formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    },

    escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }
};

document.addEventListener('DOMContentLoaded', () => {
    MailModule.init();
});
