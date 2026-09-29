<?php

namespace Modules\Bookings\Meetings;

use Illuminate\Support\Str;
use Modules\Bookings\Contracts\MeetingProvider;
use Modules\Bookings\DTO\MeetingResult;
use Modules\Bookings\Models\Booking;

/**
 * The local-development and test meeting driver (plan §9.7).
 */
class FakeMeetingProvider implements MeetingProvider
{
    public function create(Booking $booking): MeetingResult
    {
        return new MeetingResult(
            eventId: 'fake-'.Str::uuid(),
            joinUrl: 'https://meet.google.com/fak-'.Str::lower(Str::random(4)).'-'.Str::lower(Str::random(3)),
        );
    }

    public function cancel(Booking $booking): void
    {
        // Nothing to cancel in the fake driver.
    }

    public function name(): string
    {
        return 'fake';
    }
}
