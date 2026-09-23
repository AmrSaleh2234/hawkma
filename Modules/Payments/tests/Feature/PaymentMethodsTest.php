<?php

namespace Modules\Payments\Tests\Feature;

use Modules\Payments\Models\PaymentMethod;
use Tests\TestCase;

class PaymentMethodsTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CLI-PM-01 GET /api/v1/client/payment-methods
    |----------------------------------------------------------------------
    */

    public function test_cli_pm_01_lists_the_clients_cards_default_first_not_paginated(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        PaymentMethod::factory()->create(['client_id' => $client->id, 'last_four' => '1111']);
        PaymentMethod::factory()->default()->create(['client_id' => $client->id, 'last_four' => '9999']);
        PaymentMethod::factory()->create(); // another client's

        $response = $this->getJson('/api/v1/client/payment-methods');

        $this->assertApiSuccess($response);
        $this->assertIsArray($response->json('data'));
        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertCount(2, $response->json('data'));
        $this->assertSame('9999', $response->json('data.0.last_four'));
        $this->assertTrue($response->json('data.0.is_default'));
    }

    /*
    |----------------------------------------------------------------------
    | CLI-PM-02 POST /api/v1/client/payment-methods
    |----------------------------------------------------------------------
    */

    public function test_cli_pm_02_adds_a_card_from_a_token_without_leaking_it(): void
    {
        $this->actingAsClient();

        $response = $this->postJson('/api/v1/client/payment-methods', [
            'token' => 'tok_fake_success',
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.brand', 'visa')
            ->assertJsonPath('data.last_four', '4242')
            ->assertJsonPath('data.display', 'Visa •••• 4242')
            ->assertJsonPath('data.holder_name', 'Test Card')
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.is_expired', false);

        $this->assertArrayNotHasKey('gateway_token', $response->json('data'));
        $this->assertDatabaseHas('payment_methods', [
            'gateway' => 'fake',
            'gateway_token' => 'tok_fake_success',
            'brand' => 'visa',
            'last_four' => '4242',
        ]);
    }

    public function test_cli_pm_02_a_duplicate_token_returns_the_existing_card(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);

        $first = $this->postJson('/api/v1/client/payment-methods', ['token' => 'tok_fake_success']);
        $second = $this->postJson('/api/v1/client/payment-methods', ['token' => 'tok_fake_success']);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, $client->paymentMethods()->count());
    }

    public function test_cli_pm_02_is_default_unsets_the_others(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        $existing = PaymentMethod::factory()->default()->create(['client_id' => $client->id]);

        $response = $this->postJson('/api/v1/client/payment-methods', [
            'token' => 'tok_fake_ok_new',
            'is_default' => true,
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.is_default', true);
        $this->assertFalse($existing->refresh()->is_default);
    }

    public function test_cli_pm_02_requires_a_token(): void
    {
        $this->actingAsClient();

        $this->assertApiError(
            $this->postJson('/api/v1/client/payment-methods', []),
            422,
            'VALIDATION_ERROR',
        );
    }

    /*
    |----------------------------------------------------------------------
    | CLI-PM-03 DELETE /api/v1/client/payment-methods/{paymentMethod}
    |----------------------------------------------------------------------
    */

    public function test_cli_pm_03_soft_deletes_the_card(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        $method = PaymentMethod::factory()->create(['client_id' => $client->id]);

        $this->deleteJson("/api/v1/client/payment-methods/{$method->id}")->assertOk();

        $this->assertSoftDeleted('payment_methods', ['id' => $method->id]);
    }

    public function test_cli_pm_03_another_clients_card_is_a_404(): void
    {
        $this->actingAsClient();
        $other = PaymentMethod::factory()->create();

        $this->assertApiError(
            $this->deleteJson("/api/v1/client/payment-methods/{$other->id}"),
            404,
            'NOT_FOUND',
        );
    }

    /*
    |----------------------------------------------------------------------
    | CLI-PM-04 PATCH /api/v1/client/payment-methods/{paymentMethod}/default
    |----------------------------------------------------------------------
    */

    public function test_cli_pm_04_sets_the_default_and_unsets_the_previous(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        $old = PaymentMethod::factory()->default()->create(['client_id' => $client->id]);
        $new = PaymentMethod::factory()->create(['client_id' => $client->id]);

        $response = $this->patchJson("/api/v1/client/payment-methods/{$new->id}/default");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.is_default', true);
        $this->assertFalse($old->refresh()->is_default);
    }

    public function test_cli_pm_04_another_clients_card_is_a_404(): void
    {
        $this->actingAsClient();
        $other = PaymentMethod::factory()->create();

        $this->assertApiError(
            $this->patchJson("/api/v1/client/payment-methods/{$other->id}/default"),
            404,
            'NOT_FOUND',
        );
    }

    public function test_cli_pm_routes_require_a_client_token(): void
    {
        $this->assertApiError($this->getJson('/api/v1/client/payment-methods'), 401, 'UNAUTHENTICATED');
    }

    public function test_cli_pm_routes_reject_an_admin_token(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError($this->getJson('/api/v1/client/payment-methods'), 401, 'UNAUTHENTICATED');
    }
}
