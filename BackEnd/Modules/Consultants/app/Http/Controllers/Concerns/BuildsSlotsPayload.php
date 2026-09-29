<?php

namespace Modules\Consultants\Http\Controllers\Concerns;

use Carbon\CarbonImmutable;
use Modules\Consultants\Http\Resources\SlotResource;
use Modules\Consultants\Services\SlotService;
use Modules\Users\Models\User;

/**
 * The shared slots response of PUB-06 and CON-13 / MY-06.
 */
trait BuildsSlotsPayload
{
    protected function slotsPayload(User $consultant, string $date): array
    {
        $day = CarbonImmutable::createFromFormat('Y-m-d', $date, config('app.timezone'))->startOfDay();

        return [
            'date' => $day->toDateString(),
            'timezone' => config('app.timezone'),
            'slot_minutes' => (int) config('bookings.slot_minutes'),
            'duration_minutes' => (int) config('bookings.duration_minutes'),
            'slots' => SlotResource::collection(app(SlotService::class)->getSlots($consultant, $day)),
        ];
    }
}
