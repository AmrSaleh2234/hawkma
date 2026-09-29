<?php

namespace Modules\Consultants\Http\Requests\Concerns;

use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;

trait ValidatesAvailability
{
    /**
     * The semantic availability checks of plan §9.2 / CON-09, run in the
     * `after()` hook (wildcard rules are not reliable for nested arrays):
     * end_time > start_time, minutes are multiples of the slot size, the
     * range is at least the booking duration long, and no overlap inside a
     * day (touching is allowed).
     *
     * Violations throw 422 AVAILABILITY_OVERLAP with errors keyed like
     * `days.0.ranges.1`.
     *
     * @param  array<int, array<string, mixed>>  $days
     */
    protected function validateAvailabilityRanges(array $days, string $keyPrefix = 'days'): void
    {
        $slotMinutes = (int) config('bookings.slot_minutes', 30);
        $duration = (int) config('bookings.duration_minutes', 30);
        $errors = [];

        foreach ($days as $i => $day) {
            $parsed = [];

            foreach (($day['ranges'] ?? []) as $j => $range) {
                $start = $range['start_time'] ?? null;
                $end = $range['end_time'] ?? null;
                $key = "{$keyPrefix}.{$i}.ranges.{$j}";

                // Format errors are reported by the regular rules.
                if (! is_string($start) || ! is_string($end)
                    || ! preg_match('/^\d{2}:\d{2}$/', $start)
                    || ! preg_match('/^\d{2}:\d{2}$/', $end)) {
                    continue;
                }

                [$startHour, $startMinute] = array_map('intval', explode(':', $start));
                [$endHour, $endMinute] = array_map('intval', explode(':', $end));

                if ($startHour > 23 || $endHour > 23 || $startMinute > 59 || $endMinute > 59) {
                    continue;
                }

                $startMinutes = $startHour * 60 + $startMinute;
                $endMinutes = $endHour * 60 + $endMinute;

                if ($startMinute % $slotMinutes !== 0 || $endMinute % $slotMinutes !== 0) {
                    $errors[$key][] = __('consultants::availability.minutes_not_allowed', ['minutes' => $slotMinutes]);

                    continue;
                }

                if ($endMinutes <= $startMinutes) {
                    $errors[$key][] = __('consultants::availability.end_after_start');

                    continue;
                }

                if ($endMinutes - $startMinutes < $duration) {
                    $errors[$key][] = __('consultants::availability.too_short', ['minutes' => $duration]);

                    continue;
                }

                foreach ($parsed as [$otherStart, $otherEnd]) {
                    // Touching is OK: 10:00–12:00 and 12:00–14:00.
                    if ($startMinutes < $otherEnd && $otherStart < $endMinutes) {
                        $errors[$key][] = __('consultants::availability.overlap');

                        break;
                    }
                }

                $parsed[] = [$startMinutes, $endMinutes];
            }
        }

        if ($errors !== []) {
            throw new BusinessException(ErrorCode::AvailabilityOverlap, status: 422, errors: $errors);
        }
    }
}
