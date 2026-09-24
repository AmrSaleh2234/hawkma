<?php

namespace Modules\Bookings\Tests\Feature\Client;

use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Tests\TestCase;

class QuoteBookingTest extends TestCase
{
    protected string $url = '/api/v1/client/bookings/quote';

    public function test_cli_bkg_01_without_a_subscription_requires_payment_at_the_package_price(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        $package = Package::factory()->iron()->create(); // 190000 halalas

        $response = $this->postJson($this->url, ['package_id' => $package->id]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.package.id', $package->id)
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.amount', 190000)
            ->assertJsonPath('data.amount_formatted', '1,900.00 SAR')
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.subscription', null)
            ->assertJsonPath('data.slot_available', null);
    }

    public function test_cli_bkg_01_an_active_subscription_with_quota_makes_it_free(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        $package = Package::factory()->iron()->create();
        $subscription = ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'consultations_used' => 1,
        ]);

        $response = $this->postJson($this->url, ['package_id' => $package->id]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.requires_payment', false)
            ->assertJsonPath('data.amount', 0)
            ->assertJsonPath('data.amount_formatted', '0.00 SAR')
            ->assertJsonPath('data.subscription.id', $subscription->id);
    }

    public function test_cli_bkg_01_a_used_up_subscription_requires_payment_again(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        $package = Package::factory()->iron()->create(); // limit 2
        ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'consultations_used' => 2,
        ]);

        $response = $this->postJson($this->url, ['package_id' => $package->id]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.amount', 190000)
            ->assertJsonPath('data.subscription', null);
    }

    public function test_cli_bkg_01_an_unlimited_subscription_is_always_free_while_active(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        $package = Package::factory()->gold()->create(); // unlimited
        ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'consultations_limit' => null,
            'consultations_used' => 40,
        ]);

        $response = $this->postJson($this->url, ['package_id' => $package->id]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.requires_payment', false)
            ->assertJsonPath('data.amount', 0);
    }

    public function test_cli_bkg_01_slot_availability_is_checked_when_consultant_date_and_time_are_sent(): void
    {
        $client = $this->createClient();
        $this->actingAsClient($client);
        $package = Package::factory()->iron()->create();
        $consultant = $this->createConsultant(); // Sun–Thu 09:00–17:00

        // Monday 2026-09-21 10:00 is inside the default availability.
        $available = $this->postJson($this->url, [
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
        ]);

        $this->assertApiSuccess($available)
            ->assertJsonPath('data.slot_available', true);

        // 20:00 is outside the default availability.
        $unavailable = $this->postJson($this->url, [
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '20:00',
        ]);

        $this->assertApiSuccess($unavailable)
            ->assertJsonPath('data.slot_available', false);
    }

    public function test_cli_bkg_01_requires_a_valid_package(): void
    {
        $this->actingAsClient();

        $this->postJson($this->url, [])->assertUnprocessable();
        $this->postJson($this->url, ['package_id' => 99999])->assertUnprocessable();
    }

    public function test_cli_bkg_01_requires_a_client_token(): void
    {
        $package = Package::factory()->iron()->create();

        $this->postJson($this->url, ['package_id' => $package->id])->assertUnauthorized();
    }
}
