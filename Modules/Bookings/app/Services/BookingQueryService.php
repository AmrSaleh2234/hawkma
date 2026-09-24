<?php

namespace Modules\Bookings\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Bookings\Models\Booking;

/**
 * The shared booking list filters (BKG-01, CON-15, CLI-BKG-03, BKG-06).
 */
class BookingQueryService
{
    /**
     * @param  Builder<Booking>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Booking>
     */
    public static function filter(Builder $query, array $filters): Builder
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['report_status'] ?? null, fn (Builder $q, $status) => $q->where('report_status', $status))
            ->when($filters['payment_status'] ?? null, fn (Builder $q, $status) => $q->where('payment_status', $status))
            ->when($filters['consultant_id'] ?? null, fn (Builder $q, $id) => $q->where('consultant_id', $id))
            ->when($filters['client_id'] ?? null, fn (Builder $q, $id) => $q->where('client_id', $id))
            ->when($filters['package_id'] ?? null, fn (Builder $q, $id) => $q->where('package_id', $id));

        if (! empty($filters['date_from'])) {
            $query->where('starts_at', '>=', CarbonImmutable::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('starts_at', '<=', CarbonImmutable::parse($filters['date_to'])->endOfDay());
        }

        if (! empty($filters['upcoming'])) {
            $query->where('starts_at', '>=', now());
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('client', fn (Builder $c) => $c
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%"))
                    ->orWhereHas('consultant', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        return $query;
    }
}
