<?php

namespace Modules\Bookings\Support;

use Carbon\CarbonImmutable;
use Modules\Bookings\Models\Booking;
use Modules\Consultants\Contracts\BusyTimeProvider;

/**
 * The Bookings implementation of BusyTimeProvider (plan §9.3 step 6): the
 * blocking bookings of a consultant inside a window.
 */
class BookingsBusyTimeProvider implements BusyTimeProvider
{
    /**
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function busyRanges(int $consultantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Booking::query()
            ->forConsultant($consultantId)
            ->blocking()
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Booking $booking) => [
                CarbonImmutable::instance($booking->starts_at),
                CarbonImmutable::instance($booking->ends_at),
            ])
            ->all();
    }
}
