<?php

namespace Modules\AccessControl\Support;

/**
 * The single source of truth for every permission in the system.
 *
 * The seeder, the GET /admin/permissions endpoint and the tests all read
 * from this class. Never hard-code permission names anywhere else.
 */
final class PermissionRegistry
{
    public const GROUP_DASHBOARD = 'dashboard';

    public const GROUP_ROLES = 'roles';

    public const GROUP_PERMISSIONS = 'permissions';

    public const GROUP_USERS = 'users';

    public const GROUP_CONSULTANTS = 'consultants';

    public const GROUP_AVAILABILITY = 'availability';

    public const GROUP_CLIENTS = 'clients';

    public const GROUP_PACKAGES = 'packages';

    public const GROUP_BOOKINGS = 'bookings';

    public const GROUP_REPORTS = 'reports';

    public const GROUP_PAYMENTS = 'payments';

    /**
     * All permissions grouped by their group key.
     *
     * @return array<string, array<int, string>>
     */
    public static function all(): array
    {
        return [
            self::GROUP_DASHBOARD => ['view-dashboard'],
            self::GROUP_ROLES => ['view-roles', 'create-roles', 'update-roles', 'delete-roles'],
            self::GROUP_PERMISSIONS => ['view-permissions'],
            self::GROUP_USERS => ['view-users', 'create-users', 'update-users', 'delete-users', 'assign-roles'],
            self::GROUP_CONSULTANTS => ['view-consultants', 'create-consultants', 'update-consultants', 'delete-consultants'],
            self::GROUP_AVAILABILITY => ['view-availability', 'manage-availability'],
            self::GROUP_CLIENTS => ['view-clients', 'update-clients', 'delete-clients'],
            self::GROUP_PACKAGES => ['view-packages', 'create-packages', 'update-packages', 'delete-packages'],
            self::GROUP_BOOKINGS => ['view-bookings', 'complete-bookings', 'cancel-bookings', 'manage-meetings'],
            self::GROUP_REPORTS => ['view-reports', 'upload-reports', 'download-reports', 'delete-reports'],
            self::GROUP_PAYMENTS => ['view-payments', 'refund-payments'],
        ];
    }

    /**
     * Flat list of every permission name.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return collect(self::all())->flatten()->values()->all();
    }

    /**
     * The default permissions of the consultant role (section 7.3).
     *
     * @return array<int, string>
     */
    public static function consultantDefaults(): array
    {
        return [
            'view-dashboard',
            'view-bookings',
            'complete-bookings',
            'cancel-bookings',
            'view-reports',
            'upload-reports',
            'download-reports',
            'view-clients',
            'view-availability',
            'manage-availability',
        ];
    }
}
