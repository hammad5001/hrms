/**
 * Protect portals: PHP session required. Non-admins only see their own portal.
 */
(function () {
    'use strict';

    const path = (window.location.pathname || '').toLowerCase();

    if (path.includes('login') || path.endsWith('index.html') || path.includes('admin.php')) {
        return;
    }

    // --- Heartbeat Ping to maintain Online Status ---
    const apiPrefix = window.balitechApiPrefix ? window.balitechApiPrefix() : (path.includes('/attendance/') || path.includes('/workfromhome/') ? '../' : '');
    setInterval(() => {
        if (!document.hidden) {
            fetch(apiPrefix + 'api/ping.php').catch(() => { });
        }
    }, 60000);

    /** @type {Record<string, string>} file fragment -> portal key */
    const PORTAL_KEYS = {
        'hr-portal.html': 'hr',
        'reception-portal.html': 'receptionist',
        'management-portal.html': 'management',
        'training-portal.html': 'training',
        'recruiter-portal.html': 'recruiter',
        'analytics-portal.html': 'analytics',
        'employee-portal.html': 'employee',
        'admin-dashboard.html': 'admin',
        'attendance-dashboard.html': 'attendance'
    };

    let portalKey = null;
    let currentFile = '';
    for (const [file, key] of Object.entries(PORTAL_KEYS)) {
        if (path.includes(file)) {
            portalKey = key;
            currentFile = file;
            break;
        }
    }

    if (!portalKey) {
        return;
    }

    // apiPrefix already defined above - do not re-declare

    function showAdminViewBanner(name, role) {
        // Feature disabled: The user prefers using the native browser back button,
        // which now works because admin-dashboard.html opens portals in the same tab.
    }

    function redirectToRolePortal(role) {
        const url = window.portalUrlForRole ? window.portalUrlForRole(role) : null;
        if (url) {
            window.location.replace(apiPrefix + url);
        } else {
            window.location.replace(apiPrefix + 'user-login.html');
        }
    }

    async function tryAdminPortalAccess() {
        if (sessionStorage.getItem('adminPortalAccess') !== 'true') {
            return false;
        }
        try {
            const res = await fetch(apiPrefix + 'api/admin_portal_access.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ portal_url: currentFile || window.location.pathname })
            });
            const data = await res.json();
            return data.success === true;
        } catch (e) {
            return false;
        }
    }

    async function guard() {
        let data = null;
        try {
            const res = await fetch(apiPrefix + 'api/portal_auth_check.php', { credentials: 'include' });
            data = JSON.parse(await res.text());
        } catch (e) {
            console.warn('Session check failed', e);
        }

        if (data && data.success && data.data && data.data.authenticated) {
            const d = data.data;
            const role = d.portal_role || '';

            if (d.requires_password_change) {
                window.location.replace(apiPrefix + 'user-login.html');
                return;
            }

            const isTeamManager = window.isTeamManagerRole && window.isTeamManagerRole(role);
            const mayViewAsAdmin = (d.is_super || d.is_admin || isTeamManager) && (
                !portalKey
                || (window.portalRoleMayAccessPage && window.portalRoleMayAccessPage(role, portalKey))
                || (role === 'finance' && portalKey === 'attendance')
            );
            if (mayViewAsAdmin || (d.admin_portal_view && portalKey && window.portalRoleMayAccessPage && window.portalRoleMayAccessPage(role, portalKey))) {
                showAdminViewBanner(d.full_name, d.portal_role);
                return;
            }
            // If admin/super is on employee-portal AND came from workfromhome, allow it
            const isWfhSession = localStorage.getItem('companyBranch') === 'workfromhome';
            if ((d.is_admin || d.is_super) && portalKey && !isWfhSession) {
                redirectToRolePortal(role);
                return;
            }

            if (portalKey === 'employee') {
                return;
            }

            const mayAccess = window.portalRoleMayAccessPage
                ? window.portalRoleMayAccessPage(role, portalKey)
                : (role === portalKey);

            if (!mayAccess) {
                redirectToRolePortal(role);
                return;
            }
            return;
        }

        if (await tryAdminPortalAccess()) {
            showAdminViewBanner('Administrator', 'admin');
            return;
        }

        const page = window.location.pathname.split('/').pop() || '';
        const isWfh = localStorage.getItem('companyBranch') === 'workfromhome';
        const loginPage = isWfh ? (apiPrefix + 'workfromhome/index.html') : (apiPrefix + 'index.html');
        window.location.replace(loginPage + '?redirect=' + encodeURIComponent(page));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', guard);
    } else {
        guard();
    }
    
    // Update logout links if WFH
    document.addEventListener('DOMContentLoaded', () => {
        if (localStorage.getItem('companyBranch') === 'workfromhome') {
            document.querySelectorAll('a[href*="logout.php"]').forEach(link => {
                const url = new URL(link.href, window.location.origin);
                url.searchParams.set('wfh', 'true');
                link.href = url.toString();
            });
        }
    });
})();
