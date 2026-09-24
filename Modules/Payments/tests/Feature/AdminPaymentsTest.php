<?php

namespace Modules\Payments\Tests\Feature;

use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;
use Tests\TestCase;

class AdminPaymentsTest extends TestCase
{
    protected string $url = '/api/v1/admin/payments';

    /*
    |----------------------------------------------------------------------
    | PAY-01 GET /api/v1/admin/payments
    |----------------------------------------------------------------------
    */

    public function test_pay_01_an_admin_sees_all_payments_with_the_client(): void
    {
        $this->actingAsAdmin();
        Payment::factory()->paid()->count(2)->create();
        Payment::factory()->failed()->create();

        $response = $this->getJson($this->url);

        $this->assertPaginated($response);
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertArrayHasKey('client', $response->json('data.0'));
        $this->assertArrayNotHasKey('gateway_response', $response->json('data.0'));
    }

    public function test_pay_01_a_consultant_sees_only_his_bookings_payments(): void
    {
        $consultant = $this->createConsultant();
        $consultant->givePermissionTo('view-payments');
        $this->actingAsConsultant($consultant);

        $ownBooking = Booking::factory()->create(['consultant_id' => $consultant->id]);
        Payment::factory()->paid()->create(['booking_id' => $ownBooking->id]);
        Payment::factory()->paid()->create(); // another consultant's booking

        $response = $this->getJson($this->url);

        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_pay_01_filters_by_status_and_searches_by_reference(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->create();
        Payment::factory()->paid()->create(['booking_id' => $booking->id]);
        Payment::factory()->failed()->create();

        $this->assertSame(1, $this->getJson($this->url.'?status=paid')->json('meta.total'));
        $this->assertSame(1, $this->getJson($this->url.'?search='.$booking->reference)->json('meta.total'));
    }

    public function test_pay_01_requires_the_view_payments_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-bookings']);
        $this->actingAsAdmin($staff);

        $this->getJson($this->url)->assertForbidden();
    }

    /*
    |----------------------------------------------------------------------
    | PAY-02 GET /api/v1/admin/payments/{payment}
    |----------------------------------------------------------------------
    */

    public function test_pay_02_shows_a_payment_with_the_booking_summary(): void
    {
        $this->actingAsAdmin();
        $payment = Payment::factory()->paid()->create();

        $response = $this->getJson($this->url.'/'.$payment->id);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.payment.id', $payment->id)
            ->assertJsonPath('data.booking.id', $payment->booking_id)
            ->assertJsonPath('data.booking.reference', $payment->booking->reference);

        $this->assertArrayNotHasKey('gateway_response', $response->json('data.payment'));
    }

    public function test_pay_02_another_consultants_payment_is_a_404(): void
    {
        $consultant = $this->createConsultant();
        $consultant->givePermissionTo('view-payments');
        $this->actingAsConsultant($consultant);

        $payment = Payment::factory()->paid()->create(); // another consultant's booking

        $this->getJson($this->url.'/'.$payment->id)->assertNotFound();
    }
}
