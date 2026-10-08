<?php

namespace Modules\Bookings\Tests\Feature\Client;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Bookings\Jobs\CreateMeetingJob;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Payments\Models\Payment;
use Modules\Users\Models\User;
use Tests\TestCase;

/**
 * CLI-BKG-06 POST /api/v1/client/subscriptions/{subscription}/bookings —
 * booking the next meeting inside an already-purchased package consumes one
 * consultation from the subscription quota, with no payment involved.
 */
class BookFromSubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
    }

    /**
     * A valid payload for Monday 2026-09-21 (inside the default Sun–Thu
     * 09:00–17:00 availability).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(Client $client, User $consultant, array $overrides = []): array
    {
        return array_merge([
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'date' => '2026-09-21',
            'time' => '10:00',
        ], $overrides);
    }

    public function test_the_client_can_book_a_meeting_from_an_active_subscription(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->silver()->create(); // 5 consultations

        // The first booking already paid for the package.
        $subscription = ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'consultations_used' => 1,
        ]);

        $this->postJson("/api/v1/client/subscriptions/{$subscription->id}/bookings",
            $this->payload($client, $consultant))
            ->assertCreated()
            ->assertJsonPath('data.booking.status', 'pending')
            ->assertJsonPath('data.booking.amount', 0)
            ->assertJsonPath('data.booking.payment_status', 'not_required')
            ->assertJsonPath('data.booking.package.name_en', $package->name_en)
            ->assertJsonPath('data.subscription.id', $subscription->id)
            ->assertJsonPath('data.subscription.consultations_used', 2)
            ->assertJsonPath('data.subscription.consultations_remaining', 3);

        $booking = Booking::sole();
        $this->assertSame($subscription->id, $booking->client_subscription_id);
        $this->assertSame($package->id, $booking->package_id);
        $this->assertSame(0, Payment::count());

        Queue::assertPushed(CreateMeetingJob::class);
    }

    public function test_another_clients_subscription_returns_404(): void
    {
        $this->actingAsClient();
        $consultant = $this->createConsultant();
        $otherClient = Client::factory()->create();
        $subscription = ClientSubscription::factory()->forPackage(Package::factory()->create())
            ->create(['client_id' => $otherClient->id]);

        $this->postJson("/api/v1/client/subscriptions/{$subscription->id}/bookings",
            $this->payload($this->actingAsClient(), $consultant))
            ->assertNotFound();

        $this->assertSame(0, Booking::count());
    }

    public function test_an_exhausted_subscription_is_rejected(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $subscription = ClientSubscription::factory()->forPackage(Package::factory()->iron()->create())
            ->usedUp()
            ->create(['client_id' => $client->id]);

        $this->assertApiError(
            $this->postJson("/api/v1/client/subscriptions/{$subscription->id}/bookings",
                $this->payload($client, $consultant)),
            422,
            'SUBSCRIPTION_EXHAUSTED',
        );

        $this->assertSame(0, Booking::count());
    }

    public function test_an_expired_or_cancelled_subscription_is_rejected(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->create();

        foreach ([
            ClientSubscription::factory()->forPackage($package)->expired()->create(['client_id' => $client->id]),
            ClientSubscription::factory()->forPackage($package)->cancelled()->create(['client_id' => $client->id]),
        ] as $subscription) {
            $this->assertApiError(
                $this->postJson("/api/v1/client/subscriptions/{$subscription->id}/bookings",
                    $this->payload($client, $consultant)),
                422,
                'SUBSCRIPTION_INACTIVE',
            );
        }

        $this->assertSame(0, Booking::count());
    }

    public function test_a_taken_slot_is_rejected(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $subscription = ClientSubscription::factory()->forPackage(Package::factory()->silver()->create())
            ->create(['client_id' => $client->id]);

        $this->postJson("/api/v1/client/subscriptions/{$subscription->id}/bookings",
            $this->payload($client, $consultant))->assertCreated();

        $this->assertApiError(
            $this->postJson("/api/v1/client/subscriptions/{$subscription->id}/bookings",
                $this->payload($client, $consultant)),
            409,
            'SLOT_NOT_AVAILABLE',
        );

        // The failed attempt did not consume a consultation.
        $this->assertSame(1, $subscription->refresh()->consultations_used);
        $this->assertSame(1, Booking::count());
    }
}
