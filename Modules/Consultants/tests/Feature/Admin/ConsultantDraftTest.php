<?php

namespace Modules\Consultants\Tests\Feature\Admin;

use Tests\TestCase;

class ConsultantDraftTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CON-19 GET /api/v1/admin/consultants/trashed — Perm: view-consultants
    |----------------------------------------------------------------------
    */

    public function test_con_19_lists_drafted_consultants_only(): void
    {
        $this->actingAsAdmin();
        $drafted = $this->createConsultant(['name' => 'Drafted Consultant']);
        $live = $this->createConsultant(['name' => 'Live Consultant']);
        $draftedAdmin = $this->createAdmin(['name' => 'Drafted Admin']);
        $draftedAdmin->delete();

        $this->deleteJson("/api/v1/admin/consultants/{$drafted->id}")->assertOk();

        $response = $this->getJson('/api/v1/admin/consultants/trashed');

        $this->assertPaginated($response);
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($drafted->id, $response->json('data.0.id'));
        $this->assertNotNull($response->json('data.0.deleted_at'));

        // Drafted consultants stay out of the main list.
        $main = $this->getJson('/api/v1/admin/consultants');
        $this->assertSame([$live->id], collect($main->json('data'))->pluck('id')->all());
    }

    public function test_con_19_supports_search(): void
    {
        $this->actingAsAdmin();
        $drafted = $this->createConsultant(['name' => 'Unique Consultant Name']);
        $this->createConsultant(['name' => 'Other Consultant']);

        $this->deleteJson("/api/v1/admin/consultants/{$drafted->id}")->assertOk();

        $response = $this->getJson('/api/v1/admin/consultants/trashed?search=Unique Consultant');

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($drafted->id, $response->json('data.0.id'));
    }

    public function test_con_19_requires_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/consultants/trashed'), 401, 'UNAUTHENTICATED');
    }

    public function test_con_19_requires_view_consultants_permission(): void
    {
        $this->actingAsAdmin($this->createStaffWithPermissions(['view-users']));

        $this->assertApiError($this->getJson('/api/v1/admin/consultants/trashed'), 403, 'FORBIDDEN');
    }

    /*
    |----------------------------------------------------------------------
    | CON-20 POST /api/v1/admin/consultants/{id}/restore — Perm: delete-consultants
    |----------------------------------------------------------------------
    */

    public function test_con_20_restores_a_drafted_consultant(): void
    {
        $target = $this->createConsultant(['email' => 'back@gcmc.sa', 'password' => 'Password@123']);
        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/consultants/{$target->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $target->id]);

        $response = $this->postJson("/api/v1/admin/consultants/{$target->id}/restore");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.deleted_at', null)
            ->assertJsonPath('data.roles', ['consultant']);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);

        // Availability survives the draft.
        $this->assertSame(5, $target->refresh()->availabilities()->count());

        $this->app['auth']->forgetGuards();
        $this->assertApiSuccess($this->postJson('/api/v1/admin/auth/login', [
            'email' => 'back@gcmc.sa',
            'password' => 'Password@123',
        ]));
    }

    public function test_con_20_restoring_a_live_consultant_returns_not_drafted(): void
    {
        $live = $this->createConsultant();
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson("/api/v1/admin/consultants/{$live->id}/restore"),
            422,
            'NOT_DRAFTED',
        );
    }

    public function test_con_20_unknown_consultant_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError($this->postJson('/api/v1/admin/consultants/9999/restore'), 404, 'NOT_FOUND');
    }

    public function test_con_20_an_admin_id_is_not_a_consultant(): void
    {
        $admin = $this->createAdmin();
        $this->actingAsAdmin();

        $this->assertApiError($this->postJson("/api/v1/admin/consultants/{$admin->id}/restore"), 404, 'NOT_FOUND');
    }

    public function test_con_20_requires_delete_consultants_permission(): void
    {
        $drafted = $this->createConsultant();
        $this->actingAsAdmin($this->createStaffWithPermissions(['view-consultants']));

        $drafted->delete();

        $this->assertApiError(
            $this->postJson("/api/v1/admin/consultants/{$drafted->id}/restore"),
            403,
            'FORBIDDEN',
        );
    }

    public function test_con_20_requires_authentication(): void
    {
        $this->assertApiError($this->postJson('/api/v1/admin/consultants/1/restore'), 401, 'UNAUTHENTICATED');
    }
}
