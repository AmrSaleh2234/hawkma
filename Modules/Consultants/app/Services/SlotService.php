<?php

namespace Modules\Consultants\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Consultants\Contracts\BusyTimeProvider;
use Modules\Consultants\Models\ConsultantAvailability;
use Modules\Users\Models\User;

/**
 * Slot generation (plan §9.3). All times are in the application timezone
 * (Asia/Riyadh).
 */
class SlotService
{
    public function __construct(protected BusyTimeProvider $busyTimeProvider) {}

    /**
     * The bookable slots of a consultant on a given date.
     *
     * @return array<int, array{time: string, starts_at: string, ends_at: string}>
     */
    public function getSlots(User $consultant, CarbonImmutable $date): array
    {
        if (! $consultant->isConsultant() || ! $consultant->is_active) {
            return [];
        }

        $today = CarbonImmutable::now()->startOfDay();
        $day = $date->startOfDay();

        if ($day->lt($today) || $day->gt($today->addDays($this->maxAdvanceDays()))) {
            return [];
        }

        $ranges = $consultant->availabilities()->forDay($day->dayOfWeek)->get();

        if ($ranges->isEmpty()) {
            return [];
        }

        $timeOffs = $consultant->timeOffs()->whereDate('date', $day->toDateString())->get();

        // A time off without times blocks the whole day.
        if ($timeOffs->contains(fn ($off) => $off->start_time === null)) {
            return [];
        }

        $offRanges = $this->timeOffRanges($day, $timeOffs);
        $busy = $this->busyTimeProvider->busyRanges($consultant->id, $day, $day->endOfDay());
        $earliest = CarbonImmutable::now()->addMinutes($this->minNoticeMinutes());

        return $this->computeSlots($day, $ranges, $offRanges, $busy, $earliest);
    }

    /**
     * The dates of the given month (between today and the horizon) that have
     * at least one slot. Loads the data with 3 queries, not one per day.
     *
     * @return array<int, string> `Y-m-d` dates.
     */
    public function getAvailableDates(User $consultant, CarbonImmutable $month): array
    {
        if (! $consultant->isConsultant() || ! $consultant->is_active) {
            return [];
        }

        $today = CarbonImmutable::now()->startOfDay();
        $horizon = $today->addDays($this->maxAdvanceDays());

        $from = $month->startOfMonth()->gt($today) ? $month->startOfMonth() : $today;
        $to = $month->endOfMonth()->lt($horizon) ? $month->endOfMonth() : $horizon;

        if ($from->gt($to)) {
            return [];
        }

        // Query 1: the weekly schedule. Query 2: the time offs of the window.
        // Query 3: the busy ranges of the window.
        $availabilities = $consultant->availabilities()->get()->groupBy('day_of_week');
        $timeOffs = $consultant->timeOffs()
            ->between($from->toDateString(), $to->toDateString())
            ->get()
            ->groupBy(fn ($off) => $off->date->toDateString());
        $busy = $this->busyTimeProvider->busyRanges($consultant->id, $from, $to->endOfDay());

        $dates = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $ranges = $availabilities->get($day->dayOfWeek, collect());

            if ($ranges->isEmpty()) {
                continue;
            }

            $dayOffs = $timeOffs->get($day->toDateString(), collect());

            if ($dayOffs->contains(fn ($off) => $off->start_time === null)) {
                continue;
            }

            $earliest = CarbonImmutable::now()->addMinutes($this->minNoticeMinutes());

            $slots = $this->computeSlots($day, $ranges, $this->timeOffRanges($day, $dayOffs), $busy, $earliest);

            if ($slots !== []) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }

    /**
     * Used when a booking is created: is this exact start time bookable?
     */
    public function isSlotAvailable(User $consultant, CarbonImmutable $startsAt): bool
    {
        return in_array(
            $startsAt->format('H:i'),
            array_column($this->getSlots($consultant, $startsAt->startOfDay()), 'time'),
            true,
        );
    }

    /**
     * @param  iterable<int, ConsultantAvailability>  $ranges
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $offRanges
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy
     * @return array<int, array{time: string, starts_at: string, ends_at: string}>
     */
    protected function computeSlots(CarbonImmutable $day, iterable $ranges, array $offRanges, array $busy, CarbonImmutable $earliest): array
    {
        $step = $this->stepMinutes();
        $duration = $this->durationMinutes();
        $slots = [];

        foreach ($ranges as $range) {
            $cursor = $this->atTime($day, $range->start_time);
            $rangeEnd = $this->atTime($day, $range->end_time);

            while ($cursor->addMinutes($duration)->lte($rangeEnd)) {
                $slotEnd = $cursor->addMinutes($duration);

                if ($cursor->gte($earliest)
                    && ! $this->overlapsAny($cursor, $slotEnd, $offRanges)
                    && ! $this->overlapsAny($cursor, $slotEnd, $busy)) {
                    $slots[$cursor->format('H:i')] = [
                        'time' => $cursor->format('H:i'),
                        'starts_at' => $cursor->toIso8601String(),
                        'ends_at' => $slotEnd->toIso8601String(),
                    ];
                }

                $cursor = $cursor->addMinutes($step);
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /**
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    protected function timeOffRanges(CarbonImmutable $day, Collection $timeOffs): array
    {
        return $timeOffs
            ->filter(fn ($off) => $off->start_time !== null)
            ->map(fn ($off) => [$this->atTime($day, $off->start_time), $this->atTime($day, $off->end_time)])
            ->values()
            ->all();
    }

    protected function atTime(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', ConsultantAvailability::toHi($time)));

        return $day->setTime($hour, $minute);
    }

    /**
     * overlap(aStart, aEnd, bStart, bEnd) = aStart < bEnd AND bStart < aEnd
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     */
    protected function overlapsAny(CarbonImmutable $start, CarbonImmutable $end, array $ranges): bool
    {
        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            if ($start->lt($rangeEnd) && $rangeStart->lt($end)) {
                return true;
            }
        }

        return false;
    }

    protected function stepMinutes(): int
    {
        return (int) config('bookings.slot_minutes', 30);
    }

    protected function durationMinutes(): int
    {
        return (int) config('bookings.duration_minutes', 30);
    }

    protected function minNoticeMinutes(): int
    {
        return (int) config('bookings.min_notice_minutes', 60);
    }

    protected function maxAdvanceDays(): int
    {
        return (int) config('bookings.max_advance_days', 60);
    }
}
