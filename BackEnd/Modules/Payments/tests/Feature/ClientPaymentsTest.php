<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Bookings\Jobs\CreateMeetingJob;
use Modules\Bookings\Models\Booking;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Payments\Models\Payment;
use Tests\TestCase;

class ClientPaymentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
    }

    /*
    |----------------------------------------------------------------------
    | CLI-PAY-01 GET /api/v1/client/payments
    |----------------------------------------------------------------------
    */

    public function test_cli_pay_01_lists_only_the_clients_payments(): void
    {
        $client = $this->actingAsClient();
        $booking = Booking::factory()->create(['client_id' => $client->id]);
        Payment::factory()->paid()->create(['booking_id' => $booking->id]);
        Payment::factory()->paid()->create(); // another client's

        $response = $this->getJson('/api/v1/client/payments');

        $this->assertPaginated($response);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($booking->reference, $response->json('data.0.booking_reference'));
        $this->assertArrayNotHasKey('gateway_response', $response->json('data.0'));
    }

    /*
    |----------------------------------------------------------------------
    | CLI-PAY-02 POST /api/v1/client/payments/{payment}/verify
    |----------------------------------------------------------------------
    */

    public function test_cli_pay_02_verify_after_3ds_marks_the_booking_pending(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $created = $this->postJson('/api/v1/client/bookings', [
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_3ds',
        ]);

        $this->assertApiSuccess($created, 201);
        $paymentId = $created->json('data.payment.id');

        $response = $this->postJson("/api/v1/client/payments/{$paymentId}/verify");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.booking.status', 'pending')
            ->assertJsonPath('data.booking.payment_status', 'paid')
            ->assertJsonPath('data.payment.status', 'paid');

        $this->assertSame(1, ClientSubscription::count());
        $this->assertSame(1, ClientSubscription::sole()->consultations_used);

        Queue::assertPushed(CreateMeetingJob::class);
    }

    public function test_cli_pay_02_verifying_twice_does_not_create_two_subscriptions(): void
    {
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $created = $this->postJson('/api/v1/client/bookings', [
            'package_id' => $package->id,
            'consultant_id' => $consultant->id,
            'date' => '2026-09-21',
            'time' => '10:00',
            'client_location_id' => $client->locations()->first()->id,
            'card_token' => 'tok_fake_3ds',
        ]);

        $paymentId = $created->json('data.payment.id');

        $this->postJson("/api/v1/client/payments/{$paymentId}/verify");
        $second = $this->postJson("/api/v1/client/payments/{$paymentId}/verify");

        $this->assertApiSuccess($second)
            ->assertJsonPath('data.payment.status', 'paid');

        $this->assertSame(1, ClientSubscription::count());
        $this->assertSame(1, ClientSubscription::sole()->consultations_used);
    }

    public function test_cli_pay_02_another_clients_payment_is_a_404(): void
    {
        $this->actingAsClient();
        $payment = Payment::factory()->initiated()->create();

        $this->postJson("/api/v1/client/payments/{$payment->id}/verify")->assertNotFound();
    }
}
