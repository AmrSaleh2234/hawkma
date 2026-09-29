<?php

namespace Modules\Consultants\Contracts;

use Carbon\CarbonImmutable;

/**
 * Provides the busy time ranges of a consultant. Implemented by the Bookings
 * module in Phase 9 (a `NullBusyTimeProvider` is bound until then), which
 * keeps the Consultants module independent of Bookings.
 */
interface BusyTimeProvider
{
    /**
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}> List of [starts_at, ends_at] busy ranges.
     */
    public function busyRanges(int $consultantId, CarbonImmutable $from, CarbonImmutable $to): array;
}
