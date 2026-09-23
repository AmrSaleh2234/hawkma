<?php

namespace Modules\AccessControl\Tests\Feature\Admin;

use Laravel\Sanctum\Sanctum;
use Modules\AccessControl\Models\Role;
use Modules\AccessControl\Support\PermissionRegistry;
use Tests\TestCase;

class RoleTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | ACL-01 GET /api/v1/admin/permissions
    |----------------------------------------------------------------------
    */

    public function test_acl_01_returns_grouped_permissions_matching_the_registry(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/permissions');

        $this->assertApiSuccess($response)
            ->assertJsonStructure(['data' => [['group', 'label', 'permissions' => [['id', 'name', 'group', 'label']]]]]);

        $total = collect($response->json('data'))->sum(fn ($group) => count($group['permissions']));

        $this->assertSame(count(PermissionRegistry::names()), $total);
        $this->assertSame(count(PermissionRegistry::all()), count($response->json('data')));
    }

    public function test_acl_01_forbidden_for_a_consultant_without_permission(): void
    {
        $this->actingAsConsultant();

        $this->assertApiError($this->getJson('/api/v1/admin/permissions'), 403, 'FORBIDDEN');
    }

    public function test_acl_01_requires_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/permissions'), 401, 'UNAUTHENTICATED');
    }

    /*
    |----------------------------------------------------------------------
    | ACL-02 GET /api/v1/admin/roles
    |----------------------------------------------------------------------
    */

    public function test_acl_02_lists_roles_with_counts(): void
    {
        $this->createAdmin();
        $this->createConsultant();

        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/roles');

        $this->assertPaginated($response);

        $roles = collect($response->json('data'))->keyBy('name');

        $this->assertTrue($roles->has('admin'));
        $this->assertTrue($roles->has('consultant'));
        $this->assertSame(34, $roles['admin']['permissions_count']);
        $this->assertSame(10, $roles['consultant']['permissions_count']);
        $this->assertSame(2, $roles['admin']['users_count']);
        $this->assertSame(1, $roles['consultant']['users_count']);
        $this->assertTrue($roles['admin']['is_protected']);
    }

    public function test_acl_02_search_by_name(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/roles?search=consult');

        $names = collect($response->json('data'))->pluck('name');

        $this->assertSame(['consultant'], $names->all());
    }

    public function test_acl_02_forbidden_without_permission(): void
    {
        $this->actingAsConsultant();

        $this->assertApiError($this->getJson('/api/v1/admin/roles'), 403, 'FORBIDDEN');
    }

    /*
    |----------------------------------------------------------------------
    | ACL-03 POST /api/v1/admin/roles
    |----------------------------------------------------------------------
    */

    public function test_acl_03_creates_a_role_with_permissions(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/roles', [
            'name' => 'supervisor',
            'permissions' => ['view-bookings', 'view-reports', 'view-clients'],
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.name', 'supervisor')
            ->assertJsonPath('data.is_protected', false);

        $this->assertCount(3, $response->json('data.permissions'));
        $this->assertDatabaseHas('roles', ['name' => 'supervisor', 'guard_name' => 'admin']);
    }

    public function test_acl_03_duplicate_name_returns_422(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/roles', [
                'name' => 'admin',
                'permissions' => ['view-bookings'],
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_acl_03_unknown_permission_returns_422(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/roles', [
                'name' => 'supervisor',
                'permissions' => ['fly-to-the-moon'],
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_acl_03_empty_permissions_return_422(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/roles', ['name' => 'supervisor', 'permissions' => []]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_acl_03_invalid_name_format_returns_422(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/roles', ['name' => 'Super Visor!', 'permissions' => ['view-bookings']]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_acl_03_forbidden_without_permission(): void
    {
        $this->actingAsConsultant();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/roles', ['name' => 'supervisor', 'permissions' => ['view-bookings']]),
            403,
            'FORBIDDEN',
        );
    }

    /*
    |----------------------------------------------------------------------
    | ACL-04 GET /api/v1/admin/roles/{role}
    |----------------------------------------------------------------------
    */

    public function test_acl_04_shows_a_role_with_its_permissions(): void
    {
        $this->actingAsAdmin();

        $role = Role::findByName('consultant', 'admin');

        $response = $this->getJson("/api/v1/admin/roles/{$role->id}");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.name', 'consultant')
            ->assertJsonStructure(['data' => ['permissions' => [['id', 'name', 'group', 'label']]]]);

        $this->assertCount(10, $response->json('data.permissions'));
    }

    public function test_acl_04_unknown_role_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError($this->getJson('/api/v1/admin/roles/9999'), 404, 'NOT_FOUND');
    }

    /*
    |----------------------------------------------------------------------
    | ACL-05 PUT /api/v1/admin/roles/{role}
    |----------------------------------------------------------------------
    */

    public function test_acl_05_updates_name_and_permissions(): void
    {
        $this->actingAsAdmin();

        $role = Role::create(['name' => 'supervisor', 'guard_name' => 'admin']);
        $role->syncPermissions(['view-bookings']);

        $response = $this->putJson("/api/v1/admin/roles/{$role->id}", [
            'name' => 'manager',
            'permissions' => ['view-bookings', 'cancel-bookings'],
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.name', 'manager');

        $this->assertEqualsCanonicalizing(
            ['view-bookings', 'cancel-bookings'],
            $role->fresh()->permissions->pluck('name')->all(),
        );
    }

    public function test_acl_05_the_admin_role_cannot_be_changed(): void
    {
        $this->actingAsAdmin();

        $role = Role::findByName('admin', 'admin');

        $this->assertApiError(
            $this->putJson("/api/v1/admin/roles/{$role->id}", ['permissions' => ['view-bookings']]),
            403,
            'ROLE_PROTECTED',
        );

        $this->assertApiError(
            $this->putJson("/api/v1/admin/roles/{$role->id}", ['name' => 'root']),
            403,
            'ROLE_PROTECTED',
        );
    }

    public function test_acl_05_the_consultant_role_cannot_be_renamed(): void
    {
        $this->actingAsAdmin();

        $role = Role::findByName('consultant', 'admin');

        $this->assertApiError(
            $this->putJson("/api/v1/admin/roles/{$role->id}", ['name' => 'advisor']),
            403,
            'ROLE_PROTECTED',
        );
    }

    public function test_acl_05_the_consultant_role_permissions_can_change_and_apply_right_away(): void
    {
        $consultant = $this->createConsultant();
        $this->actingAsAdmin();

        $role = Role::findByName('consultant', 'admin');

        $this->putJson("/api/v1/admin/roles/{$role->id}", [
            'permissions' => ['view-dashboard', 'view-bookings'],
        ])->assertOk();

        // The consultant's /me shows the new permissions right away.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($consultant, ['*'], 'admin');

        $response = $this->getJson('/api/v1/admin/auth/me');

        $this->assertEqualsCanonicalizing(
            ['view-dashboard', 'view-bookings'],
            $response->json('data.permissions'),
        );
    }

    /*
    |----------------------------------------------------------------------
    | ACL-06 DELETE /api/v1/admin/roles/{role}
    |----------------------------------------------------------------------
    */

    public function test_acl_06_deletes_a_role(): void
    {
        $this->actingAsAdmin();

        $role = Role::create(['name' => 'supervisor', 'guard_name' => 'admin']);

        $this->deleteJson("/api/v1/admin/roles/{$role->id}")->assertOk();

        $this->assertDatabaseMissing('roles', ['name' => 'supervisor']);
    }

    public function test_acl_06_protected_roles_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();

        foreach (['admin', 'consultant'] as $name) {
            $role = Role::findByName($name, 'admin');

            $this->assertApiError(
                $this->deleteJson("/api/v1/admin/roles/{$role->id}"),
                403,
                'ROLE_PROTECTED',
            );
        }
    }

    public function test_acl_06_a_role_with_users_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();

        $role = Role::create(['name' => 'supervisor', 'guard_name' => 'admin']);
        $this->createStaffWithPermissions([])->assignRole($role);

        $this->assertApiError(
            $this->deleteJson("/api/v1/admin/roles/{$role->id}"),
            409,
            'ROLE_HAS_USERS',
        );
    }

    /*
    |----------------------------------------------------------------------
    | ACL-07 GET /api/v1/admin/roles/{role}/users
    |----------------------------------------------------------------------
    */

    public function test_acl_07_returns_only_the_users_with_the_role(): void
    {
        $this->createAdmin();
        $this->createConsultant();
        $this->createStaffWithPermissions(['view-bookings']);

        $this->actingAsAdmin();

        $role = Role::findByName('consultant', 'admin');

        $response = $this->getJson("/api/v1/admin/roles/{$role->id}/users");

        $this->assertPaginated($response);
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(['consultant'], $response->json('data.0.roles'));
    }
}
