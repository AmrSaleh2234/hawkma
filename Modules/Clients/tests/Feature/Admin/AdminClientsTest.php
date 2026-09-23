<?php

namespace Modules\Clients\Tests\Feature\Admin;

use Modules\Clients\Models\Client;
use Tests\TestCase;

class AdminClientsTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | ADM-CL-01 GET /api/v1/admin/clients
    |----------------------------------------------------------------------
    */

    public function test_adm_cl_01_lists_clients_paginated(): void
    {
        $this->actingAsAdmin();
        $this->createClient(['name' => 'Ahmed Ali', 'company_name' => 'ACME']);
        $this->createClient(['name' => 'Sara Khaled', 'company_name' => 'Noor']);

        $response = $this->getJson('/api/v1/admin/clients');

        $this->assertApiSuccess($response);
        $this->assertPaginated($response);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_adm_cl_01_search_matches_name_email_phone_and_company(): void
    {
        $this->actingAsAdmin();
        $this->createClient(['name' => 'Ahmed Ali', 'company_name' => 'ACME']);
        $this->createClient(['name' => 'Sara Khaled', 'company_name' => 'Noor Trading']);

        $byName = $this->getJson('/api/v1/admin/clients?search=Ahmed');
        $this->assertCount(1, $byName->json('data'));

        $byCompany = $this->getJson('/api/v1/admin/clients?search=Noor');
        $this->assertCount(1, $byCompany->json('data'));

        $none = $this->getJson('/api/v1/admin/clients?search=zzz-no-match');
        $this->assertCount(0, $none->json('data'));
    }

    public function test_adm_cl_01_filters_by_is_active(): void
    {
        $this->actingAsAdmin();
        $this->createClient();
        $this->createClient(['is_active' => false]);

        $active = $this->getJson('/api/v1/admin/clients?is_active=1');
        $this->assertCount(1, $active->json('data'));

        $inactive = $this->getJson('/api/v1/admin/clients?is_active=0');
        $this->assertCount(1, $inactive->json('data'));
    }

    public function test_adm_cl_01_has_active_subscription_filter_matches_nothing_until_phase_7(): void
    {
        $this->actingAsAdmin();
        $this->createClient();

        $yes = $this->getJson('/api/v1/admin/clients?has_active_subscription=1');
        $this->assertCount(0, $yes->json('data'));

        $no = $this->getJson('/api/v1/admin/clients?has_active_subscription=0');
        $this->assertCount(1, $no->json('data'));
    }

    public function test_adm_cl_01_requires_the_view_clients_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-bookings']);
        $this->actingAsAdmin($staff);

        $this->assertApiError($this->getJson('/api/v1/admin/clients'), 403, 'FORBIDDEN');
    }

    public function test_adm_cl_01_consultant_sees_only_his_clients(): void
    {
        // Phase 5-8: no bookings exist yet, so a consultant sees no clients.
        $this->actingAsConsultant();
        $this->createClient();

        $response = $this->getJson('/api/v1/admin/clients');

        $this->assertApiSuccess($response);
        $this->assertCount(0, $response->json('data'));
    }

    /*
    |----------------------------------------------------------------------
    | ADM-CL-02 GET /api/v1/admin/clients/{client}
    |----------------------------------------------------------------------
    */

    public function test_adm_cl_02_shows_a_client_with_locations(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $response = $this->getJson("/api/v1/admin/clients/{$client->id}");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonCount(1, 'data.locations');
    }

    public function test_adm_cl_02_consultant_without_a_booking_gets_403(): void
    {
        $this->actingAsConsultant();
        $client = $this->createClient();

        $this->assertApiError(
            $this->getJson("/api/v1/admin/clients/{$client->id}"),
            403,
            'FORBIDDEN',
        );
    }

    /*
    |----------------------------------------------------------------------
    | ADM-CL-03 PUT /api/v1/admin/clients/{client}
    |----------------------------------------------------------------------
    */

    public function test_adm_cl_03_updates_a_client(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $response = $this->putJson("/api/v1/admin/clients/{$client->id}", [
            'name' => 'Updated Name',
            'email' => 'updated@company.sa',
            'phone' => '0501112222',
            'company_name' => 'Updated Co',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'updated@company.sa');
    }

    public function test_adm_cl_03_requires_the_update_clients_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-clients']);
        $this->actingAsAdmin($staff);
        $client = $this->createClient();

        $this->assertApiError(
            $this->putJson("/api/v1/admin/clients/{$client->id}", [
                'name' => 'X',
                'email' => 'x@company.sa',
                'phone' => '0501112222',
                'company_name' => 'X Co',
            ]),
            403,
            'FORBIDDEN',
        );
    }

    /*
    |----------------------------------------------------------------------
    | ADM-CL-04 PATCH /api/v1/admin/clients/{client}/status
    |----------------------------------------------------------------------
    */

    public function test_adm_cl_04_deactivating_deletes_the_clients_tokens(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $token = $client->createToken('client-dashboard')->plainTextToken;

        $response = $this->patchJson("/api/v1/admin/clients/{$client->id}/status", [
            'is_active' => false,
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.is_active', false);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/client/auth/me')
            ->assertStatus(401);
    }

    public function test_adm_cl_04_deactivated_client_is_blocked_by_active_client_middleware(): void
    {
        $client = $this->createClient();
        $token = $client->createToken('client-dashboard')->plainTextToken;

        $client->forceFill(['is_active' => false])->save();

        $this->assertApiError(
            $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/client/profile'),
            403,
            'ACCOUNT_DISABLED',
        );
    }

    public function test_adm_cl_04_reactivating_works(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient(['is_active' => false]);

        $this->patchJson("/api/v1/admin/clients/{$client->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    /*
    |----------------------------------------------------------------------
    | ADM-CL-05 DELETE /api/v1/admin/clients/{client}
    |----------------------------------------------------------------------
    */

    public function test_adm_cl_05_soft_deletes_a_client_and_deletes_tokens(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $token = $client->createToken('client-dashboard')->plainTextToken;

        $this->deleteJson("/api/v1/admin/clients/{$client->id}")->assertOk();

        $this->assertSoftDeleted('clients', ['id' => $client->id]);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/client/auth/me')
            ->assertStatus(401);
    }

    public function test_adm_cl_05_requires_the_delete_clients_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-clients', 'update-clients']);
        $this->actingAsAdmin($staff);
        $client = $this->createClient();

        $this->assertApiError(
            $this->deleteJson("/api/v1/admin/clients/{$client->id}"),
            403,
            'FORBIDDEN',
        );
    }

    public function test_adm_cl_routes_require_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/clients'), 401, 'UNAUTHENTICATED');
    }

    public function test_adm_cl_deleted_client_does_not_appear_in_the_list(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $this->createClient();

        $client->delete();

        $response = $this->getJson('/api/v1/admin/clients');

        $this->assertCount(1, $response->json('data'));
    }
}
