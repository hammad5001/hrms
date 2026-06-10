/**
 * Balitech Workspace Chat — messaging with edit, delete, attachments, read receipts
 * Features: edit, delete, clear chat, delete chat, blue seen ticks, context menus
 */
const Chat = {
    me: null,
    conversations: [],
    activeId: null,
    messages: [],
    heartbeatTimer: null,
    hasMoreHistory: false,
    ws: {
        socket: null,
        url: '',
        token: '',
        connected: false,
        enabled: false,
        reconnectMs: 2000,
        reconnectTimer: null,
        pingTimer: null,
        subscribedCid: null,
        reconnectAttempts: 0,
        maxReconnectAttempts: 25,
    },
    groupMembers: [],
    searchTimer: null,
    sidebarSearchTimer: null,
    groupSearchTimer: null,
    searchRequestId: 0,
    typingTimer: null,
    typingSent: false,
    convType: 'direct',
    lastRead: null,
    presence: null,
    // Message action state
    contextMsgId: null,
    editMsgId: null,
    deleteMsgId: null,
    chatActionPending: null,   // { action: 'clear'|'delete', cid }
    archivedIds: [],
    listFilter: 'all',         // all | unread | groups | requests
    showArchived: false,
    requestCount: 0,
    blockedCount: 0,
    blockedUsers: [],
    activeMeta: null,
    activePeerId: null,
    inChatSearch: '',
    replyTo: null,
    mutedIds: new Set(),
    blinkMsgIds: new Set(),
    uploading: false,
    memberColors: ['#4f46e5', '#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777'],
    scroll: {
        pinnedToBottom: true,
        lastScrollTop: 0,
        lastScrollHeight: 0,
        userHasScrolled: false,
        opening: false,
        programmatic: false,
    }
};

function getMsgArea() {
    return document.getElementById('msgArea');
}

/** Keep scroll position unless user is at the bottom. */
function renderScrollMode() {
    return Chat.scroll.pinnedToBottom && !Chat.scroll.userHasScrolled ? 'bottom' : 'preserve';
}

function isNearBottom(area, threshold = 100) {
    if (!area) return true;
    return area.scrollHeight - area.scrollTop - area.clientHeight <= threshold;
}

function saveScrollAnchor(area) {
    if (!area) return;
    Chat.scroll.lastScrollTop = area.scrollTop;
    Chat.scroll.lastScrollHeight = area.scrollHeight;
    Chat.scroll.pinnedToBottom = isNearBottom(area);
}

function applyScrollAfterRender(area, mode) {
    if (!area) return;
    if (mode === 'preserve') {
        scrollMsgAreaTo(Chat.scroll.lastScrollTop);
        return;
    }
    if (mode === 'bottom') {
        scrollToBottomStable(area);
        Chat.scroll.pinnedToBottom = true;
        return;
    }
    if (mode === 'unread') {
        // Highlight only — always open at latest messages (no jump to middle).
        scrollToBottomStable(area);
        Chat.scroll.pinnedToBottom = true;
        return;
    }
    if (mode === 'search') {
        const hit = area.querySelector('.chat-msg.search-hit');
        if (hit) {
            Chat.scroll.programmatic = true;
            hit.scrollIntoView({ block: 'center', behavior: 'auto' });
            Chat.scroll.pinnedToBottom = false;
            Chat.scroll.userHasScrolled = true;
            requestAnimationFrame(() => { Chat.scroll.programmatic = false; });
        }
        return;
    }
    if (Chat.scroll.pinnedToBottom && !Chat.scroll.userHasScrolled) {
        scrollToBottomStable(area);
    } else if (!Chat.scroll.pinnedToBottom) {
        scrollMsgAreaTo(Chat.scroll.lastScrollTop);
    }
}

/** Scroll to bottom once layout + images have settled (avoids open-chat jitter). */
function scrollToBottomStable(area) {
    if (!area) return;
    Chat.scroll.programmatic = true;
    const snap = () => scrollMsgAreaTo(area.scrollHeight);
    snap();
    requestAnimationFrame(() => {
        snap();
        requestAnimationFrame(() => {
            snap();
            Chat.scroll.programmatic = false;
        });
    });
}

function scrollMsgAreaTo(y) {
    const area = getMsgArea();
    if (!area) return;
    const prev = area.style.scrollBehavior;
    area.style.scrollBehavior = 'auto';
    area.scrollTop = y;
    requestAnimationFrame(() => { area.style.scrollBehavior = prev; });
}

function initScrollGuard() {
    const area = getMsgArea();
    if (!area || area.dataset.scrollGuard) return;
    area.dataset.scrollGuard = '1';
    area.addEventListener('scroll', () => {
        if (Chat.scroll.programmatic) return;
        Chat.scroll.pinnedToBottom = isNearBottom(area);
        Chat.scroll.userHasScrolled = !Chat.scroll.pinnedToBottom;
    }, { passive: true });
}

function bindImageScrollStabilizer(area) {
    if (!area) return;
    area.querySelectorAll('.chat-img').forEach(img => {
        if (img.dataset.scrollBound) return;
        img.dataset.scrollBound = '1';
        const onResize = () => {
            if (Chat.scroll.pinnedToBottom && !Chat.scroll.userHasScrolled) {
                scrollMsgAreaTo(area.scrollHeight);
            }
        };
        if (!img.complete) img.addEventListener('load', onResize, { once: true });
    });
}

function memberColor(id) {
    return Chat.memberColors[Math.abs(id) % Chat.memberColors.length];
}

function isImageFile(file) {
    return file && (file.type.startsWith('image/') || /\.(jpe?g|png|gif|webp|bmp)$/i.test(file.name || ''));
}

function fileIconClass(name, mime) {
    const n = (name || '').toLowerCase();
    if (mime?.includes('pdf') || n.endsWith('.pdf')) return 'fa-file-pdf';
    if (n.endsWith('.doc') || n.endsWith('.docx')) return 'fa-file-word';
    return 'fa-file-lines';
}

function isUnreadIncomingMessage(m, lastReadAt) {
    if (m.is_mine || m.is_deleted || !m.id || String(m.id).startsWith('tmp')) return false;
    if (!lastReadAt) return true;
    const msgTs = new Date(m.created_at).getTime();
    const readTs = new Date(lastReadAt).getTime();
    return msgTs > readTs;
}

/** Highlight only messages that were unread before opening (not full history). */
function applyUnreadHighlights(messages, lastReadAt, unreadCount) {
    Chat.blinkMsgIds.clear();
    const unread = messages.filter(m => isUnreadIncomingMessage(m, lastReadAt));
    let toMark = unread;
    if (unreadCount > 0 && unread.length > unreadCount) {
        toMark = unread.slice(-unreadCount);
    }
    toMark.forEach(m => {
        Chat.blinkMsgIds.add(m.id);
        scheduleBlinkClear(m.id);
    });
    return toMark.length;
}

/** New messages arriving while chat is open (live). */
function highlightNewIncoming(msgs) {
    msgs.filter(m => !m.is_mine && m.id && !String(m.id).startsWith('tmp') && !m.is_deleted).forEach(m => {
        Chat.blinkMsgIds.add(m.id);
        scheduleBlinkClear(m.id);
    });
}

function scheduleBlinkClear(msgId) {
    setTimeout(() => {
        Chat.blinkMsgIds.delete(msgId);
        const el = document.querySelector(`.chat-msg[data-msg-id="${msgId}"]`);
        if (el) el.classList.remove('msg-unread-blink');
    }, 2200);
}

/** Update seen label without re-scrolling (banner is also rendered in renderMessages). */
function updateSeenBanner() {
    if (Chat.convType !== 'direct') return;
    const area = getMsgArea();
    if (!area) return;
    const label = Chat.lastRead?.label;
    let banner = area.querySelector('.chat-seen-banner');
    if (label) {
        if (!banner) return;
        banner.textContent = label;
    } else {
        banner?.remove();
    }
}

const CHAT_PREFS_KEY = 'balitech_chat_prefs_v1';

function loadChatPrefs() {
    try {
        const raw = localStorage.getItem(CHAT_PREFS_KEY);
        if (!raw) return { archived: [], muted: [] };
        const p = JSON.parse(raw);
        return {
            archived: Array.isArray(p.archived) ? p.archived.map(Number) : [],
            muted: Array.isArray(p.muted) ? p.muted.map(Number) : [],
        };
    } catch {
        return { archived: [], muted: [] };
    }
}

function saveChatPrefs() {
    localStorage.setItem(CHAT_PREFS_KEY, JSON.stringify({
        archived: Chat.archivedIds || [],
        muted: [...Chat.mutedIds],
    }));
}

function initChatPrefs() {
    const p = loadChatPrefs();
    Chat.archivedIds = p.archived;
    Chat.mutedIds = new Set(p.muted);
}

const EMOJIS = ['😀','😃','😄','😁','😅','😂','🤣','😊','😇','🙂','😉','😍','🥰','😘','😋','😎','🤔','😐','😢','😭','😡','👍','👎','👏','🙏','💪','❤️','🔥','✅','❌','⭐','🎉','📎','📷','💼','☕','🚀'];

/* ─── Utility ───────────────────────────────────────────── */
function chatApiRoot() {
    if (window.CHAT_API_ROOT) {
        return String(window.CHAT_API_ROOT).replace(/\/?$/, '/');
    }
    return new URL('./', window.location.href).href;
}

function chatApiUrl(action, params = {}) {
    const url = new URL('api/chat_api.php', chatApiRoot());
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([key, value]) => {
        if (value != null && String(value) !== '') {
            url.searchParams.set(key, String(value));
        }
    });
    return url.href;
}

async function parseChatResponse(r) {
    const text = await r.text();
    if (!r.ok) {
        console.error('Chat API HTTP', r.status, r.url, text.slice(0, 240));
        return { success: false, error: r.status === 401 ? 'Session expired. Please sign in again.' : 'Server error. Try again.' };
    }
    try { return JSON.parse(text); }
    catch {
        console.error('Chat API invalid JSON', r.url, text.slice(0, 240));
        return { success: false, error: 'Server error. Try again.' };
    }
}

async function chatApi(action, opts = {}) {
    const method = opts.method || 'GET';
    if (method === 'GET') {
        const r = await fetch(chatApiUrl(action, opts.params || {}), { credentials: 'include' });
        return parseChatResponse(r);
    }
    if (opts.formData) {
        if (!opts.formData.has('action')) opts.formData.append('action', action);
        let url = chatApiUrl(action);
        const cid = opts.conversationId || Chat.activeId;
        if (cid && action === 'upload') {
            const uploadUrl = new URL(url);
            uploadUrl.searchParams.set('conversation_id', String(cid));
            url = uploadUrl.href;
            if (!opts.formData.has('conversation_id')) opts.formData.append('conversation_id', String(cid));
        }
        const r = await fetch(url, { method: 'POST', credentials: 'include', body: opts.formData });
        return parseChatResponse(r);
    }
    const r = await fetch(chatApiUrl(action), {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(opts.body || {})
    });
    return parseChatResponse(r);
}

