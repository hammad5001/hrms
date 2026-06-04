/**
 * Balitech portal chat — Teams UI + WhatsApp delivery ticks
 */
const Chat = {
    me: null,
    conversations: [],
    activeId: null,
    messages: [],
    pollTimer: null,
    statusTimer: null,
    heartbeatTimer: null,
    groupMembers: [],
    searchTimer: null,
    typingTimer: null,
    typingSent: false,
    convType: 'direct',
    lastRead: null,
    presence: null
};

const EMOJIS = ['😀','😃','😄','😁','😅','😂','🤣','😊','😇','🙂','😉','😍','🥰','😘','😋','😎','🤔','😐','😢','😭','😡','👍','👎','👏','🙏','💪','❤️','🔥','✅','❌','⭐','🎉','📎','📷','💼','☕','🚀'];

async function parseChatResponse(r) {
    const text = await r.text();
    try {
        return JSON.parse(text);
    } catch {
        console.error('Chat API invalid JSON', text.slice(0, 300));
        return { success: false, error: 'Server error. Try again.' };
    }
}

async function chatApi(action, opts = {}) {
    const method = opts.method || 'GET';
    if (method === 'GET') {
        const q = new URLSearchParams({ action, ...opts.params });
        const r = await fetch('api/chat_api.php?' + q, { credentials: 'include' });
        return parseChatResponse(r);
    }
    if (opts.formData) {
        if (!opts.formData.has('action')) {
            opts.formData.append('action', action);
        }
        let url = 'api/chat_api.php?action=' + encodeURIComponent(action);
        const cid = opts.conversationId || Chat.activeId;
        if (cid && action === 'upload') {
            url += '&conversation_id=' + encodeURIComponent(String(cid));
            if (!opts.formData.has('conversation_id')) {
                opts.formData.append('conversation_id', String(cid));
            }
        }
        const r = await fetch(url, {
            method: 'POST',
            credentials: 'include',
            body: opts.formData
        });
        return parseChatResponse(r);
    }
    const r = await fetch('api/chat_api.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(opts.body || {})
    });
    return parseChatResponse(r);
}

