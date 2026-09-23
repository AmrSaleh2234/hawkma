<?php

namespace Modules\Consultants\Support;

use Carbon\CarbonImmutable;
use Modules\Consultants\Contracts\BusyTimeProvider;

/**
 * Bound until Phase 9 replaces it with the Bookings implementation: no
 * bookings can exist yet, so no busy ranges.
 */
class NullBusyTimeProvider implements BusyTimeProvider
{
    public function busyRanges(int $consultantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }
}
