<?php

namespace Modules\Consultants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Consultants\Models\ConsultantAvailability;
use Modules\Consultants\Support\WeekDays;

/**
 * The weekly schedule of a consultant, always 7 items, Sunday first.
 * Wraps a User with the `availabilities` relation loaded.
 */
class AvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $byDay = $this->availabilities->groupBy('day_of_week');

        return [
            'days' => collect(range(0, 6))
                ->map(function (int $day) use ($byDay) {
                    $ranges = $byDay->get($day, collect());

                    return [
                        'day_of_week' => $day,
                        'day_name' => WeekDays::name($day, 'en'),
                        'day_name_ar' => WeekDays::name($day, 'ar'),
                        'is_working' => $ranges->isNotEmpty(),
                        'ranges' => $ranges
                            ->map(fn (ConsultantAvailability $range) => [
                                'id' => $range->id,
                                'start_time' => ConsultantAvailability::toHi($range->start_time),
                                'end_time' => ConsultantAvailability::toHi($range->end_time),
                            ])
                            ->values(),
                    ];
                })
                ->values(),
        ];
    }
}
