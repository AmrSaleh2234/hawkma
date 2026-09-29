<?php

namespace Modules\Bookings\Tests\Unit;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\PaymentStatus;
use Modules\Bookings\Enums\RefundStatus;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Bookings\Jobs\CancelMeetingJob;
use Modules\Bookings\Jobs\CreateMeetingJob;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Notifications\BookingCancelledNotification;
use Modules\Bookings\Services\BookingStateMachine;
use Modules\Core\Exceptions\BusinessException;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Notifications\PaymentFailedNotification;
use Tests\TestCase;

class BookingStateMachineTest extends TestCase
{
    protected BookingStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = app(BookingStateMachine::class);
        Queue::fake();
        Notification::fake();
    }

    /*
    |----------------------------------------------------------------------
    | markPaid: pending_payment → pending
    |----------------------------------------------------------------------
    */

    public function test_mark_paid_activates_the_subscription_and_dispatches_the_meeting_job(): void
    {
        $package = Package::factory()->iron()->create();
        $booking = Booking::factory()->pendingPayment()->create(['package_id' => $package->id]);
        $payment = Payment::factory()->create([
            'booking_id' => $booking->id,
            'status' => PaymentRecordStatus::Paid,
            'amount' => 190000,
        ]);

        $booking = $this->machine->markPaid($booking, $payment);

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(PaymentStatus::Paid, $booking->payment_status);
        $this->assertNull($booking->expires_at);

        $subscription = $booking->subscription;
        $this->assertNotNull($subscription);
        $this->assertSame($package->id, $subscription->package_id);
        $this->assertSame(2, $subscription->consultations_limit);
        $this->assertSame(1, $subscription->consultations_used);
        $this->assertSame(190000, $subscription->price_paid);
        $this->assertTrue($subscription->starts_at->isSameDay(now()));
        $this->assertTrue($subscription->ends_at->isSameDay(now()->addDays(30)));

        Queue::assertPushed(CreateMeetingJob::class, fn ($job) => $job->booking->is($booking));
    }

    public function test_mark_paid_on_a_pending_booking_throws(): void
    {
        $booking = Booking::factory()->pending()->create();
        $payment = Payment::factory()->create(['booking_id' => $booking->id]);

        try {
            $this->machine->markPaid($booking, $payment);
            $this->fail('expected BusinessException');
        } catch (BusinessException $e) {
            $this->assertSame('BOOKING_INVALID_STATUS', $e->errorCode->value);
        }

        Queue::assertNotPushed(CreateMeetingJob::class);
    }

    /*
    |----------------------------------------------------------------------
    | markPaymentFailed: pending_payment → cancelled
    |----------------------------------------------------------------------
    */

    public function test_mark_payment_failed_cancels_the_booking_and_notifies_the_client(): void
    {
        $booking = Booking::factory()->pendingPayment()->create();
        $payment = Payment::factory()->initiated()->create(['booking_id' => $booking->id]);

        $booking = $this->machine->markPaymentFailed($booking, $payment, 'Card declined');

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(PaymentStatus::Failed, $booking->payment_status);
        $this->assertSame('system', $booking->cancelled_by_type);
        $this->assertSame('payment_failed', $booking->cancellation_reason);
        $this->assertNotNull($booking->cancelled_at);

        Notification::assertSentTo($booking->client, PaymentFailedNotification::class);
    }

    public function test_mark_payment_failed_on_a_pending_booking_throws(): void
    {
        $booking = Booking::factory()->pending()->create();
        $payment = Payment::factory()->create(['booking_id' => $booking->id]);

        $this->expectException(BusinessException::class);

        $this->machine->markPaymentFailed($booking, $payment, 'x');
    }

    /*
    |----------------------------------------------------------------------
    | expire: pending_payment → cancelled (payment_timeout)
    |----------------------------------------------------------------------
    */

    public function test_expire_cancels_a_pending_payment_booking_without_notifying(): void
    {
        $booking = Booking::factory()->pendingPayment()->create([
            'expires_at' => now()->subMinute(),
        ]);

        $booking = $this->machine->expire($booking);

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame('system', $booking->cancelled_by_type);
        $this->assertSame('payment_timeout', $booking->cancellation_reason);
        $this->assertSame(PaymentStatus::Unpaid, $booking->payment_status);

        Notification::assertNothingSent();
    }

    public function test_expire_on_a_pending_booking_throws(): void
    {
        $booking = Booking::factory()->pending()->create();

        $this->expectException(BusinessException::class);

        $this->machine->expire($booking);
    }

    /*
    |----------------------------------------------------------------------
    | complete: pending → completed
    |----------------------------------------------------------------------
    */

    public function test_complete_after_the_start_time(): void
    {
        $admin = $this->createAdmin();
        $booking = Booking::factory()->pending()->past()->create();

        $booking = $this->machine->complete($booking, $admin);

        $this->assertSame(BookingStatus::Completed, $booking->status);
        $this->assertSame(ReportStatus::Pending, $booking->report_status);
        $this->assertSame($admin->id, $booking->completed_by);
        $this->assertNotNull($booking->completed_at);
    }

    public function test_complete_before_the_start_time_throws_booking_not_started(): void
    {
        $admin = $this->createAdmin();
        $booking = Booking::factory()->pending()->future()->create();

        try {
            $this->machine->complete($booking, $admin);
            $this->fail('expected BusinessException');
        } catch (BusinessException $e) {
            $this->assertSame('BOOKING_NOT_STARTED', $e->errorCode->value);
        }
    }

    public function test_complete_a_cancelled_booking_throws(): void
    {
        $admin = $this->createAdmin();
        $booking = Booking::factory()->cancelled()->create();

        $this->expectException(BusinessException::class);

        $this->machine->complete($booking, $admin);
    }

    /*
    |----------------------------------------------------------------------
    | cancel: pending|pending_payment → cancelled
    |----------------------------------------------------------------------
    */

    public function test_cancel_by_staff_releases_the_quota_and_requests_the_refund(): void
    {
        $admin = $this->createAdmin();
        $package = Package::factory()->iron()->create();
        $booking = Booking::factory()->pending()->future()->create([
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::Paid,
            'meeting_event_id' => 'fake-event-1',
        ]);
        $subscription = ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $booking->client_id,
            'consultations_used' => 1,
        ]);
        $booking->forceFill(['client_subscription_id' => $subscription->id])->save();

        $booking = $this->machine->cancel($booking, $admin, 'Schedule conflict');

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame('user', $booking->cancelled_by_type);
        $this->assertSame($admin->id, $booking->cancelled_by_id);
        $this->assertSame('Schedule conflict', $booking->cancellation_reason);
        $this->assertSame(RefundStatus::Requested, $booking->refund_status);

        // The consultation went back to the quota.
        $this->assertSame(0, $subscription->refresh()->consultations_used);

        Queue::assertPushed(CancelMeetingJob::class);
        Notification::assertSentTo($booking->client, BookingCancelledNotification::class);
        Notification::assertSentTo($booking->consultant, BookingCancelledNotification::class);
    }

    public function test_cancel_by_the_client_more_than_24h_before(): void
    {
        $booking = Booking::factory()->pending()->create([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
        ]);

        $booking = $this->machine->cancel($booking, $booking->client, 'Plans changed');

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame('client', $booking->cancelled_by_type);
    }

    public function test_cancel_by_the_client_inside_24h_throws(): void
    {
        $booking = Booking::factory()->pending()->create([
            'starts_at' => now()->addHours(5),
            'ends_at' => now()->addHours(5)->addMinutes(30),
        ]);

        try {
            $this->machine->cancel($booking, $booking->client, null);
            $this->fail('expected BusinessException');
        } catch (BusinessException $e) {
            $this->assertSame('BOOKING_CANCEL_WINDOW_PASSED', $e->errorCode->value);
        }
    }

    public function test_the_client_can_always_cancel_a_pending_payment_booking(): void
    {
        $booking = Booking::factory()->pendingPayment()->create([
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(2)->addMinutes(30),
        ]);

        $booking = $this->machine->cancel($booking, $booking->client, null);

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame('client', $booking->cancelled_by_type);
    }

    public function test_cancel_a_completed_booking_throws(): void
    {
        $admin = $this->createAdmin();
        $booking = Booking::factory()->completed()->create();

        $this->expectException(BusinessException::class);

        $this->machine->cancel($booking, $admin, 'nope');
    }

    /*
    |----------------------------------------------------------------------
    | markReportUploaded: completed + report pending → uploaded
    |----------------------------------------------------------------------
    */

    public function test_mark_report_uploaded(): void
    {
        $booking = Booking::factory()->withReportPending()->create();

        $booking = $this->machine->markReportUploaded($booking);

        $this->assertSame(ReportStatus::Uploaded, $booking->report_status);

        // Idempotent on a second call (a re-upload).
        $this->machine->markReportUploaded($booking);
        $this->assertSame(ReportStatus::Uploaded, $booking->report_status);
    }

    public function test_mark_report_uploaded_on_a_pending_booking_throws(): void
    {
        $booking = Booking::factory()->pending()->create();

        $this->expectException(BusinessException::class);

        $this->machine->markReportUploaded($booking);
    }
}
