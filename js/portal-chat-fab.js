/**
 * Floating Chat button - Employee Self Service portal only (opens in-page chat view).
 */
(function () {
    if (document.getElementById('portalChatFab')) return;

    // Inject WebRTC Call Shell and CSS dynamically on all portals
    const path = (window.location.pathname || '').toLowerCase();
    const isWfh = path.includes('/workfromhome/');
    const inAttendance = path.includes('/attendance/');
    const prefix = (isWfh || inAttendance) ? '../' : '';

    if (!document.querySelector('link[href*="call-shell.css"]')) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = prefix + 'css/call-shell.css';
        document.head.appendChild(link);
    }

    const script = document.createElement('script');
    script.src = prefix + 'js/call-shell.js';
    document.body.appendChild(script);

    if (!path.includes('employee-portal.html')) {
        return;
    }

    if (!document.querySelector('link[href*="portal-chat-fab.css"]')) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'css/portal-chat-fab.css';
        document.head.appendChild(link);
    }

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'portalChatFab';
    btn.className = 'portal-chat-fab';
    btn.title = 'Balitech Chat';
    btn.innerHTML = '<i class="fas fa-comments"></i><span>Chat</span><span class="portal-chat-fab-badge hidden" id="badgeChatFab">0</span>';
    btn.addEventListener('click', () => {
        if (typeof window.showView === 'function') {
            window.showView('chat');
        }
    });
    document.body.appendChild(btn);
})();