function chatFileUrl(path) {
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    try { return new URL(path.replace(/^\//, ''), window.location.href).href; }
    catch { return path; }
}

function toast(msg, duration = 3000) {
    const el = document.getElementById('chatToast');
    el.textContent = msg;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), duration);
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
}

/** Professional avatar: photo or initials fallback. */
function avatarHtml(opts = {}) {
    const {
        url, name, id, group = false, groupIcon = false, className = '', color: colorIn
    } = opts;
    const color = colorIn || memberColor(id || (name || 'x').length);
    let cls = `avatar${group ? ' group' : ''}${url ? ' avatar--photo' : ''}`;
    if (className) cls += ` ${className}`;
    if (group && groupIcon && !url) {
        return `<div class="${cls}" style="background:${color}"><i class="fas fa-users"></i></div>`;
    }
    if (url) {
        return `<div class="${cls}" style="background:${color}"><img src="${escapeHtml(chatFileUrl(url))}" alt="${escapeHtml(name || 'User')}" loading="lazy"></div>`;
    }
    return `<div class="${cls}" style="background:${color}">${escapeHtml(initials(name))}</div>`;
}

function setAvatarElement(el, opts) {
    if (!el) return;
    const color = opts.color || memberColor(opts.id || (opts.name || 'x').length);
    el.className = 'avatar' + (opts.group ? ' group' : '') + (opts.url ? ' avatar--photo' : '') + (opts.className ? ` ${opts.className}` : '');
    el.style.background = color;
    if (opts.group && opts.groupIcon && !opts.url) {
        el.innerHTML = '<i class="fas fa-users"></i>';
        return;
    }
    if (opts.url) {
        el.innerHTML = `<img src="${escapeHtml(chatFileUrl(opts.url))}" alt="${escapeHtml(opts.name || 'User')}" loading="lazy">`;
        return;
    }
    el.innerHTML = '';
    el.textContent = initials(opts.name);
}

function updateNavProfileAvatar() {
    const av = document.querySelector('.nav-profile-avatar');
    if (!av || !Chat.me) return;
    const url = Chat.me.avatar_url;
    const color = Chat.me.avatar_color || memberColor(Chat.me.id);
    av.className = 'nav-profile-avatar' + (url ? ' avatar--photo' : '');
    av.style.background = color;
    if (url) {
        av.innerHTML = `<img src="${escapeHtml(chatFileUrl(url))}" alt="">`;
    } else {
        av.textContent = initials(Chat.me.full_name);
    }
}

function formatTime(ts) {
    if (!ts) return '';
    const d = new Date(ts), now = new Date();
    if (d.toDateString() === now.toDateString())
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

function formatFullTime(ts) {
    if (!ts) return '';
    return new Date(ts).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function formatFileSize(bytes) {
    const b = parseInt(bytes, 10) || 0;
    if (b < 1024) return b + ' B';
    if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
    return (b / (1024 * 1024)).toFixed(1) + ' MB';
}

function openLightbox(url) {
    if (!url) return;
    const lb = document.getElementById('chatLightbox');
    const img = document.getElementById('lightboxImg');
    const dl = document.getElementById('lightboxDownload');
    if (!lb || !img) return;
    img.src = url;
    if (dl) { dl.href = url; dl.download = ''; }
    lb.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    document.getElementById('chatLightbox')?.classList.add('hidden');
    document.getElementById('lightboxImg').src = '';
    document.body.style.overflow = '';
}

function toggleAttachMenu(show) {
    const menu = document.getElementById('attachMenu');
    const btn = document.getElementById('btnAttach');
    if (!menu) return;
    const open = typeof show === 'boolean' ? show : menu.classList.contains('hidden');
    menu.classList.toggle('hidden', !open);
    btn?.classList.toggle('attach-open', open);
}

function hideAttachMenu() {
    toggleAttachMenu(false);
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function linkify(text) {
    return escapeHtml(text).replace(/\n/g, '<br>');
}

/* ─── Ticks ─────────────────────────────────────────────── */
function renderTicks(status) {
    const titles = { pending: 'Sending…', sent: 'Sent', delivered: 'Delivered', read: 'Seen' };
    const title = titles[status] || 'Sent';
    if (status === 'pending') return `<span class="msg-ticks tick-pending" title="${title}"><i class="fas fa-clock"></i></span>`;
    if (status === 'sent')    return `<span class="msg-ticks tick-sent" title="${title}">✓</span>`;
    if (status === 'delivered') return `<span class="msg-ticks tick-delivered" title="${title}">✓✓</span>`;
    if (status === 'read')    return `<span class="msg-ticks tick-read" title="${title}">✓✓</span>`;
    return `<span class="msg-ticks tick-sent" title="Sent">✓</span>`;
}

/* ─── Date Separator ────────────────────────────────────── */
function dateSeparatorLabel(ts) {
    const d = new Date(ts), now = new Date();
    const yest = new Date(now); yest.setDate(yest.getDate() - 1);
    if (d.toDateString() === now.toDateString()) return 'Today';
    if (d.toDateString() === yest.toDateString()) return 'Yesterday';
    return d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
}

/* ─── Presence / Header ─────────────────────────────────── */
function updateHeaderPresence(presence) {
    const sub = document.getElementById('headerSub');
    const av  = document.getElementById('headerAvatar');
    const dot = document.getElementById('presenceDot');
    if (!sub) return;
    sub.className = 'sub';
    av?.classList.remove('online-ring');
    dot?.classList.remove('online', 'typing');
    showTypingIndicator(false);
    if (!presence) return;
    if (presence.status === 'online') {
        sub.textContent = 'online'; sub.classList.add('online');
        av?.classList.add('online-ring'); dot?.classList.add('online');
    } else if (presence.status === 'typing') {
        sub.textContent = 'typing…'; sub.classList.add('typing');
        dot?.classList.add('typing'); showTypingIndicator(true);
    } else if (presence.label) {
        sub.textContent = presence.label;
    }
}

function showTypingIndicator(show) {
    const el = document.getElementById('typingIndicator');
    if (el) el.classList.toggle('show', !!show);
    if (show && Chat.scroll.pinnedToBottom && !Chat.scroll.userHasScrolled) {
        const area = getMsgArea();
        if (area) scrollMsgAreaTo(area.scrollHeight);
    }
}

async function refreshPresence() {
    if (!Chat.activeId) return;
    const res = await chatApi('getPresence', { params: { conversation_id: Chat.activeId } });
    if (res.success) {
        Chat.presence = res.data.presence;
        Chat.lastRead = res.data.last_read;
        updateHeaderPresence(res.data.presence);
        showTypingIndicator(!!res.data.presence?.typing);
        updateSeenBanner();
    }
}

async function refreshMessageStatuses() {
    if (!Chat.activeId) return;
    const mineIds = Chat.messages.filter(m => m.is_mine && m.id && !String(m.id).startsWith('tmp')).map(m => m.id);
    if (!mineIds.length) return;
    const res = await chatApi('messageStatuses', { params: { conversation_id: Chat.activeId, ids: mineIds.join(',') } });
    if (!res.success || !res.data) return;
    let changed = false;
    Chat.messages.forEach(m => {
        if (m.is_mine && res.data[m.id] && res.data[m.id] !== m.status) {
            m.status = res.data[m.id]; changed = true;
        }
    });
    if (changed) updateMessageTicksInPlace();
}

function updateMessageTicksInPlace() {
    const area = getMsgArea();
    if (!area) return;
    Chat.messages.forEach(m => {
        if (!m.is_mine || !m.id) return;
        const row = area.querySelector(`.chat-msg[data-msg-id="${m.id}"]`);
        if (!row) return;
        const meta = row.querySelector('.chat-msg-meta');
        if (!meta) return;
        const ticks = meta.querySelector('.msg-ticks');
        const html = renderTicks(m.status);
        if (ticks) ticks.outerHTML = html;
        else meta.insertAdjacentHTML('beforeend', html);
    });
}

/* ─── Typing ────────────────────────────────────────────── */
function notifyTyping() {
    if (!Chat.activeId) return;
    if (!Chat.typingSent) {
        Chat.typingSent = true;
        chatApi('setTyping', { method: 'POST', body: { conversation_id: Chat.activeId, typing: true } });
    }
    clearTimeout(Chat.typingTimer);
    Chat.typingTimer = setTimeout(() => {
        Chat.typingSent = false;
        chatApi('setTyping', { method: 'POST', body: { conversation_id: Chat.activeId, typing: false } });
    }, 2000);
}

/* ─── Search ────────────────────────────────────────────── */
function chatSearchDisplayName(user) {
    if (!user) return '';
    const code = (user.employee_code || '').trim();
    const name = (user.full_name || '').trim();
    return code ? `${code} - ${name}` : name;
}

function renderSearchResults(users, container, onPick) {
    const el = document.getElementById(container);
    if (!el) return;
    if (!users.length) {
        el.classList.remove('hidden');
        el.innerHTML = '<div class="chat-search-empty">No colleagues found. Try name, BID, or email.</div>';
        return;
    }
    el.classList.remove('hidden');
    el.innerHTML = users.map(u => `
        <div class="chat-search-hit" data-id="${u.id}">
            ${avatarHtml({ url: u.avatar_url, name: u.full_name, id: u.id, color: u.avatar_color })}
            <div class="meta">
                <div class="name">${escapeHtml(chatSearchDisplayName(u))}</div>
                <div class="sub">${escapeHtml([u.designation, u.department, u.email].filter(Boolean).join(' · '))}</div>
            </div>
        </div>
    `).join('');
    el.querySelectorAll('.chat-search-hit').forEach(hit => {
        hit.addEventListener('click', () => {
            const id = Number(hit.dataset.id);
            onPick(id, users.find(x => Number(x.id) === id));
        });
    });
}

function closeSidebarSearch() {
    const el = document.getElementById('searchResults');
    const input = document.getElementById('userSearch');
    const clearBtn = document.getElementById('btnClearSearch');
    if (el) {
        el.classList.add('hidden');
        el.innerHTML = '';
    }
    if (input) input.value = '';
    clearBtn?.classList.add('hidden');
}

async function searchUsers(q, container, onPick, opts = {}) {
    const clearBtn = document.getElementById('btnClearSearch');
    const el = document.getElementById(container);
    if (q.length < 1) {
        if (opts.sidebar) closeSidebarSearch();
        else if (el) {
            el.classList.add('hidden');
            el.innerHTML = '';
        }
        return;
    }
    if (opts.sidebar) clearBtn?.classList.remove('hidden');
    const requestId = ++Chat.searchRequestId;
    if (el) {
        el.classList.remove('hidden');
        el.innerHTML = '<div class="chat-search-empty">Searching…</div>';
    }
    const res = await chatApi('searchUsers', { params: { q } });
    if (requestId !== Chat.searchRequestId) return;
    if (!res.success) {
        if (el) {
            el.classList.remove('hidden');
            el.innerHTML = `<div class="chat-search-empty">${escapeHtml(res.error || 'Search failed. Try again.')}</div>`;
        }
        toast(res.error || 'Search failed. Try again.');
        return;
    }
    renderSearchResults(Array.isArray(res.data) ? res.data : [], container, onPick);
}

function initConversationListEvents() {
    const list = document.getElementById('convList');
    if (!list || list.dataset.bound) return;
    list.dataset.bound = '1';
    list.addEventListener('click', (e) => {
        const item = e.target.closest('.chat-conv-item');
        if (!item) return;
        e.preventDefault();
        const id = parseInt(item.dataset.id, 10);
        if (id > 0) {
            closeSidebarSearch();
            openConversation(id);
        }
    });
}

function setFilterChipsActive(filter) {
    document.querySelectorAll('.filter-chip').forEach(c => {
        const on = (c.dataset.filter || 'all') === filter;
        c.classList.toggle('active', on);
        c.setAttribute('aria-selected', on ? 'true' : 'false');
    });
}

function updateSidebarListLabel() {
    const el = document.getElementById('sidebarListLabel');
    if (!el) return;
    if (Chat.showArchived) el.textContent = 'Archived';
    else if (Chat.listFilter === 'unread') el.textContent = 'Unread';
    else if (Chat.listFilter === 'groups') el.textContent = 'Groups';
    else if (Chat.listFilter === 'requests') el.textContent = 'Message requests';
    else el.textContent = 'Recent';
}

async function startDirectChat(userId) {
    const res = await chatApi('createDirect', { method: 'POST', body: { user_id: userId } });
    if (!res.success) { toast(res.error || 'Could not start chat'); return; }
    closeSidebarSearch();
    await loadConversations();
    if (res.data.is_new_request) {
        toast('Message request sent — they must accept before replying');
    }
    openConversation(res.data.conversation_id);
}

/* ─── Conversations ─────────────────────────────────────── */
function previewText(msg) {
    if (!msg) return 'No messages yet';
    if (msg.msg_type === 'image') return 'Photo';
    if (msg.msg_type === 'file') return 'Attachment';
    if (msg.is_deleted) return 'Message deleted';
    return (msg.body || '').slice(0, 60);
}

function getFilteredConversations() {
    const archived = new Set(Chat.archivedIds || []);
    let list = Chat.conversations.filter(c => {
        const isArch = archived.has(c.id);
        return Chat.showArchived ? isArch : !isArch;
    });
    if (Chat.listFilter === 'unread') list = list.filter(c => (c.unread || 0) > 0);
    if (Chat.listFilter === 'groups') list = list.filter(c => c.type === 'group');
    if (Chat.listFilter === 'requests') list = list.filter(c => c.is_request);
    else if (!Chat.showArchived) list = list.filter(c => !c.is_request);
    return list;
}

function updateFilterCounts() {
    const unreadTotal = Chat.conversations.reduce((s, c) => s + (c.unread || 0), 0);
    const unreadEl = document.getElementById('unreadFilterCount');
    if (unreadEl) {
        unreadEl.textContent = unreadTotal > 0 ? String(unreadTotal) : '';
        unreadEl.classList.toggle('hidden', unreadTotal === 0);
    }
    const archCount = (Chat.archivedIds || []).length;
    const archEl = document.getElementById('archivedCount');
    if (archEl) archEl.textContent = String(archCount);
    const archRow = document.getElementById('archivedRow');
    if (archRow) archRow.classList.toggle('archived-active', Chat.showArchived);
    const reqEl = document.getElementById('requestsFilterCount');
    if (reqEl) {
        reqEl.textContent = Chat.requestCount > 0 ? String(Chat.requestCount) : '';
        reqEl.classList.toggle('hidden', Chat.requestCount === 0);
    }
}

function renderConversationList() {
    const list = document.getElementById('convList');
    if (!list) return;
    const filtered = getFilteredConversations();
    updateFilterCounts();
    updateSidebarListLabel();

    if (!Chat.conversations.length) {
        list.innerHTML = `
            <div class="chat-list-empty">
                <div class="chat-list-empty-icon"><i class="fas fa-comments"></i></div>
                <strong>No conversations yet</strong>
                Search for a colleague above or tap <i class="fas fa-plus"></i> to start chatting.
            </div>`;
        return;
    }
    if (!filtered.length) {
        const hints = {
            unread: { title: 'All caught up', body: 'You have no unread messages.', icon: 'fa-check-double' },
            groups: { title: 'No groups', body: 'Create a team group with the group button above.', icon: 'fa-user-group' },
            requests: { title: 'No message requests', body: 'When someone messages you for the first time, their request will appear here.', icon: 'fa-user-clock' },
            all: { title: Chat.showArchived ? 'No archived chats' : 'Nothing here', body: Chat.showArchived ? 'Archive chats from the menu inside a conversation.' : 'Try another filter or start a new chat.', icon: 'fa-inbox' },
        };
        const h = hints[Chat.listFilter] || hints.all;
        const extra = Chat.listFilter === 'unread'
            ? '<button type="button" class="btn-list-action" id="btnMarkAllRead">Mark all as read</button>'
            : '';
        list.innerHTML = `
            <div class="chat-list-empty">
                <div class="chat-list-empty-icon"><i class="fas ${h.icon}"></i></div>
                <strong>${h.title}</strong>
                ${h.body}${extra}
            </div>`;
        document.getElementById('btnMarkAllRead')?.addEventListener('click', markAllConversationsRead);
        return;
    }

    list.innerHTML = filtered.map(c => {
        const active = c.id === Chat.activeId ? 'active' : '';
        const lm = c.last_message;
        const muted = Chat.mutedIds.has(c.id);
        const typeIcon = c.type === 'group' ? '<i class="fas fa-user-group conv-type-icon" title="Group"></i>' : '';
        const requestBadge = c.is_request ? '<span class="conv-request-badge">Request</span>' : '';
        const av = c.type === 'group'
            ? avatarHtml({ name: c.display_title, id: c.id, group: true, groupIcon: true, color: c.avatar_color })
            : avatarHtml({ url: c.avatar_url, name: c.display_title, id: c.peer_id || c.id, color: c.avatar_color });
        return `
        <div class="chat-conv-item ${active}${c.unread > 0 ? ' has-unread' : ''}${c.is_request ? ' is-request' : ''}" data-id="${c.id}">
            ${av}
            <div class="chat-conv-body">
                <div class="top">
                    <span class="title">${typeIcon}${escapeHtml(c.display_title)}${requestBadge}</span>
                    <span class="top-end">
                        ${c.unread > 0 ? `<span class="chat-unread">${c.unread > 99 ? '99+' : c.unread}</span>` : ''}
                        <span class="time">${lm ? formatTime(lm.created_at) : ''}</span>
                    </span>
                </div>
                <div class="preview">${muted ? '<i class="fas fa-bell-slash conv-muted-icon"></i> ' : ''}${escapeHtml(c.is_request ? (previewText(lm) || 'New message request') : previewText(lm))}</div>
            </div>
        </div>`;
    }).join('');
}

async function loadConversations() {
    const res = await chatApi('listConversations');
    if (!res.success) return;
    const payload = res.data;
    Chat.conversations = Array.isArray(payload) ? payload : (payload.items || []);
    Chat.requestCount = Array.isArray(payload) ? Chat.conversations.filter(c => c.is_request).length : (payload.request_count || 0);
    renderConversationList();
    notifyParentUnread();
}

async function markAllConversationsRead() {
    const unread = Chat.conversations.filter(c => (c.unread || 0) > 0);
    if (!unread.length) {
        toast('No unread messages');
        return;
    }
    await Promise.all(unread.map(c =>
        chatApi('markRead', { method: 'POST', body: { conversation_id: c.id } })
    ));
    toast('All conversations marked as read');
    await loadConversations();
}

function toggleMuteConversation(cid) {
    if (Chat.mutedIds.has(cid)) Chat.mutedIds.delete(cid);
    else Chat.mutedIds.add(cid);
    saveChatPrefs();
    updateMuteMenuLabel();
    renderConversationList();
    toast(Chat.mutedIds.has(cid) ? 'Notifications muted for this chat' : 'Notifications unmuted');
}

function updateMuteMenuLabel() {
    const btn = document.getElementById('menuMuteChat');
    if (!btn || !Chat.activeId) return;
    const muted = Chat.mutedIds.has(Chat.activeId);
    btn.innerHTML = muted
        ? '<i class="fas fa-bell"></i> Unmute notifications'
        : '<i class="fas fa-bell-slash"></i> Mute notifications';
}

function updateArchiveMenuLabel() {
    const btn = document.getElementById('menuArchiveChat');
    if (!btn || !Chat.activeId) return;
    const isArch = (Chat.archivedIds || []).includes(Chat.activeId);
    btn.innerHTML = isArch
        ? '<i class="fas fa-inbox"></i> Unarchive chat'
        : '<i class="fas fa-archive"></i> Archive chat';
}

function archiveConversation(cid) {
    if (!Chat.archivedIds.includes(cid)) Chat.archivedIds.push(cid);
    saveChatPrefs();
    if (Chat.activeId === cid) {
        Chat.activeId = null;
        Chat.messages = [];
        stopRealtime();
        document.getElementById('chatActive')?.classList.add('hidden');
        document.getElementById('chatEmpty')?.classList.remove('hidden');
    }
    renderConversationList();
    toast('Chat archived');
}

function unarchiveConversation(cid) {
    Chat.archivedIds = (Chat.archivedIds || []).filter(id => id !== cid);
    saveChatPrefs();
    renderConversationList();
}

/* ─── Render Messages ───────────────────────────────────── */
function highlightSearchText(html, query) {
    if (!query || query.length < 2) return html;
    const q = escapeHtml(query);
    try {
        const re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return html.replace(re, '<mark class="chat-search-mark">$1</mark>');
    } catch {
        return html;
    }
}

function renderMessages(scrollMode) {
    const area = getMsgArea();
    if (!area) return;
    saveScrollAnchor(area);

    const showSender = Chat.convType === 'group';
    const searchQ = (Chat.inChatSearch || '').trim();
    let html = '', lastDate = '';
    let matchCount = 0;

    Chat.messages.forEach(m => {
        const dLabel = dateSeparatorLabel(m.created_at);
        if (dLabel !== lastDate) {
            lastDate = dLabel;
            html += `<div class="chat-date-sep">${escapeHtml(dLabel)}</div>`;
        }

        const mine      = m.is_mine;
        const isDeleted = m.is_deleted || m.is_deleted === 1;
        const isEdited  = m.is_edited  || m.is_edited  === 1;
        const isImage   = !isDeleted && m.msg_type === 'image' && m.file_url;
        const isFile    = !isDeleted && m.msg_type === 'file'  && m.file_url;
        const fileUrl   = chatFileUrl(m.file_url);
        const isTmp     = String(m.id || '').startsWith('tmp');

        let bubbleClass = 'chat-bubble';
        let inner = '';

        if (isDeleted) {
            bubbleClass += ' is-deleted';
            inner = `<i class="fas fa-ban" style="margin-right:6px;opacity:0.6;"></i><em>This message was deleted</em>`;
        } else if (isImage) {
            bubbleClass += ' chat-bubble-media';
            const cap = m.body && !/^(📷|Photo)/i.test(m.body) ? `<div class="chat-caption">${linkify(m.body)}</div>` : '';
            inner = `<div class="chat-media"><img class="chat-img" src="${escapeHtml(fileUrl)}" alt="Photo" loading="lazy" data-full="${escapeHtml(fileUrl)}"></div>${cap}`;
        } else if (isFile) {
            const fic = fileIconClass(m.file_name, '');
            const size = m.file_size ? ` · ${formatFileSize(m.file_size)}` : '';
            inner = `<a class="file-link" href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" download>
                <span class="file-link-icon"><i class="fas ${fic}"></i></span>
                <span class="file-link-meta"><span class="file-link-name">${escapeHtml(m.file_name || 'Document')}</span><span class="file-link-sub">Tap to download${escapeHtml(size)}</span></span></a>`;
        } else {
            inner = linkify(m.body || '');
            if (searchQ.length >= 2 && (m.body || '').toLowerCase().includes(searchQ.toLowerCase())) {
                inner = highlightSearchText(inner, searchQ);
                matchCount++;
            }
        }

        // Edited label
        const editedTag = (!isDeleted && isEdited) ? `<span class="msg-edited-tag">(edited)</span>` : '';

        const status = m.status || (mine ? 'sent' : '');
        const meta = mine
            ? `<div class="chat-msg-meta"><span class="time" title="${formatFullTime(m.created_at)}">${formatTime(m.created_at)}</span>${editedTag}${renderTicks(status)}</div>`
            : `<div class="chat-msg-meta"><span class="time" title="${formatFullTime(m.created_at)}">${formatTime(m.created_at)}</span>${editedTag}</div>`;

        // Action chevron button (not for deleted or temp messages)
        const canAct = !isTmp && m.id && !isDeleted;
        const chevron = canAct
            ? `<button class="msg-action-btn" data-msg-id="${m.id}" title="More options"><i class="fas fa-chevron-down"></i></button>`
            : '';

        const searchHit = searchQ.length >= 2 && !isDeleted && (m.body || '').toLowerCase().includes(searchQ.toLowerCase());
        const blink = Chat.blinkMsgIds.has(m.id) ? ' msg-unread-blink' : '';
        const uploading = String(m.id || '').startsWith('tmp-upload') ? ' msg-uploading' : '';
        const senderRow = !mine && showSender
            ? `<div class="msg-sender-row">${avatarHtml({ url: m.sender_avatar_url, name: m.sender_name, id: m.sender_id, className: 'msg-sender-avatar', color: m.sender_avatar_color })}<span class="sender">${escapeHtml(m.sender_name)}</span></div>`
            : '';
        html += `
        <div class="chat-msg ${mine ? 'mine' : 'theirs'}${isImage ? ' has-media' : ''}${searchHit ? ' search-hit' : ''}${blink}${uploading}" data-msg-id="${m.id || ''}" data-is-mine="${mine ? '1' : '0'}">
            ${senderRow}
            <div class="${bubbleClass}">${inner}${mine ? meta : ''}</div>
            ${!mine ? meta : ''}
            ${chevron}
        </div>`;
    });

    if (Chat.convType === 'direct' && Chat.lastRead?.label) {
        html += `<div class="chat-seen-banner">${escapeHtml(Chat.lastRead.label)}</div>`;
    }

    area.innerHTML = html;

    let mode = scrollMode;
    if (Chat.scroll.opening) mode = 'bottom';
    else if (!mode && searchQ.length >= 2) mode = 'search';
    else if (!mode && Chat.scroll.userHasScrolled && !Chat.scroll.pinnedToBottom) mode = 'preserve';
    applyScrollAfterRender(area, mode);
    bindImageScrollStabilizer(area);

    const inSearchInput = document.getElementById('chatInSearchInput');
    if (inSearchInput && document.activeElement === inSearchInput) {
        inSearchInput.dataset.matches = String(matchCount);
    }

    area.querySelectorAll('.chat-img').forEach(img => {
        img.addEventListener('click', e => {
            e.preventDefault();
            openLightbox(img.dataset.full || img.src);
        });
    });

    area.querySelectorAll('.msg-action-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const msgEl  = btn.closest('.chat-msg');
            const msgId  = parseInt(btn.dataset.msgId, 10);
            const isMine = msgEl.dataset.isMine === '1';
            showMessageContextMenu(e, msgId, isMine);
        });
    });
}

/* ─── Context Menu ──────────────────────────────────────── */
function showMessageContextMenu(e, msgId, isMine) {
    Chat.contextMsgId = msgId;
    const menu = document.getElementById('msgContextMenu');

    const ctxEdit = document.getElementById('ctxEdit');
    const ctxDelete = document.getElementById('ctxDelete');
    const ctxDeleteForMe = document.getElementById('ctxDeleteForMe');
    const ctxReply = document.getElementById('ctxReply');
    ctxEdit.style.display = isMine ? 'flex' : 'none';
    ctxDelete.style.display = isMine ? 'flex' : 'none';
    if (ctxDeleteForMe) ctxDeleteForMe.style.display = isMine ? 'none' : 'flex';
    if (ctxReply) ctxReply.style.display = 'flex';

    menu.classList.remove('hidden');
    const mw = 190, mh = 180;
    let x = e.clientX, y = e.clientY;
    if (x + mw > window.innerWidth)  x = window.innerWidth  - mw - 8;
    if (y + mh > window.innerHeight) y = window.innerHeight - mh - 8;
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';
}

function hideContextMenu() {
    document.getElementById('msgContextMenu').classList.add('hidden');
    Chat.contextMsgId = null;
}

/* ─── Edit Message ──────────────────────────────────────── */
function openEditModal(msgId) {
    const msg = Chat.messages.find(m => m.id === msgId);
    if (!msg) return;
    Chat.editMsgId = msgId;
    document.getElementById('editMsgInput').value = msg.body || '';
    document.getElementById('editMsgModal').classList.add('open');
    document.getElementById('editMsgInput').focus();
}

async function confirmEdit() {
    const newBody = document.getElementById('editMsgInput').value.trim();
    if (!newBody || !Chat.editMsgId) return;
    const res = await chatApi('editMessage', { method: 'POST', body: { message_id: Chat.editMsgId, body: newBody } });
    if (!res.success) { toast(res.error || 'Could not edit message'); return; }
    // Update local state immediately
    const msg = Chat.messages.find(m => m.id === Chat.editMsgId);
    if (msg) { msg.body = newBody; msg.is_edited = 1; }
    document.getElementById('editMsgModal').classList.remove('open');
    Chat.editMsgId = null;
    renderMessages(renderScrollMode());
    toast('Message updated');
}

/* ─── Delete Message ────────────────────────────────────── */
function openDeleteModal(msgId) {
    Chat.deleteMsgId = msgId;
    document.getElementById('deleteMsgModal').classList.add('open');
}

async function confirmDelete() {
    if (!Chat.deleteMsgId) return;
    const res = await chatApi('deleteMessage', { method: 'POST', body: { message_id: Chat.deleteMsgId, for_all: true } });
    if (!res.success) { toast(res.error || 'Could not delete message'); return; }
    const msg = Chat.messages.find(m => m.id === Chat.deleteMsgId);
    if (msg) { msg.is_deleted = 1; msg.body = ''; msg.file_url = null; }
    document.getElementById('deleteMsgModal').classList.remove('open');
    Chat.deleteMsgId = null;
    renderMessages(renderScrollMode());
    toast('Message deleted');
}

async function deleteMessageForMe(msgId) {
    if (!msgId) return;
    const res = await chatApi('deleteMessage', { method: 'POST', body: { message_id: msgId, for_all: false } });
    if (!res.success) { toast(res.error || 'Could not delete message'); return; }
    Chat.messages = Chat.messages.filter(m => m.id !== msgId);
    renderMessages(renderScrollMode());
    toast('Message removed');
}

/* ─── Clear / Delete Chat ───────────────────────────────── */
function openChatActionModal(action) {
    Chat.chatActionPending = { action, cid: Chat.activeId };
    const modal = document.getElementById('chatActionModal');
    if (action === 'clear') {
        document.getElementById('chatActionTitle').innerHTML = '<i class="fas fa-broom modal-icon-accent"></i> Clear chat';
        document.getElementById('chatActionBody').textContent = 'This will permanently clear all messages in this conversation for everyone. This cannot be undone.';
        document.getElementById('confirmChatAction').textContent = 'Clear chat';
    } else {
        document.getElementById('chatActionTitle').innerHTML = '<i class="fas fa-trash-alt modal-icon-danger"></i> Delete chat';
        document.getElementById('chatActionBody').textContent = 'You will be removed from this conversation. If you are the last member, the conversation will be deleted permanently.';
        document.getElementById('confirmChatAction').textContent = 'Delete chat';
    }
    modal.classList.add('open');
}

async function confirmChatAction() {
    if (!Chat.chatActionPending) return;
    const { action, cid, user_id } = Chat.chatActionPending;
    if (action === 'block') {
        const res = await chatApi('blockUser', { method: 'POST', body: { user_id } });
        document.getElementById('chatActionModal').classList.remove('open');
        Chat.chatActionPending = null;
        if (!res.success) { toast(res.error || 'Could not block user'); return; }
        toast('User blocked — open Settings to unblock anytime');
        Chat.activeId = null;
        document.getElementById('chatActive')?.classList.add('hidden');
        document.getElementById('chatEmpty')?.classList.remove('hidden');
        await loadBlockedUsers();
        renderSettingsBlockedList();
        await loadConversations();
        return;
    }
    const apiAction = action === 'clear' ? 'clearChat' : 'deleteChat';
    const res = await chatApi(apiAction, { method: 'POST', body: { conversation_id: cid } });
    document.getElementById('chatActionModal').classList.remove('open');
    Chat.chatActionPending = null;
    if (!res.success) { toast(res.error || 'Action failed'); return; }

    if (action === 'clear') {
        Chat.messages = [];
        renderMessages('bottom');
        toast('Chat cleared');
    } else {
        // Go back to welcome screen
        Chat.activeId = null;
        Chat.messages = [];
        stopRealtime();
        document.getElementById('chatActive').classList.add('hidden');
        document.getElementById('chatEmpty').classList.remove('hidden');
        toast('Chat deleted');
    }
    loadConversations();
}

/* ─── WebSocket (real-time) ─────────────────────────────── */
function setWsConnectionStatus(state) {
    const el = document.getElementById('chatWsStatus');
    if (!el) return;
    el.classList.remove('connected', 'connecting', 'error');
    if (state === 'connected') {
        el.classList.add('connected');
        el.title = 'Connected — real-time';
        el.setAttribute('aria-label', 'Connected');
    } else if (state === 'connecting') {
        el.classList.add('connecting');
        el.title = 'Connecting…';
        el.setAttribute('aria-label', 'Connecting');
    } else if (state === 'error') {
        el.classList.add('error');
        el.title = 'Disconnected — retrying';
        el.setAttribute('aria-label', 'Disconnected');
    } else {
        el.title = 'Real-time off';
        el.setAttribute('aria-label', 'Offline');
    }
}

function updateLoadOlderBar() {
    const bar = document.getElementById('loadOlderBar');
    if (!bar) return;
    bar.classList.toggle('hidden', !Chat.activeId || !Chat.hasMoreHistory);
}

async function loadOlderMessages() {
    if (!Chat.activeId || !Chat.messages.length || Chat.loadingOlder) return;
    const first = Chat.messages.find(m => m.id && !String(m.id).startsWith('tmp'));
    if (!first) return;
    const area = getMsgArea();
    const anchorId = first.id;
    const prevHeight = area ? area.scrollHeight : 0;
    Chat.loadingOlder = true;
    const btn = document.getElementById('btnLoadOlder');
    if (btn) btn.disabled = true;
    const res = await chatApi('getMessages', {
        params: { conversation_id: Chat.activeId, before_id: first.id, limit: 50 },
    });
    Chat.loadingOlder = false;
    if (btn) btn.disabled = false;
    if (!res.success) {
        toast(res.error || 'Could not load history');
        return;
    }
    Chat.hasMoreHistory = !!res.data.has_more;
    const existing = new Set(Chat.messages.map(m => String(m.id)));
    const older = (res.data.messages || []).filter(m => !existing.has(String(m.id)));
    if (older.length) {
        Chat.messages = [...older, ...Chat.messages];
        renderMessages('preserve');
        if (area) {
            const delta = area.scrollHeight - prevHeight;
            area.scrollTop = Math.max(0, area.scrollTop + delta);
        }
    }
    updateLoadOlderBar();
}

function getTotalUnread() {
    return (Chat.conversations || []).reduce((sum, c) => sum + (c.unread || 0), 0);
}

function notifyParentUnread() {
    const total = getTotalUnread();
    try {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({ type: 'portal-chat-unread', total }, '*');
        }
    } catch { /* ignore */ }
    if (typeof window.updatePortalChatBadge === 'function') {
        window.updatePortalChatBadge(total);
    }
}

