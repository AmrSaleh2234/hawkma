<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Bookings\Jobs\CreateMeetingJob;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Payments\Models\Payment;
use Tests\TestCase;

/**
 * WHK-01 POST /api/v1/webhooks/payments/moyasar (§9.6).
 */
class MoyasarWebhookTest extends TestCase
{
    protected string $url = '/api/v1/webhooks/payments/moyasar';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        config(['payments.moyasar.webhook_secret' => 'whsec_test']);
    }

    /**
     * Create a booking through the 3-D Secure flow and return its initiated
     * payment (as if the client was redirected and the gateway now calls us).
     */
    protected function createInitiatedPayment(): Payment
    {
        $client = $this->createClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $this->actingAsClient($client);

        $created = $this->postJson('/api/v1/client/bookings', [
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_3ds',
        ]);

        $this->assertApiSuccess($created, 201);
        $this->app['auth']->forgetGuards();

        return Payment::sole();
    }

    public function test_whk_01_rejects_a_wrong_secret(): void
    {
        $this->postJson($this->url, [
            'secret_token' => 'wrong',
            'data' => ['id' => 'fake_3ds_123'],
        ])->assertUnauthorized();
    }

    public function test_whk_01_marks_the_payment_paid_and_confirms_the_booking(): void
    {
        $payment = $this->createInitiatedPayment();

        $response = $this->postJson($this->url, [
            'secret_token' => 'whsec_test',
            'type' => 'payment_paid',
            'data' => ['id' => $payment->gateway_payment_id],
        ]);

        $this->assertApiSuccess($response);

        $this->assertSame('paid', $payment->refresh()->status->value);
        $this->assertSame('pending', $payment->booking->refresh()->status->value);
        $this->assertSame(1, ClientSubscription::count());

        Queue::assertPushed(CreateMeetingJob::class);
    }

    public function test_whk_01_is_idempotent(): void
    {
        $payment = $this->createInitiatedPayment();

        $payload = [
            'secret_token' => 'whsec_test',
            'type' => 'payment_paid',
            'data' => ['id' => $payment->gateway_payment_id],
        ];

        $this->postJson($this->url, $payload);
        $second = $this->postJson($this->url, $payload);

        $this->assertApiSuccess($second);
        $this->assertSame(1, ClientSubscription::count());
        $this->assertSame(1, ClientSubscription::sole()->consultations_used);
    }

    public function test_whk_01_an_unknown_gateway_id_still_returns_200(): void
    {
        $response = $this->postJson($this->url, [
            'secret_token' => 'whsec_test',
            'type' => 'payment_paid',
            'data' => ['id' => 'unknown-id'],
        ]);

        $this->assertApiSuccess($response);
        $this->assertDatabaseCount('payments', 0);
    }
}
