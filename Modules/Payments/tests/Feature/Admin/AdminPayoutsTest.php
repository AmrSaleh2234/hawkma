<?php

namespace Modules\Payments\Tests\Feature\Admin;

use Illuminate\Support\Facades\Notification;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Services\BookingStateMachine;
use Modules\Core\Support\Settings;
use Modules\Payments\Enums\PayoutStatus;
use Modules\Payments\Models\Payout;
use Modules\Users\Models\User;
use Tests\TestCase;

class AdminPayoutsTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | Payout creation on booking completion
    |----------------------------------------------------------------------
    */

    public function test_completing_a_booking_creates_a_pending_payout_with_the_default_fee(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $consultant = $this->createConsultant(['consultation_price' => 100000]); // SAR 1,000
        $booking = Booking::factory()->pending()->past()->create(['consultant_id' => $consultant->id]);

        app(BookingStateMachine::class)->complete($booking, User::first());

        $payout = Payout::sole();
        $this->assertSame($booking->id, $payout->booking_id);
        $this->assertSame($consultant->id, $payout->consultant_id);
        $this->assertSame(100000, $payout->amount);
        // Default fee is 15% → SAR 150 fee, SAR 850 net.
        $this->assertSame(15.0, (float) $payout->fee_percent);
        $this->assertSame(15000, $payout->fee_amount);
        $this->assertSame(85000, $payout->net_amount);
        $this->assertSame(PayoutStatus::Pending, $payout->status);
    }

    public function test_a_consultant_without_a_price_gets_no_payout(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $booking = Booking::factory()->pending()->past()->create();

        app(BookingStateMachine::class)->complete($booking, User::first());

        $this->assertSame(0, Payout::count());
    }

    public function test_the_consultant_override_beats_the_global_fee(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        Settings::set(Settings::PLATFORM_FEE_PERCENT, 20);
        $consultant = $this->createConsultant([
            'consultation_price' => 100000,
            'platform_fee_percent' => 5,
        ]);
        $booking = Booking::factory()->pending()->past()->create(['consultant_id' => $consultant->id]);

        app(BookingStateMachine::class)->complete($booking, User::first());

        $payout = Payout::sole();
        $this->assertSame(5.0, (float) $payout->fee_percent);
        $this->assertSame(5000, $payout->fee_amount);
        $this->assertSame(95000, $payout->net_amount);
    }

    /*
    |----------------------------------------------------------------------
    | PO-01 / PO-02 list, stats, show
    |----------------------------------------------------------------------
    */

    public function test_po_01_lists_payouts_with_filters(): void
    {
        $this->actingAsAdmin();

        $pending = Payout::factory()->create();
        Payout::factory()->released()->create();

        $this->assertSame(2, $this->getJson('/api/v1/admin/payouts')->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/payouts?status=pending')->json('meta.total'));
        $this->assertSame($pending->id, $this->getJson('/api/v1/admin/payouts?status=pending')->json('data.0.id'));
    }

    public function test_po_01_a_consultant_only_sees_his_own_payouts(): void
    {
        $consultant = $this->createConsultant();
        $this->actingAsConsultant($consultant);

        $mine = Payout::factory()->create(['consultant_id' => $consultant->id]);
        Payout::factory()->create(); // another consultant's

        $response = $this->getJson('/api/v1/admin/payouts');
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($mine->id, $response->json('data.0.id'));

        $this->getJson('/api/v1/admin/payouts/'.Payout::latest('id')->first()->id)->assertNotFound();
    }

    public function test_po_01b_stats_group_by_status(): void
    {
        $this->actingAsAdmin();

        Payout::factory()->count(2)->create();
        Payout::factory()->released()->create();

        $data = $this->getJson('/api/v1/admin/payouts/stats')->json('data');

        $byStatus = collect($data['by_status'])->keyBy('status');
        $this->assertSame(2, $byStatus['pending']['count']);
        $this->assertSame(1, $byStatus['released']['count']);
        $this->assertFalse($data['auto_transfer']);
    }

    public function test_po_01_requires_the_view_payouts_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-payments']);
        $this->actingAsAdmin($staff);

        $this->getJson('/api/v1/admin/payouts')->assertForbidden();
    }

    /*
    |----------------------------------------------------------------------
    | PO-03 release — manual mode (default)
    |----------------------------------------------------------------------
    */

    public function test_po_03_releases_a_pending_payout_manually(): void
    {
        $admin = $this->actingAsAdmin();
        $payout = Payout::factory()->create();

        $response = $this->postJson("/api/v1/admin/payouts/{$payout->id}/release", [
            'transfer_reference' => 'TRX-9988',
            'note' => 'Sent via bank portal.',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.driver', 'manual')
            ->assertJsonPath('data.transfer_reference', 'TRX-9988');

        $payout->refresh();
        $this->assertSame(PayoutStatus::Released, $payout->status);
        $this->assertSame($admin->id, $payout->released_by);
        $this->assertNotNull($payout->released_at);
    }

    public function test_po_03_an_already_released_payout_fails(): void
    {
        $this->actingAsAdmin();
        $payout = Payout::factory()->released()->create();

        $this->assertApiError(
            $this->postJson("/api/v1/admin/payouts/{$payout->id}/release"),
            422,
            'PAYOUT_ALREADY_RELEASED',
        );
    }

    public function test_po_03_requires_the_release_payouts_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-payouts']);
        $this->actingAsAdmin($staff);
        $payout = Payout::factory()->create();

        $this->postJson("/api/v1/admin/payouts/{$payout->id}/release")->assertForbidden();
    }

    /*
    |----------------------------------------------------------------------
    | PO-03 release — auto transfer via the configured gateway
    |----------------------------------------------------------------------
    */

    public function test_po_03_auto_transfer_uses_the_gateway_and_records_the_transfer_id(): void
    {
        $this->actingAsAdmin();
        config(['payments.payout_driver' => 'fake']);
        Settings::set(Settings::PAYOUT_AUTO_TRANSFER, true);

        $consultant = $this->createConsultant(['iban' => 'SA0380000000608010167519']);
        $payout = Payout::factory()->create(['consultant_id' => $consultant->id]);

        $response = $this->postJson("/api/v1/admin/payouts/{$payout->id}/release");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.driver', 'fake');

        $this->assertStringStartsWith('fake_payout_', $response->json('data.transfer_reference'));
    }

    public function test_po_03_auto_transfer_without_an_iban_marks_the_payout_failed(): void
    {
        $this->actingAsAdmin();
        config(['payments.payout_driver' => 'fake']);
        Settings::set(Settings::PAYOUT_AUTO_TRANSFER, true);

        $consultant = $this->createConsultant(['iban' => null]);
        $payout = Payout::factory()->create(['consultant_id' => $consultant->id]);

        $this->assertApiError(
            $this->postJson("/api/v1/admin/payouts/{$payout->id}/release"),
            422,
            'PAYOUT_NOT_RELEASABLE',
        );

        $this->assertSame(PayoutStatus::Failed, $payout->refresh()->status);
    }
}
