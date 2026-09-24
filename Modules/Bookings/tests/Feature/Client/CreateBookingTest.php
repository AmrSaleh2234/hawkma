<?php

namespace Modules\Bookings\Tests\Feature\Client;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Bookings\Contracts\MeetingProvider;
use Modules\Bookings\Jobs\CreateMeetingJob;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Notifications\BookingConfirmedNotification;
use Modules\Bookings\Notifications\NewBookingNotification;
use Modules\Consultants\Services\SlotService;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentMethod;
use Tests\TestCase;

class CreateBookingTest extends TestCase
{
    protected string $url = '/api/v1/client/bookings';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
    }

    /**
     * A valid payload for Monday 2026-09-21 10:00 (inside the default
     * Sun–Thu 09:00–17:00 availability).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-09-21',
            'time' => '10:00',
        ], $overrides);
    }

    public function test_cli_bkg_02_with_a_successful_card_token_creates_a_paid_booking(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_success',
        ]));

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.booking.status', 'pending')
            ->assertJsonPath('data.booking.payment_status', 'paid')
            ->assertJsonPath('data.booking.amount', 190000)
            ->assertJsonPath('data.payment.status', 'paid')
            ->assertJsonPath('data.payment.requires_action', false)
            ->assertJsonPath('data.payment.transaction_url', null);

        $booking = Booking::first();
        $this->assertSame('BK-2026-000001', $booking->reference);
        $this->assertNull($booking->expires_at);
        $this->assertSame('Headquarters', $booking->location_snapshot['name']);

        // The subscription was created with one consultation consumed.
        $subscription = ClientSubscription::sole();
        $this->assertSame($package->id, $subscription->package_id);
        $this->assertSame(1, $subscription->consultations_used);
        $this->assertSame($subscription->id, $booking->client_subscription_id);

        Queue::assertPushed(CreateMeetingJob::class);
    }

    public function test_cli_bkg_02_with_a_saved_payment_method(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();
        $method = PaymentMethod::factory()->create(['client_id' => $client->id]);

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'payment_method_id' => $method->id,
        ]));

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.booking.status', 'pending')
            ->assertJsonPath('data.booking.payment_status', 'paid');

        $this->assertSame($method->id, Payment::sole()->payment_method_id);
        Queue::assertPushed(CreateMeetingJob::class);
    }

    public function test_cli_bkg_02_with_a_3ds_token_returns_a_transaction_url(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_3ds',
        ]));

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.booking.status', 'pending_payment')
            ->assertJsonPath('data.booking.payment_status', 'unpaid')
            ->assertJsonPath('data.payment.status', 'initiated')
            ->assertJsonPath('data.payment.requires_action', true);

        $this->assertNotEmpty($response->json('data.payment.transaction_url'));
        $this->assertNotNull(Booking::sole()->expires_at);

        // No meeting until the payment is verified.
        Queue::assertNotPushed(CreateMeetingJob::class);
    }

    public function test_cli_bkg_02_with_a_declined_card_returns_402_and_frees_the_slot(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_declined',
        ]));

        $this->assertApiError($response, 402, 'PAYMENT_FAILED');

        $booking = Booking::sole();
        $this->assertSame('cancelled', $booking->status->value);
        $this->assertSame('failed', $booking->payment_status->value);
        $this->assertSame('payment_failed', $booking->cancellation_reason);

        // The slot is free again.
        $startsAt = CarbonImmutable::parse('2026-09-21 10:00', 'Asia/Riyadh');
        $this->assertTrue(app(SlotService::class)->isSlotAvailable($consultant, $startsAt));
    }

    public function test_cli_bkg_02_an_active_subscription_covers_the_booking_without_a_payment(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();
        $subscription = ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'consultations_used' => 0,
        ]);

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
        ]));

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.booking.status', 'pending')
            ->assertJsonPath('data.booking.payment_status', 'not_required')
            ->assertJsonPath('data.booking.amount', 0)
            ->assertJsonPath('data.payment', null);

        $this->assertSame(1, $subscription->refresh()->consultations_used);
        $this->assertSame($subscription->id, Booking::sole()->client_subscription_id);
        $this->assertDatabaseCount('payments', 0);

        Queue::assertPushed(CreateMeetingJob::class);
    }

    public function test_cli_bkg_02_a_taken_slot_returns_409(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        Booking::factory()->pending()->create([
            'consultant_id' => $consultant->id,
            'starts_at' => '2026-09-21 10:00:00',
            'ends_at' => '2026-09-21 10:30:00',
        ]);

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_success',
        ]));

        $this->assertApiError($response, 409, 'SLOT_NOT_AVAILABLE');
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_cli_bkg_02_a_slot_outside_availability_returns_409(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_success',
            'time' => '20:00',
        ]));

        $this->assertApiError($response, 409, 'SLOT_NOT_AVAILABLE');
    }

    public function test_cli_bkg_02_a_time_in_the_past_returns_409(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        // Today 08:00 — before now (09:00) and before the minimum notice.
        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_success',
            'date' => '2026-09-20',
            'time' => '08:00',
        ]));

        $this->assertApiError($response, 409, 'SLOT_NOT_AVAILABLE');
    }

    public function test_cli_bkg_02_another_clients_location_returns_422(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();
        $otherLocation = $this->createClient()->locations()->first();

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $otherLocation->id,
            'card_token' => 'tok_fake_success',
        ]));

        $this->assertApiError($response, 422, 'LOCATION_NOT_OWNED');
    }

    public function test_cli_bkg_02_another_clients_payment_method_returns_422(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();
        $otherMethod = PaymentMethod::factory()->create(); // another client's

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'payment_method_id' => $otherMethod->id,
        ]));

        $this->assertApiError($response, 422, 'PAYMENT_METHOD_NOT_OWNED');
    }

    public function test_cli_bkg_02_without_payment_info_when_payment_is_needed_returns_422(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
        ]));

        $this->assertApiError($response, 422, 'PAYMENT_METHOD_REQUIRED');
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_cli_bkg_02_save_card_stores_the_payment_method(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_success',
            'save_card' => true,
        ]));

        $this->assertApiSuccess($response, 201);

        $this->assertDatabaseHas('payment_methods', [
            'client_id' => $client->id,
            'gateway' => 'fake',
            'gateway_token' => 'tok_fake_success',
            'last_four' => '4242',
        ]);

        // The charge used the saved method.
        $method = $client->paymentMethods()->sole();
        $this->assertSame($method->id, Payment::sole()->payment_method_id);
    }

    public function test_cli_bkg_02_an_inactive_consultant_returns_422(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant(['is_active' => false]);
        $package = Package::factory()->iron()->create();

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_success',
        ]));

        $this->assertApiError($response, 422, 'CONSULTANT_INACTIVE');
    }

    public function test_cli_bkg_02_an_admin_token_gets_401(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => 1,
            'card_token' => 'tok_fake_success',
        ]))->assertUnauthorized();
    }

    public function test_cli_bkg_02_running_the_meeting_job_sets_the_url_and_notifies(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $response = $this->postJson($this->url, $this->payload([
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_success',
        ]));

        $this->assertApiSuccess($response, 201);

        // Run the pushed job synchronously (as the sync queue driver would).
        $booking = Booking::sole();
        Queue::assertPushed(CreateMeetingJob::class);
        (new CreateMeetingJob($booking))->handle(app(MeetingProvider::class));

        $booking->refresh();
        $this->assertNotNull($booking->meeting_url);
        $this->assertSame('created', $booking->meeting_status->value);
        $this->assertSame('fake', $booking->meeting_provider);

        Notification::assertSentTo(
            $client,
            BookingConfirmedNotification::class,
            fn ($notification) => $notification->withLink === true,
        );
        Notification::assertSentTo($consultant, NewBookingNotification::class);
    }
}
