<?php

namespace Modules\Bookings\Tests\Feature\Client;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Bookings\Models\Booking;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Tests\TestCase;

class ClientBookingsTest extends TestCase
{
    protected string $url = '/api/v1/client/bookings';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
    }

    /*
    |----------------------------------------------------------------------
    | CLI-BKG-03 GET /api/v1/client/bookings
    |----------------------------------------------------------------------
    */

    public function test_cli_bkg_03_lists_only_the_clients_bookings_latest_first(): void
    {
        $client = $this->actingAsClient();

        Booking::factory()->create([
            'client_id' => $client->id,
            'starts_at' => '2026-09-25 10:00:00',
            'ends_at' => '2026-09-25 10:30:00',
        ]);
        Booking::factory()->create([
            'client_id' => $client->id,
            'starts_at' => '2026-09-22 10:00:00',
            'ends_at' => '2026-09-22 10:30:00',
        ]);
        Booking::factory()->create(); // another client's

        $response = $this->getJson($this->url);

        $this->assertPaginated($response);
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame('2026-09-25', $response->json('data.0.date'));
    }

    public function test_cli_bkg_03_filters_by_status_dates_and_upcoming(): void
    {
        $client = $this->actingAsClient();

        Booking::factory()->pending()->create([
            'client_id' => $client->id,
            'starts_at' => '2026-09-25 10:00:00',
            'ends_at' => '2026-09-25 10:30:00',
        ]);
        Booking::factory()->completed()->create(['client_id' => $client->id]);
        Booking::factory()->cancelled()->past()->create(['client_id' => $client->id]);

        $this->assertSame(1, $this->getJson($this->url.'?status=completed')->json('meta.total'));
        $this->assertSame(1, $this->getJson($this->url.'?date_from=2026-09-24&date_to=2026-09-26')->json('meta.total'));
        $this->assertSame(1, $this->getJson($this->url.'?upcoming=1')->json('meta.total'));
        $this->assertSame(3, $this->getJson($this->url)->json('meta.total'));
    }

    /*
    |----------------------------------------------------------------------
    | CLI-BKG-04 GET /api/v1/client/bookings/{booking}
    |----------------------------------------------------------------------
    */

    public function test_cli_bkg_04_shows_the_booking_with_the_meeting_link(): void
    {
        $client = $this->actingAsClient();
        $booking = Booking::factory()->pending()->future()->create([
            'client_id' => $client->id,
            'meeting_url' => 'https://meet.google.com/fak-abcd-efg',
            'meeting_status' => 'created',
            'meeting_provider' => 'fake',
        ]);

        $response = $this->getJson($this->url.'/'.$booking->id);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $booking->id)
            ->assertJsonPath('data.meeting.url', 'https://meet.google.com/fak-abcd-efg')
            ->assertJsonPath('data.can.cancel', true);
    }

    public function test_cli_bkg_04_another_clients_booking_is_a_404(): void
    {
        $this->actingAsClient();
        $booking = Booking::factory()->create();

        $this->getJson($this->url.'/'.$booking->id)->assertNotFound();
    }

    /*
    |----------------------------------------------------------------------
    | CLI-BKG-05 POST /api/v1/client/bookings/{booking}/cancel
    |----------------------------------------------------------------------
    */

    public function test_cli_bkg_05_cancels_a_pending_booking_more_than_24h_before(): void
    {
        $client = $this->actingAsClient();
        $package = Package::factory()->iron()->create();
        $booking = Booking::factory()->pending()->create([
            'client_id' => $client->id,
            'package_id' => $package->id,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
        ]);
        $subscription = ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'consultations_used' => 1,
        ]);
        $booking->forceFill(['client_subscription_id' => $subscription->id])->save();

        $response = $this->postJson($this->url.'/'.$booking->id.'/cancel', [
            'reason' => 'Plans changed',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Plans changed');

        // The consultation went back to the quota.
        $this->assertSame(0, $subscription->refresh()->consultations_used);
        $this->assertSame('client', $booking->refresh()->cancelled_by_type);
    }

    public function test_cli_bkg_05_less_than_24h_before_returns_422(): void
    {
        $client = $this->actingAsClient();
        $booking = Booking::factory()->pending()->create([
            'client_id' => $client->id,
            'starts_at' => now()->addHours(5),
            'ends_at' => now()->addHours(5)->addMinutes(30),
        ]);

        $this->assertApiError(
            $this->postJson($this->url.'/'.$booking->id.'/cancel', ['reason' => 'x']),
            422,
            'BOOKING_CANCEL_WINDOW_PASSED',
        );
    }

    public function test_cli_bkg_05_a_pending_payment_booking_can_always_be_cancelled(): void
    {
        $client = $this->actingAsClient();
        $booking = Booking::factory()->pendingPayment()->create([
            'client_id' => $client->id,
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(2)->addMinutes(30),
        ]);

        $this->assertApiSuccess($this->postJson($this->url.'/'.$booking->id.'/cancel'))
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cli_bkg_05_another_clients_booking_is_a_404(): void
    {
        $this->actingAsClient();
        $booking = Booking::factory()->pending()->future()->create();

        $this->postJson($this->url.'/'.$booking->id.'/cancel', ['reason' => 'x'])->assertNotFound();
    }
}
