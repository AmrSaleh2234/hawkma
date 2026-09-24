<?php

namespace Modules\Bookings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Bookings\Contracts\MeetingProvider;
use Modules\Bookings\Models\Booking;

/**
 * Deletes the meeting event of a booking. Errors are logged and ignored
 * (plan §9.7).
 */
class CancelMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly Booking $booking) {}

    public function handle(MeetingProvider $provider): void
    {
        try {
            $provider->cancel($this->booking);
        } catch (\Throwable $e) {
            Log::warning('CancelMeetingJob: could not cancel the meeting event', [
                'booking_id' => $this->booking->id,
                'meeting_event_id' => $this->booking->meeting_event_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