function shouldAlertIncomingChat(ev) {
    if (!ev?.message) return false;
    if (parseInt(ev.message.sender_id, 10) === parseInt(Chat.me?.id, 10)) return false;
    const cid = parseInt(ev.conversation_id, 10);
    if (Chat.mutedIds.has(cid)) return false;
    const onActive = Chat.activeId && parseInt(Chat.activeId, 10) === cid;
    if (onActive && document.visibilityState === 'visible') return false;
    return true;
}

function maybeNotifyIncoming(ev) {
    if (!shouldAlertIncomingChat(ev)) return;

    window.PortalNotifySound?.play();

    if (document.visibilityState === 'visible') return;

    const conv = Chat.conversations.find(c => c.id === parseInt(ev.conversation_id, 10));
    const title = conv?.display_title || 'New message';
    const body = (ev.message.body || '').slice(0, 120) || 'Sent an attachment';
    if (!('Notification' in window)) return;
    if (Notification.permission === 'granted') {
        try {
            const n = new Notification(title, { body, tag: 'chat-' + ev.conversation_id });
            n.onclick = () => { window.focus(); openConversation(ev.conversation_id); n.close(); };
        } catch { /* ignore */ }
    } else if (Notification.permission === 'default') {
        Notification.requestPermission();
    }
}

