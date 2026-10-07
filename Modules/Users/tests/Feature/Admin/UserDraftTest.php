<?php

namespace Modules\Users\Tests\Feature\Admin;

use Tests\TestCase;

class UserDraftTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | USR-09 GET /api/v1/admin/users/trashed — Perm: view-users
    |----------------------------------------------------------------------
    */

    public function test_usr_09_lists_drafted_users_with_deleted_at(): void
    {
        $this->actingAsAdmin();
        $drafted = $this->createConsultant(['email' => 'drafted@gcmc.sa']);
        $live = $this->createAdmin(['email' => 'live@gcmc.sa']);

        $this->deleteJson("/api/v1/admin/users/{$drafted->id}")->assertOk();

        $response = $this->getJson('/api/v1/admin/users/trashed');

        $this->assertPaginated($response);
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($drafted->id, $response->json('data.0.id'));
        $this->assertNotNull($response->json('data.0.deleted_at'));
        $this->assertSame(['consultant'], $response->json('data.0.roles'));
    }

    public function test_usr_09_hides_drafted_users_from_the_main_list(): void
    {
        $actor = $this->actingAsAdmin();
        $drafted = $this->createConsultant(['email' => 'drafted@gcmc.sa']);

        $this->deleteJson("/api/v1/admin/users/{$drafted->id}")->assertOk();

        $response = $this->getJson('/api/v1/admin/users');

        $this->assertSame(1, $response->json('meta.total')); // only the actor
        $this->assertSame($actor->id, $response->json('data.0.id'));
    }

    public function test_usr_09_filters_by_search_and_type(): void
    {
        $this->actingAsAdmin();
        $draftedConsultant = $this->createConsultant(['name' => 'Unique Consultant Name']);
        $draftedAdmin = $this->createAdmin(['name' => 'Other Admin']);

        $this->deleteJson("/api/v1/admin/users/{$draftedConsultant->id}")->assertOk();
        $this->deleteJson("/api/v1/admin/users/{$draftedAdmin->id}")->assertOk();

        $bySearch = $this->getJson('/api/v1/admin/users/trashed?search=Unique Consultant');
        $this->assertSame(1, $bySearch->json('meta.total'));
        $this->assertSame($draftedConsultant->id, $bySearch->json('data.0.id'));

        $byType = $this->getJson('/api/v1/admin/users/trashed?type=consultant');
        $this->assertSame(1, $byType->json('meta.total'));
        $this->assertSame($draftedConsultant->id, $byType->json('data.0.id'));
    }

    public function test_usr_09_requires_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/users/trashed'), 401, 'UNAUTHENTICATED');
    }

    public function test_usr_09_requires_view_users_permission(): void
    {
        $this->actingAsAdmin($this->createStaffWithPermissions(['view-consultants']));

        $this->assertApiError($this->getJson('/api/v1/admin/users/trashed'), 403, 'FORBIDDEN');
    }

    /*
    |----------------------------------------------------------------------
    | USR-10 POST /api/v1/admin/users/{id}/restore — Perm: delete-users
    |----------------------------------------------------------------------
    */

    public function test_usr_10_restores_a_drafted_user_who_can_log_in_again(): void
    {
        $target = $this->createConsultant(['email' => 'back@gcmc.sa', 'password' => 'Password@123']);
        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/users/{$target->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $target->id]);

        $response = $this->postJson("/api/v1/admin/users/{$target->id}/restore");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.deleted_at', null)
            ->assertJsonPath('data.roles', ['consultant']);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);

        $this->app['auth']->forgetGuards();
        $this->assertApiSuccess($this->postJson('/api/v1/admin/auth/login', [
            'email' => 'back@gcmc.sa',
            'password' => 'Password@123',
        ]));
    }

    public function test_usr_10_restoring_a_live_user_returns_not_drafted(): void
    {
        $live = $this->createAdmin();
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson("/api/v1/admin/users/{$live->id}/restore"),
            422,
            'NOT_DRAFTED',
        );
    }

    public function test_usr_10_unknown_user_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError($this->postJson('/api/v1/admin/users/9999/restore'), 404, 'NOT_FOUND');
    }

    public function test_usr_10_requires_delete_users_permission(): void
    {
        $drafted = $this->createConsultant();
        $this->actingAsAdmin($this->createStaffWithPermissions(['view-users']));

        $drafted->delete();

        $this->assertApiError(
            $this->postJson("/api/v1/admin/users/{$drafted->id}/restore"),
            403,
            'FORBIDDEN',
        );
    }

    public function test_usr_10_requires_authentication(): void
    {
        $this->assertApiError($this->postJson('/api/v1/admin/users/1/restore'), 401, 'UNAUTHENTICATED');
    }
}