/** Resolve uploads/chat/... to a full URL from the current page */
function chatFileUrl(path) {
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    try {
        return new URL(path.replace(/^\//, ''), window.location.href).href;
    } catch {
        return path;
    }
}

function toast(msg) {
    const el = document.getElementById('chatToast');
    el.textContent = msg;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 3000);
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
}

function formatTime(ts) {
    if (!ts) return '';
    const d = new Date(ts);
    const now = new Date();
    if (d.toDateString() === now.toDateString()) {
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

function formatFullTime(ts) {
    if (!ts) return '';
    return new Date(ts).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function linkify(text) {
    return escapeHtml(text).replace(/\n/g, '<br>');
}

function renderTicks(status) {
    const titles = {
        pending: 'Sending…',
        sent: 'Sent',
        delivered: 'Delivered',
        read: 'Seen'
    };
    const title = titles[status] || 'Sent';
    if (status === 'pending') {
        return `<span class="msg-ticks tick-pending" title="${title}"><i class="fas fa-clock"></i></span>`;
    }
    if (status === 'sent') {
        return `<span class="msg-ticks tick-sent" title="${title}">✓</span>`;
    }
    if (status === 'delivered') {
        return `<span class="msg-ticks tick-delivered" title="${title}">✓✓</span>`;
    }
    if (status === 'read') {
        return `<span class="msg-ticks tick-read" title="${title}">✓✓</span>`;
    }
    return `<span class="msg-ticks tick-sent" title="Sent">✓</span>`;
}

function dateSeparatorLabel(ts) {
    const d = new Date(ts);
    const now = new Date();
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    if (d.toDateString() === now.toDateString()) return 'Today';
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
}

function updateHeaderPresence(presence) {
    const sub = document.getElementById('headerSub');
    const av = document.getElementById('headerAvatar');
    const dot = document.getElementById('presenceDot');
    if (!sub) return;
    sub.className = 'sub';
    av?.classList.remove('online-ring');
    dot?.classList.remove('online', 'typing');
    showTypingIndicator(false);
    if (!presence) return;
    if (presence.status === 'online') {
        sub.textContent = 'online';
        sub.classList.add('online');
        av?.classList.add('online-ring');
        dot?.classList.add('online');
    } else if (presence.status === 'typing') {
        sub.textContent = 'typing…';
        sub.classList.add('typing');
        dot?.classList.add('typing');
        showTypingIndicator(true);
    } else if (presence.label) {
        sub.textContent = presence.label;
    }
}

function showTypingIndicator(show) {
    const el = document.getElementById('typingIndicator');
    if (el) el.classList.toggle('show', !!show);
    if (show) {
        const area = document.getElementById('msgArea');
        if (area) area.scrollTop = area.scrollHeight;
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
        if (Chat.convType === 'direct') {
            renderMessages();
        }
    }
}

async function refreshMessageStatuses() {
    if (!Chat.activeId) return;
    const mineIds = Chat.messages.filter(m => m.is_mine && m.id).map(m => m.id);
    if (!mineIds.length) return;
    const res = await chatApi('messageStatuses', {
        params: { conversation_id: Chat.activeId, ids: mineIds.join(',') }
    });
    if (!res.success || !res.data) return;
    let changed = false;
    Chat.messages.forEach(m => {
        if (m.is_mine && res.data[m.id] && res.data[m.id] !== m.status) {
            m.status = res.data[m.id];
            changed = true;
        }
    });
    if (changed) renderMessages();
}

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

function renderSearchResults(users, container, onPick) {
    const el = document.getElementById(container);
    if (!users.length) {
        el.classList.add('hidden');
        el.innerHTML = '';
        return;
    }
    el.classList.remove('hidden');
    el.innerHTML = users.map(u => `
        <div class="chat-search-hit" data-id="${u.id}">
            <div class="avatar">${initials(u.full_name)}</div>
            <div class="meta">
                <div class="name">${escapeHtml(u.full_name)}</div>
                <div class="sub">BID: ${escapeHtml(u.employee_code || '—')} · ${escapeHtml(u.email)}</div>
            </div>
        </div>
    `).join('');
    el.querySelectorAll('.chat-search-hit').forEach(hit => {
        hit.addEventListener('click', () => onPick(parseInt(hit.dataset.id, 10), users.find(x => x.id === parseInt(hit.dataset.id, 10))));
    });
}

async function searchUsers(q, container, onPick) {
    if (q.length < 1) {
        document.getElementById(container).classList.add('hidden');
        return;
    }
    const res = await chatApi('searchUsers', { params: { q } });
    if (res.success) renderSearchResults(res.data, container, onPick);
}

async function startDirectChat(userId) {
    const res = await chatApi('createDirect', { method: 'POST', body: { user_id: userId } });
    if (!res.success) {
        toast(res.error || 'Could not start chat');
        return;
    }
    document.getElementById('searchResults').classList.add('hidden');
    document.getElementById('userSearch').value = '';
    await loadConversations();
    openConversation(res.data.conversation_id);
}

function previewText(msg) {
    if (!msg) return 'No messages yet';
    if (msg.msg_type === 'image') return '📷 Photo';
    if (msg.msg_type === 'file') return '📎 File';
    return (msg.body || '').slice(0, 60);
}

async function loadConversations() {
    const res = await chatApi('listConversations');
    if (!res.success) return;
    Chat.conversations = res.data;
    const list = document.getElementById('convList');
    if (!res.data.length) {
        list.innerHTML = '<p class="chat-list-empty">No chats yet.<br>Search by BID or email above.</p>';
        return;
    }
    list.innerHTML = res.data.map(c => {
        const active = c.id === Chat.activeId ? 'active' : '';
        const lm = c.last_message;
        return `
        <div class="chat-conv-item ${active}" data-id="${c.id}">
            <div class="avatar ${c.type === 'group' ? 'group' : ''}" style="background:${c.avatar_color || '#6264a7'}">${initials(c.display_title)}</div>
            <div class="chat-conv-body">
                <div class="top">
                    <span class="title">${escapeHtml(c.display_title)}${c.unread > 0 ? `<span class="chat-unread">${c.unread}</span>` : ''}</span>
                    <span class="time">${lm ? formatTime(lm.created_at) : ''}</span>
                </div>
                <div class="preview">${escapeHtml(previewText(lm))}</div>
            </div>
        </div>`;
    }).join('');
    list.querySelectorAll('.chat-conv-item').forEach(item => {
        item.addEventListener('click', () => openConversation(parseInt(item.dataset.id, 10)));
    });
}

function renderMessages() {
    const area = document.getElementById('msgArea');
    const showSender = Chat.convType === 'group';
    let html = '';
    let lastDate = '';

    Chat.messages.forEach(m => {
        const dLabel = dateSeparatorLabel(m.created_at);
        if (dLabel !== lastDate) {
            lastDate = dLabel;
            html += `<div class="chat-date-sep">${escapeHtml(dLabel)}</div>`;
        }

        const mine = m.is_mine;
        const isImage = m.msg_type === 'image' && m.file_url;
        const isFile = m.msg_type === 'file' && m.file_url;
        const fileUrl = chatFileUrl(m.file_url);
        let bubbleClass = 'chat-bubble';
        let inner = '';

        if (isImage) {
            bubbleClass += ' chat-bubble-media';
            const caption = m.body && m.body !== '📷 Photo' ? `<div class="chat-caption">${linkify(m.body)}</div>` : '';
            inner = `
                <div class="chat-media">
                    <img class="chat-img" src="${escapeHtml(fileUrl)}" alt="Photo" loading="lazy"
                         onclick="window.open('${escapeHtml(fileUrl)}','_blank')">
                </div>${caption}`;
        } else if (isFile) {
            inner = `<a class="file-link" href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener" download>
                <i class="fas fa-file-alt"></i><span>${escapeHtml(m.file_name || 'Download file')}</span></a>`;
        } else {
            inner = linkify(m.body || '');
        }

        const status = m.status || (mine ? 'sent' : '');
        const meta = mine
            ? `<div class="chat-msg-meta"><span class="time" title="${formatFullTime(m.created_at)}">${formatTime(m.created_at)}</span>${renderTicks(status)}</div>`
            : `<div class="chat-msg-meta"><span class="time" title="${formatFullTime(m.created_at)}">${formatTime(m.created_at)}</span></div>`;

        html += `
        <div class="chat-msg ${mine ? 'mine' : 'theirs'}${isImage ? ' has-media' : ''}" data-msg-id="${m.id || ''}">
            ${!mine && showSender ? `<span class="sender">${escapeHtml(m.sender_name)}</span>` : ''}
            <div class="${bubbleClass}">${inner}${mine ? meta : ''}</div>
            ${!mine ? meta : ''}
        </div>`;
    });

    if (Chat.convType === 'direct' && Chat.lastRead?.label) {
        html += `<div class="chat-seen-banner">${escapeHtml(Chat.lastRead.label)}</div>`;
    }

    area.innerHTML = html;
    area.scrollTop = area.scrollHeight;
}

function startPollers() {
    stopPollers();
    Chat.pollTimer = setInterval(pollNewMessages, 3000);
    Chat.statusTimer = setInterval(async () => {
        await refreshMessageStatuses();
        await refreshPresence();
    }, 2500);
}

function stopPollers() {
    if (Chat.pollTimer) clearInterval(Chat.pollTimer);
    if (Chat.statusTimer) clearInterval(Chat.statusTimer);
    Chat.pollTimer = null;
    Chat.statusTimer = null;
}

function stopPoll() {
    stopPollers();
    if (Chat.activeId) {
        chatApi('setTyping', { method: 'POST', body: { conversation_id: Chat.activeId, typing: false } });
    }
}

async function openConversation(id) {
    Chat.activeId = id;
    stopPoll();
    const res = await chatApi('getMessages', { params: { conversation_id: id } });
    if (!res.success) {
        toast(res.error || 'Could not load chat');
        return;
    }
    Chat.messages = res.data.messages;
    Chat.convType = res.data.conversation.type;
    Chat.presence = res.data.presence;
    Chat.lastRead = res.data.last_read;

    const title = res.data.conversation.display_title || res.data.conversation.title || 'Chat';
    document.getElementById('chatEmpty').classList.add('hidden');
    document.getElementById('chatActive').classList.remove('hidden');
    document.getElementById('headerTitle').textContent = title;
    document.getElementById('headerAvatar').textContent = initials(title);
    updateHeaderPresence(res.data.presence);

    renderMessages();
    await chatApi('markRead', { method: 'POST', body: { conversation_id: id } });
    await refreshPresence();
    await loadConversations();

    if (window.innerWidth <= 768) {
        document.getElementById('listPanel').classList.add('hidden-mobile');
        document.getElementById('btnBackMobile').style.display = 'flex';
    }

    startPollers();
}

async function pollNewMessages() {
    if (!Chat.activeId) return;

    if (!Chat.messages.length) {
        const res = await chatApi('getMessages', { params: { conversation_id: Chat.activeId } });
        if (res.success && res.data.messages.length) {
            Chat.messages = res.data.messages;
            Chat.lastRead = res.data.last_read;
            renderMessages();
            await chatApi('markRead', { method: 'POST', body: { conversation_id: Chat.activeId } });
        }
        loadConversations();
        return;
    }

    const lastId = Chat.messages[Chat.messages.length - 1].id;
    const res = await chatApi('getMessages', { params: { conversation_id: Chat.activeId, after_id: lastId } });
    if (res.success && res.data.messages.length) {
        const hadIncoming = res.data.messages.some(m => !m.is_mine);
        Chat.messages.push(...res.data.messages);
        renderMessages();
        if (hadIncoming) {
            await chatApi('markRead', { method: 'POST', body: { conversation_id: Chat.activeId } });
            const pr = await chatApi('getPresence', { params: { conversation_id: Chat.activeId } });
            if (pr.success) Chat.lastRead = pr.data.last_read;
        }
    }
    loadConversations();
}

async function sendText() {
    const input = document.getElementById('msgInput');
    const body = input.value.trim();
    if (!Chat.activeId || !body) return;

    const tempId = 'tmp-' + Date.now();
    Chat.messages.push({
        id: tempId,
        body,
        msg_type: 'text',
        is_mine: true,
        status: 'pending',
        created_at: new Date().toISOString(),
        sender_name: Chat.me.full_name
    });
    input.value = '';
    renderMessages();

    const res = await chatApi('sendMessage', { method: 'POST', body: { conversation_id: Chat.activeId, body } });
    if (!res.success) {
        Chat.messages = Chat.messages.filter(m => m.id !== tempId);
        renderMessages();
        toast(res.error || 'Send failed');
        return;
    }

    const full = await chatApi('getMessages', { params: { conversation_id: Chat.activeId } });
    if (full.success) {
        Chat.messages = full.data.messages;
        Chat.lastRead = full.data.last_read;
        renderMessages();
    }
    loadConversations();
}

async function uploadFile(file) {
    if (!file) return;
    const cid = parseInt(Chat.activeId, 10);
    if (!cid) {
        toast('Open a chat first, then attach a file');
        return;
    }

    const maxMb = 10;
    if (file.size > maxMb * 1024 * 1024) {
        toast('File too large (max 10MB)');
        return;
    }

    const fd = new FormData();
    fd.append('file', file, file.name || 'upload');

    toast('Uploading…');
    const uploadUrl = 'api/chat_upload.php?conversation_id=' + encodeURIComponent(String(cid));
    let res;
    try {
        const r = await fetch(uploadUrl, {
            method: 'POST',
            credentials: 'include',
            headers: { 'X-Chat-Conversation-Id': String(cid) },
            body: fd
        });
        res = await parseChatResponse(r);
    } catch (e) {
        console.error('Upload failed', e);
        toast('Upload failed — check your connection');
        return;
    }
    if (!res.success) {
        toast(res.error || res.message || 'Upload failed');
        return;
    }

    const full = await chatApi('getMessages', { params: { conversation_id: Chat.activeId } });
    if (full.success) {
        Chat.messages = full.data.messages;
        Chat.lastRead = full.data.last_read;
        renderMessages();
    }
    loadConversations();
    toast(file.type.startsWith('image/') ? 'Photo sent' : 'File sent');
}

function initEmojiPanel() {
    const panel = document.getElementById('emojiPanel');
    panel.innerHTML = EMOJIS.map(e => `<button type="button">${e}</button>`).join('');
    panel.querySelectorAll('button').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('msgInput').value += btn.textContent;
            document.getElementById('msgInput').focus();
        });
    });
}