function normalizeWsMessage(m) {
    if (!m) return null;
    const mine = Chat.me && parseInt(m.sender_id, 10) === parseInt(Chat.me.id, 10);
    const out = { ...m, is_mine: mine };
    if (mine && !out.status) out.status = 'sent';
    return out;
}

function wsSend(obj) {
    if (Chat.ws.socket && Chat.ws.socket.readyState === WebSocket.OPEN) {
        Chat.ws.socket.send(JSON.stringify(obj));
    }
}

function wsSubscribe(conversationId) {
    Chat.ws.subscribedCid = conversationId ? parseInt(conversationId, 10) : null;
    if (Chat.ws.connected && Chat.ws.subscribedCid) {
        wsSend({ type: 'subscribe', conversation_id: Chat.ws.subscribedCid });
    }
}

function wsUnsubscribe() {
    Chat.ws.subscribedCid = null;
    wsSend({ type: 'unsubscribe' });
}

function scheduleWsReconnect() {
    if (Chat.ws.reconnectTimer) return;
    if (Chat.ws.reconnectAttempts >= Chat.ws.maxReconnectAttempts) {
        setWsConnectionStatus('error');
        toast('Connection lost. Refresh the page or check the chat server.');
        return;
    }
    setWsConnectionStatus('connecting');
    Chat.ws.reconnectTimer = setTimeout(async () => {
        Chat.ws.reconnectTimer = null;
        Chat.ws.reconnectAttempts++;
        if (Chat.ws.reconnectAttempts % 3 === 0) await refreshWsToken();
        if (Chat.ws.enabled) connectChatWebSocket();
    }, Chat.ws.reconnectMs);
    Chat.ws.reconnectMs = Math.min(Chat.ws.reconnectMs * 1.5, 30000);
}

