<?php

/**

 * Portal role helpers: enum, labels, redirects, sheet-based role suggestion.

 */



function ensure_receptionist_portal_role(mysqli $conn): void {

    ensure_portal_role_enum($conn);

}



function ensure_portal_role_enum(mysqli $conn): void {

    static $done = false;

    if ($done) {

        return;

    }

    $done = true;



    $res = $conn->query("SHOW COLUMNS FROM `users` LIKE 'portal_role'");

    if (!$res || $res->num_rows === 0) {

        return;

    }

    $row = $res->fetch_assoc();

    $type = $row['Type'] ?? '';

    if (stripos($type, 'data_entry') !== false) {

        return;

    }



    $enum = "'super_admin','admin','hr','recruiter','management','training','agent','receptionist','user','team_lead','floor_manager','data_entry','dialer','developer','analytics','attendance'";

    @$conn->query("ALTER TABLE `users` MODIFY `portal_role` ENUM($enum) NOT NULL DEFAULT 'user'");

}



/** @return string[] */

function allowed_portal_roles(): array {

    return [

        'super_admin', 'admin', 'hr', 'recruiter', 'management', 'training', 'receptionist', 'user',

        'team_lead', 'floor_manager', 'data_entry', 'dialer', 'developer',

        'agent', 'analytics', 'attendance',

    ];

}



/** Ordered options for admin create/edit dropdowns. */

function portal_role_options(): array {

    $order = [

        'user', 'data_entry', 'dialer', 'developer', 'team_lead', 'floor_manager',

        'receptionist', 'recruiter', 'hr', 'management', 'training', 'analytics', 'attendance', 'admin', 'super_admin',

    ];

    $out = [];

    foreach ($order as $role) {

        if (in_array($role, allowed_portal_roles(), true)) {

            $out[$role] = portal_role_label($role);

        }

    }

    return $out;

}



function is_valid_portal_role(string $role): bool {

    return in_array($role, allowed_portal_roles(), true);

}



function portal_role_label(string $role): string {

    $labels = [

        'super_admin' => 'Super Admin',

        'admin' => 'Admin',

        'hr' => 'HR',

        'recruiter' => 'Recruiter',

        'management' => 'Management',

        'training' => 'Training',

        'receptionist' => 'Receptionist',

        'user' => 'Employee (General)',

        'team_lead' => 'Team Lead',

        'floor_manager' => 'Floor Manager',

        'data_entry' => 'Data Entry',

        'dialer' => 'Dialer',

        'developer' => 'Developer',

        'agent' => 'Agent (Reception)',

        'analytics' => 'Analytics',

        'attendance' => 'Attendance',

    ];

    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));

}



/**

 * Suggest portal_role from attendance sheet fields (team, department, designation).

 */

function suggest_portal_role_from_sheet(string $department, string $team = '', string $designation = ''): string {

    $d = strtolower(trim($department));

    $t = strtolower(trim($team));

    $des = strtolower(trim($designation));

    $combined = $d . ' ' . $t . ' ' . $des;



    if (str_contains($combined, 'dialer')) {

        return 'dialer';

    }

    if (str_contains($combined, 'developer') || str_contains($combined, 'developers')) {

        return 'developer';

    }

    if (str_contains($combined, 'data entry')) {

        return 'data_entry';

    }

    // Only true front-desk roles — avoid marking all staff as reception (substring "reception" in other fields)
    if (preg_match('/\b(reception(ist)?|front\s*desk|concierge)\b/i', $des)
        || preg_match('/^(reception|front\s*desk)$/i', $d)
        || preg_match('/^(reception|front\s*desk)$/i', $t)) {
        return 'receptionist';
    }

    if ($d === 'hr' || str_contains($combined, 'human resource')) {

        return 'hr';

    }

    if (str_contains($combined, 'recruit')) {

        return 'recruiter';

    }

    if (str_contains($combined, 'training')) {

        return 'training';

    }

    if (str_contains($combined, 'management') || str_contains($des, 'manager') && str_contains($combined, 'gm')) {

        return 'management';

    }

    if (str_contains($des, 'team lead') || ($des === 'lead' && str_contains($t, 'team'))) {

        return 'team_lead';

    }

    if (str_contains($des, 'floor manager')) {

        return 'floor_manager';

    }



    return 'user';

}



/** Roles that may open the employee HRMS portal. */

function employee_portal_roles(): array {

    return ['user', 'team_lead', 'floor_manager', 'dialer', 'developer'];

}