function initGroupModal() {
    const modal = document.getElementById('groupModal');
    document.getElementById('btnNewGroup').addEventListener('click', () => {
        Chat.groupMembers = [];
        document.getElementById('groupTitle').value = '';
        document.getElementById('groupSelected').innerHTML = '';
        modal.classList.add('open');
    });
    document.getElementById('closeGroupModal').addEventListener('click', () => modal.classList.remove('open'));
    document.getElementById('cancelGroup').addEventListener('click', () => modal.classList.remove('open'));

    document.getElementById('groupMemberSearch').addEventListener('input', e => {
        clearTimeout(Chat.searchTimer);
        const q = e.target.value.trim();
        Chat.searchTimer = setTimeout(() => {
            searchUsers(q, 'groupSearchResults', (id, user) => {
                if (Chat.groupMembers.some(m => m.id === id)) return;
                Chat.groupMembers.push(user);
                document.getElementById('groupSelected').innerHTML = Chat.groupMembers
                    .map(m => `<span>${escapeHtml(m.full_name)} · ${escapeHtml(m.employee_code || m.email)}</span>`)
                    .join('');
                document.getElementById('groupMemberSearch').value = '';
                document.getElementById('groupSearchResults').classList.add('hidden');
            });
        }, 300);
    });

    document.getElementById('createGroup').addEventListener('click', async () => {
        const title = document.getElementById('groupTitle').value.trim();
        if (!title || Chat.groupMembers.length < 1) {
            toast('Enter group name and add at least one member');
            return;
        }
        const res = await chatApi('createGroup', {
            method: 'POST',
            body: { title, member_ids: Chat.groupMembers.map(m => m.id) }
        });
        if (!res.success) {
            toast(res.error || 'Could not create group');
            return;
        }
        modal.classList.remove('open');
        await loadConversations();
        openConversation(res.data.conversation_id);
    });
}