function clearWsReconnect() {
    if (Chat.ws.reconnectTimer) {
        clearTimeout(Chat.ws.reconnectTimer);
        Chat.ws.reconnectTimer = null;
    }
    Chat.ws.reconnectMs = 2000;
}

function startWsPing() {
    stopWsPing();
    Chat.ws.pingTimer = setInterval(() => wsSend({ type: 'ping' }), 25000);
}

function stopWsPing() {
    if (Chat.ws.pingTimer) {
        clearInterval(Chat.ws.pingTimer);
        Chat.ws.pingTimer = null;
    }
}

function disconnectChatWebSocket() {
    stopWsPing();
    clearWsReconnect();
    if (Chat.ws.socket) {
        Chat.ws.socket.onclose = null;
        Chat.ws.socket.close();
        Chat.ws.socket = null;
    }
    Chat.ws.connected = false;
}

function connectChatWebSocket() {
    if (!Chat.ws.enabled || !Chat.ws.url || !Chat.ws.token) return;
    setWsConnectionStatus('connecting');
    if (Chat.ws.socket && Chat.ws.socket.readyState === WebSocket.OPEN) return;
    if (Chat.ws.socket) {
        Chat.ws.socket.onclose = null;
        Chat.ws.socket.close();
        Chat.ws.socket = null;
    }
    stopWsPing();
    try {
        const sock = new WebSocket(Chat.ws.url);
        Chat.ws.socket = sock;
        sock.onopen = () => {
            sock.send(JSON.stringify({ type: 'auth', token: Chat.ws.token }));
        };
        sock.onmessage = (e) => {
            try {
                handleChatWsEvent(JSON.parse(e.data));
            } catch { /* ignore malformed */ }
        };
        sock.onclose = () => {
            Chat.ws.connected = false;
            stopWsPing();
            setWsConnectionStatus('connecting');
            scheduleWsReconnect();
        };
        sock.onerror = () => { /* onclose handles reconnect */ };
    } catch {
        scheduleWsReconnect();
    }
}

async function refreshWsToken() {
    const res = await chatApi('wsConfig');
    if (!res.success || !res.data?.enabled) {
        Chat.ws.enabled = false;
        return false;
    }
    Chat.ws.enabled = true;
    Chat.ws.url = res.data.url;
    Chat.ws.token = res.data.token;
    return true;
}

async function initChatWebSocket() {
    const ok = await refreshWsToken();
    if (!ok) {
        setWsConnectionStatus('error');
        toast('Real-time chat unavailable — start Redis + Node (see chat/ARCHITECTURE.md)');
        return;
    }
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
    connectChatWebSocket();
    setInterval(refreshWsToken, 50 * 60 * 1000);
}

function mergeIncomingMessage(raw) {
    const m = normalizeWsMessage(raw);
    if (!m || !m.id) return false;
    const idKey = String(m.id);
    if (Chat.messages.some(x => String(x.id) === idKey)) return false;
    Chat.messages.push(m);
    return true;
}

function handleChatWsEvent(ev) {
    if (!ev || !ev.type) return;

    if (ev.type === 'auth_ok') {
        Chat.ws.connected = true;
        Chat.ws.reconnectAttempts = 0;
        clearWsReconnect();
        setWsConnectionStatus('connected');
        startWsPing();
        chatApi('heartbeat');
        if (Chat.ws.subscribedCid) wsSubscribe(Chat.ws.subscribedCid);
        return;
    }
    if (ev.type === 'auth_error') {
        refreshWsToken().then(() => connectChatWebSocket());
        return;
    }
    if (ev.type === 'pong' || ev.type === 'ping') return;

    if (ev.type === 'inbox') {
        loadConversations();
        return;
    }

    if (ev.type === 'presence') {
        const uid = parseInt(ev.user_id, 10);
        if (uid && uid !== parseInt(Chat.me?.id, 10)) {
            const active = Chat.activeId && Chat.convType === 'direct';
            if (active) refreshPresence();
            loadConversations();
        }
        return;
    }

    const cid = parseInt(ev.conversation_id, 10);
    const active = Chat.activeId && parseInt(Chat.activeId, 10) === cid;

    if (ev.type === 'message.new' && ev.message) {
        maybeNotifyIncoming(ev);
        if (!active) {
            loadConversations();
            return;
        }
        const area = getMsgArea();
        const wasPinned = isNearBottom(area);
        const added = mergeIncomingMessage(ev.message);
        if (added) {
            const incoming = !ev.message.sender_id || parseInt(ev.message.sender_id, 10) !== parseInt(Chat.me?.id, 10);
            if (incoming && wasPinned) highlightNewIncoming([ev.message]);
            renderMessages(wasPinned && !Chat.scroll.userHasScrolled ? 'bottom' : 'preserve');
            if (incoming) {
                chatApi('markRead', { method: 'POST', body: { conversation_id: cid } });
            }
        }
        return;
    }

    if (!active) return;

    if (ev.type === 'typing') {
        const uid = parseInt(ev.user_id, 10);
        if (uid && uid !== parseInt(Chat.me?.id, 10)) {
            if (ev.typing) {
                Chat.presence = { status: 'typing', label: 'typing…', typing: true };
                updateHeaderPresence(Chat.presence);
                showTypingIndicator(true);
            } else {
                refreshPresence();
            }
        }
        return;
    }

    if (ev.type === 'read') {
        const readerId = parseInt(ev.reader_id, 10);
        if (readerId && readerId !== parseInt(Chat.me?.id, 10)) {
            refreshMessageStatuses();
            refreshPresence();
        }
        return;
    }

    if (ev.type === 'message.edit') {
        const msg = Chat.messages.find(m => parseInt(m.id, 10) === parseInt(ev.message_id, 10));
        if (msg) {
            msg.body = ev.body;
            msg.is_edited = 1;
            renderMessages(Chat.scroll.pinnedToBottom ? 'bottom' : 'preserve');
        }
        return;
    }

    if (ev.type === 'message.delete') {
        const msg = Chat.messages.find(m => parseInt(m.id, 10) === parseInt(ev.message_id, 10));
        if (msg) {
            msg.is_deleted = 1;
            msg.body = '';
            msg.file_url = null;
            renderMessages(Chat.scroll.pinnedToBottom ? 'bottom' : 'preserve');
        }
        return;
    }

    if (ev.type === 'chat.cleared') {
        Chat.messages = [];
        renderMessages('bottom');
        return;
    }

    if (ev.type === 'request.accepted' || ev.type === 'request.declined') {
        loadConversations();
        if (active) {
            openConversation(cid);
        }
    }
}

function startRealtime() {
    wsSubscribe(Chat.activeId);
}

function stopRealtime() {
    wsUnsubscribe();
}

function stopPoll() {
    stopRealtime();
    if (Chat.activeId) chatApi('setTyping', { method: 'POST', body: { conversation_id: Chat.activeId, typing: false } });
}

/* ─── Open Conversation ─────────────────────────────────── */
async function openConversation(id) {
    Chat.activeId = id;
    Chat.scroll.pinnedToBottom = true;
    Chat.scroll.userHasScrolled = false;
    Chat.scroll.opening = true;
    stopPoll();
    const convMeta = Chat.conversations.find(c => c.id === id);
    const res = await chatApi('getMessages', { params: { conversation_id: id } });
    if (!res.success) { toast(res.error || 'Could not load chat'); return; }
    Chat.messages  = res.data.messages;
    Chat.hasMoreHistory = !!res.data.has_more;
    updateLoadOlderBar();
    Chat.convType = res.data.conversation.type;
    Chat.presence = res.data.presence;
    Chat.lastRead = res.data.last_read;
    Chat.activeMeta = res.data.meta || null;
    Chat.activePeerId = Chat.activeMeta?.peer_id || convMeta?.peer_id || null;

    const unreadCount = res.data.unread_count ?? convMeta?.unread ?? 0;
    const lastReadAt = res.data.last_read_at || null;
    Chat.blinkMsgIds.clear();

    if (unreadCount > 0) {
        applyUnreadHighlights(Chat.messages, lastReadAt, unreadCount);
    }

    const title = res.data.conversation.display_title || res.data.conversation.title || 'Chat';
    document.getElementById('chatEmpty').classList.add('hidden');
    document.getElementById('chatActive').classList.remove('hidden');
    document.getElementById('headerTitle').textContent = title;
    const headerAv = document.getElementById('headerAvatar');
    const conv = convMeta;
    if (Chat.convType === 'group') {
        setAvatarElement(headerAv, { name: title, id: conv?.id, group: true, groupIcon: true, color: conv?.avatar_color });
        const pc = res.data.participants?.length;
        document.getElementById('headerSub').textContent = pc ? `${pc} participants` : 'Group chat';
    } else {
        const peer = res.data.participants?.find(p => p.id !== Chat.me?.id);
        setAvatarElement(headerAv, {
            url: peer?.avatar_url || conv?.avatar_url,
            name: title,
            id: peer?.id || conv?.peer_id,
            color: peer?.avatar_color || conv?.avatar_color
        });
    }
    updateHeaderPresence(res.data.presence);

    renderMessages('bottom');
    renderConversationList();

    await chatApi('markRead', { method: 'POST', body: { conversation_id: id } });
    await refreshPresence();
    updateSeenBanner();
    await loadConversations();
    setTimeout(() => { Chat.scroll.opening = false; }, 400);
    updateMuteMenuLabel();
    updateArchiveMenuLabel();
    updateBlockMenuVisibility();
    updateRequestBanner();
    updateComposerState();
    clearReply();
    closeInChatSearch();

    if (window.innerWidth <= 768) {
        document.getElementById('listPanel').classList.add('hidden-mobile');
        document.getElementById('btnBackMobile').style.display = 'flex';
    }
    startRealtime();
}

/* ─── Send ──────────────────────────────────────────────── */
function setReply(msg) {
    if (!msg || msg.is_deleted) return;
    Chat.replyTo = {
        id: msg.id,
        body: (msg.body || '').slice(0, 120),
        sender_name: msg.sender_name || (msg.is_mine ? 'You' : 'User'),
    };
    const bar = document.getElementById('replyPreview');
    const snippet = document.getElementById('replySnippet');
    if (bar && snippet) {
        snippet.textContent = `${Chat.replyTo.sender_name}: ${Chat.replyTo.body || 'Attachment'}`;
        bar.classList.remove('hidden');
    }
    document.getElementById('msgInput')?.focus();
}

function clearReply() {
    Chat.replyTo = null;
    document.getElementById('replyPreview')?.classList.add('hidden');
}

function buildOutgoingBody(text) {
    if (!Chat.replyTo) return text;
    const quote = (Chat.replyTo.body || '…').replace(/\n/g, ' ');
    return `↩ ${Chat.replyTo.sender_name}: "${quote}"\n\n${text}`;
}

function toggleInChatSearch(force) {
    const bar = document.getElementById('chatInSearchBar');
    const input = document.getElementById('chatInSearchInput');
    if (!bar || !input) return;
    const open = typeof force === 'boolean' ? force : bar.classList.contains('hidden');
    if (open) {
        bar.classList.remove('hidden');
        input.focus();
        document.getElementById('btnSearchInChat')?.classList.add('active');
    } else {
        closeInChatSearch();
    }
}

function closeInChatSearch() {
    Chat.inChatSearch = '';
    const bar = document.getElementById('chatInSearchBar');
    const input = document.getElementById('chatInSearchInput');
    if (bar) bar.classList.add('hidden');
    if (input) input.value = '';
    document.getElementById('btnSearchInChat')?.classList.remove('active');
    renderMessages(renderScrollMode());
}

function updateBlockMenuVisibility() {
    const btn = document.getElementById('menuBlockUser');
    const divider = document.getElementById('menuBlockDivider');
    const show = Chat.convType === 'direct' && Chat.activePeerId;
    btn?.classList.toggle('hidden', !show);
    divider?.classList.toggle('hidden', !show);
}

