<?php

namespace Modules\Clients\Tests\Feature;

use Modules\Clients\Models\ClientLocation;
use Tests\TestCase;

class ClientLocationsTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CLI-LOC-01 GET /api/v1/client/locations
    |----------------------------------------------------------------------
    */

    public function test_cli_loc_01_lists_locations_not_paginated_default_first(): void
    {
        $client = $this->actingAsClient();

        $second = ClientLocation::factory()->for($client)->create(['name' => 'Branch']);
        $third = ClientLocation::factory()->for($client)->create(['name' => 'Warehouse']);

        $response = $this->getJson('/api/v1/client/locations');

        $this->assertApiSuccess($response);
        $this->assertCount(3, $response->json('data'));
        $this->assertTrue($response->json('data.0.is_default'));
        $this->assertArrayNotHasKey('meta', $response->json());
    }

    public function test_cli_loc_01_only_lists_own_locations(): void
    {
        $client = $this->actingAsClient();
        $other = $this->createClient();

        $response = $this->getJson('/api/v1/client/locations');

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($client->locations()->first()->id, $response->json('data.0.id'));
    }

    /*
    |----------------------------------------------------------------------
    | CLI-LOC-02 POST /api/v1/client/locations
    |----------------------------------------------------------------------
    */

    public function test_cli_loc_02_first_location_is_the_default_automatically(): void
    {
        $client = $this->actingAsClient($this->createClient([], false));

        $response = $this->postJson('/api/v1/client/locations', [
            'name' => 'Headquarters',
            'city' => 'Riyadh',
            'address' => 'King Fahd Rd',
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.is_default', true);
    }

    public function test_cli_loc_02_setting_is_default_unsets_the_others(): void
    {
        $client = $this->actingAsClient();
        $first = $client->defaultLocation;

        $response = $this->postJson('/api/v1/client/locations', [
            'name' => 'Branch',
            'city' => 'Jeddah',
            'address' => 'Tahlia St',
            'is_default' => true,
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(1, $client->locations()->where('is_default', true)->count());
    }

    public function test_cli_loc_02_second_location_without_the_flag_is_not_default(): void
    {
        $client = $this->actingAsClient();

        $response = $this->postJson('/api/v1/client/locations', [
            'name' => 'Branch',
            'address' => 'Tahlia St',
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.is_default', false);
    }

    public function test_cli_loc_02_validation(): void
    {
        $this->actingAsClient();

        $this->assertApiError(
            $this->postJson('/api/v1/client/locations', [
                'name' => '',
                'address' => '',
                'latitude' => 95,
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    /*
    |----------------------------------------------------------------------
    | CLI-LOC-03/04/05 show, update, delete — scoped to the client (404)
    |----------------------------------------------------------------------
    */

    public function test_cli_loc_03_shows_an_own_location(): void
    {
        $client = $this->actingAsClient();
        $location = $client->defaultLocation;

        $this->getJson("/api/v1/client/locations/{$location->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $location->id);
    }

    public function test_cli_loc_03_another_clients_location_is_a_404(): void
    {
        $this->actingAsClient();
        $other = $this->createClient();

        $this->assertApiError(
            $this->getJson("/api/v1/client/locations/{$other->defaultLocation->id}"),
            404,
            'NOT_FOUND',
        );
    }

    public function test_cli_loc_04_updates_an_own_location(): void
    {
        $client = $this->actingAsClient();
        $location = $client->defaultLocation;

        $response = $this->putJson("/api/v1/client/locations/{$location->id}", [
            'name' => 'Main Office',
            'city' => 'Dammam',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.name', 'Main Office')
            ->assertJsonPath('data.city', 'Dammam')
            ->assertJsonPath('data.address', $location->address);
    }

    public function test_cli_loc_04_another_clients_location_is_a_404(): void
    {
        $this->actingAsClient();
        $other = $this->createClient();

        $this->assertApiError(
            $this->putJson("/api/v1/client/locations/{$other->defaultLocation->id}", ['name' => 'Hacked']),
            404,
            'NOT_FOUND',
        );
    }

    public function test_cli_loc_05_deletes_a_location(): void
    {
        $client = $this->actingAsClient();
        $extra = ClientLocation::factory()->for($client)->create();

        $this->deleteJson("/api/v1/client/locations/{$extra->id}")->assertOk();

        $this->assertSoftDeleted('client_locations', ['id' => $extra->id]);
    }

    public function test_cli_loc_05_deleting_the_default_promotes_the_newest_remaining(): void
    {
        $client = $this->actingAsClient();

        $older = ClientLocation::factory()->for($client)->create(['created_at' => now()->subDay()]);
        $newest = ClientLocation::factory()->for($client)->create();
        $default = $client->defaultLocation;

        $this->deleteJson("/api/v1/client/locations/{$default->id}")->assertOk();

        $this->assertTrue($newest->fresh()->is_default);
        $this->assertFalse($older->fresh()->is_default);
    }

    public function test_cli_loc_05_another_clients_location_is_a_404(): void
    {
        $this->actingAsClient();
        $other = $this->createClient();

        $this->assertApiError(
            $this->deleteJson("/api/v1/client/locations/{$other->defaultLocation->id}"),
            404,
            'NOT_FOUND',
        );

        $this->assertNotNull($other->defaultLocation->fresh());
    }

    /*
    |----------------------------------------------------------------------
    | CLI-LOC-06 PATCH /api/v1/client/locations/{location}/default
    |----------------------------------------------------------------------
    */

    public function test_cli_loc_06_sets_the_default_and_unsets_the_others(): void
    {
        $client = $this->actingAsClient();
        $old = $client->defaultLocation;
        $new = ClientLocation::factory()->for($client)->create();

        $response = $this->patchJson("/api/v1/client/locations/{$new->id}/default");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($old->fresh()->is_default);
        $this->assertSame(1, $client->locations()->where('is_default', true)->count());
    }

    public function test_cli_loc_routes_require_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/client/locations'), 401, 'UNAUTHENTICATED');
    }
}
