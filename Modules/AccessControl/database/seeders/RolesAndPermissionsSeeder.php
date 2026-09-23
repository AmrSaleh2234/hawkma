<?php

namespace Modules\AccessControl\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AccessControl\Models\Permission;
use Modules\AccessControl\Models\Role;
use Modules\AccessControl\Support\PermissionRegistry;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $known = [];

        foreach (PermissionRegistry::all() as $group => $permissions) {
            foreach ($permissions as $name) {
                Permission::firstOrCreate(
                    ['name' => $name, 'guard_name' => 'admin'],
                    ['group' => $group],
                );

                $known[] = $name;
            }
        }

        // Remove permissions that are no longer in the registry.
        Permission::query()
            ->where('guard_name', 'admin')
            ->whereNotIn('name', $known)
            ->delete();

        $admin = Role::firstOrCreate(['name' => Role::ADMIN, 'guard_name' => 'admin']);
        $admin->syncPermissions(Permission::query()->where('guard_name', 'admin')->get());

        $consultant = Role::firstOrCreate(['name' => Role::CONSULTANT, 'guard_name' => 'admin']);

        // Default consultant permissions are applied only on first creation,
        // so permission changes made by admins are kept on re-seeding.
        if (! $consultant->wasRecentlyCreated) {
            return;
        }

        $consultant->syncPermissions(PermissionRegistry::consultantDefaults());
    }
}