function updateRequestBanner() {
    const banner = document.getElementById('chatRequestBanner');
    const actions = document.getElementById('chatRequestActions');
    const title = document.getElementById('chatRequestTitle');
    const sub = document.getElementById('chatRequestSub');
    if (!banner) return;
    const meta = Chat.activeMeta || {};
    const isRequest = meta.is_request;
    const peerPending = meta.peer_status === 'pending' && meta.my_status === 'active';
    if (!isRequest && !peerPending) {
        banner.classList.add('hidden');
        return;
    }
    banner.classList.remove('hidden');
    if (isRequest) {
        title.textContent = 'Message request';
        sub.textContent = 'Accept this request to reply and continue the conversation.';
        actions?.classList.remove('hidden');
    } else if (peerPending) {
        title.textContent = 'Request sent';
        sub.textContent = 'Waiting for them to accept your message request. You can still send messages.';
        actions?.classList.add('hidden');
    }
}

function updateComposerState() {
    const footer = document.querySelector('.chat-footer');
    const input = document.getElementById('msgInput');
    const sendBtn = document.getElementById('btnSend');
    const canReply = Chat.activeMeta?.can_reply !== false;
    footer?.classList.toggle('composer-locked', !canReply);
    if (input) {
        input.disabled = !canReply;
        input.placeholder = canReply ? 'Type a message…' : 'Accept the message request to reply…';
    }
    if (sendBtn) sendBtn.disabled = !canReply;
}

async function acceptMessageRequest() {
    if (!Chat.activeId) return;
    const res = await chatApi('acceptRequest', { method: 'POST', body: { conversation_id: Chat.activeId } });
    if (!res.success) { toast(res.error || 'Could not accept request'); return; }
    toast('Request accepted — you can reply now');
    await loadConversations();
    await openConversation(Chat.activeId);
}

async function declineMessageRequest() {
    if (!Chat.activeId) return;
    const res = await chatApi('declineRequest', { method: 'POST', body: { conversation_id: Chat.activeId } });
    if (!res.success) { toast(res.error || 'Could not decline request'); return; }
    toast('Message request declined');
    Chat.activeId = null;
    document.getElementById('chatActive')?.classList.add('hidden');
    document.getElementById('chatEmpty')?.classList.remove('hidden');
    await loadConversations();
}

function openBlockUserModal() {
    if (!Chat.activePeerId) return;
    Chat.chatActionPending = { action: 'block', cid: Chat.activeId, user_id: Chat.activePeerId };
    const modal = document.getElementById('chatActionModal');
    document.getElementById('chatActionTitle').innerHTML = '<i class="fas fa-ban modal-icon-danger"></i> Block user';
    document.getElementById('chatActionBody').textContent = 'They will not be able to message you or send new requests. Unblock anytime from Settings → Blocked users.';
    document.getElementById('confirmChatAction').textContent = 'Block user';
    modal.classList.add('open');
}

async function sendText() {
    const input = document.getElementById('msgInput');
    let body  = input.value.trim();
    if (!Chat.activeId || !body) return;
    if (Chat.activeMeta?.can_reply === false) {
        toast('Accept the message request to reply');
        return;
    }
    body = buildOutgoingBody(body);

    const tempId = 'tmp-' + Date.now();
    Chat.messages.push({ id: tempId, body, msg_type: 'text', is_mine: true, status: 'pending', created_at: new Date().toISOString(), sender_name: Chat.me?.full_name });
    input.value = '';
    input.style.height = '';
    clearReply();
    renderMessages('bottom');

    const res = await chatApi('sendMessage', { method: 'POST', body: { conversation_id: Chat.activeId, body } });
    if (!res.success) {
        Chat.messages = Chat.messages.filter(m => m.id !== tempId);
        renderMessages(renderScrollMode());
        toast(res.error || 'Send failed');
        return;
    }
    Chat.messages = Chat.messages.filter(m => m.id !== tempId);
    if (res.data?.message) {
        mergeIncomingMessage(res.data.message);
        const confirmed = Chat.messages.find(m => parseInt(m.id, 10) === parseInt(res.data.message.id, 10));
        if (confirmed) confirmed.status = res.data.status || 'sent';
    }
    renderMessages('bottom');
    loadConversations();
}

/* ─── Upload ────────────────────────────────────────────── */
function showUploadPreview(file) {
    const box = document.getElementById('uploadPreview');
    const thumb = document.getElementById('uploadPreviewThumb');
    const name = document.getElementById('uploadPreviewName');
    const bar = document.getElementById('uploadProgressBar');
    if (!box) return;
    box.classList.remove('hidden');
    name.textContent = file.name || 'Uploading…';
    bar.style.width = '0%';
    if (isImageFile(file)) {
        const url = URL.createObjectURL(file);
        thumb.innerHTML = `<img src="${url}" alt="">`;
        thumb.dataset.objurl = url;
    } else {
        thumb.innerHTML = `<i class="fas ${fileIconClass(file.name, file.type)}"></i>`;
        thumb.dataset.objurl = '';
    }
}

function hideUploadPreview() {
    const box = document.getElementById('uploadPreview');
    const thumb = document.getElementById('uploadPreviewThumb');
    if (thumb?.dataset.objurl) URL.revokeObjectURL(thumb.dataset.objurl);
    box?.classList.add('hidden');
    document.getElementById('uploadProgressBar').style.width = '0%';
}

function uploadFileXHR(file) {
    return new Promise((resolve, reject) => {
        const cid = parseInt(Chat.activeId, 10);
        const fd = new FormData();
        fd.append('file', file, file.name || 'upload');
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/chat_upload.php?conversation_id=' + encodeURIComponent(String(cid)));
        xhr.withCredentials = true;
        xhr.setRequestHeader('X-Chat-Conversation-Id', String(cid));
        xhr.upload.onprogress = e => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                const bar = document.getElementById('uploadProgressBar');
                if (bar) bar.style.width = pct + '%';
            }
        };
        xhr.onload = () => {
            try { resolve(JSON.parse(xhr.responseText)); }
            catch { reject(new Error('Invalid server response')); }
        };
        xhr.onerror = () => reject(new Error('Network error'));
        xhr.send(fd);
    });
}

async function uploadFile(file) {
    if (!file || Chat.uploading) return;
    const cid = parseInt(Chat.activeId, 10);
    if (!cid) { toast('Open a chat first'); return; }
    if (file.size > 10 * 1024 * 1024) { toast('File too large (max 10 MB)'); return; }

    hideAttachMenu();
    Chat.uploading = true;
    showUploadPreview(file);

    const tempId = 'tmp-upload-' + Date.now();
    if (isImageFile(file)) {
        const url = URL.createObjectURL(file);
        Chat.messages.push({
            id: tempId, body: '', msg_type: 'image', is_mine: true, status: 'pending',
            file_url: url, created_at: new Date().toISOString(), sender_name: Chat.me?.full_name
        });
        renderMessages('bottom');
    }

    try {
        const res = await uploadFileXHR(file);
        Chat.messages = Chat.messages.filter(m => m.id !== tempId);
        if (!res.success) {
            renderMessages(renderScrollMode());
            toast(res.error || 'Upload failed');
            return;
        }
        if (res.data?.message) {
            mergeIncomingMessage(res.data.message);
        }
        renderMessages('bottom');
        loadConversations();
        toast(isImageFile(file) ? 'Photo sent' : 'File sent');
    } catch (e) {
        Chat.messages = Chat.messages.filter(m => m.id !== tempId);
        renderMessages(renderScrollMode());
        toast(e.message || 'Upload failed');
    } finally {
        Chat.uploading = false;
        hideUploadPreview();
    }
}

async function handleFilePick(fileList) {
    const files = [...fileList].filter(Boolean);
    for (const file of files) {
        await uploadFile(file);
    }
}

function initDragDropUpload() {
    const overlay = document.getElementById('chatDropOverlay');
    const wrap = document.getElementById('messagesWrap');
    if (!overlay || !wrap) return;

    let dragDepth = 0;
    const show = () => { if (Chat.activeId) overlay.classList.remove('hidden'); };
    const hide = () => overlay.classList.add('hidden');

    ['dragenter', 'dragover'].forEach(ev => {
        wrap.addEventListener(ev, e => {
            e.preventDefault();
            dragDepth++;
            show();
        });
    });
    wrap.addEventListener('dragleave', e => {
        e.preventDefault();
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0) hide();
    });
    wrap.addEventListener('drop', e => {
        e.preventDefault();
        dragDepth = 0;
        hide();
        if (!Chat.activeId) { toast('Open a conversation first'); return; }
        handleFilePick(e.dataTransfer.files);
    });
}

function initPasteUpload() {
    document.addEventListener('paste', e => {
        const panel = document.getElementById('chatActive');
        if (!Chat.activeId || !panel || panel.classList.contains('hidden')) return;
        const items = e.clipboardData?.items;
        if (!items) return;
        for (const item of items) {
            if (item.type.startsWith('image/')) {
                const file = item.getAsFile();
                if (file) { e.preventDefault(); uploadFile(file); break; }
            }
        }
    });
}

/* ─── Emoji ─────────────────────────────────────────────── */
function initEmojiPanel() {
    const panel = document.getElementById('emojiPanel');
    panel.innerHTML = EMOJIS.map(e => `<button type="button">${e}</button>`).join('');
    panel.querySelectorAll('button').forEach(btn => {
        btn.addEventListener('click', () => {
            const inp = document.getElementById('msgInput');
            inp.value += btn.textContent;
            inp.focus();
        });
    });
}

function updateGroupModalState() {
    const title = document.getElementById('groupTitle')?.value.trim() || '';
    const count = Chat.groupMembers.length;
    const cntEl = document.getElementById('groupMemberCount');
    const btn = document.getElementById('createGroup');
    const preview = document.getElementById('groupAvatarPreview');
    if (cntEl) cntEl.textContent = count === 0 ? '0 participants' : `${count + 1} participants (incl. you)`;
    if (btn) btn.disabled = !(title.length >= 2 && count >= 1);
    if (preview) {
        preview.style.background = memberColor(title.length || 1);
        preview.innerHTML = title.length >= 2
            ? `<span>${escapeHtml(initials(title))}</span>`
            : '<i class="fas fa-users"></i>';
    }
}

function renderGroupChips() {
    const tray = document.getElementById('groupSelected');
    if (!tray) return;
    if (!Chat.groupMembers.length) {
        tray.innerHTML = '<p class="group-empty-hint">Search and tap colleagues to add them to this group.</p>';
        updateGroupModalState();
        return;
    }
    tray.innerHTML = Chat.groupMembers.map(m => `
        <div class="group-member-card" data-id="${m.id}">
            ${avatarHtml({ url: m.avatar_url, name: m.full_name, id: m.id, className: 'group-member-av', color: m.avatar_color })}
            <div class="group-member-info">
                <span class="group-member-name">${escapeHtml(m.full_name)}</span>
                <span class="group-member-sub">${escapeHtml(m.employee_code || m.email || '')}</span>
            </div>
            <button type="button" class="chip-remove" data-id="${m.id}" aria-label="Remove">&times;</button>
        </div>
    `).join('');
    tray.querySelectorAll('.chip-remove').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const rid = parseInt(btn.dataset.id, 10);
            Chat.groupMembers = Chat.groupMembers.filter(x => x.id !== rid);
            renderGroupChips();
        });
    });
    updateGroupModalState();
}

function renderProfileModal() {
    if (!Chat.me) return;
    const nameEl = document.getElementById('profileModalName');
    const roleEl = document.getElementById('profileModalRole');
    const metaEl = document.getElementById('profileModalMeta');
    const initialsEl = document.getElementById('profileDpInitials');
    const imgEl = document.getElementById('profileDpImg');
    const preview = document.getElementById('profileDpPreview');
    const removeBtn = document.getElementById('btnRemoveProfilePhoto');

    if (nameEl) nameEl.textContent = Chat.me.full_name || 'User';
    if (roleEl) roleEl.textContent = (Chat.me.portal_role || 'user').replace(/_/g, ' ');
    const parts = [Chat.me.designation, Chat.me.department, Chat.me.employee_code ? `BID ${Chat.me.employee_code}` : ''].filter(Boolean);
    if (metaEl) metaEl.textContent = parts.join(' · ') || Chat.me.email || '';

    const color = Chat.me.avatar_color || memberColor(Chat.me.id);
    if (preview) preview.style.background = color;

    if (Chat.me.avatar_url) {
        initialsEl?.classList.add('hidden');
        if (imgEl) {
            imgEl.src = chatFileUrl(Chat.me.avatar_url);
            imgEl.classList.remove('hidden');
        }
        removeBtn?.classList.remove('hidden');
    } else {
        if (initialsEl) {
            initialsEl.textContent = initials(Chat.me.full_name);
            initialsEl.classList.remove('hidden');
        }
        imgEl?.classList.add('hidden');
        removeBtn?.classList.add('hidden');
    }
}

