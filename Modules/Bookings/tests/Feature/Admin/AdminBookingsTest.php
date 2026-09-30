<?php

namespace Modules\Bookings\Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Bookings\Contracts\MeetingProvider;
use Modules\Bookings\DTO\MeetingResult;
use Modules\Bookings\Jobs\CancelMeetingJob;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Notifications\BookingCancelledNotification;
use Modules\Bookings\Notifications\BookingConfirmedNotification;
use Modules\Packages\Enums\SubscriptionStatus;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Models\Payment;
use RuntimeException;
use Tests\TestCase;

class AdminBookingsTest extends TestCase
{
    protected string $url = '/api/v1/admin/bookings';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
    }

    /*
    |----------------------------------------------------------------------
    | BKG-01 GET /api/v1/admin/bookings
    |----------------------------------------------------------------------
    */

    public function test_bkg_01_an_admin_sees_all_bookings(): void
    {
        $this->actingAsAdmin();
        Booking::factory()->count(3)->create();

        $response = $this->getJson($this->url);

        $this->assertPaginated($response);
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_bkg_01_a_consultant_sees_only_his_bookings(): void
    {
        $consultant = $this->createConsultant();
        $this->actingAsConsultant($consultant);

        Booking::factory()->create(['consultant_id' => $consultant->id]);
        Booking::factory()->count(2)->create(); // other consultants'

        $response = $this->getJson($this->url);

        $this->assertPaginated($response);
        $this->assertSame(1, $response->json('meta.total'));

        // consultant_id is ignored for consultants (it cannot widen the scope).
        $response = $this->getJson($this->url.'?consultant_id='.Booking::latest('id')->first()->consultant_id);
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_bkg_01_filters(): void
    {
        $admin = $this->actingAsAdmin();
        $consultant = $this->createConsultant();
        $package = Package::factory()->iron()->create();

        $matching = Booking::factory()->withReportPending()->create([
            'consultant_id' => $consultant->id,
            'package_id' => $package->id,
            'payment_status' => 'paid',
            'starts_at' => '2026-09-18 10:00:00',
            'ends_at' => '2026-09-18 10:30:00',
        ]);
        Booking::factory()->pending()->future()->create(['payment_status' => 'unpaid']);
        Booking::factory()->cancelled()->create(['payment_status' => 'failed']);

        $this->assertSame(1, $this->getJson($this->url.'?status=completed')->json('meta.total'));
        $this->assertSame(1, $this->getJson($this->url.'?report_status=pending')->json('meta.total'));
        $this->assertSame(1, $this->getJson($this->url.'?payment_status=paid')->json('meta.total'));
        $this->assertSame(1, $this->getJson($this->url.'?consultant_id='.$consultant->id)->json('meta.total'));
        $this->assertSame(1, $this->getJson($this->url.'?client_id='.$matching->client_id)->json('meta.total'));
        $this->assertSame(1, $this->getJson($this->url.'?package_id='.$package->id)->json('meta.total'));
        $this->assertSame(1, $this->getJson($this->url.'?date_from=2026-09-01&date_to=2026-09-19')->json('meta.total'));
        $this->assertSame(3, $this->getJson($this->url)->json('meta.total'));
    }

    public function test_bkg_01_searches_by_reference(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->create();
        Booking::factory()->create();

        $response = $this->getJson($this->url.'?search='.$booking->reference);

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($booking->id, $response->json('data.0.id'));
    }

    public function test_bkg_01_has_no_n_plus_1(): void
    {
        $this->actingAsAdmin();
        Booking::factory()->count(3)->create();

        $this->getJson($this->url); // warm up the auth/permission caches

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($this->url);
        $baseline = count(DB::getQueryLog());

        Booking::factory()->count(3)->create();

        DB::flushQueryLog();
        $this->getJson($this->url);
        $after = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($baseline, $after, 'the query count grew with the number of bookings');
    }

    /*
    |----------------------------------------------------------------------
    | BKG-02 GET /api/v1/admin/bookings/{booking}
    |----------------------------------------------------------------------
    */

    public function test_bkg_02_an_admin_views_any_booking_with_all_payments(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->create();
        Payment::factory()->paid()->create(['booking_id' => $booking->id]);

        $response = $this->getJson($this->url.'/'.$booking->id);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $booking->id)
            ->assertJsonPath('data.reference', $booking->reference)
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonStructure(['data' => ['can' => ['complete', 'cancel', 'upload_report']]]);

        $this->assertArrayNotHasKey('gateway_response', $response->json('data.payments.0'));
    }

    public function test_bkg_02_a_consultant_views_his_own_but_not_anothers(): void
    {
        $consultant = $this->createConsultant();
        $this->actingAsConsultant($consultant);

        $own = Booking::factory()->create(['consultant_id' => $consultant->id]);
        $other = Booking::factory()->create();

        $this->getJson($this->url.'/'.$own->id)->assertOk();
        $this->getJson($this->url.'/'.$other->id)->assertForbidden();
    }

    /*
    |----------------------------------------------------------------------
    | BKG-03 POST /api/v1/admin/bookings/{booking}/complete
    |----------------------------------------------------------------------
    */

    public function test_bkg_03_complete_after_the_start_time(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->pending()->past()->create();

        $response = $this->postJson($this->url.'/'.$booking->id.'/complete', [
            'notes' => 'Great session',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.report_status', 'pending');

        $this->assertSame('Great session', $booking->refresh()->completion_notes);
    }

    public function test_bkg_03_complete_before_the_start_time_returns_422(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->pending()->future()->create();

        $this->assertApiError(
            $this->postJson($this->url.'/'.$booking->id.'/complete'),
            422,
            'BOOKING_NOT_STARTED',
        );
    }

    public function test_bkg_03_complete_a_cancelled_booking_returns_422(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->cancelled()->create();

        $this->assertApiError(
            $this->postJson($this->url.'/'.$booking->id.'/complete'),
            422,
            'BOOKING_INVALID_STATUS',
        );
    }

    public function test_bkg_03_another_consultant_gets_403(): void
    {
        $this->actingAsConsultant(); // not the booking's consultant
        $booking = Booking::factory()->pending()->past()->create();

        $this->postJson($this->url.'/'.$booking->id.'/complete')->assertForbidden();
    }

    /*
    |----------------------------------------------------------------------
    | BKG-04 POST /api/v1/admin/bookings/{booking}/cancel
    |----------------------------------------------------------------------
    */

    public function test_bkg_04_cancel_releases_the_quota_and_requests_the_refund(): void
    {
        $this->actingAsAdmin();
        $package = Package::factory()->iron()->create();
        $booking = Booking::factory()->pending()->future()->create([
            'package_id' => $package->id,
            'payment_status' => 'paid',
            'meeting_event_id' => 'fake-event-1',
        ]);
        $subscription = ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $booking->client_id,
            'consultations_used' => 1,
        ]);
        $booking->forceFill(['client_subscription_id' => $subscription->id])->save();

        $response = $this->postJson($this->url.'/'.$booking->id.'/cancel', [
            'reason' => 'Client asked to cancel',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.refund_status', 'requested')
            ->assertJsonPath('data.cancellation_reason', 'Client asked to cancel');

        $this->assertSame(0, $subscription->refresh()->consultations_used);
        $this->assertSame('user', $booking->refresh()->cancelled_by_type);

        Queue::assertPushed(CancelMeetingJob::class);
        Notification::assertSentTo($booking->client, BookingCancelledNotification::class);
        Notification::assertSentTo($booking->consultant, BookingCancelledNotification::class);
    }

    public function test_bkg_04_cancel_a_completed_booking_returns_422(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->completed()->create();

        $this->assertApiError(
            $this->postJson($this->url.'/'.$booking->id.'/cancel', ['reason' => 'nope']),
            422,
            'BOOKING_INVALID_STATUS',
        );
    }

    public function test_bkg_04_reason_is_required(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->pending()->future()->create();

        $this->postJson($this->url.'/'.$booking->id.'/cancel', [])->assertUnprocessable();
    }

    /*
    |----------------------------------------------------------------------
    | BKG-05 POST /api/v1/admin/bookings/{booking}/meeting
    |----------------------------------------------------------------------
    */

    public function test_bkg_05_regenerates_the_meeting_synchronously(): void
    {
        $admin = $this->createAdmin();
        $admin->givePermissionTo('manage-meetings');
        $this->actingAsAdmin($admin);

        $booking = Booking::factory()->pending()->future()->create([
            'meeting_event_id' => 'fake-old-event',
            'meeting_url' => 'https://meet.google.com/old-link',
            'meeting_status' => 'created',
        ]);

        $response = $this->postJson($this->url.'/'.$booking->id.'/meeting');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.meeting.status', 'created');

        $booking->refresh();
        $this->assertNotSame('fake-old-event', $booking->meeting_event_id);
        $this->assertNotSame('https://meet.google.com/old-link', $booking->meeting_url);

        Notification::assertSentTo($booking->client, BookingConfirmedNotification::class);
    }

    public function test_bkg_05_a_provider_failure_returns_502_and_marks_the_meeting_failed(): void
    {
        $admin = $this->createAdmin();
        $admin->givePermissionTo('manage-meetings');
        $this->actingAsAdmin($admin);

        $this->app->bind(MeetingProvider::class, fn () => new class implements MeetingProvider
        {
            public function create(Booking $booking): MeetingResult
            {
                throw new RuntimeException('Google is down');
            }

            public function cancel(Booking $booking): void {}

            public function name(): string
            {
                return 'fake';
            }
        });

        $booking = Booking::factory()->pending()->future()->create();

        $this->assertApiError(
            $this->postJson($this->url.'/'.$booking->id.'/meeting'),
            502,
            'MEETING_CREATION_FAILED',
        );

        $this->assertSame('failed', $booking->refresh()->meeting_status->value);
    }

    public function test_bkg_05_only_pending_bookings(): void
    {
        $admin = $this->createAdmin();
        $admin->givePermissionTo('manage-meetings');
        $this->actingAsAdmin($admin);

        $booking = Booking::factory()->completed()->create();

        $this->assertApiError(
            $this->postJson($this->url.'/'.$booking->id.'/meeting'),
            422,
            'BOOKING_INVALID_STATUS',
        );
    }

    /*
    |----------------------------------------------------------------------
    | BKG-06 GET /api/v1/admin/bookings/calendar
    |----------------------------------------------------------------------
    */

    public function test_bkg_06_returns_the_calendar_items_not_paginated(): void
    {
        $this->actingAsAdmin();

        Booking::factory()->create([
            'starts_at' => '2026-09-10 10:00:00',
            'ends_at' => '2026-09-10 10:30:00',
        ]);
        Booking::factory()->create([
            'starts_at' => '2026-10-25 10:00:00', // outside the range
            'ends_at' => '2026-10-25 10:30:00',
        ]);

        $response = $this->getJson($this->url.'/calendar?from=2026-09-01&to=2026-09-30');

        $this->assertApiSuccess($response);
        $this->assertIsArray($response->json('data'));
        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(
            ['id', 'reference', 'status', 'starts_at', 'ends_at', 'consultant', 'client'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_bkg_06_a_range_over_62_days_returns_422(): void
    {
        $this->actingAsAdmin();

        $this->getJson($this->url.'/calendar?from=2026-09-01&to=2026-12-31')->assertUnprocessable();
    }

    public function test_bkg_06_a_consultant_only_sees_his_own_items(): void
    {
        $consultant = $this->createConsultant();
        $this->actingAsConsultant($consultant);

        Booking::factory()->create([
            'consultant_id' => $consultant->id,
            'starts_at' => '2026-09-10 10:00:00',
            'ends_at' => '2026-09-10 10:30:00',
        ]);
        Booking::factory()->create([
            'starts_at' => '2026-09-11 10:00:00',
            'ends_at' => '2026-09-11 10:30:00',
        ]);

        $response = $this->getJson($this->url.'/calendar?from=2026-09-01&to=2026-09-30');

        $this->assertCount(1, $response->json('data'));
    }

    /*
    |----------------------------------------------------------------------
    | BKG-07 POST /api/v1/admin/bookings/{booking}/mark-refunded
    |----------------------------------------------------------------------
    */

    public function test_bkg_07_marks_a_requested_refund_as_refunded(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->cancelled()->create([
            'payment_status' => 'paid',
            'refund_status' => 'requested',
        ]);
        $payment = Payment::factory()->paid()->create(['booking_id' => $booking->id]);

        $response = $this->postJson($this->url.'/'.$booking->id.'/mark-refunded', ['note' => 'Done via bank']);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.refund_status', 'refunded')
            ->assertJsonPath('data.payment_status', 'refunded');

        $this->assertSame(PaymentRecordStatus::Refunded, $payment->refresh()->status);
    }

    public function test_bkg_07_marking_refunded_cancels_the_subscription_the_booking_paid_for(): void
    {
        $this->actingAsAdmin();
        $subscription = ClientSubscription::factory()->create(['status' => SubscriptionStatus::Active]);
        $booking = Booking::factory()->cancelled()->create([
            'client_id' => $subscription->client_id,
            'client_subscription_id' => $subscription->id,
            'payment_status' => 'paid',
            'refund_status' => 'requested',
        ]);
        Payment::factory()->paid()->create(['booking_id' => $booking->id]);

        $this->postJson($this->url.'/'.$booking->id.'/mark-refunded')->assertOk();

        // The client got the money back — the package cannot stay active.
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->refresh()->status);
    }

    public function test_bkg_07_only_when_a_refund_was_requested(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->cancelled()->create(['refund_status' => 'none']);

        $this->assertApiError(
            $this->postJson($this->url.'/'.$booking->id.'/mark-refunded'),
            422,
            'BOOKING_INVALID_STATUS',
        );
    }

    public function test_bkg_07_requires_the_refund_payments_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-bookings']);
        $this->actingAsAdmin($staff);

        $booking = Booking::factory()->cancelled()->create(['refund_status' => 'requested']);

        $this->postJson($this->url.'/'.$booking->id.'/mark-refunded')->assertForbidden();
    }
}
