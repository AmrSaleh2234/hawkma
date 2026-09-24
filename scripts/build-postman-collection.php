<?php

/**
 * Builds postman/GCMC-API.postman_collection.json and the two environment
 * files (plan §14). Run: php scripts/build-postman-collection.php
 *
 * The structure is declarative: every request lists its method, path,
 * body/formdata, query params, description (permission + fields + errors)
 * and the extra test-script lines (mostly the variable saves).
 */

// ---------------------------------------------------------------------
// Builders
// ---------------------------------------------------------------------

/** The two tests every JSON request carries (plan 14.4.9). */
function baseTests(): array
{
    return [
        'pm.test("status is 2xx", () => pm.expect(pm.response.code).to.be.within(200, 299));',
        'const j = pm.response.json();',
        'pm.test("envelope", () => {',
        '  pm.expect(j).to.have.property("success", true);',
        '  pm.expect(j).to.have.property("data");',
        '});',
    ];
}

/** Tests for file-download responses (no JSON envelope). */
function downloadTests(): array
{
    return [
        'pm.test("status is 200", () => pm.response.to.have.status(200));',
        'pm.test("attachment", () => {',
        '  const cd = pm.response.headers.get("Content-Disposition") || "";',
        '  pm.expect(cd).to.include("attachment");',
        '});',
    ];
}

function bearerAuth(string $tokenVar): array
{
    return ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{'.$tokenVar.'}}', 'type' => 'string']]];
}

/**
 * @param  array<string, mixed>  $opts  keys: auth, body (array => raw json),
 *                                      formdata (array of [key, value] or
 *                                      [key, null, 'file' => src]), query
 *                                      ([key, value, description, disabled]),
 *                                      tests (extra exec lines), prerequest
 *                                      (exec lines), manual (bool), description
 */
