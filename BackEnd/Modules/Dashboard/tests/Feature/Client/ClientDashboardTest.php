<?php

namespace Modules\Dashboard\Tests\Feature\Client;

use Modules\Bookings\Models\Booking;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Reports\Models\Report;
use Tests\TestCase;

class ClientDashboardTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CLI-DSH-01 GET /api/v1/client/dashboard
    |----------------------------------------------------------------------
    */

    public function test_cli_dsh_01_returns_the_client_dashboard(): void
    {
        $client = $this->actingAsClient();

        // Next booking: the nearest future pending one.
        Booking::factory()->pending()->create([
            'client_id' => $client->id,
            'starts_at' => now()->addDays(5)->setTime(10, 0),
            'ends_at' => now()->addDays(5)->setTime(10, 30),
        ]);
        $next = Booking::factory()->pending()->create([
            'client_id' => $client->id,
            'starts_at' => now()->addDays(2)->setTime(10, 0),
            'ends_at' => now()->addDays(2)->setTime(10, 30),
        ]);
        Booking::factory()->completed()->create(['client_id' => $client->id]);
        Booking::factory()->cancelled()->past()->create(['client_id' => $client->id]);
        Booking::factory()->pending()->future()->create(); // another client's

        // Reports: two, one never downloaded.
        $read = Report::factory()->create(['client_id' => $client->id, 'first_downloaded_at' => now()]);
        Report::factory()->create(['client_id' => $client->id]);
        Report::factory()->create(); // another client's

        // Subscriptions: one active, one expired.
        ClientSubscription::factory()->create([
            'client_id' => $client->id,
            'package_id' => Package::factory()->create()->id,
            'status' => 'active',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
        ]);
        ClientSubscription::factory()->create([
            'client_id' => $client->id,
            'package_id' => Package::factory()->create()->id,
            'status' => 'expired',
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
        ]);

        $response = $this->getJson('/api/v1/client/dashboard');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.next_booking.id', $next->id)
            ->assertJsonPath('data.bookings.upcoming', 2)
            ->assertJsonPath('data.bookings.completed', 1)
            ->assertJsonPath('data.bookings.cancelled', 1)
            ->assertJsonPath('data.reports.total', 2)
            ->assertJsonPath('data.reports.unread', 1);

        $subscriptions = $response->json('data.active_subscriptions');
        $this->assertCount(1, $subscriptions);
        $this->assertSame('active', $subscriptions[0]['status']);
        $this->assertArrayHasKey('package', $subscriptions[0]);
    }

    public function test_cli_dsh_01_an_empty_dashboard_returns_nulls_and_zeros(): void
    {
        $this->actingAsClient();

        $response = $this->getJson('/api/v1/client/dashboard');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.next_booking', null)
            ->assertJsonPath('data.bookings.upcoming', 0)
            ->assertJsonPath('data.bookings.completed', 0)
            ->assertJsonPath('data.bookings.cancelled', 0)
            ->assertJsonPath('data.reports.total', 0)
            ->assertJsonPath('data.reports.unread', 0);

        $this->assertSame([], $response->json('data.active_subscriptions'));
    }

    public function test_cli_dsh_01_requires_a_client_token(): void
    {
        $this->getJson('/api/v1/client/dashboard')->assertUnauthorized();

        $this->actingAsAdmin(); // an admin token is not a client token
        $this->getJson('/api/v1/client/dashboard')->assertUnauthorized();
    }
}