function portal_url_for_role(string $role): ?string {

    if (in_array($role, employee_portal_roles(), true)) {

        return 'employee-portal.html';

    }

    $map = [

        'super_admin' => 'admin-dashboard.html',

        'admin' => 'admin-dashboard.html',

        'hr' => 'hr-portal.html',

        'recruiter' => 'recruiter-portal.html',

        'management' => 'Management-Portal.html',

        'training' => 'training-portal.html',

        'receptionist' => 'reception-portal.html',

        'agent' => 'reception-portal.html',

        'data_entry' => 'reception-portal.html',

        'analytics' => 'analytics-portal.html',

        'attendance' => 'attendance/attendance-dashboard.html',

    ];

    return $map[$role] ?? null;

}



function portal_redirect_for_role(string $role): string {

    return portal_url_for_role($role) ?? 'employee-portal.html';

}



/** Whether a user's role may access a portal page (by canonical portal key). */

function portal_role_may_access(string $user_role, string $portal_key): bool {

    // Super admin has access to ALL portals
    if ($user_role === 'super_admin') {

        return true;

    }

    // Admin has access to everything except recruiter (needs super_admin for that)
    if ($user_role === 'admin') {

        return $portal_key !== 'recruiter';

    }

    $aliases = [

        'agent' => 'receptionist',

        'data_entry' => 'receptionist',

    ];

    $user_role = $aliases[$user_role] ?? $user_role;



    $portal_roles = [

        'hr' => ['hr'],

        'receptionist' => ['receptionist', 'data_entry'],

        'recruiter' => ['recruiter'],

        'management' => ['management'],

        'training' => ['training'],

        'analytics' => ['analytics'],

        'attendance' => ['attendance'],

        'admin' => ['admin'],

        'employee' => employee_portal_roles(),

    ];



    if (!isset($portal_roles[$portal_key])) {

        return false;

    }

    return in_array($user_role, $portal_roles[$portal_key], true);

}



/** True if this user works at the physical reception desk. */

function is_reception_staff(array $user): bool {

    $d = strtolower(trim((string)($user['department'] ?? '')));

    $des = strtolower(trim((string)($user['designation'] ?? '')));

    $t = strtolower(trim((string)($user['team'] ?? '')));

    $blob = $d . ' ' . $des . ' ' . $t;

    return (bool) preg_match('/\b(reception(ist)?|front\s*desk|concierge)\b/i', $blob);

}



/** Map profile fields to an employee HRMS portal role. */

function employee_role_from_profile(array $user): string {

    $suggested = suggest_portal_role_from_sheet(

        (string)($user['department'] ?? ''),

        (string)($user['team'] ?? ''),

        (string)($user['designation'] ?? '')

    );

    if (in_array($suggested, employee_portal_roles(), true)) {

        return $suggested;

    }

    return 'user';

}



/**

 * Correct portal role for login and portal access.

 * Fixes accounts saved as agent/receptionist when they are general staff.

 */

function effective_portal_role(array $user): string {

    $role = trim((string)($user['portal_role'] ?? ''));

    if ($role === '' || !is_valid_portal_role($role)) {

        return 'user';

    }

    if (in_array($role, employee_portal_roles(), true)) {

        return $role;

    }

    if (in_array($role, ['super_admin', 'admin', 'hr', 'recruiter', 'management', 'training', 'analytics', 'attendance'], true)) {

        return $role;

    }

    if (in_array($role, ['agent', 'receptionist'], true)) {

        return is_reception_staff($user) ? 'receptionist' : employee_role_from_profile($user);

    }

    return $role;

}



/** Persist effective role when DB still has agent/receptionist for non-reception staff. */

function sync_user_portal_role(mysqli $conn, array $user): string {

    $effective = effective_portal_role($user);

    $stored = trim((string)($user['portal_role'] ?? ''));

    $id = (int)($user['id'] ?? 0);

    if ($id > 0 && $effective !== $stored && in_array($stored, ['agent', 'receptionist'], true)) {

        $stmt = $conn->prepare('UPDATE users SET portal_role = ? WHERE id = ?');

        $stmt->bind_param('si', $effective, $id);

        $stmt->execute();

    }

    return $effective;

}



/** One-time repair for misassigned agent/receptionist accounts. */

function fix_misassigned_portal_roles(mysqli $conn): void {

    static $done = false;

    if ($done) {

        return;

    }

    $done = true;

    ensure_portal_role_enum($conn);

    $res = $conn->query("SELECT id, portal_role, department, designation, team FROM users WHERE portal_role IN ('agent','receptionist')");

    if (!$res) {

        return;

    }

    while ($row = $res->fetch_assoc()) {

        sync_user_portal_role($conn, $row);

    }

}


