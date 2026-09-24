<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Models\Booking;
use Modules\Packages\Models\Package;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Models\Payment;
use Tests\TestCase;

/**
 * The money-safety scenario: a gateway outage while a 3-D Secure payment is
 * being verified must NOT fail the payment. The payment stays initiated, the
 * booking stays pending_payment, and the later "paid" webhook reconciles
 * both. Only an explicit `failed` payload from Moyasar may fail a payment.
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

        // 2. Moyasar is down while the callback page verifies: 500, and
        //    nothing changes — the payment is NOT marked failed.
        $this->postJson("/api/v1/client/payments/{$paymentId}/verify")
            ->assertServerError()
            ->assertJsonPath('error_code', 'SERVER_ERROR');

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
