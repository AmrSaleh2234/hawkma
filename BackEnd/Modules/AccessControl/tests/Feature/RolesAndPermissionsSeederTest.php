<?php

namespace Modules\AccessControl\Tests\Feature;

use Modules\AccessControl\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\AccessControl\Models\Permission;
use Modules\AccessControl\Models\Role;
use Modules\AccessControl\Support\PermissionRegistry;
use Tests\TestCase;

class RolesAndPermissionsSeederTest extends TestCase
{
    public function test_running_the_seeder_twice_does_not_duplicate_anything(): void
    {
        // setUp() already seeded once.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(PermissionRegistry::names()), Permission::count());
        $this->assertSame(1, Role::query()->where('name', 'admin')->count());
        $this->assertSame(1, Role::query()->where('name', 'consultant')->count());
    }

    public function test_the_admin_role_has_all_the_permissions(): void
    {
        $admin = Role::findByName('admin', 'admin');

        $this->assertSame(
            count(PermissionRegistry::names()),
            $admin->permissions()->count(),
        );
    }

    public function test_the_consultant_role_has_the_default_permissions(): void
    {
        $consultant = Role::findByName('consultant', 'admin');

        $this->assertEqualsCanonicalizing(
            PermissionRegistry::consultantDefaults(),
            $consultant->permissions->pluck('name')->all(),
        );
    }

    public function test_reseeding_keeps_changes_made_to_the_consultant_role(): void
    {
        $consultant = Role::findByName('consultant', 'admin');
        $consultant->syncPermissions(['view-dashboard']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['view-dashboard'],
            $consultant->fresh()->permissions->pluck('name')->all(),
        );
    }

    public function test_reseeding_removes_permissions_that_left_the_registry(): void
    {
        Permission::create(['name' => 'obsolete-permission', 'guard_name' => 'admin', 'group' => 'users']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseMissing('permissions', ['name' => 'obsolete-permission']);
    }
}
