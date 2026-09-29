<?php

namespace Modules\Users\Tests\Feature\Admin;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Modules\AccessControl\Models\Role;
use Modules\Users\Models\User;
use Modules\Users\Notifications\StaffAccountCreatedNotification;
use Tests\TestCase;

class UserTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | USR-01 GET /api/v1/admin/users
    |----------------------------------------------------------------------
    */

    public function test_usr_01_lists_admins_and_consultants_with_pagination_meta(): void
    {
        $this->createAdmin();
        $this->createConsultant();

        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/users');

        $this->assertPaginated($response);
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_usr_01_filters_by_type_role_search_and_is_active(): void
    {
        $this->createAdmin(['name' => 'Finder Admin']);
        $consultant = $this->createConsultant(['name' => 'Unique Consultant Name']);
        $inactive = User::factory()->admin()->inactive()->create();

        $this->actingAsAdmin();

        $byType = $this->getJson('/api/v1/admin/users?type=consultant');
        $this->assertSame(1, $byType->json('meta.total'));
        $this->assertSame($consultant->id, $byType->json('data.0.id'));

        $byRole = $this->getJson('/api/v1/admin/users?role=consultant');
        $this->assertSame(1, $byRole->json('meta.total'));

        $bySearch = $this->getJson('/api/v1/admin/users?search=Unique Consultant');
        $this->assertSame(1, $bySearch->json('meta.total'));

        $byActive = $this->getJson('/api/v1/admin/users?is_active=0');
        $this->assertSame(1, $byActive->json('meta.total'));
        $this->assertSame($inactive->id, $byActive->json('data.0.id'));
    }

    public function test_usr_01_forbidden_without_permission(): void
    {
        $this->actingAsConsultant();

        $this->assertApiError($this->getJson('/api/v1/admin/users'), 403, 'FORBIDDEN');
    }

    public function test_usr_01_requires_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/users'), 401, 'UNAUTHENTICATED');
    }

    /*
    |----------------------------------------------------------------------
    | USR-02 POST /api/v1/admin/users
    |----------------------------------------------------------------------
    */

    public function test_usr_02_creates_an_admin_with_the_admin_role(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'Second Admin',
            'email' => 'second@gcmc.sa',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'roles' => ['admin'],
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.type', 'admin')
            ->assertJsonPath('data.roles', ['admin']);

        // A password was given, so no welcome notification is sent.
        Notification::assertNothingSent();
    }

    public function test_usr_02_without_password_sends_the_welcome_notification(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'Staff Member',
            'email' => 'staff@gcmc.sa',
            'roles' => ['admin'],
        ]);

        $this->assertApiSuccess($response, 201);

        $user = User::query()->where('email', 'staff@gcmc.sa')->firstOrFail();

        Notification::assertSentTo($user, StaffAccountCreatedNotification::class);
    }

    public function test_usr_02_creates_a_consultant_and_adds_the_consultant_role(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $role = Role::create(['name' => 'supervisor', 'guard_name' => 'admin']);
        $role->syncPermissions(['view-bookings']);

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'Advisor',
            'email' => 'advisor@gcmc.sa',
            'type' => 'consultant',
            'roles' => ['supervisor'],
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.type', 'consultant');

        $this->assertEqualsCanonicalizing(['consultant', 'supervisor'], $response->json('data.roles'));
    }

    public function test_usr_02_duplicate_email_returns_422(): void
    {
        $admin = $this->createAdmin();
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/users', [
                'name' => 'Copy',
                'email' => $admin->email,
                'roles' => ['admin'],
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_usr_02_unknown_role_returns_422(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/users', [
                'name' => 'Staff',
                'email' => 'staff@gcmc.sa',
                'roles' => ['does-not-exist'],
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_usr_02_the_avatar_is_saved(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'With Avatar',
            'email' => 'avatar@gcmc.sa',
            'roles' => ['admin'],
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $this->assertApiSuccess($response, 201);

        $user = User::query()->where('email', 'avatar@gcmc.sa')->firstOrFail();

        $this->assertNotNull($user->getFirstMedia('avatar'));
    }

    /*
    |----------------------------------------------------------------------
    | USR-03 / USR-04
    |----------------------------------------------------------------------
    */

    public function test_usr_03_shows_a_user(): void
    {
        $target = $this->createConsultant();
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.roles', ['consultant']);
    }

    public function test_usr_03_unknown_user_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError($this->getJson('/api/v1/admin/users/9999'), 404, 'NOT_FOUND');
    }

    public function test_usr_04_updates_name_and_email(): void
    {
        $target = $this->createAdmin();
        $this->actingAsAdmin();

        $this->putJson("/api/v1/admin/users/{$target->id}", [
            'name' => 'Updated Name',
            'email' => 'updated@gcmc.sa',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'updated@gcmc.sa');
    }

    public function test_usr_04_email_unique_ignores_self(): void
    {
        $target = $this->createAdmin();
        $this->actingAsAdmin();

        $this->putJson("/api/v1/admin/users/{$target->id}", [
            'email' => $target->email,
        ])->assertOk();
    }

    /*
    |----------------------------------------------------------------------
    | USR-05 DELETE /api/v1/admin/users/{user}
    |----------------------------------------------------------------------
    */

    public function test_usr_05_deletes_a_user_who_then_cannot_log_in(): void
    {
        $target = $this->createConsultant(['email' => 'gone@gcmc.sa']);
        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/users/{$target->id}")->assertOk();

        $this->assertSoftDeleted('users', ['id' => $target->id]);

        $this->app['auth']->forgetGuards();
        $this->assertApiError(
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => 'gone@gcmc.sa',
                'password' => 'Password@123',
            ]),
            422,
            'INVALID_CREDENTIALS',
        );
    }

    public function test_usr_05_cannot_delete_self(): void
    {
        $admin = $this->actingAsAdmin();

        $this->assertApiError(
            $this->deleteJson("/api/v1/admin/users/{$admin->id}"),
            422,
            'CANNOT_DELETE_SELF',
        );
    }

    public function test_usr_05_the_last_active_admin_cannot_be_deleted(): void
    {
        $onlyAdmin = $this->createAdmin();

        // The actor is a staff member with the permission, not an "admin" role holder.
        $this->actingAsAdmin($this->createStaffWithPermissions(['delete-users']));

        $this->assertApiError(
            $this->deleteJson("/api/v1/admin/users/{$onlyAdmin->id}"),
            422,
            'LAST_ADMIN',
        );
    }

    /*
    |----------------------------------------------------------------------
    | USR-06 PUT /api/v1/admin/users/{user}/roles
    |----------------------------------------------------------------------
    */

    public function test_usr_06_assigns_two_roles(): void
    {
        $target = $this->createAdmin();

        $roleA = Role::create(['name' => 'supervisor', 'guard_name' => 'admin']);
        $roleA->syncPermissions(['view-bookings']);
        $roleB = Role::create(['name' => 'accountant', 'guard_name' => 'admin']);
        $roleB->syncPermissions(['view-payments']);

        $this->actingAsAdmin();

        $response = $this->putJson("/api/v1/admin/users/{$target->id}/roles", [
            'roles' => ['supervisor', 'accountant'],
        ]);

        $this->assertApiSuccess($response);
        $this->assertEqualsCanonicalizing(['supervisor', 'accountant'], $response->json('data.roles'));
        $this->assertEqualsCanonicalizing(
            ['view-bookings', 'view-payments'],
            $response->json('data.permissions'),
        );
    }

    public function test_usr_06_removing_admin_from_the_last_admin_returns_last_admin(): void
    {
        $onlyAdmin = $this->createAdmin();

        $this->actingAsAdmin($this->createStaffWithPermissions(['assign-roles']));

        $this->assertApiError(
            $this->putJson("/api/v1/admin/users/{$onlyAdmin->id}/roles", [
                'roles' => ['consultant'],
            ]),
            422,
            'LAST_ADMIN',
        );
    }

    public function test_usr_06_the_consultant_role_is_kept_for_consultants(): void
    {
        $consultant = $this->createConsultant();

        $role = Role::create(['name' => 'supervisor', 'guard_name' => 'admin']);

        $this->actingAsAdmin();

        $response = $this->putJson("/api/v1/admin/users/{$consultant->id}/roles", [
            'roles' => ['supervisor'],
        ]);

        $this->assertApiSuccess($response);
        $this->assertEqualsCanonicalizing(['consultant', 'supervisor'], $response->json('data.roles'));
    }

    /*
    |----------------------------------------------------------------------
    | USR-07 PATCH /api/v1/admin/users/{user}/status
    |----------------------------------------------------------------------
    */

    public function test_usr_07_deactivating_deletes_the_users_tokens(): void
    {
        $target = $this->createAdmin(['email' => 'target@gcmc.sa']);

        $token = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'target@gcmc.sa',
            'password' => 'Password@123',
        ])->json('data.token');

        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/users/{$target->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // The old token no longer works.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/auth/me')
            ->assertStatus(401);
    }

    public function test_usr_07_cannot_deactivate_self(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->patchJson("/api/v1/admin/users/{$admin->id}/status", ['is_active' => false]);

        $this->assertApiError($response, 422, 'CANNOT_DELETE_SELF')
            ->assertJsonPath('message', 'You cannot deactivate your own account.');
    }

    /*
    |----------------------------------------------------------------------
    | USR-08 POST /api/v1/admin/users/{user}/avatar
    |----------------------------------------------------------------------
    */

    public function test_usr_08_uploads_an_avatar_for_a_user(): void
    {
        $target = $this->createConsultant();
        $this->actingAsAdmin();

        $response = $this->postJson("/api/v1/admin/users/{$target->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $this->assertApiSuccess($response);
        $this->assertNotNull($target->fresh()->getFirstMedia('avatar'));
    }
}
