<?php

namespace Modules\Bookings\Tests\Feature\Client;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Bookings\Jobs\CreateMeetingJob;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\Payout;
use Tests\TestCase;

/**
 * Direct bookings: the client books a consultant without a package — the
 * amount is the consultant's consultation_price and the booking always
 * requires a payment (no subscription is created).
 */
class DirectBookingTest extends TestCase
{
    protected string $quoteUrl = '/api/v1/client/bookings/quote';

    protected string $storeUrl = '/api/v1/client/bookings';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
    }

    public function test_direct_quote_uses_the_consultant_price(): void
    {
        $this->actingAsClient();
        $consultant = $this->createConsultant(['consultation_price' => 50000]);

        $response = $this->postJson($this->quoteUrl, [
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.package', null)
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.amount', 50000)
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.subscription', null)
            ->assertJsonPath('data.slot_available', true);
    }

    public function test_direct_quote_without_a_consultant_price_returns_422(): void
    {
        $this->actingAsClient();
        $consultant = $this->createConsultant(['consultation_price' => null]);

        $response = $this->postJson($this->quoteUrl, [
            'consultant_id' => $consultant->id,
        ]);

        $this->assertApiError($response, 422, 'CONSULTANT_PRICE_REQUIRED');
    }

    public function test_a_quote_needs_a_package_or_a_consultant(): void
    {
        $this->actingAsClient();

        $this->postJson($this->quoteUrl, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['package_id', 'consultant_id']);
    }

    public function test_direct_booking_pays_the_consultant_price_and_creates_no_subscription(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant(['consultation_price' => 50000]);

        $response = $this->postJson($this->storeUrl, [
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_success',
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.booking.status', 'pending')
            ->assertJsonPath('data.booking.payment_status', 'paid')
            ->assertJsonPath('data.booking.amount', 50000)
            ->assertJsonPath('data.booking.package', null)
            ->assertJsonPath('data.payment.status', 'paid');

        $booking = Booking::sole();
        $this->assertNull($booking->package_id);
        $this->assertNull($booking->package_snapshot);
        $this->assertNull($booking->client_subscription_id);
        $this->assertSame(50000, $booking->amount);
        $this->assertDatabaseCount('client_subscriptions', 0);

        Queue::assertPushed(CreateMeetingJob::class);
    }

    public function test_direct_booking_with_3ds_verify_marks_it_paid_without_a_subscription(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant(['consultation_price' => 50000]);

        $created = $this->postJson($this->storeUrl, [
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_3ds',
        ]);

        $this->assertApiSuccess($created, 201)
            ->assertJsonPath('data.booking.status', 'pending_payment');

        $paymentId = $created->json('data.payment.id');
        $response = $this->postJson("/api/v1/client/payments/{$paymentId}/verify");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.booking.status', 'pending')
            ->assertJsonPath('data.booking.payment_status', 'paid');

        $booking = Booking::sole();
        $this->assertNull($booking->client_subscription_id);
        $this->assertDatabaseCount('client_subscriptions', 0);
        $this->assertSame(50000, Payment::sole()->amount);

        Queue::assertPushed(CreateMeetingJob::class);
    }

    public function test_direct_booking_without_payment_info_returns_422(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant(['consultation_price' => 50000]);

        $response = $this->postJson($this->storeUrl, [
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
        ]);

        $this->assertApiError($response, 422, 'PAYMENT_METHOD_REQUIRED');
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_direct_booking_without_a_consultant_price_returns_422(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant(['consultation_price' => null]);

        $response = $this->postJson($this->storeUrl, [
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_success',
        ]);

        $this->assertApiError($response, 422, 'CONSULTANT_PRICE_REQUIRED');
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_completing_a_direct_booking_creates_a_payout_on_the_consultation_price(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant(['consultation_price' => 50000]);

        $this->postJson($this->storeUrl, [
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_success',
        ])->assertCreated();

        $booking = Booking::sole();
        $booking->forceFill([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->subMinutes(30),
        ])->save();

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/complete")
            ->assertOk();

        $payout = Payout::sole();
        $this->assertSame($booking->id, $payout->booking_id);
        $this->assertSame($consultant->id, $payout->consultant_id);
        $this->assertSame(50000, $payout->amount);
        $this->assertDatabaseCount('client_subscriptions', 0);
    }
}
