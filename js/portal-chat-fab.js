/**
 * Floating Chat button on all Balitech portals (skip login page).
 */
(function () {
    if (document.getElementById('portalChatFab')) return;
    const path = window.location.pathname || '';
    if (/\/index\.html?$/i.test(path) || path.endsWith('/') && !path.includes('portal')) {
        const baseName = path.split('/').pop();
        if (baseName === '' || baseName === 'interview-forms' || baseName === 'interview-forms/') return;
    }
    if (/index\.html$/i.test(path) && !path.includes('portal')) return;

    const inAttendance = /\/attendance\//i.test(path);
    const base = inAttendance ? '..' : '.';
    const chatUrl = base + '/chat-portal.html';

    if (!document.querySelector('link[href*="portal-chat-fab.css"]')) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = base + '/css/portal-chat-fab.css';
        document.head.appendChild(link);
    }

    const a = document.createElement('a');
    a.id = 'portalChatFab';
    a.href = chatUrl;
    a.className = 'portal-chat-fab';
    a.title = 'Balitech Chat';
    a.innerHTML = '<i class="fas fa-comments"></i><span>Chat</span>';
    document.body.appendChild(a);
})();
