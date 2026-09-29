<?php

namespace Modules\Bookings\Services;

use Illuminate\Support\Facades\DB;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\PaymentStatus;
use Modules\Bookings\Enums\RefundStatus;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Bookings\Jobs\CancelMeetingJob;
use Modules\Bookings\Jobs\CreateMeetingJob;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Notifications\BookingCancelledNotification;
use Modules\Clients\Models\Client;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Packages\Services\SubscriptionService;
use Modules\Payments\Models\Payment;
use Modules\Payments\Notifications\PaymentFailedNotification;
use Modules\Users\Models\User;

/**
 * Every booking transition from plan §9.1. Anything else throws
 * BusinessException(BOOKING_INVALID_STATUS). Each method re-reads the
 * booking with lockForUpdate() inside a transaction.
 */
class BookingStateMachine
{
    public function __construct(protected SubscriptionService $subscriptions) {}

    /**
     * pending_payment → pending: the payment succeeded.
     *
     * Creates the subscription (§9.4) with consultations_used = 1 for the
     * booking that paid, clears the expiry, and dispatches CreateMeetingJob.
     */
    public function markPaid(Booking $booking, Payment $payment): Booking
    {
        $booking = DB::transaction(function () use ($booking, $payment): Booking {
            $locked = $this->lock($booking);

            if ($locked->status !== BookingStatus::PendingPayment) {
                throw new BusinessException(ErrorCode::BookingInvalidStatus);
            }

            $subscription = $this->subscriptions->activateFromPayment($locked->client, $locked->package, $payment);
            $this->subscriptions->consume($subscription);

            $locked->forceFill([
                'status' => BookingStatus::Pending,
                'payment_status' => PaymentStatus::Paid,
                'expires_at' => null,
                'client_subscription_id' => $subscription->id,
            ])->save();

            return $locked;
        });

        CreateMeetingJob::dispatch($booking);

        return $booking;
    }

    /**
     * pending_payment → cancelled: the payment failed.
     */
    public function markPaymentFailed(Booking $booking, Payment $payment, string $reason): Booking
    {
        $booking = DB::transaction(function () use ($booking, $payment, $reason): Booking {
            $locked = $this->lock($booking);

            if ($locked->status !== BookingStatus::PendingPayment) {
                throw new BusinessException(ErrorCode::BookingInvalidStatus);
            }

            if ($payment->failure_reason === null) {
                $payment->forceFill(['failure_reason' => $reason])->save();
            }

            $locked->forceFill([
                'status' => BookingStatus::Cancelled,
                'payment_status' => PaymentStatus::Failed,
                'cancelled_at' => now(),
                'cancelled_by_type' => 'system',
                'cancelled_by_id' => null,
                'cancellation_reason' => 'payment_failed',
            ])->save();

            return $locked;
        });

        $booking->client->notify(new PaymentFailedNotification($booking));

        return $booking;
    }

    /**
     * pending_payment → cancelled: the payment window expired (scheduled
     * command). No notifications (§9.11 excludes payment timeouts).
     */
    public function expire(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $locked = $this->lock($booking);

            if ($locked->status !== BookingStatus::PendingPayment) {
                throw new BusinessException(ErrorCode::BookingInvalidStatus);
            }

            $locked->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_type' => 'system',
                'cancelled_by_id' => null,
                'cancellation_reason' => 'payment_timeout',
            ])->save();

            return $locked;
        });
    }

    /**
     * pending → completed: only once the booking has started.
     */
    public function complete(Booking $booking, User $by): Booking
    {
        return DB::transaction(function () use ($booking, $by): Booking {
            $locked = $this->lock($booking);

            if ($locked->status !== BookingStatus::Pending) {
                throw new BusinessException(ErrorCode::BookingInvalidStatus);
            }

            if (now()->lt($locked->starts_at)) {
                throw new BusinessException(ErrorCode::BookingNotStarted);
            }

            $locked->forceFill([
                'status' => BookingStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $by->id,
                'report_status' => ReportStatus::Pending,
            ])->save();

            return $locked;
        });
    }

    /**
     * pending|pending_payment → cancelled.
     *
     * A staff user may always cancel; the client may cancel a pending booking
     * only up to client_cancel_hours before it starts (a pending_payment
     * booking can always be cancelled by the client). Side effects: the
     * consultation returns to the subscription quota, refund_status =
     * requested when the booking was paid, the meeting event is deleted, and
     * both parties are notified.
     */
    public function cancel(Booking $booking, User|Client|null $by, ?string $reason): Booking
    {
        $booking = DB::transaction(function () use ($booking, $by, $reason): Booking {
            $locked = $this->lock($booking);

            if (! in_array($locked->status, [BookingStatus::Pending, BookingStatus::PendingPayment], true)) {
                throw new BusinessException(ErrorCode::BookingInvalidStatus);
            }

            if ($by instanceof Client && $locked->status === BookingStatus::Pending) {
                $hours = (int) config('bookings.client_cancel_hours', 24);

                if (now()->addHours($hours)->gt($locked->starts_at)) {
                    throw new BusinessException(ErrorCode::BookingCancelWindowPassed);
                }
            }

            if ($locked->client_subscription_id !== null && $locked->status === BookingStatus::Pending) {
                $this->subscriptions->release($locked->subscription);
            }

            $locked->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_type' => match (true) {
                    $by instanceof User => 'user',
                    $by instanceof Client => 'client',
                    default => 'system',
                },
                'cancelled_by_id' => $by?->id,
                'cancellation_reason' => $reason,
                'refund_status' => $locked->payment_status === PaymentStatus::Paid
                    ? RefundStatus::Requested
                    : $locked->refund_status,
            ])->save();

            return $locked;
        });

        if ($booking->meeting_event_id !== null) {
            CancelMeetingJob::dispatch($booking);
        }

        if ($by !== null) {
            $booking->client->notify(new BookingCancelledNotification($booking, 'client'));
            $booking->consultant->notify(new BookingCancelledNotification($booking, 'consultant'));
        }

        return $booking;
    }

    /**
     * completed + report_status=pending → completed + report_status=uploaded.
     * Idempotent when the report was already uploaded (a re-upload).
     */
    public function markReportUploaded(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $locked = $this->lock($booking);

            if ($locked->status !== BookingStatus::Completed) {
                throw new BusinessException(ErrorCode::BookingInvalidStatus);
            }

            if ($locked->report_status === ReportStatus::Pending) {
                $locked->forceFill(['report_status' => ReportStatus::Uploaded])->save();
            }

            return $locked;
        });
    }

    protected function lock(Booking $booking): Booking
    {
        return Booking::query()->lockForUpdate()->findOrFail($booking->id);
    }
}
