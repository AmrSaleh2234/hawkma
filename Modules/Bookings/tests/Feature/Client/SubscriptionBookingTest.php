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
 * Booking more consultations inside an already-purchased package (CLI-BKG-01
 * and CLI-BKG-02): the remaining quota keeps applying even after the package
 * is updated, deactivated, or drafted, and existing records keep showing the
 * purchase-time state via package_snapshot.
 */
class SubscriptionBookingTest extends TestCase
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
    protected function payload(Client $client, Package $package, User $consultant, array $overrides = []): array
    {
        return array_merge([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'date' => '2026-09-21',
            'time' => '10:00',
        ], $overrides);
    }

    /*
    |----------------------------------------------------------------------
    | More consultations on the same package
    |----------------------------------------------------------------------
    */

    public function test_the_client_can_book_every_consultation_in_the_package_quota(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create(); // 2 consultations

        // First consultation: pays the package and activates the subscription.
        $this->postJson('/api/v1/client/bookings', $this->payload($client, $package, $consultant, [
            'card_token' => 'tok_fake_success',
        ]))->assertCreated()->assertJsonPath('data.booking.status', 'pending');

        $subscription = ClientSubscription::sole();
        $this->assertSame(1, $subscription->consultations_used);

        // Second consultation in the SAME package: free, no payment info needed.
        $this->postJson('/api/v1/client/bookings', $this->payload($client, $package, $consultant, [
            'time' => '11:00',
        ]))->assertCreated()
            ->assertJsonPath('data.booking.status', 'pending')
            ->assertJsonPath('data.booking.amount', 0)
            ->assertJsonPath('data.booking.payment_status', 'not_required')
            ->assertJsonPath('data.payment', null);

        $this->assertSame(2, $subscription->refresh()->consultations_used);
        $this->assertSame(2, Booking::count());
        $this->assertSame(1, Payment::count()); // only the first, paid booking

        Queue::assertPushed(CreateMeetingJob::class, 2);
    }

    public function test_quote_reports_the_remaining_quota_as_free(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->silver()->create(); // 5 consultations

        ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'consultations_used' => 1,
        ]);

        $this->postJson('/api/v1/client/bookings/quote', [
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
        ])->assertOk()
            ->assertJsonPath('data.requires_payment', false)
            ->assertJsonPath('data.amount', 0)
            ->assertJsonPath('data.subscription.consultations_remaining', 4)
            ->assertJsonPath('data.slot_available', true);
    }

    /*
    |----------------------------------------------------------------------
    | The package changes after the purchase
    |----------------------------------------------------------------------
    */

    public function test_the_remaining_quota_survives_a_package_deactivation(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'consultations_used' => 1,
        ]);

        $package->update(['is_active' => false]);

        $this->postJson('/api/v1/client/bookings/quote', [
            'package_id' => $package->id,
        ])->assertOk()
            ->assertJsonPath('data.requires_payment', false)
            ->assertJsonPath('data.amount', 0);

        $this->postJson('/api/v1/client/bookings', $this->payload($client, $package, $consultant))
            ->assertCreated()
            ->assertJsonPath('data.booking.status', 'pending')
            ->assertJsonPath('data.booking.amount', 0);
    }

    public function test_the_remaining_quota_survives_a_package_draft(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'consultations_used' => 1,
        ]);

        $package->delete(); // drafted (soft deleted)

        $this->postJson('/api/v1/client/bookings/quote', [
            'package_id' => $package->id,
        ])->assertOk()
            ->assertJsonPath('data.requires_payment', false)
            ->assertJsonPath('data.amount', 0);

        $response = $this->postJson('/api/v1/client/bookings', $this->payload($client, $package, $consultant))
            ->assertCreated()
            ->assertJsonPath('data.booking.status', 'pending')
            ->assertJsonPath('data.booking.amount', 0);

        // The drafted package still displays on the booking (snapshot).
        $this->assertSame('Iron Package', $response->json('data.booking.package.name_en'));
        $this->assertSame($package->id, $response->json('data.booking.package.id'));
    }

    public function test_a_new_purchase_of_an_inactive_or_drafted_package_is_rejected(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $inactive = Package::factory()->iron()->inactive()->create();
        $drafted = Package::factory()->silver()->create();
        $drafted->delete();

        foreach ([$inactive, $drafted] as $package) {
            $this->assertApiError(
                $this->postJson('/api/v1/client/bookings/quote', ['package_id' => $package->id]),
                422,
                'PACKAGE_INACTIVE',
            );

            $this->assertApiError(
                $this->postJson('/api/v1/client/bookings', $this->payload($client, $package, $consultant, [
                    'card_token' => 'tok_fake_success',
                ])),
                422,
                'PACKAGE_INACTIVE',
            );
        }

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, ClientSubscription::count());
    }

    public function test_package_updates_never_change_existing_purchases(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create(); // 190000, 2 consultations

        // The client buys the package with the first booking.
        $this->postJson('/api/v1/client/bookings', $this->payload($client, $package, $consultant, [
            'card_token' => 'tok_fake_success',
        ]))->assertCreated();

        $booking = Booking::sole();
        $subscription = ClientSubscription::sole();

        // The admin later updates the package (price, quota, and name).
        $package->update([
            'price' => 250000,
            'consultations_limit' => 5,
            'name_en' => 'Iron Plus Package',
            'name_ar' => 'الباقة الحديدية بلس',
        ]);

        // The subscription keeps the purchase-time state.
        $this->assertSame(190000, $subscription->refresh()->price_paid);
        $this->assertSame(2, $subscription->consultations_limit);
        $this->assertSame('Iron Package', $subscription->packageDisplay()['name_en']);

        // The old booking keeps the purchase-time name.
        $this->assertSame('Iron Package', $booking->refresh()->packageDisplay()['name_en']);

        // The client's subscription list shows the purchase-time name.
        $this->getJson('/api/v1/client/subscriptions')
            ->assertOk()
            ->assertJsonPath('data.0.package.name_en', 'Iron Package')
            ->assertJsonPath('data.0.price_paid', 190000);

        $this->getJson("/api/v1/client/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.package.name_en', 'Iron Package');

        // The second consultation in the package is free and uses the frozen quota.
        $second = $this->postJson('/api/v1/client/bookings', $this->payload($client, $package, $consultant, [
            'time' => '11:00',
        ]))->assertCreated()
            ->assertJsonPath('data.booking.amount', 0)
            ->assertJsonPath('data.booking.package.name_en', 'Iron Package');

        $this->assertSame(2, $subscription->refresh()->consultations_used);

        // The quota (2) is now exhausted: a new purchase would pay the NEW price.
        $this->postJson('/api/v1/client/bookings/quote', [
            'package_id' => $package->id,
        ])->assertOk()
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.amount', 250000)
            ->assertJsonPath('data.subscription', null);
    }

    public function test_a_drafted_package_still_displays_on_existing_bookings(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $this->postJson('/api/v1/client/bookings', $this->payload($client, $package, $consultant, [
            'card_token' => 'tok_fake_success',
        ]))->assertCreated();

        $booking = Booking::sole();
        $package->delete();

        $this->getJson("/api/v1/client/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.package.id', $package->id)
            ->assertJsonPath('data.package.name_en', 'Iron Package');

        $this->getJson('/api/v1/client/subscriptions')
            ->assertOk()
            ->assertJsonPath('data.0.package.id', $package->id)
            ->assertJsonPath('data.0.package.name_en', 'Iron Package');
    }
}
