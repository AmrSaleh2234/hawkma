<?php

namespace Modules\Bookings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Bookings\Contracts\MeetingProvider;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\MeetingStatus;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Notifications\BookingConfirmedNotification;
use Modules\Bookings\Notifications\NewBookingNotification;

/**
 * Creates the Google Meet event for a booking, then notifies both parties
 * (plan §9.7). On final failure the client still gets the confirmation
 * email, without a link ("the link will be sent later").
 */
class CreateMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly Booking $booking) {}

    public function handle(MeetingProvider $provider): void
    {
        $booking = $this->booking->fresh();

        // The booking may have been cancelled while the job waited.
        if ($booking === null || $booking->status !== BookingStatus::Pending) {
            return;
        }

        $booking->forceFill(['meeting_status' => MeetingStatus::Pending])->save();

        $result = $provider->create($booking);

        $booking->forceFill([
            'meeting_provider' => $provider->name(),
            'meeting_status' => MeetingStatus::Created,
            'meeting_url' => $result->joinUrl,
            'meeting_event_id' => $result->eventId,
        ])->save();

        $booking->client->notify(new BookingConfirmedNotification($booking));
        $booking->consultant->notify(new NewBookingNotification($booking));
    }

    public function failed(\Throwable $e): void
    {
        $booking = $this->booking->fresh();

        if ($booking === null) {
            return;
        }

        $booking->forceFill(['meeting_status' => MeetingStatus::Failed])->save();

        // The confirmation still goes out, without a link.
        $booking->client->notify(new BookingConfirmedNotification($booking, withLink: false));
    }
}
