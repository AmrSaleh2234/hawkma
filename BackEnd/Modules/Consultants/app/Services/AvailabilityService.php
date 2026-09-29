<?php

namespace Modules\Consultants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Bookings\Models\Booking;
use Modules\Consultants\Models\ConsultantAvailability;
use Modules\Users\Models\User;

class AvailabilityService
{
    /**
     * Replace the weekly schedule as a whole (plan §9.2): days not sent are
     * cleared. Runs in one transaction.
     *
     * @param  array<int, array{day_of_week: int, ranges: array<int, array{start_time: string, end_time: string}>}>  $days
     */
    public function replaceWeek(User $consultant, array $days): void
    {
        DB::transaction(function () use ($consultant, $days) {
            $consultant->availabilities()->delete();

            $rows = [];

            foreach ($days as $day) {
                foreach ($day['ranges'] ?? [] as $range) {
                    $rows[] = [
                        'consultant_id' => $consultant->id,
                        'day_of_week' => (int) $day['day_of_week'],
                        'start_time' => $range['start_time'],
                        'end_time' => $range['end_time'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if ($rows !== []) {
                ConsultantAvailability::insert($rows);
            }
        });

        $consultant->unsetRelation('availabilities');
    }

    /**
     * The number of future `pending` bookings that fall outside the new
     * availability (returned as `warnings.conflicting_bookings_count`).
     * Changing availability never cancels bookings.
     */
    public function countConflictingBookings(User $consultant): int
    {
        // The Booking model is introduced in Phase 9; until then no bookings
        // can exist, so nothing can conflict.
        if (! class_exists(Booking::class)) {
            return 0;
        }

        // TODO Phase 9: count future pending bookings whose starts_at is not
        // covered by any availability range and not on a time off.
        return 0;
    }
}
