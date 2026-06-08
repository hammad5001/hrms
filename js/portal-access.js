/**
 * Shared portal URL map (case-correct paths).
 */

/** API prefix when page lives under /attendance/ */
window.balitechApiPrefix = function () {
    const p = (window.location.pathname || '').toLowerCase();
    return p.includes('/attendance/') ? '../' : '';
};

/** Work portals only — recruiters, HR, admin, etc. (not Employee Self Service). */
window.WORK_PORTAL_URLS = {
    super_admin: 'admin-dashboard.html',
    admin: 'admin-dashboard.html',
    hr: 'hr-portal.html',
    recruiter: 'recruiter-portal.html',
    management: 'Management-Portal.html',
    training: 'training-portal.html',
    receptionist: 'reception-portal.html',
    agent: 'reception-portal.html',
    data_entry: 'reception-portal.html',
    analytics: 'analytics-portal.html',
    attendance: 'attendance/attendance-dashboard.html',
    team_lead: 'admin-dashboard.html',
    floor_manager: 'admin-dashboard.html',
    dialer: 'employee-portal.html',
    developer: 'employee-portal.html'
};

window.TEAM_MANAGER_ROLES = ['team_lead', 'floor_manager'];

window.isTeamManagerRole = function (role) {
    return window.TEAM_MANAGER_ROLES.indexOf(role) !== -1;
};

window.BALITECH_PORTAL_URLS = Object.assign(
    { user: 'employee-portal.html' },
    window.WORK_PORTAL_URLS
);

window.EMPLOYEE_PORTAL_ROLES = ['user', 'team_lead', 'floor_manager', 'dialer', 'developer'];

window.EMPLOYEE_SELF_SERVICE_URL = 'employee-portal.html';

/** Pure employee accounts (`user` role) — Self Service only, no work HRMS portal. */
window.canAccessWorkPortal = function (role) {
    return !!role && role !== 'user';
};

window.workPortalUrlForRole = function (role) {
    if (!window.canAccessWorkPortal(role)) return null;
    if (window.WORK_PORTAL_URLS[role]) return window.WORK_PORTAL_URLS[role];
    return null;
};

window.hasSeparateWorkPortal = function (role) {
    const url = window.workPortalUrlForRole(role);
    return !!url && url !== window.EMPLOYEE_SELF_SERVICE_URL;
};

window.isSuperAdminRole = function (role) {
    return role === 'super_admin';
};

window.loginDestination = function (user, mode, apiData) {
    if (!user) return null;
    if (mode === 'ess') {
        return apiData.ess_redirect || window.EMPLOYEE_SELF_SERVICE_URL;
    }
    if (window.isSuperAdminRole(user.portal_role)) {
        return apiData.work_redirect || window.WORK_PORTAL_URLS.super_admin || 'admin-dashboard.html';
    }
    if (apiData.can_access_work_portal === false || !window.canAccessWorkPortal(user.portal_role)) {
        return null;
    }
    const work = apiData.work_redirect || window.workPortalUrlForRole(user.portal_role);
    return work || null;
};

window.portalUrlForRole = function (role) {
    return window.workPortalUrlForRole(role) || window.BALITECH_PORTAL_URLS[role] || null;
};

window.storeLoginPortalChoice = function (user, mode, dest) {
    try {
        sessionStorage.setItem('balitech_login_mode', mode);
        sessionStorage.setItem('balitech_last_portal', dest || '');
        const work = window.workPortalUrlForRole(user?.portal_role);
        if (work) sessionStorage.setItem('balitech_work_portal', work);
    } catch (e) { /* ignore */ }
};

window.portalRoleMayAccessPage = function (userRole, portalKey) {
    if (!userRole) return false;
    // Super admin gets access to everything
    if (userRole === 'super_admin') return true;
    // Admin gets access to everything except recruiter
    if (userRole === 'admin') return portalKey !== 'recruiter';
    const normalized = (userRole === 'agent' || userRole === 'data_entry') ? 'receptionist' : userRole;
    const map = {
        hr: ['hr'],
        receptionist: ['receptionist', 'data_entry'],
        recruiter: ['recruiter'],
        management: ['management'],
        training: ['training'],
        analytics: ['analytics'],
        attendance: ['attendance'],
        admin: ['admin'],
    };
    if (portalKey === 'employee') {
        return !!userRole;
    }
    if (window.isTeamManagerRole && window.isTeamManagerRole(userRole)) {
        return ['admin', 'attendance', 'employee'].indexOf(portalKey) !== -1;
    }
    const allowed = map[portalKey];
    if (!allowed) return false;
    return allowed.indexOf(normalized) !== -1;
};