function updateBlockedCountBadge() {
    const n = Chat.blockedCount || 0;
    const label = n === 1 ? '1 blocked' : `${n} blocked`;
    document.getElementById('blockedCountBadge')?.classList.toggle('hidden', n === 0);
    const profileBadge = document.getElementById('blockedCountBadge');
    if (profileBadge) profileBadge.textContent = String(n);
    ['navSettingsBadge', 'sidebarSettingsBadge'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = String(n);
        el.classList.toggle('hidden', n === 0);
    });
    const countEl = document.getElementById('settingsBlockedCount');
    if (countEl) countEl.textContent = n === 0 ? 'No one blocked' : label;
}

async function loadBlockedUsers() {
    const res = await chatApi('listBlockedUsers');
    if (!res.success) {
        Chat.blockedUsers = [];
        Chat.blockedCount = 0;
        updateBlockedCountBadge();
        return [];
    }
    Chat.blockedUsers = res.data?.items || [];
    Chat.blockedCount = res.data?.count ?? Chat.blockedUsers.length;
    updateBlockedCountBadge();
    return Chat.blockedUsers;
}

function formatBlockedDate(iso) {
    if (!iso) return '';
    try {
        const d = new Date(iso.replace(' ', 'T'));
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    } catch {
        return '';
    }
}

function renderSettingsBlockedList() {
    const list = document.getElementById('settingsBlockedList');
    if (!list) return;
    const items = Chat.blockedUsers || [];
    if (!items.length) {
        list.innerHTML = `<div class="blocked-empty"><i class="fas fa-circle-check"></i><strong>No blocked users</strong><span>When you block someone, they will appear in this list.</span></div>`;
        return;
    }
    list.innerHTML = items.map(u => {
        const meta = [u.designation, u.employee_code ? `BID ${u.employee_code}` : ''].filter(Boolean).join(' · ');
        const blockedOn = formatBlockedDate(u.blocked_at);
        return `<div class="blocked-item" data-id="${u.id}">
            ${avatarHtml({ url: u.avatar_url, name: u.full_name, id: u.id, color: u.avatar_color })}
            <div class="blocked-item-body">
                <strong>${escapeHtml(u.full_name)}</strong>
                <small>${escapeHtml(meta || u.email || '')}</small>
                ${blockedOn ? `<span class="blocked-item-date">Blocked ${escapeHtml(blockedOn)}</span>` : ''}
            </div>
            <button type="button" class="btn-unblock" data-unblock-id="${u.id}" title="Unblock ${escapeHtml(u.full_name)}">
                <i class="fas fa-unlock"></i> Unblock
            </button>
        </div>`;
    }).join('');
    list.querySelectorAll('[data-unblock-id]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const uid = parseInt(btn.dataset.unblockId, 10);
            if (uid > 0) await unblockContact(uid, btn);
        });
    });
}

async function unblockContact(userId, btn) {
    if (btn) btn.disabled = true;
    const res = await chatApi('unblockUser', { method: 'POST', body: { user_id: userId } });
    if (!res.success) {
        if (btn) btn.disabled = false;
        toast(res.error || 'Could not unblock user');
        return;
    }
    toast('User unblocked — they can message you again');
    await loadBlockedUsers();
    renderSettingsBlockedList();
    await loadConversations();
}

async function openSettingsModal() {
    closeProfileModal();
    document.getElementById('chatDropdown')?.classList.remove('open');
    document.getElementById('settingsModal')?.classList.add('open');
    document.body.classList.add('chat-modal-open');
    const list = document.getElementById('settingsBlockedList');
    if (list) list.innerHTML = '<div class="blocked-loading"><i class="fas fa-spinner fa-spin"></i> Loading blocked users…</div>';
    await loadBlockedUsers();
    renderSettingsBlockedList();
}

function closeSettingsModal() {
    document.getElementById('settingsModal')?.classList.remove('open');
    if (!document.getElementById('profileModal')?.classList.contains('open')) {
        document.body.classList.remove('chat-modal-open');
    }
}

function openProfileModal() {
    renderProfileModal();
    updateBlockedCountBadge();
    document.getElementById('profileModal')?.classList.add('open');
    document.body.classList.add('chat-modal-open');
}

function closeProfileModal() {
    document.getElementById('profileModal')?.classList.remove('open');
    document.body.classList.remove('chat-modal-open');
}

async function handleProfilePhotoFile(file) {
    if (!file || !isImageFile(file)) {
        toast('Choose a JPG, PNG, GIF, or WEBP image');
        return;
    }
    if (file.size > 3 * 1024 * 1024) {
        toast('Image must be under 3MB');
        return;
    }
    const uploadBtn = document.getElementById('btnUploadProfilePhoto');
    const dpBtn = document.getElementById('profileDpBtn');
    if (uploadBtn) uploadBtn.disabled = true;
    if (dpBtn) dpBtn.disabled = true;
    toast('Uploading photo…', 2000);

    const fd = new FormData();
    fd.append('file', file);
    const res = await chatApi('uploadProfilePhoto', { method: 'POST', formData: fd });
    if (uploadBtn) uploadBtn.disabled = false;
    if (dpBtn) dpBtn.disabled = false;

    if (!res.success) {
        toast(res.error || 'Could not upload photo');
        return;
    }
    Chat.me.avatar_url = res.data.avatar_url || '';
    Chat.me.avatar_color = res.data.avatar_color || Chat.me.avatar_color;
    updateNavProfileAvatar();
    renderProfileModal();
    await loadConversations();
    if (Chat.activeId) {
        const res2 = await chatApi('getMessages', { params: { conversation_id: Chat.activeId, limit: 1 } });
        if (res2.success && res2.data.participants) {
            const peer = res2.data.participants.find(p => p.id !== Chat.me.id);
            if (Chat.convType === 'direct' && peer) {
                setAvatarElement(document.getElementById('headerAvatar'), {
                    url: peer.avatar_url, name: peer.full_name, id: peer.id, color: peer.avatar_color
                });
            }
        }
    }
    toast('Profile photo updated');
}

function initProfileModal() {
    const modal = document.getElementById('profileModal');
    const input = document.getElementById('profilePhotoInput');

    const pickPhoto = () => input?.click();

    document.getElementById('profileDpBtn')?.addEventListener('click', pickPhoto);
    document.getElementById('btnUploadProfilePhoto')?.addEventListener('click', pickPhoto);
    document.getElementById('closeProfileModal')?.addEventListener('click', closeProfileModal);
    modal?.addEventListener('click', e => { if (e.target === modal) closeProfileModal(); });

    input?.addEventListener('change', e => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (file) handleProfilePhotoFile(file);
    });

    document.getElementById('btnRemoveProfilePhoto')?.addEventListener('click', async () => {
        const btn = document.getElementById('btnRemoveProfilePhoto');
        if (btn) btn.disabled = true;
        const res = await chatApi('removeProfilePhoto', { method: 'POST', body: {} });
        if (btn) btn.disabled = false;
        if (!res.success) { toast(res.error || 'Could not remove photo'); return; }
        Chat.me.avatar_url = '';
        Chat.me.avatar_color = res.data.avatar_color || Chat.me.avatar_color;
        updateNavProfileAvatar();
        renderProfileModal();
        await loadConversations();
        toast('Profile photo removed');
    });
}

function openGroupModal() {
    const modal = document.getElementById('groupModal');
    if (!modal) return;
    Chat.groupMembers = [];
    const titleInp = document.getElementById('groupTitle');
    const memberSearch = document.getElementById('groupMemberSearch');
    if (titleInp) titleInp.value = '';
    if (memberSearch) memberSearch.value = '';
    document.getElementById('groupSearchResults')?.classList.add('hidden');
    renderGroupChips();
    modal.classList.add('open');
    document.body.classList.add('chat-modal-open');
    titleInp?.focus();
}

function closeGroupModal() {
    const modal = document.getElementById('groupModal');
    modal?.classList.remove('open');
    document.body.classList.remove('chat-modal-open');
}

/* ─── Group Modal ───────────────────────────────────────── */
function initGroupModal() {
    const modal = document.getElementById('groupModal');
    document.getElementById('groupTitle')?.addEventListener('input', updateGroupModalState);

    document.getElementById('btnNewGroup')?.addEventListener('click', openGroupModal);
    document.getElementById('navBtnNewGroup')?.addEventListener('click', openGroupModal);

    document.getElementById('closeGroupModal')?.addEventListener('click', closeGroupModal);
    document.getElementById('cancelGroup')?.addEventListener('click', closeGroupModal);
    modal?.addEventListener('click', e => {
        if (e.target === modal) closeGroupModal();
    });
    document.getElementById('groupMemberSearch').addEventListener('input', e => {
        clearTimeout(Chat.groupSearchTimer);
        const q = e.target.value.trim();
        Chat.groupSearchTimer = setTimeout(() => {
            searchUsers(q, 'groupSearchResults', (id, user) => {
                if (Chat.groupMembers.some(m => m.id === id)) {
                    toast('Already added to group');
                    return;
                }
                Chat.groupMembers.push(user);
                renderGroupChips();
                document.getElementById('groupMemberSearch').value = '';
                document.getElementById('groupSearchResults').classList.add('hidden');
            });
        }, 300);
    });
    document.getElementById('createGroup').addEventListener('click', async () => {
        const title = document.getElementById('groupTitle').value.trim();
        if (!title || Chat.groupMembers.length < 1) { toast('Enter group name and add at least one member'); return; }
        const btn = document.getElementById('createGroup');
        btn.disabled = true;
        btn.textContent = 'Creating…';
        const res = await chatApi('createGroup', { method: 'POST', body: { title, member_ids: Chat.groupMembers.map(m => m.id) } });
        btn.textContent = 'Create group';
        if (!res.success) { toast(res.error || 'Could not create group'); updateGroupModalState(); return; }
        closeGroupModal();
        toast('Group created');
        await loadConversations();
        openConversation(res.data.conversation_id);
    });
}

