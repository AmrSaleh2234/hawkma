<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Models\Booking;
use Modules\Packages\Models\Package;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Models\Payment;
use Tests\TestCase;

/**
 * The money-safety scenarios: a gateway outage must never lose a payment.
 *
 * - An outage while verifying a 3-D Secure payment keeps it initiated; the
 *   later "paid" webhook reconciles both payment and booking.
 * - An outage on the charge request itself leaves a payment with no gateway
 *   id; the webhook finds it through the payment id in the charge metadata.
 * - Only an explicit `failed` payload from Moyasar may fail a payment.
 */
class GatewayOutageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('payments.driver', 'moyasar');
        config()->set('payments.moyasar.secret_key', 'sk_test_123');
        config()->set('payments.moyasar.base_url', 'https://api.moyasar.com/v1');
        config()->set('payments.moyasar.webhook_secret', 'whsec_test');
    }

    public function test_a_gateway_outage_during_verify_keeps_the_payment_initiated_and_the_webhook_reconciles(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        // 1. The charge starts 3-D Secure (initiated + transaction_url). The
        //    fetch endpoint first answers 503 (the outage), then paid.
        Http::fake([
            'api.moyasar.com/v1/payments' => Http::response([
                'id' => 'pay_3ds',
                'status' => 'initiated',
                'source' => ['type' => 'token', 'transaction_url' => 'https://api.moyasar.com/3ds/abc'],
            ]),
            'api.moyasar.com/v1/payments/pay_3ds' => Http::sequence()
                ->push(['message' => 'Service unavailable'], 503)
                ->push([
                    'id' => 'pay_3ds',
                    'status' => 'paid',
                    'source' => ['type' => 'token', 'brand' => 'visa', 'last_four' => '4242'],
                ]),
        ]);

        $created = $this->postJson('/api/v1/client/bookings', [
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_moyasar_123',
        ]);

        $this->assertApiSuccess($created, 201)
            ->assertJsonPath('data.payment.status', 'initiated')
            ->assertJsonPath('data.booking.status', 'pending_payment');

        $paymentId = $created->json('data.payment.id');
        $bookingId = $created->json('data.booking.id');

        // The charge carries our idempotency key and our payment id, so a
        // retry cannot double-charge and the webhook can always find us.
        $payment = Payment::findOrFail($paymentId);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.moyasar.com/v1/payments'
            && $request['given_id'] === $payment->uuid
            && $request['metadata']['payment_id'] === (string) $payment->id
            && $request['metadata']['booking_id'] === $bookingId);

        // 2. Moyasar is down while the callback page verifies: 503 with a
        //    specific code, and nothing changes — NOT marked failed.
        $this->postJson("/api/v1/client/payments/{$paymentId}/verify")
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'PAYMENT_PENDING_CONFIRMATION');

        $this->assertSame(PaymentRecordStatus::Initiated, Payment::findOrFail($paymentId)->status);
        $this->assertSame(BookingStatus::PendingPayment, Booking::findOrFail($bookingId)->status);

        // 3. Moyasar sends the real "paid" webhook afterwards: the payment is
        //    still reconcilable, so the booking is confirmed.
        $this->postJson('/api/v1/webhooks/payments/moyasar', [
            'id' => 'pay_3ds',
            'status' => 'paid',
            'secret_token' => 'whsec_test',
        ])->assertOk();

        $this->assertSame(PaymentRecordStatus::Paid, Payment::findOrFail($paymentId)->status);
        $this->assertSame(BookingStatus::Pending, Booking::findOrFail($bookingId)->status);
    }

    public function test_a_charge_timeout_is_reconciled_by_the_webhook_via_the_metadata_payment_id(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        // 1. Moyasar takes the money but the response never reaches us.
        Http::fake([
            'api.moyasar.com/v1/payments' => fn () => throw new ConnectionException('Operation timed out'),
        ]);

        $this->postJson('/api/v1/client/bookings', [
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_moyasar_123',
        ])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'PAYMENT_PENDING_CONFIRMATION');

        // 2. The booking row persisted as pending_payment and the payment has
        //    no gateway id — we never learned it.
        $booking = Booking::sole();
        $payment = Payment::sole();

        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
        $this->assertSame(PaymentRecordStatus::Initiated, $payment->status);
        $this->assertNull($payment->gateway_payment_id);

        // 3. Moyasar's "paid" webhook arrives with an id we have never seen,
        //    but the charge metadata carries our payment id.
        Http::fake([
            'api.moyasar.com/v1/payments/pay_real' => Http::response([
                'id' => 'pay_real',
                'status' => 'paid',
                'source' => ['type' => 'creditcard', 'brand' => 'visa', 'last_four' => '4242'],
            ]),
        ]);

        $this->postJson('/api/v1/webhooks/payments/moyasar', [
            'type' => 'payment_paid',
            'secret_token' => 'whsec_test',
            'data' => [
                'id' => 'pay_real',
                'status' => 'paid',
                'metadata' => ['payment_id' => (string) $payment->id],
            ],
        ])->assertOk();

        // 4. The payment adopted the gateway id and was reconciled; the
        //    booking is confirmed — no money lost, no refund needed.
        $payment->refresh();
        $this->assertSame('pay_real', $payment->gateway_payment_id);
        $this->assertSame(PaymentRecordStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_the_webhook_never_repoints_a_payment_that_already_has_a_different_gateway_id(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        Http::fake([
            'api.moyasar.com/v1/payments' => Http::response([
                'id' => 'pay_3ds',
                'status' => 'initiated',
                'source' => ['type' => 'token', 'transaction_url' => 'https://api.moyasar.com/3ds/abc'],
            ]),
        ]);

        $this->postJson('/api/v1/client/bookings', [
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_moyasar_123',
        ]);

        $payment = Payment::sole();
        $this->assertSame('pay_3ds', $payment->gateway_payment_id);

        // A webhook for an unknown gateway id whose metadata points at OUR
        // payment: the link must not be hijacked, and nothing is fetched.
        Http::fake();

        $this->postJson('/api/v1/webhooks/payments/moyasar', [
            'type' => 'payment_paid',
            'secret_token' => 'whsec_test',
            'data' => [
                'id' => 'pay_other',
                'status' => 'paid',
                'metadata' => ['payment_id' => (string) $payment->id],
            ],
        ])->assertOk();

        $this->assertSame('pay_3ds', $payment->refresh()->gateway_payment_id);
        $this->assertSame(PaymentRecordStatus::Initiated, $payment->status);
        Http::assertNothingSent();
    }

    public function test_verifying_a_payment_without_a_gateway_id_skips_the_gateway_call(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        Http::fake([
            'api.moyasar.com/v1/payments' => fn () => throw new ConnectionException('Operation timed out'),
        ]);

        $this->postJson('/api/v1/client/bookings', [
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_moyasar_123',
        ])->assertStatus(503);

        $payment = Payment::sole();
        $this->assertNull($payment->gateway_payment_id);

        Http::fake();

        $this->postJson("/api/v1/client/payments/{$payment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'initiated')
            ->assertJsonPath('data.booking.status', 'pending_payment');

        Http::assertNothingSent();
    }

    public function test_an_explicit_failed_payload_still_fails_the_payment(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        Http::fake([
            'api.moyasar.com/v1/payments' => Http::response([
                'id' => 'pay_3ds',
                'status' => 'initiated',
                'source' => ['type' => 'token', 'transaction_url' => 'https://api.moyasar.com/3ds/abc'],
            ]),
        ]);

        $created = $this->postJson('/api/v1/client/bookings', [
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_moyasar_123',
        ]);

        $paymentId = $created->json('data.payment.id');

        Http::fake([
            'api.moyasar.com/v1/payments/pay_3ds' => Http::response([
                'id' => 'pay_3ds',
                'status' => 'failed',
                'source' => ['type' => 'creditcard', 'message' => '3DS authentication failed'],
            ]),
        ]);

        $this->postJson("/api/v1/client/payments/{$paymentId}/verify")
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'failed')
            ->assertJsonPath('data.booking.status', 'cancelled');
    }
}
