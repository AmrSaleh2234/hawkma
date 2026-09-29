<?php

namespace Modules\Bookings\Contracts;

use Modules\Bookings\DTO\MeetingResult;
use Modules\Bookings\Models\Booking;

interface MeetingProvider
{
    /**
     * Create the meeting/event for a booking.
     */
    public function create(Booking $booking): MeetingResult;

    /**
     * Delete the meeting/event of a booking. Errors are logged and ignored.
     */
    public function cancel(Booking $booking): void;

    /**
     * 'fake' | 'google_meet'
     */
    public function name(): string;
}
