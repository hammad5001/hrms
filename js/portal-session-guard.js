/**
 * Protect portals: PHP session required. Non-admins only see their own portal.
 */
(function () {
    'use strict';

    const path = (window.location.pathname || '').toLowerCase();

    if (path.includes('login') || path.endsWith('index.html') || path.includes('admin.php')) {
        return;
    }

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

    const apiPrefix = window.balitechApiPrefix ? window.balitechApiPrefix() : (path.includes('/attendance/') ? '../' : '');

    function showAdminViewBanner(name, role) {
        if (document.getElementById('adminPortalBanner')) return;
        const adminHome = apiPrefix + 'admin-dashboard.html';
        const isSuperAdmin = (role === 'super_admin');
        const bar = document.createElement('div');
        bar.id = 'adminPortalBanner';
        const bgColor = isSuperAdmin
            ? 'linear-gradient(90deg,#ca8a04,#facc15)'
            : 'linear-gradient(90deg,#f97316,#ea580c)';
        const textColor = isSuperAdmin ? '#000' : '#fff';
        const roleLabel = isSuperAdmin ? '⚡ Super Admin view' : '🛡 Admin view';
        bar.style.cssText = `position:fixed;top:0;left:0;right:0;z-index:99999;background:${bgColor};color:${textColor};padding:10px 16px;font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:space-between;box-shadow:0 4px 12px rgba(0,0,0,.3)`;
        bar.innerHTML = `
            <span><i class="fas fa-shield-alt"></i> ${roleLabel} — ${name || 'Administrator'}</span>
            <button type="button" id="adminPortalBannerBack" style="background:rgba(0,0,0,.15);border:none;color:${textColor};padding:6px 14px;border-radius:8px;cursor:pointer;font-weight:600">← Back to Admin</button>
        `;
        document.body.prepend(bar);
        document.getElementById('adminPortalBannerBack')?.addEventListener('click', () => {
            window.location.href = adminHome;
        });
        document.body.style.paddingTop = '44px';
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

            if (d.is_admin || d.is_super || d.admin_portal_view) {
                showAdminViewBanner(d.full_name, d.portal_role);
                return;
            }

            const mayAccess = window.portalRoleMayAccessPage
                ? window.portalRoleMayAccessPage(role, portalKey)
                : (role === portalKey || (portalKey === 'employee' && window.EMPLOYEE_PORTAL_ROLES && window.EMPLOYEE_PORTAL_ROLES.indexOf(role) !== -1));

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
        window.location.replace(apiPrefix + 'user-login.html?redirect=' + encodeURIComponent(page));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', guard);
    } else {
        guard();
    }
})();