/* ─── DOMContentLoaded ──────────────────────────────────── */
document.addEventListener('DOMContentLoaded', async () => {
    document.addEventListener('click', () => window.PortalNotifySound?.unlock?.(), { once: true, capture: true });
    document.getElementById('btnEmbedBackDashboard')?.addEventListener('click', () => {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({ type: 'portal-navigate', view: 'dashboard' }, '*');
        } else {
            window.location.href = 'employee-portal.html';
        }
    });
    initChatPrefs();
    const meRes = await chatApi('me');
    if (!meRes.success) { window.location.href = 'index.html'; return; }
    Chat.me = meRes.data;

    updateNavProfileAvatar();

    const back = document.getElementById('chatBackLink');
    if (back) {
        let href = 'employee-portal.html';
        try {
            const chatOrigin = sessionStorage.getItem('balitech_chat_origin');
            if (chatOrigin && chatOrigin.includes('employee-portal')) {
                href = 'employee-portal.html';
            } else {
                const storedWork = sessionStorage.getItem('balitech_work_portal');
                const mode = sessionStorage.getItem('balitech_login_mode');
                if (mode === 'work' && storedWork) {
                    href = storedWork;
                } else if (window.workPortalUrlForRole && window.hasSeparateWorkPortal?.(Chat.me.portal_role)) {
                    href = window.workPortalUrlForRole(Chat.me.portal_role);
                } else if (window.workPortalUrlForRole) {
                    href = window.workPortalUrlForRole(Chat.me.portal_role) || 'employee-portal.html';
                }
            }
        } catch (e) { /* ignore */ }
        back.href = href;
        back.title = href.includes('employee-portal') ? 'Employee Self Service' : 'Work portal';
    }

    initEmojiPanel();
    initGroupModal();
    initProfileModal();
    initDragDropUpload();
    initPasteUpload();
    initScrollGuard();

    document.getElementById('closeLightbox')?.addEventListener('click', closeLightbox);
    document.getElementById('chatLightbox')?.addEventListener('click', e => {
        if (e.target.id === 'chatLightbox') closeLightbox();
    });
    document.getElementById('btnCancelUpload')?.addEventListener('click', () => {
        if (!Chat.uploading) hideUploadPreview();
    });

    await loadConversations();
    await loadBlockedUsers();
    initConversationListEvents();
    await initChatWebSocket();
    Chat.heartbeatTimer = setInterval(() => chatApi('heartbeat'), 30000);

    /* ── Sidebar search ── */
    const userSearch = document.getElementById('userSearch');
    userSearch?.addEventListener('input', e => {
        clearTimeout(Chat.sidebarSearchTimer);
        const q = e.target.value.trim();
        Chat.sidebarSearchTimer = setTimeout(
            () => searchUsers(q, 'searchResults', id => startDirectChat(id), { sidebar: true }),
            280
        );
    });
    userSearch?.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeSidebarSearch();
    });
    document.getElementById('btnClearSearch')?.addEventListener('click', closeSidebarSearch);

    const focusNewChatSearch = () => {
        Chat.showArchived = false;
        Chat.listFilter = 'all';
        setFilterChipsActive('all');
        renderConversationList();
        closeSidebarSearch();
        userSearch?.focus();
        userSearch?.select();
    };
    document.getElementById('btnNewChat')?.addEventListener('click', focusNewChatSearch);
    document.getElementById('welcomeNewChat')?.addEventListener('click', focusNewChatSearch);
    document.getElementById('welcomeNewGroup')?.addEventListener('click', openGroupModal);

    /* ── Emoji / Attach / Send ── */
    document.getElementById('btnEmoji').addEventListener('click', () => {
        hideAttachMenu();
        document.getElementById('emojiPanel').classList.toggle('open');
    });
    document.getElementById('btnAttach').addEventListener('click', e => {
        e.stopPropagation();
        document.getElementById('emojiPanel').classList.remove('open');
        toggleAttachMenu();
    });
    document.getElementById('btnPickPhoto').addEventListener('click', () => {
        hideAttachMenu();
        document.getElementById('photoInput').click();
    });
    document.getElementById('btnPickDocument').addEventListener('click', () => {
        hideAttachMenu();
        document.getElementById('fileInput').click();
    });
    document.getElementById('photoInput').addEventListener('change', e => {
        handleFilePick(e.target.files);
        e.target.value = '';
    });
    document.getElementById('fileInput').addEventListener('change', e => {
        if (e.target.files[0]) uploadFile(e.target.files[0]);
        e.target.value = '';
    });

    document.getElementById('btnSend').addEventListener('click', sendText);
    const msgInput = document.getElementById('msgInput');
    msgInput.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendText(); }
        else notifyTyping();
    });
    msgInput.addEventListener('input', () => {
        notifyTyping();
        // Auto-resize textarea
        msgInput.style.height = 'auto';
        msgInput.style.height = Math.min(msgInput.scrollHeight, 120) + 'px';
    });

    /* ── Header 3-dot dropdown ── */
    const btnChatMenu = document.getElementById('btnChatMenu');
    const chatDropdown = document.getElementById('chatDropdown');
    btnChatMenu?.addEventListener('click', e => {
        e.stopPropagation();
        chatDropdown.classList.toggle('open');
    });

    document.getElementById('menuBlockUser')?.addEventListener('click', () => {
        chatDropdown.classList.remove('open');
        if (!Chat.activePeerId) return;
        openBlockUserModal();
    });

    document.getElementById('menuBlockedContacts')?.addEventListener('click', () => {
        chatDropdown.classList.remove('open');
        openSettingsModal();
    });

    document.getElementById('btnOpenSettingsFromProfile')?.addEventListener('click', openSettingsModal);
    document.getElementById('navBtnSettings')?.addEventListener('click', openSettingsModal);
    document.getElementById('btnSidebarSettings')?.addEventListener('click', openSettingsModal);
    document.getElementById('closeSettingsModal')?.addEventListener('click', closeSettingsModal);
    document.getElementById('settingsModal')?.addEventListener('click', e => {
        if (e.target.id === 'settingsModal') closeSettingsModal();
    });
    document.getElementById('settingsOpenProfile')?.addEventListener('click', () => {
        closeSettingsModal();
        openProfileModal();
    });

    document.getElementById('btnAcceptRequest')?.addEventListener('click', acceptMessageRequest);
    document.getElementById('btnDeclineRequest')?.addEventListener('click', declineMessageRequest);

    document.getElementById('menuClearChat')?.addEventListener('click', () => {
        chatDropdown.classList.remove('open');
        if (!Chat.activeId) return;
        openChatActionModal('clear');
    });

    document.getElementById('menuDeleteChat')?.addEventListener('click', () => {
        chatDropdown.classList.remove('open');
        if (!Chat.activeId) return;
        openChatActionModal('delete');
    });

    document.getElementById('menuMuteChat')?.addEventListener('click', () => {
        chatDropdown.classList.remove('open');
        if (!Chat.activeId) { toast('Select a conversation first'); return; }
        toggleMuteConversation(Chat.activeId);
    });

    document.getElementById('menuArchiveChat')?.addEventListener('click', () => {
        chatDropdown.classList.remove('open');
        if (!Chat.activeId) { toast('Select a conversation first'); return; }
        const isArch = (Chat.archivedIds || []).includes(Chat.activeId);
        if (isArch) unarchiveConversation(Chat.activeId);
        else archiveConversation(Chat.activeId);
        updateArchiveMenuLabel();
    });

    /* ── Context menu actions ── */
    document.getElementById('ctxEdit')?.addEventListener('click', () => {
        const id = Chat.contextMsgId; hideContextMenu();
        if (id) openEditModal(id);
    });

    document.getElementById('ctxDelete')?.addEventListener('click', () => {
        const id = Chat.contextMsgId; hideContextMenu();
        if (id) openDeleteModal(id);
    });

    document.getElementById('ctxDeleteForMe')?.addEventListener('click', () => {
        const id = Chat.contextMsgId; hideContextMenu();
        if (id) deleteMessageForMe(id);
    });

    document.getElementById('ctxCopy')?.addEventListener('click', () => {
        const msg = Chat.messages.find(m => m.id === Chat.contextMsgId);
        hideContextMenu();
        if (msg?.body) { navigator.clipboard.writeText(msg.body).then(() => toast('Copied!')); }
    });

    document.getElementById('ctxReply')?.addEventListener('click', () => {
        const msg = Chat.messages.find(m => m.id === Chat.contextMsgId);
        hideContextMenu();
        if (msg) setReply(msg);
    });

    document.getElementById('btnCancelReply')?.addEventListener('click', clearReply);

    document.getElementById('btnSearchInChat')?.addEventListener('click', () => {
        if (!Chat.activeId) { toast('Open a conversation first'); return; }
        toggleInChatSearch(true);
    });
    document.getElementById('btnCloseInSearch')?.addEventListener('click', closeInChatSearch);
    document.getElementById('chatInSearchInput')?.addEventListener('input', e => {
        Chat.inChatSearch = e.target.value.trim();
        renderMessages(Chat.inChatSearch.length >= 2 ? 'search' : undefined);
    });
    document.getElementById('chatInSearchInput')?.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeInChatSearch();
    });

    const archivedRow = document.getElementById('archivedRow');
    const toggleArchived = () => {
        Chat.showArchived = !Chat.showArchived;
        setFilterChipsActive('all');
        renderConversationList();
        toast(Chat.showArchived ? 'Showing archived chats' : 'Showing active chats');
    };
    archivedRow?.addEventListener('click', toggleArchived);
    archivedRow?.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleArchived(); }
    });

    document.getElementById('navProfile')?.addEventListener('click', openProfileModal);

    /* ── Edit modal ── */
    document.getElementById('closeEditModal')?.addEventListener('click', () => {
        document.getElementById('editMsgModal').classList.remove('open');
        Chat.editMsgId = null;
    });
    document.getElementById('cancelEditMsg')?.addEventListener('click', () => {
        document.getElementById('editMsgModal').classList.remove('open');
        Chat.editMsgId = null;
    });
    document.getElementById('confirmEditMsg')?.addEventListener('click', confirmEdit);
    document.getElementById('editMsgInput')?.addEventListener('keydown', e => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) confirmEdit();
    });

    /* ── Delete modal ── */
    document.getElementById('closeDeleteModal')?.addEventListener('click', () => {
        document.getElementById('deleteMsgModal').classList.remove('open');
        Chat.deleteMsgId = null;
    });
    document.getElementById('cancelDeleteMsg')?.addEventListener('click', () => {
        document.getElementById('deleteMsgModal').classList.remove('open');
        Chat.deleteMsgId = null;
    });
    document.getElementById('confirmDeleteMsg')?.addEventListener('click', confirmDelete);

    /* ── Chat action (clear/delete) modal ── */
    document.getElementById('closeChatActionModal')?.addEventListener('click', () => {
        document.getElementById('chatActionModal').classList.remove('open');
        Chat.chatActionPending = null;
    });
    document.getElementById('cancelChatAction')?.addEventListener('click', () => {
        document.getElementById('chatActionModal').classList.remove('open');
        Chat.chatActionPending = null;
    });
    document.getElementById('confirmChatAction')?.addEventListener('click', confirmChatAction);

    /* ── Mobile back ── */
    document.getElementById('btnBackMobile')?.addEventListener('click', () => {
        document.getElementById('listPanel').classList.remove('hidden-mobile');
        document.getElementById('chatActive')?.classList.add('hidden');
        document.getElementById('chatEmpty')?.classList.remove('hidden');
        stopRealtime();
    });

    /* ── Global click: close menus ── */
    document.addEventListener('click', e => {
        // Close context menu
        if (!e.target.closest('#msgContextMenu') && !e.target.closest('.msg-action-btn')) {
            document.getElementById('msgContextMenu').classList.add('hidden');
        }
        // Close header dropdown
        if (!e.target.closest('#chatMenuWrap')) {
            chatDropdown.classList.remove('open');
        }
        // Close emoji panel
        if (!e.target.closest('#emojiPanel') && !e.target.closest('#btnEmoji')) {
            document.getElementById('emojiPanel').classList.remove('open');
        }
        if (!e.target.closest('#attachMenu') && !e.target.closest('#btnAttach')) {
            hideAttachMenu();
        }
        if (!e.target.closest('.sidebar-search-wrap')) {
            closeSidebarSearch();
        }
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeLightbox();
            hideAttachMenu();
            closeGroupModal();
            closeProfileModal();
            document.getElementById('editMsgModal')?.classList.remove('open');
            document.getElementById('deleteMsgModal')?.classList.remove('open');
            document.getElementById('chatActionModal')?.classList.remove('open');
            closeSettingsModal();
        }
    });

    /* ── Right-click on messages → context menu ── */
    document.getElementById('msgArea')?.addEventListener('contextmenu', e => {
        const msgEl = e.target.closest('.chat-msg');
        if (!msgEl) return;
        const msgId  = parseInt(msgEl.dataset.msgId, 10);
        const isMine = msgEl.dataset.isMine === '1';
        const msg    = Chat.messages.find(m => m.id === msgId);
        if (!msg || msg.is_deleted || !msgId) return;
        e.preventDefault();
        showMessageContextMenu(e, msgId, isMine);
    });

    /* ── Visibility for read receipts ── */
    document.getElementById('btnLoadOlder')?.addEventListener('click', loadOlderMessages);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            if (Chat.ws.enabled && !Chat.ws.connected) {
                refreshWsToken().then(() => connectChatWebSocket());
            }
            if (Chat.activeId) {
                chatApi('markRead', { method: 'POST', body: { conversation_id: Chat.activeId } });
                refreshPresence();
            }
        }
    });

    window.addEventListener('online', () => {
        if (Chat.ws.enabled) {
            Chat.ws.reconnectAttempts = 0;
            refreshWsToken().then(() => connectChatWebSocket());
        }
    });

    /* ── Filter chips ── */
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            Chat.listFilter = chip.dataset.filter || 'all';
            Chat.showArchived = false;
            setFilterChipsActive(Chat.listFilter);
            renderConversationList();
        });
    });

});