function req(string $name, string $method, string $path, array $opts = []): array
{
    $segments = explode('/', $path);

    $url = [
        'raw' => '{{base_url}}/'.$path,
        'host' => ['{{base_url}}'],
        'path' => $segments,
    ];

    if (! empty($opts['query'])) {
        $url['query'] = array_map(
            fn (array $q) => array_filter([
                'key' => $q[0],
                'value' => (string) ($q[1] ?? ''),
                'description' => $q[2] ?? null,
                'disabled' => $q[3] ?? false,
            ], fn ($v) => $v !== null && $v !== false),
            $opts['query'],
        );
        $qs = implode('&', array_map(
            fn (array $q) => $q[0].'='.($q[1] ?? ''),
            array_filter($opts['query'], fn (array $q) => ! ($q[3] ?? false)),
        ));
        if ($qs !== '') {
            $url['raw'] .= '?'.$qs;
        }
    }

    $request = array_filter([
        'method' => $method,
        'header' => [],
        'url' => $url,
        'auth' => $opts['auth'] ?? null,
        'description' => $opts['description'] ?? null,
    ], fn ($v) => $v !== null);

    if (isset($opts['body'])) {
        $request['body'] = [
            'mode' => 'raw',
            'raw' => json_encode($opts['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    if (isset($opts['formdata'])) {
        $request['body'] = [
            'mode' => 'formdata',
            'formdata' => array_map(function (array $f) {
                if (($f[2] ?? null) === 'file') {
                    return ['key' => $f[0], 'type' => 'file', 'src' => $f[1]];
                }

                return ['key' => $f[0], 'value' => (string) $f[1], 'type' => 'text'];
            }, $opts['formdata']),
        ];
    }

    $events = [];

    $testLines = ($opts['raw_tests'] ?? baseTests());
    foreach (($opts['tests'] ?? []) as $line) {
        $testLines[] = $line;
    }
    $events[] = [
        'listen' => 'test',
        'script' => ['type' => 'text/javascript', 'exec' => $testLines],
    ];

    $prerequest = $opts['prerequest'] ?? [];
    if ($opts['manual'] ?? false) {
        $prerequest = array_merge([
            '// Needs a real token/signature or a past booking — run by hand.',
            'if (pm.environment.get("ci") === "true") { pm.execution.skipRequest(); }',
        ], $prerequest);
    }
    if ($prerequest !== []) {
        $events[] = [
            'listen' => 'prerequest',
            'script' => ['type' => 'text/javascript', 'exec' => $prerequest],
        ];
    }

    return ['name' => $name, 'request' => $request, 'event' => $events, 'response' => []];
}

function folder(string $name, array $items, ?string $authVar = null, array $extra = []): array
{
    return array_filter([
        'name' => $name,
        'item' => $items,
        'auth' => $authVar === null ? null : ($authVar === 'none' ? ['type' => 'noauth'] : bearerAuth($authVar)),
    ] + $extra, fn ($v) => $v !== null);
}

/** A Markdown description: purpose, permission, fields table, errors. */
function desc(string $purpose, ?string $permission = null, array $fields = [], array $errors = []): string
{
    $out = $purpose;
    if ($permission !== null) {
        $out .= "\n\n**Permission:** `{$permission}`";
    }
    if ($fields !== []) {
        $out .= "\n\n| Field | Rules |\n|---|---|";
        foreach ($fields as $field => $rules) {
            $out .= "\n| {$field} | {$rules} |";
        }
    }
    if ($errors !== []) {
        $out .= "\n\n**Errors:** ".implode(', ', $errors);
    }

    return $out;
}

$set = fn (string $var, string $expr) => "pm.environment.set(\"{$var}\", {$expr});";

// ---------------------------------------------------------------------
// 00 Public
// ---------------------------------------------------------------------

$public = folder('00 Public', [
    req('Meta (PUB-08)', 'GET', 'public/meta', [
        'description' => desc('Enum values and labels for the frontend (booking/report/payment statuses, days of week), the booking config and the payment gateway driver + publishable key.'),
    ]),
    req('List packages (PUB-01)', 'GET', 'public/packages', [
        'description' => desc('The active subscription packages, ordered by sort_order. Prices are halalas; `price_formatted` is the display value.'),
        'tests' => [
            'const iron = j.data.find(p => p.slug === "iron");',
            'const silver = j.data.find(p => p.slug === "silver");',
            $set('package_id', 'iron.id'), $set('package_slug', 'iron.slug'),
            $set('package_id_silver', 'silver.id'),
        ],
    ]),
    req('Show package (PUB-02)', 'GET', 'public/packages/{{package_slug}}', [
        'description' => desc('One active package by slug.', errors: ['404 `NOT_FOUND`']),
    ]),
    req('List consultants (PUB-03)', 'GET', 'public/consultants', [
        'description' => desc('Active consultants who have availability. 12 per page by default.'),
        'query' => [
            ['search', '', 'Search name/title/specialization', true],
            ['specialization', '', 'Exact specialization filter', true],
            ['page', '1', 'Page number', true],
        ],
        'tests' => [
            $set('consultant_id', 'j.data[0].id'),
        ],
    ]),
    req('Show consultant (PUB-04)', 'GET', 'public/consultants/{{consultant_id}}', [
        'description' => desc('A consultant profile with his availability.', errors: ['404 `NOT_FOUND`']),
    ]),
    req('Available dates (PUB-05)', 'GET', 'public/consultants/{{consultant_id}}/available-dates', [
        'description' => desc('The dates of a month on which the consultant still has free slots.', errors: ['422 `VALIDATION_ERROR`', '404 `NOT_FOUND`']),
        'prerequest' => [
            'const d = new Date(); d.setMonth(d.getMonth() + 1);',
            'pm.environment.set("slot_month", d.toISOString().slice(0, 7));',
        ],
        'query' => [
            ['month', '{{slot_month}}', 'YYYY-MM (defaults to the current month)'],
        ],
        'tests' => [
            'pm.test("has dates", () => pm.expect(j.data.dates.length).to.be.above(0));',
            $set('slot_date', 'j.data.dates[0]'),
        ],
    ]),
    req('Slots (PUB-06)', 'GET', 'public/consultants/{{consultant_id}}/slots', [
        'description' => desc('The free slots of one day. Booked slots are hidden.', errors: ['422 `VALIDATION_ERROR`', '404 `NOT_FOUND`']),
        'query' => [
            ['date', '{{slot_date}}', 'YYYY-MM-DD, required'],
        ],
        'tests' => [
            'pm.test("has slots", () => pm.expect(j.data.slots.length).to.be.above(1));',
            $set('slot_time', 'j.data.slots[0].time'),
            $set('slot_time_2', 'j.data.slots[1].time'),
        ],
    ]),
    req('Signed report download (PUB-07)', 'GET', 'public/reports/{{demo_report_id}}/signed-download', [
        'description' => desc('Downloads a report through the signed URL from the report-ready email (valid 7 days, no login). Skipped in the CI run: the signature cannot be computed in Postman — copy `signed_url` from the email.', errors: ['403 `FORBIDDEN` (missing/tampered/expired signature)', '404 `NOT_FOUND`']),
        'query' => [
            ['expires', 'PASTE-FROM-EMAIL', 'unix timestamp'],
            ['signature', 'PASTE-FROM-EMAIL', 'HMAC signature'],
        ],
        'raw_tests' => downloadTests(),
        'manual' => true,
    ]),
], 'none');

// ---------------------------------------------------------------------
// 01 Admin › Auth
// ---------------------------------------------------------------------

$adminAuth = folder('01 Admin › Auth', [
    req('Login as admin (ADM-AUTH-01)', 'POST', 'admin/auth/login', [
        'description' => desc('Dashboard A login. Returns a Sanctum token.', fields: ['email' => 'required email', 'password' => 'required', 'device_name' => 'optional'], errors: ['422 `INVALID_CREDENTIALS`', '403 `ACCOUNT_DISABLED`']),
        'body' => ['email' => '{{admin_email}}', 'password' => '{{admin_password}}', 'device_name' => 'postman'],
        'tests' => [$set('admin_token', 'j.data.token')],
    ]),
    req('Login as consultant', 'POST', 'admin/auth/login', [
        'description' => desc('A consultant logs in through the same endpoint; his `type` is `consultant` and he only sees his own data.'),
        'body' => ['email' => '{{consultant_email}}', 'password' => '{{consultant_password}}', 'device_name' => 'postman'],
        'tests' => [$set('consultant_token', 'j.data.token')],
    ]),
    req('Me (ADM-AUTH-03)', 'GET', 'admin/auth/me', [
        'auth' => bearerAuth('admin_token'),
        'description' => desc('The authenticated user with roles and the full permissions list — the frontend hides menu items by these.'),
    ]),
    req('Forgot password (ADM-AUTH-04)', 'POST', 'admin/auth/forgot-password', [
        'description' => desc('Always 200 (does not leak whether the email exists). Sends the reset email.', fields: ['email' => 'required email']),
        'body' => ['email' => '{{admin_email}}'],
    ]),
    req('Reset password (ADM-AUTH-05)', 'POST', 'admin/auth/reset-password', [
        'description' => desc('Resets the password with the token from the email. All old tokens are revoked.', fields: ['token' => 'required', 'email' => 'required email', 'password' => 'required, confirmed, min 8'], errors: ['422 `INVALID_TOKEN`']),
        'body' => ['token' => 'PASTE-TOKEN-FROM-EMAIL', 'email' => '{{admin_email}}', 'password' => 'Password@123', 'password_confirmation' => 'Password@123'],
        'manual' => true,
    ]),
], 'none');

// ---------------------------------------------------------------------
// 02 Admin › Profile
// ---------------------------------------------------------------------

$adminProfile = folder('02 Admin › Profile', [
    req('Show profile (ADM-PRF-01)', 'GET', 'admin/profile', [
        'description' => desc('The authenticated user profile.'),
    ]),
    req('Update profile (ADM-PRF-02)', 'PUT', 'admin/profile', [
        'description' => desc('Updates the profile.', fields: ['name' => 'required, max 150', 'email' => 'required, email, unique', 'phone' => 'optional', 'title' => 'optional', 'specialization' => 'optional', 'bio' => 'optional'], errors: ['422 `VALIDATION_ERROR`']),
        'body' => ['name' => 'Super Admin', 'email' => '{{admin_email}}', 'phone' => '0500000000', 'title' => 'مدير النظام', 'specialization' => null, 'bio' => null],
    ]),
    req('Upload avatar (ADM-PRF-03)', 'POST', 'admin/profile/avatar', [
        'description' => desc('Uploads the avatar (jpg/png/webp ≤ 2 MB).', errors: ['422 `VALIDATION_ERROR`']),
        'formdata' => [['avatar', 'fixtures/avatar.jpg', 'file']],
    ]),
    req('Delete avatar (ADM-PRF-04)', 'DELETE', 'admin/profile/avatar', [
        'description' => desc('Removes the avatar.'),
    ]),
    req('Update password (ADM-PRF-05)', 'PUT', 'admin/profile/password', [
        'description' => desc('Changes the password.', fields: ['current_password' => 'required', 'password' => 'required, confirmed, min 8'], errors: ['422 `VALIDATION_ERROR`', '422 `INVALID_CREDENTIALS`']),
        'body' => ['current_password' => '{{admin_password}}', 'password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123'],
        'tests' => [
            '// Restore the seeded password so re-runs and later logins keep working.',
            'pm.sendRequest({ url: pm.environment.get("base_url") + "/admin/profile/password", method: "PUT", header: { "Content-Type": "application/json", "Accept": "application/json", "Authorization": "Bearer " + pm.environment.get("admin_token") }, body: { mode: "raw", raw: JSON.stringify({ current_password: "NewPassword@123", password: pm.environment.get("admin_password"), password_confirmation: pm.environment.get("admin_password") }) } }, () => {});',
        ],
    ]),
], 'admin_token');

// ---------------------------------------------------------------------
// 03 Admin › Roles & Permissions
// ---------------------------------------------------------------------

$roles = folder('03 Admin › Roles & Permissions', [
    req('List permissions (ACL-01)', 'GET', 'admin/permissions', [
        'description' => desc('All permissions grouped by group name.', 'view-roles|view-permissions'),
    ]),
    req('List roles (ACL-02)', 'GET', 'admin/roles', [
        'description' => desc('All roles with permissions_count and users_count.', 'view-roles'),
    ]),
    req('Create role (ACL-03)', 'POST', 'admin/roles', [
        'description' => desc('Creates a role. `admin` is protected and cannot be created/edited/deleted.', 'create-roles', ['name' => 'required, lowercase slug, unique', 'permissions' => 'required array of permission names'], ['422 `VALIDATION_ERROR`', '403 `FORBIDDEN`']),
        'body' => ['name' => 'supervisor-{{$timestamp}}', 'permissions' => ['view-bookings', 'view-reports']],
        'tests' => [$set('role_id', 'j.data.id')],
    ]),
    req('Show role (ACL-04)', 'GET', 'admin/roles/{{role_id}}', [
        'description' => desc('One role with its permissions.', 'view-roles', errors: ['404 `NOT_FOUND`']),
    ]),
    req('Update role (ACL-05)', 'PUT', 'admin/roles/{{role_id}}', [
        'description' => desc('Renames a role and/or syncs its permissions.', 'update-roles', ['name' => 'optional slug', 'permissions' => 'optional array'], ['422 `VALIDATION_ERROR`', '422 `ROLE_PROTECTED`']),
        'body' => ['permissions' => ['view-bookings', 'view-reports', 'view-clients']],
    ]),
    req('Role users (ACL-07)', 'GET', 'admin/roles/{{role_id}}/users', [
        'description' => desc('The users who have this role.', 'view-roles'),
    ]),
    req('Delete role (ACL-06)', 'DELETE', 'admin/roles/{{role_id}}', [
        'description' => desc('Deletes a role. The protected `admin` role and roles still assigned to users cannot be deleted.', 'delete-roles', errors: ['422 `ROLE_PROTECTED`', '422 `ROLE_IN_USE`']),
    ]),
], 'admin_token');

// ---------------------------------------------------------------------
// 04 Admin › Users
// ---------------------------------------------------------------------

$users = folder('04 Admin › Users', [
    req('List users (USR-01)', 'GET', 'admin/users', [
        'description' => desc('Staff users (admins and consultants).', 'view-users'),
        'query' => [
            ['search', '', 'name, email or phone', true],
            ['type', '', 'admin | consultant', true],
            ['role', '', 'role name', true],
            ['is_active', '', '1 | 0', true],
            ['sort', '', 'name | created_at | last_login_at (- for desc)', true],
        ],
    ]),
    req('Create user (USR-02)', 'POST', 'admin/users', [
        'description' => desc('Creates a staff user. `type` defaults to `admin`; sending `consultant` also adds the consultant role. Without a password, a set-password email is sent.', 'create-users', ['name' => 'required, max 150', 'email' => 'required, email, unique', 'password' => 'optional, confirmed, min 8', 'type' => 'admin | consultant', 'roles' => 'required array of role names', 'is_active' => 'optional boolean', 'avatar' => 'optional image ≤ 2 MB'], ['422 `VALIDATION_ERROR`']),
        'body' => ['name' => 'موظف تجريبي', 'email' => 'staff+{{$timestamp}}@gcmc.sa', 'password' => 'Password@123', 'password_confirmation' => 'Password@123', 'type' => 'admin', 'roles' => ['admin'], 'is_active' => true],
        'tests' => [$set('user_id', 'j.data.id')],
    ]),
    req('Show user (USR-03)', 'GET', 'admin/users/{{user_id}}', [
        'description' => desc('One user with roles and permissions.', 'view-users', errors: ['404 `NOT_FOUND`']),
    ]),
    req('Update user (USR-04)', 'PUT', 'admin/users/{{user_id}}', [
        'description' => desc('Updates a user.', 'update-users', errors: ['422 `VALIDATION_ERROR`']),
        'body' => ['name' => 'موظف تجريبي (محدّث)', 'phone' => '0551234567', 'title' => 'موظف استقبال'],
    ]),
    req('Upload user avatar (USR-08)', 'POST', 'admin/users/{{user_id}}/avatar', [
        'description' => desc('Uploads the avatar of a user.', 'update-users', errors: ['422 `VALIDATION_ERROR`']),
        'formdata' => [['avatar', 'fixtures/avatar.jpg', 'file']],
    ]),
    req('Sync user roles (USR-06)', 'PUT', 'admin/users/{{user_id}}/roles', [
        'description' => desc('Replaces the roles of a user.', 'assign-roles', ['roles' => 'required array of role names'], ['422 `VALIDATION_ERROR`']),
        'body' => ['roles' => ['admin']],
    ]),
    req('Update user status (USR-07)', 'PATCH', 'admin/users/{{user_id}}/status', [
        'description' => desc('Activates/deactivates a user. Deactivation revokes his tokens. The last active admin cannot be deactivated.', 'update-users', ['is_active' => 'required boolean'], ['422 `LAST_ADMIN`']),
        'body' => ['is_active' => true],
    ]),
    req('Delete user (USR-05)', 'DELETE', 'admin/users/{{user_id}}', [
        'description' => desc('Deletes a user. You cannot delete yourself or the last active admin.', 'delete-users', errors: ['422 `LAST_ADMIN`', '422 `CANNOT_DELETE_SELF`']),
    ]),
], 'admin_token');

// ---------------------------------------------------------------------
// 05 Admin › Consultants
// ---------------------------------------------------------------------

$availabilityBody = [
    'days' => [
        ['day_of_week' => 0, 'ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
        ['day_of_week' => 1, 'ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
        ['day_of_week' => 2, 'ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
        ['day_of_week' => 3, 'ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
        ['day_of_week' => 4, 'ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
    ],
];

$timeOffPrerequest = [
    'const d = new Date(Date.now() + 120 * 24 * 3600 * 1000);',
    'pm.environment.set("time_off_date", d.toISOString().slice(0, 10));',
];

$consultants = folder('05 Admin › Consultants', [
    req('List consultants (CON-01)', 'GET', 'admin/consultants', [
        'description' => desc('All consultants. A consultant calling this only sees himself.', 'view-consultants'),
        'query' => [
            ['search', '', 'name, email, phone', true],
            ['is_active', '', '1 | 0', true],
            ['specialization', '', 'exact match', true],
        ],
    ]),
    req('Create consultant (CON-02)', 'POST', 'admin/consultants', [
        'description' => desc('Creates a consultant; the role `consultant` is assigned automatically. Without a password, a set-password email is sent.', 'create-consultants', ['name' => 'required, max 150', 'email' => 'required, email, unique', 'phone' => 'optional', 'photo' => 'optional image ≤ 2 MB', 'title' => 'optional', 'specialization' => 'optional', 'bio' => 'optional', 'password' => 'optional, confirmed', 'availability' => 'optional {days: [{day_of_week, ranges: [{start_time, end_time}]}]}'], ['422 `VALIDATION_ERROR`', '403 `FORBIDDEN`']),
        'formdata' => [
            ['name', 'أ. خالد المطيري'],
            ['email', 'khaled+{{$timestamp}}@gcmc.sa'],
            ['phone', '0551234567'],
            ['title', 'مستشار حوكمة'],
            ['specialization', 'الحوكمة المؤسسية'],
            ['bio', 'خبرة 10 سنوات في الحوكمة'],
            ['password', 'Password@123'],
            ['password_confirmation', 'Password@123'],
            ['is_active', '1'],
            ['photo', 'fixtures/avatar.jpg', 'file'],
        ],
        'tests' => [
            'pm.test("role consultant", () => pm.expect(j.data.roles).to.include("consultant"));',
            $set('consultant_id', 'j.data.id'),
        ],
    ]),
    req('Show consultant (CON-03)', 'GET', 'admin/consultants/{{consultant_id}}', [
        'description' => desc('A consultant with his availability. A consultant can only view himself.', 'view-consultants', errors: ['403 `FORBIDDEN`', '404 `NOT_FOUND`']),
    ]),
    req('Update consultant (CON-04)', 'PUT', 'admin/consultants/{{consultant_id}}', [
        'description' => desc('Updates a consultant.', 'update-consultants', errors: ['422 `VALIDATION_ERROR`']),
        'body' => ['title' => 'مستشار حوكمة أول', 'specialization' => 'الحوكمة المؤسسية', 'bio' => 'خبرة 12 سنة'],
    ]),
    req('Upload consultant photo (CON-07)', 'POST', 'admin/consultants/{{consultant_id}}/photo', [
        'description' => desc('Uploads the consultant photo shown on the public pages.', 'update-consultants', errors: ['422 `VALIDATION_ERROR`']),
        'formdata' => [['photo', 'fixtures/avatar.jpg', 'file']],
    ]),
    req('Update consultant status (CON-06)', 'PATCH', 'admin/consultants/{{consultant_id}}/status', [
        'description' => desc('Activates/deactivates a consultant. Inactive consultants disappear from the public list and cannot be booked.', 'update-consultants', ['is_active' => 'required boolean'], []),
        'body' => ['is_active' => true],
    ]),
    req('Show availability (CON-08)', 'GET', 'admin/consultants/{{consultant_id}}/availability', [
        'description' => desc('The weekly availability of the consultant.', 'view-availability'),
    ]),
    req('Replace availability (CON-09)', 'PUT', 'admin/consultants/{{consultant_id}}/availability', [
        'description' => desc('Replaces the whole weekly schedule. Days not sent are removed.', 'manage-availability', ['days' => 'required array, max 7', 'days.*.day_of_week' => '0 (Sunday) – 6 (Saturday), distinct', 'days.*.ranges' => 'present array', 'days.*.ranges.*.start_time / end_time' => 'H:i'], ['422 `VALIDATION_ERROR`']),
        'body' => $availabilityBody,
    ]),
    req('List time-offs (CON-10)', 'GET', 'admin/consultants/{{consultant_id}}/time-offs', [
        'description' => desc('The days (or parts of days) the consultant is off.', 'view-availability'),
    ]),
    req('Create time-off (CON-11)', 'POST', 'admin/consultants/{{consultant_id}}/time-offs', [
        'description' => desc('A day off; send start_time+end_time for a partial day.', 'manage-availability', ['date' => 'required Y-m-d, today or later', 'start_time / end_time' => 'optional pair H:i', 'reason' => 'optional'], ['422 `VALIDATION_ERROR`']),
        'prerequest' => $timeOffPrerequest,
        'body' => ['date' => '{{time_off_date}}', 'reason' => 'إجازة سنوية'],
        'tests' => [$set('time_off_id', 'j.data.id')],
    ]),
    req('Delete time-off (CON-12)', 'DELETE', 'admin/consultants/{{consultant_id}}/time-offs/{{time_off_id}}', [
        'description' => desc('Removes a time-off.', 'manage-availability', errors: ['404 `NOT_FOUND`']),
    ]),
    req('Consultant slots (CON-13)', 'GET', 'admin/consultants/{{consultant_id}}/slots', [
        'description' => desc('The same slot engine as PUB-06, for the dashboard.', 'view-availability', errors: ['422 `VALIDATION_ERROR`']),
        'query' => [['date', '{{slot_date}}', 'YYYY-MM-DD, required']],
    ]),
    req('Consultant clients (CON-14)', 'GET', 'admin/consultants/{{consultant_id}}/clients', [
        'description' => desc('The distinct clients who booked this consultant, with bookings_count and last_booking_at.', 'view-clients'),
        'query' => [['search', '', 'name/email/phone/company', true]],
    ]),
    req('Consultant bookings (CON-15)', 'GET', 'admin/consultants/{{consultant_id}}/bookings', [
        'description' => desc('The bookings of this consultant. The "Pending bookings" tab is `?status=pending`.', 'view-bookings'),
        'query' => [['status', '', 'pending | completed | cancelled', true]],
    ]),
    req('Consultant pending reports (CON-16)', 'GET', 'admin/consultants/{{consultant_id}}/pending-reports', [
        'description' => desc('Completed bookings still awaiting a report.', 'view-reports'),
    ]),
    req('Consultant reports (CON-17)', 'GET', 'admin/consultants/{{consultant_id}}/reports', [
        'description' => desc('The reports uploaded for this consultant.', 'view-reports'),
    ]),
    req('Consultant stats (CON-18)', 'GET', 'admin/consultants/{{consultant_id}}/stats', [
        'description' => desc('The counters of the consultant profile page.', 'view-consultants'),
    ]),
    req('Delete consultant (CON-05)', 'DELETE', 'admin/consultants/{{consultant_id}}', [
        'description' => desc('Deletes a consultant (kept last in the folder: the booking wizard re-reads the public list).', 'delete-consultants', errors: ['422 `CONSULTANT_HAS_FUTURE_BOOKINGS`']),
    ]),
], 'admin_token');

// ---------------------------------------------------------------------
// 06 Admin › My (consultant)
// ---------------------------------------------------------------------

$my = folder('06 Admin › My (consultant)', [
    req('My availability (MY-01)', 'GET', 'admin/my/availability', [
        'description' => desc('The availability of the logged-in consultant.', 'view-availability'),
    ]),
    req('Replace my availability (MY-02)', 'PUT', 'admin/my/availability', [
        'description' => desc('A consultant manages his own schedule.', 'manage-availability'),
        'body' => $availabilityBody,
    ]),
    req('My time-offs (MY-03)', 'GET', 'admin/my/time-offs', [
        'description' => desc('The time-offs of the logged-in consultant.', 'view-availability'),
    ]),
    req('Create my time-off (MY-04)', 'POST', 'admin/my/time-offs', [
        'description' => desc('Adds a day off.', 'manage-availability', ['date' => 'required Y-m-d, today or later'], ['422 `VALIDATION_ERROR`']),
        'prerequest' => $timeOffPrerequest,
        'body' => ['date' => '{{time_off_date}}', 'reason' => 'موعد شخصي'],
        'tests' => [$set('time_off_id', 'j.data.id')],
    ]),
    req('Delete my time-off (MY-05)', 'DELETE', 'admin/my/time-offs/{{time_off_id}}', [
        'description' => desc('Removes one of my time-offs.', 'manage-availability', errors: ['404 `NOT_FOUND`']),
    ]),
    req('My slots (MY-06)', 'GET', 'admin/my/slots', [
        'description' => desc('My free slots for a date.', 'view-availability'),
        'query' => [['date', '{{slot_date}}', 'YYYY-MM-DD, required']],
    ]),
], 'consultant_token');

// ---------------------------------------------------------------------
// 07 Admin › Packages
// ---------------------------------------------------------------------

$packages = folder('07 Admin › Packages', [
    req('List packages (PKG-01)', 'GET', 'admin/packages', [
        'description' => desc('All packages including inactive ones.', 'view-packages'),
        'query' => [['search', '', 'name', true], ['is_active', '', '1 | 0', true]],
    ]),
    req('Create package (PKG-02)', 'POST', 'admin/packages', [
        'description' => desc('Creates a subscription package. Price in halalas.', 'create-packages', ['slug' => 'required alpha_dash unique', 'name_ar / name_en' => 'required, max 100', 'price' => 'required integer halalas', 'billing_period_days' => 'optional, default 30', 'consultations_limit' => 'nullable = unlimited', 'documents_limit' => 'nullable = unlimited', 'is_featured / is_active / sort_order' => 'optional'], ['422 `VALIDATION_ERROR`']),
        'body' => ['slug' => 'test-{{$timestamp}}', 'name_ar' => 'باقة تجريبية', 'name_en' => 'Test Package', 'description_ar' => 'باقة للاختبار', 'description_en' => 'A test package', 'features' => [['ar' => 'استشارة', 'en' => 'One consultation']], 'price' => 100000, 'billing_period_days' => 30, 'consultations_limit' => 3, 'documents_limit' => 3, 'is_featured' => false, 'is_active' => true, 'sort_order' => 9],
        'tests' => [$set('package_id_new', 'j.data.id')],
    ]),
    req('Show package (PKG-03)', 'GET', 'admin/packages/{{package_id_new}}', [
        'description' => desc('One package.', 'view-packages', errors: ['404 `NOT_FOUND`']),
    ]),
    req('Update package (PKG-04)', 'PUT', 'admin/packages/{{package_id_new}}', [
        'description' => desc('Updates a package.', 'update-packages', errors: ['422 `VALIDATION_ERROR`']),
        'body' => ['price' => 120000, 'name_ar' => 'باقة تجريبية محدثة'],
    ]),
    req('Update package status (PKG-06)', 'PATCH', 'admin/packages/{{package_id_new}}/status', [
        'description' => desc('Activates/deactivates a package. Inactive packages disappear from the public list.', 'update-packages', ['is_active' => 'required boolean'], []),
        'body' => ['is_active' => false],
    ]),
    req('Delete package (PKG-05)', 'DELETE', 'admin/packages/{{package_id_new}}', [
        'description' => desc('Soft-deletes a package.', 'delete-packages', errors: ['422 `PACKAGE_IN_USE`']),
    ]),
], 'admin_token');

// ---------------------------------------------------------------------
// 08 Client › Auth
// ---------------------------------------------------------------------

$clientAuth = folder('08 Client › Auth', [
    req('Register (CLI-AUTH-01)', 'POST', 'client/auth/register', [
        'description' => desc('Dashboard B registration. Returns a token; sends the welcome email.', fields: ['name' => 'required, max 150', 'email' => 'required, email, unique among clients', 'phone' => 'required, 05XXXXXXXX', 'company_name' => 'required', 'password' => 'required, confirmed, min 8'], errors: ['422 `VALIDATION_ERROR`']),
        'prerequest' => ['pm.environment.set("new_client_email", "client+" + Date.now() + "@example.com");'],
        'body' => ['name' => 'عميل تجريبي', 'email' => '{{new_client_email}}', 'phone' => '0500000099', 'company_name' => 'شركة الاختبار', 'password' => 'Password@123', 'password_confirmation' => 'Password@123', 'device_name' => 'postman'],
        'tests' => [
            $set('client_token', 'j.data.token'),
            $set('new_client_id', 'j.data.user.id'),
        ],
    ]),
    req('Login (CLI-AUTH-02)', 'POST', 'client/auth/login', [
        'description' => desc('Client login. An admin email cannot log in here.', fields: ['email' => 'required email', 'password' => 'required'], errors: ['422 `INVALID_CREDENTIALS`', '403 `ACCOUNT_DISABLED`']),
        'body' => ['email' => '{{new_client_email}}', 'password' => 'Password@123', 'device_name' => 'postman'],
        'tests' => [$set('client_token', 'j.data.token')],
    ]),
    req('Me (CLI-AUTH-04)', 'GET', 'client/auth/me', [
        'auth' => bearerAuth('client_token'),
        'description' => desc('The client with active_subscriptions, default_location and default_payment_method.'),
    ]),
    req('Forgot password (CLI-AUTH-05)', 'POST', 'client/auth/forgot-password', [
        'description' => desc('Always 200; sends the reset email.', fields: ['email' => 'required email']),
        'body' => ['email' => '{{client_email}}'],
    ]),
    req('Reset password (CLI-AUTH-06)', 'POST', 'client/auth/reset-password', [
        'description' => desc('Resets the password with the token from the email.', fields: ['token' => 'required', 'email' => 'required email', 'password' => 'required, confirmed, min 8'], errors: ['422 `INVALID_TOKEN`']),
        'body' => ['token' => 'PASTE-TOKEN-FROM-EMAIL', 'email' => '{{client_email}}', 'password' => 'Password@123', 'password_confirmation' => 'Password@123'],
        'manual' => true,
    ]),
], 'none');

// ---------------------------------------------------------------------
// 09 Client › Profile
// ---------------------------------------------------------------------

$clientProfile = folder('09 Client › Profile', [
    req('Show profile (CLI-PRF-01)', 'GET', 'client/profile', [
        'description' => desc('The client profile.'),
    ]),
    req('Update profile (CLI-PRF-02)', 'PUT', 'client/profile', [
        'description' => desc('Updates the profile.', fields: ['name' => 'required', 'email' => 'required, unique', 'phone' => 'required, 05XXXXXXXX', 'company_name' => 'required'], errors: ['422 `VALIDATION_ERROR`']),
        'body' => ['name' => 'عميل تجريبي', 'email' => '{{new_client_email}}', 'phone' => '0500000099', 'company_name' => 'شركة الاختبار المحدودة'],
    ]),
    req('Upload avatar (CLI-PRF-03)', 'POST', 'client/profile/avatar', [
        'description' => desc('Uploads the avatar (jpg/png/webp ≤ 2 MB).', errors: ['422 `VALIDATION_ERROR`']),
        'formdata' => [['avatar', 'fixtures/avatar.jpg', 'file']],
    ]),
    req('Delete avatar (CLI-PRF-04)', 'DELETE', 'client/profile/avatar', [
        'description' => desc('Removes the avatar.'),
    ]),
    req('Update password (CLI-PRF-05)', 'PUT', 'client/profile/password', [
        'description' => desc('Changes the password.', fields: ['current_password' => 'required', 'password' => 'required, confirmed, min 8'], errors: ['422 `VALIDATION_ERROR`']),
        'body' => ['current_password' => 'Password@123', 'password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123'],
        'tests' => [
            '// Restore the seeded password so re-runs keep working.',
            'pm.sendRequest({ url: pm.environment.get("base_url") + "/client/profile/password", method: "PUT", header: { "Content-Type": "application/json", "Accept": "application/json", "Authorization": "Bearer " + pm.environment.get("client_token") }, body: { mode: "raw", raw: JSON.stringify({ current_password: "NewPassword@123", password: "Password@123", password_confirmation: "Password@123" }) } }, () => {});',
        ],
    ]),
], 'client_token');

// ---------------------------------------------------------------------
// 10 Client › Locations
// ---------------------------------------------------------------------

$locations = folder('10 Client › Locations', [
    req('List locations (CLI-LOC-01)', 'GET', 'client/locations', [
        'description' => desc('The locations of the client; one of them is the default.'),
    ]),
    req('Create location (CLI-LOC-02)', 'POST', 'client/locations', [
        'description' => desc('Adds a location (a branch).', fields: ['name' => 'required, max 150', 'city' => 'optional', 'address' => 'required', 'latitude / longitude' => 'optional', 'is_default' => 'optional'], errors: ['422 `VALIDATION_ERROR`']),
        'body' => ['name' => 'المقر الرئيسي', 'city' => 'الرياض', 'address' => 'طريق الملك فهد، برج المكاتب', 'latitude' => 24.7136, 'longitude' => 46.6753, 'is_default' => true],
        'tests' => [$set('location_id', 'j.data.id')],
    ]),
    req('Show location (CLI-LOC-03)', 'GET', 'client/locations/{{location_id}}', [
        'description' => desc('One location. 404 when it belongs to another client.', errors: ['404 `NOT_FOUND`']),
    ]),
    req('Update location (CLI-LOC-04)', 'PUT', 'client/locations/{{location_id}}', [
        'description' => desc('Updates a location.', errors: ['422 `VALIDATION_ERROR`', '404 `NOT_FOUND`']),
        'body' => ['name' => 'المقر الرئيسي (محدّث)', 'city' => 'الرياض', 'address' => 'طريق الملك فهد، برج المكاتب، الدور ٥'],
    ]),
    req('Set default location (CLI-LOC-06)', 'PATCH', 'client/locations/{{location_id}}/default', [
        'description' => desc('Marks the location as the default one.'),
    ]),
    req('Create a second location (CLI-LOC-02)', 'POST', 'client/locations', [
        'description' => desc('A second location, deleted by the next request so the default one survives for the booking wizard.'),
        'body' => ['name' => 'فرع مؤقت', 'city' => 'جدة', 'address' => 'شارع التحلية'],
        'tests' => [$set('delete_location_id', 'j.data.id')],
    ]),
    req('Delete location (CLI-LOC-05)', 'DELETE', 'client/locations/{{delete_location_id}}', [
        'description' => desc('Deletes a location.', errors: ['404 `NOT_FOUND`']),
    ]),
], 'client_token');

// ---------------------------------------------------------------------
// 11 Client › Payment methods
// ---------------------------------------------------------------------

$paymentMethods = folder('11 Client › Payment methods', [
    req('List payment methods (CLI-PM-01)', 'GET', 'client/payment-methods', [
        'description' => desc('The saved cards. `gateway_token` is never returned.'),
    ]),
    req('Save a card (CLI-PM-02)', 'POST', 'client/payment-methods', [
        'description' => desc('Saves a card from a gateway token. Fake driver tokens: `tok_fake_success` (visa 4242), `tok_fake_3ds`, `tok_fake_declined`.', fields: ['token' => 'required', 'is_default' => 'optional'], errors: ['422 `VALIDATION_ERROR`', '402 `PAYMENT_FAILED`']),
        'body' => ['token' => 'tok_fake_success', 'is_default' => true],
        'tests' => [
            'pm.test("visa 4242", () => { pm.expect(j.data.brand).to.eql("visa"); pm.expect(j.data.last_four).to.eql("4242"); });',
            $set('payment_method_id', 'j.data.id'),
        ],
    ]),
    req('Set default card (CLI-PM-04)', 'PATCH', 'client/payment-methods/{{payment_method_id}}/default', [
        'description' => desc('Marks a card as the default.', errors: ['404 `NOT_FOUND`']),
    ]),
    req('Save a second card (CLI-PM-02)', 'POST', 'client/payment-methods', [
        'description' => desc('A second card, deleted by the next request so the default card survives for the booking wizard.'),
        'body' => ['token' => 'tok_fake_ok2'],
        'tests' => [$set('delete_pm_id', 'j.data.id')],
    ]),
    req('Delete card (CLI-PM-03)', 'DELETE', 'client/payment-methods/{{delete_pm_id}}', [
        'description' => desc('Soft-deletes a saved card.', errors: ['404 `NOT_FOUND`']),
    ]),
], 'client_token');

// ---------------------------------------------------------------------
// 12 Client › Booking wizard
// ---------------------------------------------------------------------

$wizard = folder('12 Client › Booking wizard', [
    req('Step 1 – Packages (PUB-01)', 'GET', 'public/packages', [
        'auth' => ['type' => 'noauth'],
        'description' => desc('The client picks a package.'),
        'tests' => [
            $set('package_id', 'j.data.find(p => p.slug === "iron").id'),
            $set('package_id_silver', 'j.data.find(p => p.slug === "silver").id'),
        ],
    ]),
    req('Step 3 – Consultants (PUB-03)', 'GET', 'public/consultants', [
        'auth' => ['type' => 'noauth'],
        'description' => desc('The client picks a consultant.'),
        'tests' => [$set('consultant_id', 'j.data[0].id')],
    ]),
    req('Step 4 – Available dates (PUB-05)', 'GET', 'public/consultants/{{consultant_id}}/available-dates', [
        'auth' => ['type' => 'noauth'],
        'description' => desc('The client picks a date.'),
        'prerequest' => [
            'const d = new Date(); d.setMonth(d.getMonth() + 1);',
            'pm.environment.set("slot_month", d.toISOString().slice(0, 7));',
        ],
        'query' => [['month', '{{slot_month}}', 'YYYY-MM']],
        'tests' => [$set('slot_date', 'j.data.dates[0]')],
    ]),
    req('Step 4 – Slots (PUB-06)', 'GET', 'public/consultants/{{consultant_id}}/slots', [
        'auth' => ['type' => 'noauth'],
        'description' => desc('The client picks a time.'),
        'query' => [['date', '{{slot_date}}', 'YYYY-MM-DD']],
        'tests' => [
            $set('slot_time', 'j.data.slots[0].time'),
            $set('slot_time_2', 'j.data.slots[1].time'),
        ],
    ]),
    req('Step 5 – Locations (CLI-LOC-01)', 'GET', 'client/locations', [
        'description' => desc('The client picks where the consultation happens.'),
        'tests' => [$set('location_id', 'j.data[0].id')],
    ]),
    req('Step 6 – Payment methods (CLI-PM-01)', 'GET', 'client/payment-methods', [
        'description' => desc('The client picks a saved card or adds a new one.'),
        'tests' => [$set('payment_method_id', 'j.data[0].id')],
    ]),
    req('Quote (CLI-BKG-01)', 'POST', 'client/bookings/quote', [
        'description' => desc('The price check. With an active subscription that still has consultations, `requires_payment` is false and the amount is 0. `slot_available` is only calculated when consultant+date+time are sent.', fields: ['package_id' => 'required', 'consultant_id' => 'optional', 'date' => 'optional Y-m-d', 'time' => 'optional H:i'], errors: ['422 `VALIDATION_ERROR`']),
        'body' => ['package_id' => '{{package_id}}', 'consultant_id' => '{{consultant_id}}', 'date' => '{{slot_date}}', 'time' => '{{slot_time}}'],
        'tests' => [
            'pm.test("requires payment", () => pm.expect(j.data.requires_payment).to.be.true);',
            'pm.test("slot available", () => pm.expect(j.data.slot_available).to.be.true);',
        ],
    ]),
    req('Create booking – saved card (CLI-BKG-02)', 'POST', 'client/bookings', [
        'description' => desc('Books with a saved card: the card is charged, the booking becomes `pending` (confirmed), the meeting is created and the confirmation email is sent.', fields: ['package_id' => 'required', 'consultant_id' => 'required', 'date' => 'required Y-m-d', 'time' => 'required H:i', 'client_location_id' => 'required', 'payment_method_id' => 'required unless card_token', 'card_token' => 'a new card token', 'save_card' => 'optional', 'client_notes' => 'optional'], errors: ['409 `SLOT_NOT_AVAILABLE`', '422 `PAYMENT_METHOD_REQUIRED`', '402 `PAYMENT_FAILED`', '422 `PACKAGE_INACTIVE`']),
        'body' => ['package_id' => '{{package_id}}', 'consultant_id' => '{{consultant_id}}', 'date' => '{{slot_date}}', 'time' => '{{slot_time}}', 'client_location_id' => '{{location_id}}', 'payment_method_id' => '{{payment_method_id}}', 'client_notes' => 'أرجو التركيز على الحوكمة'],
        'tests' => [
            'pm.test("pending + paid", () => { pm.expect(j.data.booking.status).to.eql("pending"); pm.expect(j.data.payment.status).to.eql("paid"); });',
            $set('booking_id', 'j.data.booking.id'),
            $set('payment_id', 'j.data.payment.id'),
        ],
    ]),
    req('Create booking – new card 3DS (CLI-BKG-02)', 'POST', 'client/bookings', [
        'description' => desc('Books another package with a new card that needs 3-D Secure: the payment is `initiated` and the client must be sent to `transaction_url`, then the app calls verify.', errors: ['409 `SLOT_NOT_AVAILABLE`', '402 `PAYMENT_FAILED`']),
        'body' => ['package_id' => '{{package_id_silver}}', 'consultant_id' => '{{consultant_id}}', 'date' => '{{slot_date}}', 'time' => '{{slot_time_2}}', 'client_location_id' => '{{location_id}}', 'card_token' => 'tok_fake_3ds', 'save_card' => true],
        'tests' => [
            'pm.test("initiated + transaction url", () => { pm.expect(j.data.payment.status).to.eql("initiated"); pm.expect(j.data.payment.transaction_url).to.be.a("string"); });',
            $set('booking_id_3ds', 'j.data.booking.id'),
            $set('payment_id_3ds', 'j.data.payment.id'),
        ],
    ]),
    req('Verify payment (CLI-PAY-02)', 'POST', 'client/payments/{{payment_id_3ds}}/verify', [
        'description' => desc('After the 3DS redirect: fetches the payment from the gateway and applies the result (idempotent).', errors: ['404 `NOT_FOUND`']),
        'tests' => [
            'pm.test("paid", () => pm.expect(j.data.payment.status).to.eql("paid"));',
            'pm.test("booking confirmed", () => pm.expect(j.data.booking.status).to.eql("pending"));',
        ],
    ]),
], 'client_token');

// ---------------------------------------------------------------------
// 13 Client › Bookings, Payments, Subscriptions, Dashboard
// ---------------------------------------------------------------------

$clientBookings = folder('13 Client › Bookings, Payments, Subscriptions, Dashboard', [
    req('List bookings (CLI-BKG-03)', 'GET', 'client/bookings', [
        'description' => desc('The bookings of the client.'),
        'query' => [['status', '', 'pending | completed | cancelled', true], ['sort', '', '-starts_at (default) | starts_at', true]],
    ]),
    req('Show booking (CLI-BKG-04)', 'GET', 'client/bookings/{{booking_id}}', [
        'description' => desc('One booking with the meeting link and the report. 404 when it belongs to another client.', errors: ['404 `NOT_FOUND`']),
    ]),
    req('Cancel booking (CLI-BKG-05)', 'POST', 'client/bookings/{{booking_id_3ds}}/cancel', [
        'description' => desc('The client cancels up to `client_cancel_hours` (24) before the start. A paid booking gets `refund_status = requested`.', fields: ['reason' => 'optional, max 500'], errors: ['422 `BOOKING_CANCEL_WINDOW_PASSED`', '422 `BOOKING_INVALID_STATUS`', '404 `NOT_FOUND`']),
        'body' => ['reason' => 'ظرف طارئ'],
        'tests' => [
            'pm.test("cancelled + refund requested", () => { pm.expect(j.data.status).to.eql("cancelled"); pm.expect(j.data.refund_status).to.eql("requested"); });',
        ],
    ]),
    req('List payments (CLI-PAY-01)', 'GET', 'client/payments', [
        'description' => desc('The payments of the client.'),
    ]),
    req('List subscriptions (CLI-SUB-01)', 'GET', 'client/subscriptions', [
        'description' => desc('All subscriptions of the client with the remaining consultations.'),
    ]),
    req('Active subscriptions (CLI-SUB-02)', 'GET', 'client/subscriptions/active', [
        'description' => desc('Only the active ones.'),
    ]),
    req('Dashboard (CLI-DSH-01)', 'GET', 'client/dashboard', [
        'description' => desc('The client home: next booking, counters, unread reports, active subscriptions.'),
    ]),
], 'client_token');

// ---------------------------------------------------------------------
// 14 Admin › Bookings
// ---------------------------------------------------------------------

$adminBookings = folder('14 Admin › Bookings', [
    req('List bookings (BKG-01)', 'GET', 'admin/bookings', [
        'description' => desc('All bookings (a consultant only sees his own).', 'view-bookings'),
        'query' => [
            ['status', '', 'pending_payment | pending | completed | cancelled', true],
            ['consultant_id', '', 'admin only', true],
            ['client_id', '', '', true],
            ['date_from', '', 'YYYY-MM-DD', true],
            ['date_to', '', 'YYYY-MM-DD', true],
            ['search', '', 'reference or client name', true],
            ['sort', '', '-starts_at (default)', true],
        ],
    ]),
    req('List completed bookings (BKG-01)', 'GET', 'admin/bookings', [
        'description' => desc('Finds the demo completed booking that still awaits a report; the report upload uses it.', 'view-bookings'),
        'query' => [['status', 'completed', '']],
        'tests' => [
            'const b = j.data.find(x => x.report_status === "pending") || j.data[0];',
            $set('completed_booking_id', 'b.id'),
        ],
    ]),
    req('Show booking (BKG-02)', 'GET', 'admin/bookings/{{booking_id}}', [
        'description' => desc('One booking with its payments.', 'view-bookings', errors: ['404 `NOT_FOUND`', '403 `FORBIDDEN`']),
    ]),
    req('Regenerate meeting (BKG-05)', 'POST', 'admin/bookings/{{booking_id}}/meeting', [
        'description' => desc('Re-creates the meeting link (e.g. after a Google failure).', 'manage-meetings', errors: ['422 `BOOKING_INVALID_STATUS`', '502 `MEETING_CREATION_FAILED`']),
    ]),
    req('Complete booking (BKG-03)', 'POST', 'admin/bookings/{{booking_id}}/complete', [
        'description' => desc('Marks the booking completed after the session. The start time must have passed; the booking then waits for the report. Skipped in the CI run: the seeded pending bookings start in the future — run it by hand on a booking whose start has passed.', 'complete-bookings', ['notes' => 'optional, max 1000'], ['422 `BOOKING_NOT_STARTED`', '422 `BOOKING_INVALID_STATUS`']),
        'body' => ['notes' => 'تمت الجلسة بنجاح'],
        'manual' => true,
    ]),
    req('Cancel booking (BKG-04)', 'POST', 'admin/bookings/{{booking_id}}/cancel', [
        'description' => desc('The admin cancels at any time; a paid booking gets `refund_status = requested`. The client and the consultant are notified.', 'cancel-bookings', ['reason' => 'required, max 500'], ['422 `BOOKING_INVALID_STATUS`']),
        'body' => ['reason' => 'إلغاء بناءً على طلب العميل'],
        'tests' => [
            'pm.test("cancelled", () => pm.expect(j.data.status).to.eql("cancelled"));',
        ],
    ]),
    req('Calendar (BKG-06)', 'GET', 'admin/bookings/calendar', [
        'description' => desc('The bookings of a period (max 62 days) for the calendar view.', 'view-bookings'),
        'prerequest' => [
            'const f = new Date(); pm.environment.set("cal_from", f.toISOString().slice(0, 10));',
            'const t = new Date(Date.now() + 30 * 24 * 3600 * 1000); pm.environment.set("cal_to", t.toISOString().slice(0, 10));',
        ],
        'query' => [
            ['from', '{{cal_from}}', 'YYYY-MM-DD, required'],
            ['to', '{{cal_to}}', 'YYYY-MM-DD, required, from + 62 days max'],
        ],
    ]),
    req('Mark refunded (BKG-07)', 'POST', 'admin/bookings/{{booking_id_3ds}}/mark-refunded', [
        'description' => desc('After refunding in the gateway dashboard: marks the booking refunded. Only possible while `refund_status = requested` (the client cancelled this paid booking in folder 13).', 'refund-payments', errors: ['422 `REFUND_NOT_REQUESTED`']),
        'tests' => [
            'pm.test("refunded", () => pm.expect(j.data.refund_status).to.eql("refunded"));',
        ],
    ]),
], 'admin_token');

// ---------------------------------------------------------------------
// 15 Admin › Reports
// ---------------------------------------------------------------------

$adminReports = folder('15 Admin › Reports', [
    req('List reports (RPT-01)', 'GET', 'admin/reports', [
        'description' => desc('All reports (a consultant only sees his own).', 'view-reports'),
        'query' => [
            ['consultant_id', '', 'admin only', true],
            ['client_id', '', '', true],
            ['date_from', '', 'YYYY-MM-DD', true],
            ['date_to', '', 'YYYY-MM-DD', true],
            ['search', '', 'title or booking reference', true],
        ],
        'tests' => [
            $set('demo_report_id', 'j.data[0].id'),
        ],
    ]),
    req('Upload report (RPT-03)', 'POST', 'admin/bookings/{{completed_booking_id}}/report', [
        'description' => desc('Uploads the report of a completed booking (pdf/doc/docx ≤ 20 MB, private disk). Uploading again replaces the file. The client gets an email with a dashboard link and a 7-day signed download link unless `notify_client` is false.', 'upload-reports', ['title' => 'required, max 191', 'summary' => 'optional', 'file' => 'required pdf/doc/docx ≤ 20 MB', 'notify_client' => 'optional, default true'], ['422 `REPORT_NOT_ALLOWED` (booking not completed)', '422 `VALIDATION_ERROR`', '403 `FORBIDDEN`']),
        'formdata' => [
            ['title', 'تقرير الحوكمة المؤسسية'],
            ['summary', 'تقييم شامل لممارسات الحوكمة مع التوصيات.'],
            ['file', 'fixtures/report.pdf', 'file'],
        ],
        'tests' => [
            'pm.test("201 created", () => pm.response.to.have.status(201));',
            $set('report_id', 'j.data.id'),
        ],
    ]),
    req('Show report (RPT-02)', 'GET', 'admin/reports/{{report_id}}', [
        'description' => desc('One report with the guard-specific download_url.', 'view-reports', errors: ['404 `NOT_FOUND`', '403 `FORBIDDEN`']),
    ]),
    req('Download report (RPT-04)', 'GET', 'admin/reports/{{report_id}}/download', [
        'description' => desc('Streams the file as an attachment.', 'download-reports', errors: ['404 `NOT_FOUND`', '403 `FORBIDDEN`']),
        'raw_tests' => downloadTests(),
    ]),
    req('Notify client (RPT-06)', 'POST', 'admin/reports/{{report_id}}/notify-client', [
        'description' => desc('Resends the report-ready email.', 'upload-reports'),
    ]),
    req('Delete report (RPT-05)', 'DELETE', 'admin/reports/{{report_id}}', [
        'description' => desc('Deletes the report and its file; the booking goes back to awaiting a report. (Deletes the report uploaded above — the seeded demo report survives for the client folder.)', 'delete-reports', errors: ['403 `FORBIDDEN`']),
    ]),
], 'admin_token');

// ---------------------------------------------------------------------
// 16 Client › Reports
// ---------------------------------------------------------------------

$clientReports = folder('16 Client › Reports', [
    req('Login as demo client (CLI-AUTH-02)', 'POST', 'client/auth/login', [
        'auth' => ['type' => 'noauth'],
        'description' => desc('The demo client owns the seeded report, so this folder runs as him.'),
        'body' => ['email' => '{{client_email}}', 'password' => '{{client_password}}', 'device_name' => 'postman'],
        'tests' => [$set('client_token', 'j.data.token')],
    ]),
    req('List reports (CLI-RPT-01)', 'GET', 'client/reports', [
        'description' => desc('The reports of the client. `download_url` points to CLI-RPT-03.'),
        'query' => [
            ['search', '', 'title or consultant name', true],
            ['date_from', '', 'YYYY-MM-DD', true],
            ['date_to', '', 'YYYY-MM-DD', true],
        ],
    ]),
    req('Show report (CLI-RPT-02)', 'GET', 'client/reports/{{demo_report_id}}', [
        'description' => desc('One report. 404 when it belongs to another client.', errors: ['404 `NOT_FOUND`']),
    ]),
    req('Download report (CLI-RPT-03)', 'GET', 'client/reports/{{demo_report_id}}/download', [
        'description' => desc('Streams the file; the first download sets `first_downloaded_at` (the "unread" counter of the dashboard).', errors: ['404 `NOT_FOUND`']),
        'raw_tests' => downloadTests(),
    ]),
], 'client_token');

// ---------------------------------------------------------------------
// 17 Admin › Clients
// ---------------------------------------------------------------------

$adminClients = folder('17 Admin › Clients', [
    req('List clients (ADM-CL-01)', 'GET', 'admin/clients', [
        'description' => desc('All clients with bookings_count, reports_count and the active subscription. A consultant only sees clients who booked him.', 'view-clients'),
        'query' => [
            ['search', '', 'name, email, phone, company', true],
            ['is_active', '', '1 | 0', true],
            ['consultant_id', '', 'admin only', true],
            ['has_active_subscription', '', '1 | 0', true],
        ],
        'tests' => [
            $set('client_id', 'j.data.find(c => c.email === "client@gcmc.sa").id'),
        ],
    ]),
    req('Show client (ADM-CL-02)', 'GET', 'admin/clients/{{client_id}}', [
        'description' => desc('One client with locations, subscriptions and counts.', 'view-clients', errors: ['404 `NOT_FOUND`']),
    ]),
    req('Update client (ADM-CL-03)', 'PUT', 'admin/clients/{{client_id}}', [
        'description' => desc('Updates a client.', 'update-clients', ['name' => 'required', 'email' => 'required, unique', 'phone' => 'required, 05XXXXXXXX', 'company_name' => 'required'], ['422 `VALIDATION_ERROR`']),
        'body' => ['name' => 'عميل تجريبي', 'email' => 'client@gcmc.sa', 'phone' => '0500000001', 'company_name' => 'شركة تجريبية'],
    ]),
    req('Update client status (ADM-CL-04)', 'PATCH', 'admin/clients/{{client_id}}/status', [
        'description' => desc('Activates/deactivates a client. Deactivation revokes his tokens.', 'update-clients', ['is_active' => 'required boolean'], []),
        'body' => ['is_active' => true],
    ]),
    req('Client bookings (ADM-CL-06)', 'GET', 'admin/clients/{{client_id}}/bookings', [
        'description' => desc('The bookings of this client.', 'view-bookings'),
    ]),
    req('Client reports (ADM-CL-07)', 'GET', 'admin/clients/{{client_id}}/reports', [
        'description' => desc('The reports of this client.', 'view-reports'),
    ]),
    req('Client subscriptions (ADM-CL-08)', 'GET', 'admin/clients/{{client_id}}/subscriptions', [
        'description' => desc('The subscriptions of this client.', 'view-clients'),
    ]),
    req('Delete client (ADM-CL-05)', 'DELETE', 'admin/clients/{{new_client_id}}', [
        'description' => desc('Deletes the client registered in folder 08 (his bookings were cancelled in folders 13–14). A client with future bookings cannot be deleted.', 'delete-clients', errors: ['422 `CLIENT_HAS_FUTURE_BOOKINGS`']),
    ]),
], 'admin_token');

// ---------------------------------------------------------------------
// 18 Admin › Payments / 19 Dashboard / 20 Webhooks / 99 Logout
// ---------------------------------------------------------------------

$adminPayments = folder('18 Admin › Payments', [
    req('List payments (PAY-01)', 'GET', 'admin/payments', [
        'description' => desc('All payments (a consultant only sees payments of his own bookings). `gateway_response` is never returned.', 'view-payments'),
        'query' => [
            ['status', '', 'initiated | paid | failed | refunded', true],
            ['client_id', '', '', true],
            ['date_from', '', 'YYYY-MM-DD', true],
            ['date_to', '', 'YYYY-MM-DD', true],
        ],
    ]),
    req('Show payment (PAY-02)', 'GET', 'admin/payments/{{payment_id}}', [
        'description' => desc('One payment.', 'view-payments', errors: ['404 `NOT_FOUND`', '403 `FORBIDDEN`']),
    ]),
], 'admin_token');

$dashboard = folder('19 Admin › Dashboard', [
    req('Dashboard stats (DSH-01)', 'GET', 'admin/dashboard/stats', [
        'description' => desc('The home counters. A consultant gets the same keys scoped to him, without `consultants` and `revenue`.', 'view-dashboard'),
    ]),
], 'admin_token');

$webhooks = folder('20 Webhooks', [
    req('Moyasar webhook (WHK-01)', 'POST', 'webhooks/payments/moyasar', [
        'description' => desc('The gateway calls this on payment updates. Idempotent; always 200 for known events, 401 for a wrong secret. Skipped in the CI run (needs a real Moyasar signature).', errors: ['401 `UNAUTHORIZED`']),
        'body' => ['id' => 'PASTE-PAYMENT-ID', 'status' => 'paid', 'secret_token' => 'PASTE-WEBHOOK-SECRET'],
        'manual' => true,
    ]),
], 'none');

$logout = folder('99 Logout', [
    req('Admin logout (ADM-AUTH-02)', 'POST', 'admin/auth/logout', [
        'auth' => bearerAuth('admin_token'),
        'description' => desc('Revokes the current token.'),
    ]),
    req('Client logout (CLI-AUTH-03)', 'POST', 'client/auth/logout', [
        'auth' => bearerAuth('client_token'),
        'description' => desc('Revokes the current token.'),
    ]),
], 'none');

// ---------------------------------------------------------------------
// Collection
// ---------------------------------------------------------------------

$collection = [
    'info' => [
        '_postman_id' => '8f2d1c4e-9a3b-4c5d-8e6f-gcmc00000001',
        'name' => 'GCMC API',
        'description' => "GCMC Consultancy Platform API.\n\nRun order = folder order. Start from a freshly seeded database (`php artisan migrate:fresh --seed`) and import `GCMC-Local.postman_environment.json`.\n\nNewman: `npx newman run postman/GCMC-API.postman_collection.json -e postman/GCMC-Local.postman_environment.json --working-dir postman --env-var ci=true`",
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'item' => [
        $public, $adminAuth, $adminProfile, $roles, $users, $consultants, $my,
        $packages, $clientAuth, $clientProfile, $locations, $paymentMethods,
        $wizard, $clientBookings, $adminBookings, $adminReports, $clientReports,
        $adminClients, $adminPayments, $dashboard, $webhooks, $logout,
    ],
];

// Every request carries the two collection headers (plan 14.4.3).
$addHeaders = function (array &$items) use (&$addHeaders): void {
    foreach ($items as &$item) {
        if (isset($item['item'])) {
            $addHeaders($item['item']);
        } elseif (isset($item['request'])) {
            $item['request']['header'] = [
                ['key' => 'Accept', 'value' => 'application/json'],
                ['key' => 'Accept-Language', 'value' => '{{locale}}'],
            ];
        }
    }
};
$addHeaders($collection['item']);

if (! is_dir('postman')) {
    mkdir('postman', 0755, true);
}

file_put_contents(
    'postman/GCMC-API.postman_collection.json',
    json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
);

// ---------------------------------------------------------------------
// Environments
// ---------------------------------------------------------------------

$envValues = [
    ['base_url', 'http://localhost:8000/api/v1', 'default'],
    ['locale', 'ar', 'default'],
    ['admin_email', 'admin@gcmc.sa', 'default'],
    ['admin_password', 'Password@123', 'secret'],
    ['consultant_email', 'ahmad.alotaibi@gcmc.sa', 'default'],
    ['consultant_password', 'Password@123', 'secret'],
    ['client_email', 'client@gcmc.sa', 'default'],
    ['client_password', 'Password@123', 'secret'],
    ['ci', '', 'default'],
];
$tokenVars = ['admin_token', 'consultant_token', 'client_token', 'role_id', 'user_id', 'consultant_id', 'time_off_id', 'client_id', 'location_id', 'package_id', 'package_slug', 'payment_method_id', 'booking_id', 'payment_id', 'report_id', 'slot_date', 'slot_time', 'new_client_email'];
foreach ($tokenVars as $var) {
    $envValues[] = [$var, '', 'default'];
}

$envFile = function (string $name, bool $emptySecrets) use ($envValues): array {
    return [
        'id' => 'gcmc-'.strtolower(str_replace(' ', '-', $name)),
        'name' => $name,
        'values' => array_map(function (array $v) use ($emptySecrets) {
            [$key, $value, $type] = $v;
            if ($emptySecrets && ($type === 'secret' || $key === 'base_url')) {
                $value = '';
            }

            return ['key' => $key, 'value' => $value, 'type' => $type === 'secret' ? 'secret' : 'default', 'enabled' => true];
        }, $envValues),
        '_postman_variable_scope' => 'environment',
        '_postman_exported_using' => 'GCMC build script',
    ];
};

file_put_contents(
    'postman/GCMC-Local.postman_environment.json',
    json_encode($envFile('GCMC Local', false), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
);
file_put_contents(
    'postman/GCMC-Staging.postman_environment.json',
    json_encode($envFile('GCMC Staging', true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
);

$count = 0;
array_walk_recursive($collection, function ($value, $key) use (&$count) {
    if ($key === 'method') {
        $count++;
    }
});

echo "Collection written: {$count} requests\n";
echo "Environments written: GCMC Local, GCMC Staging\n";
