/**
 * Shared portal URL map (case-correct paths).
 */

/** API prefix when page lives under /attendance/ */
window.balitechApiPrefix = function () {
    const p = (window.location.pathname || '').toLowerCase();
    return p.includes('/attendance/') ? '../' : '';
};

window.BALITECH_PORTAL_URLS = {
    super_admin: 'admin-dashboard.html',
    admin: 'admin-dashboard.html',
    hr: 'hr-portal.html',
    recruiter: 'recruiter-portal.html',
    management: 'Management-Portal.html',
    training: 'training-portal.html',
    receptionist: 'reception-portal.html',
    agent: 'reception-portal.html',
    analytics: 'analytics-portal.html',
    attendance: 'attendance/attendance-dashboard.html',
    user: 'employee-portal.html',
    team_lead: 'employee-portal.html',
    floor_manager: 'employee-portal.html',
    data_entry: 'reception-portal.html',
    dialer: 'employee-portal.html',
    developer: 'employee-portal.html'
};

window.EMPLOYEE_PORTAL_ROLES = ['user', 'team_lead', 'floor_manager', 'dialer', 'developer'];

window.portalUrlForRole = function (role) {
    return window.BALITECH_PORTAL_URLS[role] || null;
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
        employee: window.EMPLOYEE_PORTAL_ROLES
    };
    const allowed = map[portalKey];
    if (!allowed) return false;
    return allowed.indexOf(normalized) !== -1;
};
