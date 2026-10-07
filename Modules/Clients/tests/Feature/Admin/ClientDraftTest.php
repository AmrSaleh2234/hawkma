<?php

namespace Modules\Clients\Tests\Feature\Admin;

use Tests\TestCase;

class ClientDraftTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | ADM-CL-09 GET /api/v1/admin/clients/trashed — Perm: view-clients
    |----------------------------------------------------------------------
    */

    public function test_adm_cl_09_lists_drafted_clients_with_deleted_at(): void
    {
        $this->actingAsAdmin();
        $drafted = $this->createClient(['company_name' => 'Drafted Company']);
        $this->createClient(['company_name' => 'Live Company']);

        $this->deleteJson("/api/v1/admin/clients/{$drafted->id}")->assertOk();

        $response = $this->getJson('/api/v1/admin/clients/trashed');

        $this->assertPaginated($response);
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($drafted->id, $response->json('data.0.id'));
        $this->assertNotNull($response->json('data.0.deleted_at'));

        // Drafted clients stay out of the main list.
        $main = $this->getJson('/api/v1/admin/clients');
        $this->assertSame(1, $main->json('meta.total'));
    }

    public function test_adm_cl_09_supports_search(): void
    {
        $this->actingAsAdmin();
        $drafted = $this->createClient(['company_name' => 'Unique Company Name']);
        $this->createClient(['company_name' => 'Other Company']);

        $this->deleteJson("/api/v1/admin/clients/{$drafted->id}")->assertOk();

        $response = $this->getJson('/api/v1/admin/clients/trashed?search=Unique Company');

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($drafted->id, $response->json('data.0.id'));
    }

    public function test_adm_cl_09_requires_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/clients/trashed'), 401, 'UNAUTHENTICATED');
    }

    public function test_adm_cl_09_requires_view_clients_permission(): void
    {
        $this->actingAsAdmin($this->createStaffWithPermissions(['view-users']));

        $this->assertApiError($this->getJson('/api/v1/admin/clients/trashed'), 403, 'FORBIDDEN');
    }

    /*
    |----------------------------------------------------------------------
    | ADM-CL-10 POST /api/v1/admin/clients/{id}/restore — Perm: delete-clients
    |----------------------------------------------------------------------
    */

    public function test_adm_cl_10_restores_a_drafted_client_who_can_log_in_again(): void
    {
        $target = $this->createClient(['email' => 'back@gcmc.sa']);
        $actor = $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/clients/{$target->id}")->assertOk();
        $this->assertSoftDeleted('clients', ['id' => $target->id]);

        // The drafted client cannot log in while drafted.
        $this->app['auth']->forgetGuards();
        $this->assertApiError($this->postJson('/api/v1/client/auth/login', [
            'email' => 'back@gcmc.sa',
            'password' => 'Password@123',
        ]), 422, 'INVALID_CREDENTIALS');

        $this->actingAsAdmin($actor);
        $response = $this->postJson("/api/v1/admin/clients/{$target->id}/restore");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.deleted_at', null);

        $this->assertDatabaseHas('clients', ['id' => $target->id, 'deleted_at' => null]);

        // Locations survive the draft.
        $this->assertSame(1, $target->refresh()->locations()->count());

        $this->app['auth']->forgetGuards();
        $this->assertApiSuccess($this->postJson('/api/v1/client/auth/login', [
            'email' => 'back@gcmc.sa',
            'password' => 'Password@123',
        ]));
    }

    public function test_adm_cl_10_restoring_a_live_client_returns_not_drafted(): void
    {
        $live = $this->createClient();
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson("/api/v1/admin/clients/{$live->id}/restore"),
            422,
            'NOT_DRAFTED',
        );
    }

    public function test_adm_cl_10_unknown_client_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError($this->postJson('/api/v1/admin/clients/9999/restore'), 404, 'NOT_FOUND');
    }

    public function test_adm_cl_10_requires_delete_clients_permission(): void
    {
        $drafted = $this->createClient();
        $this->actingAsAdmin($this->createStaffWithPermissions(['view-clients']));

        $drafted->delete();

        $this->assertApiError(
            $this->postJson("/api/v1/admin/clients/{$drafted->id}/restore"),
            403,
            'FORBIDDEN',
        );
    }

    public function test_adm_cl_10_requires_authentication(): void
    {
        $this->assertApiError($this->postJson('/api/v1/admin/clients/1/restore'), 401, 'UNAUTHENTICATED');
    }
}
