<?php

namespace Modules\Packages\Tests\Feature;

use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Tests\TestCase;

class ClientSubscriptionsTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CLI-SUB-01 GET /api/v1/client/subscriptions
    |----------------------------------------------------------------------
    */

    public function test_cli_sub_01_lists_the_clients_subscriptions_paginated(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        $package = Package::factory()->iron()->create();
        ClientSubscription::factory()->forPackage($package)->create(['client_id' => $client->id]);
        ClientSubscription::factory()->forPackage($package)->expired()->create(['client_id' => $client->id]);

        // Another client's subscription must not leak in.
        ClientSubscription::factory()->create();

        $response = $this->getJson('/api/v1/client/subscriptions');

        $this->assertApiSuccess($response);
        $this->assertPaginated($response);
        $this->assertCount(2, $response->json('data'));
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'package' => ['id', 'slug', 'name', 'name_ar', 'name_en'],
                    'status',
                    'starts_at',
                    'ends_at',
                    'consultations_limit',
                    'consultations_used',
                    'consultations_remaining',
                    'is_unlimited',
                    'price_paid',
                    'price_paid_formatted',
                ],
            ],
        ]);
        $this->assertSame('iron', $response->json('data.0.package.slug'));
    }

    public function test_cli_sub_01_filters_by_status(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        ClientSubscription::factory()->create(['client_id' => $client->id]);
        ClientSubscription::factory()->expired()->create(['client_id' => $client->id]);

        $active = $this->getJson('/api/v1/client/subscriptions?status=active');
        $this->assertCount(1, $active->json('data'));
        $this->assertSame('active', $active->json('data.0.status'));

        $expired = $this->getJson('/api/v1/client/subscriptions?status=expired');
        $this->assertCount(1, $expired->json('data'));
        $this->assertSame('expired', $expired->json('data.0.status'));
    }

    public function test_cli_sub_01_rejects_an_invalid_status(): void
    {
        $this->actingAsClient();

        $this->assertApiError(
            $this->getJson('/api/v1/client/subscriptions?status=bogus'),
            422,
            'VALIDATION_ERROR',
        );
    }

    /*
    |----------------------------------------------------------------------
    | CLI-SUB-02 GET /api/v1/client/subscriptions/active
    |----------------------------------------------------------------------
    */

    public function test_cli_sub_02_returns_only_the_active_subscriptions_not_paginated(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        $iron = Package::factory()->iron()->create();
        ClientSubscription::factory()->forPackage($iron)->create([
            'client_id' => $client->id,
            'consultations_used' => 1,
        ]);
        ClientSubscription::factory()->expired()->create(['client_id' => $client->id]);
        ClientSubscription::factory()->cancelled()->create(['client_id' => $client->id]);

        $response = $this->getJson('/api/v1/client/subscriptions/active');

        $this->assertApiSuccess($response);
        $this->assertIsArray($response->json('data'));
        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('active', $response->json('data.0.status'));
        $this->assertSame(2, $response->json('data.0.consultations_limit'));
        $this->assertSame(1, $response->json('data.0.consultations_used'));
        $this->assertSame(1, $response->json('data.0.consultations_remaining'));
        $this->assertFalse($response->json('data.0.is_unlimited'));
    }

    public function test_cli_sub_02_unlimited_subscription_has_null_remaining(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        $gold = Package::factory()->gold()->create();
        ClientSubscription::factory()->forPackage($gold)->create(['client_id' => $client->id]);

        $response = $this->getJson('/api/v1/client/subscriptions/active');

        $this->assertApiSuccess($response);
        $this->assertTrue($response->json('data.0.is_unlimited'));
        $this->assertNull($response->json('data.0.consultations_remaining'));
    }

    public function test_cli_sub_routes_require_a_client_token(): void
    {
        $this->assertApiError($this->getJson('/api/v1/client/subscriptions'), 401, 'UNAUTHENTICATED');
        $this->assertApiError($this->getJson('/api/v1/client/subscriptions/active'), 401, 'UNAUTHENTICATED');
    }

    public function test_cli_sub_routes_reject_an_admin_token(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError($this->getJson('/api/v1/client/subscriptions'), 401, 'UNAUTHENTICATED');
    }
}
