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

    public function test_pay_01_a_payment_of_a_deleted_client_still_lists(): void
    {
        $this->actingAsAdmin();
        $payment = Payment::factory()->paid()->create();
        $payment->client->delete();

        $response = $this->getJson($this->url);

        $this->assertApiSuccess($response);
        $this->assertSame($payment->client_id, $response->json('data.0.client.id'));
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

    /*
    |----------------------------------------------------------------------
    | PAY-01b GET /api/v1/admin/payments/stats
    |----------------------------------------------------------------------
    */

    public function test_pay_01b_returns_aggregate_stats(): void
    {
        $this->actingAsAdmin();

        Payment::factory()->paid()->create(['amount' => 190000]);
        Payment::factory()->paid()->create(['amount' => 450000]);
        Payment::factory()->failed()->create(['amount' => 980000]);

        $response = $this->getJson($this->url.'/stats');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.total_amount', 1620000)
            ->assertJsonPath('data.total_amount_formatted', '16,200.00 SAR');

        $byStatus = collect($response->json('data.by_status'))->keyBy('status');
        $this->assertSame(2, $byStatus['paid']['count']);
        $this->assertSame(640000, $byStatus['paid']['amount']);
        $this->assertSame('6,400.00 SAR', $byStatus['paid']['amount_formatted']);
        $this->assertSame(1, $byStatus['failed']['count']);
        $this->assertSame(980000, $byStatus['failed']['amount']);
        $this->assertSame(0, $byStatus['initiated']['count']);

        $byGateway = collect($response->json('data.by_gateway'))->keyBy('gateway');
        $this->assertSame(3, $byGateway['fake']['count']);

        $byMonth = collect($response->json('data.by_month'))->keyBy('month');
        $this->assertSame(3, $byMonth[now()->format('Y-m')]['count']);
        $this->assertSame(1620000, $byMonth[now()->format('Y-m')]['amount']);
    }

    public function test_pay_01b_respects_status_and_date_filters(): void
    {
        $this->actingAsAdmin();
        Payment::factory()->paid()->create(['amount' => 190000, 'created_at' => now()->subDays(2)]);
        Payment::factory()->failed()->create(['amount' => 50000]);

        $this->assertSame(190000, $this->getJson($this->url.'/stats?status=paid')->json('data.total_amount'));

        $range = $this->getJson($this->url.'/stats?date_from='.now()->toDateString().'&date_to='.now()->toDateString());
        $this->assertSame(50000, $range->json('data.total_amount'));
    }

    public function test_pay_01b_a_consultant_sees_only_his_bookings_payments(): void
    {
        $consultant = $this->createConsultant();
        $consultant->givePermissionTo('view-payments');
        $this->actingAsConsultant($consultant);

        $ownBooking = Booking::factory()->create(['consultant_id' => $consultant->id]);
        Payment::factory()->paid()->create(['booking_id' => $ownBooking->id, 'amount' => 190000]);
        Payment::factory()->paid()->create(['amount' => 450000]); // another consultant's

        $response = $this->getJson($this->url.'/stats');

        $this->assertSame(190000, $response->json('data.total_amount'));
        $this->assertSame(1, collect($response->json('data.by_status'))->firstWhere('status', 'paid')['count']);
    }

    public function test_pay_01b_requires_the_view_payments_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-bookings']);
        $this->actingAsAdmin($staff);

        $this->getJson($this->url.'/stats')->assertForbidden();
    }
}