document.addEventListener('DOMContentLoaded', async () => {
    const meRes = await chatApi('me');
    if (!meRes.success) {
        window.location.href = 'index.html';
        return;
    }
    Chat.me = meRes.data;

    const homeMap = {
        admin: 'admin-dashboard.html',
        hr: 'hr-portal.html',
        recruiter: 'recruiter-portal.html',
        management: 'Management-Portal.html',
        training: 'training-portal.html',
        receptionist: 'reception-portal.html',
        attendance: 'attendance/attendance-dashboard.html',
        analytics: 'analytics-portal.html',
        user: 'employee-portal.html',
        team_lead: 'employee-portal.html',
        floor_manager: 'employee-portal.html'
    };
    const back = document.getElementById('chatBackLink');
    if (back) back.href = homeMap[Chat.me.portal_role] || 'employee-portal.html';

    initEmojiPanel();
    initGroupModal();
    await loadConversations();

    Chat.heartbeatTimer = setInterval(() => chatApi('heartbeat'), 30000);

    document.getElementById('userSearch').addEventListener('input', e => {
        clearTimeout(Chat.searchTimer);
        Chat.searchTimer = setTimeout(() => searchUsers(e.target.value.trim(), 'searchResults', (id) => startDirectChat(id)), 300);
    });

    document.getElementById('btnNewChat').addEventListener('click', () => {
        document.getElementById('userSearch').focus();
    });

    document.getElementById('btnEmoji').addEventListener('click', () => {
        document.getElementById('emojiPanel').classList.toggle('open');
    });

    document.getElementById('btnAttach').addEventListener('click', () => document.getElementById('fileInput').click());
    document.getElementById('fileInput').addEventListener('change', e => {
        if (e.target.files[0]) uploadFile(e.target.files[0]);
        e.target.value = '';
    });

    document.getElementById('btnSend').addEventListener('click', sendText);
    const msgInput = document.getElementById('msgInput');
    msgInput.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendText();
        } else {
            notifyTyping();
        }
    });
    msgInput.addEventListener('input', notifyTyping);

    document.getElementById('btnBackMobile').addEventListener('click', () => {
        document.getElementById('listPanel').classList.remove('hidden-mobile');
        stopPoll();
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && Chat.activeId) {
            chatApi('markRead', { method: 'POST', body: { conversation_id: Chat.activeId } });
            refreshPresence();
        }
    });

    setInterval(loadConversations, 15000);
});
