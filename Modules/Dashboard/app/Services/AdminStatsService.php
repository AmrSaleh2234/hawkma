<?php

namespace Modules\Dashboard\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Bookings\Http\Resources\BookingResource;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Core\Support\Money;
use Modules\Core\Support\QueryFilters;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Models\Payment;
use Modules\Reports\Models\Report;
use Modules\Users\Enums\UserType;
use Modules\Users\Models\User;

class AdminStatsService
{
    /**
     * DSH-01 (§10.14). A consultant gets the same keys scoped to his own
     * numbers, but `consultants` and `revenue` are omitted.
     *
     * @return array<string, mixed>
     */
    public function statsFor(User $user): array
    {
        // "Real" bookings only — a pending_payment hold is not a booking yet.
        $bookings = fn () => Booking::query()
            ->visibleTo($user)
            ->where('status', '!=', BookingStatus::PendingPayment);

        $data = [
            'bookings' => [
                'total' => $bookings()->count(),
                'pending' => $bookings()->where('status', BookingStatus::Pending)->count(),
                'completed' => $bookings()->where('status', BookingStatus::Completed)->count(),
                'cancelled' => $bookings()->where('status', BookingStatus::Cancelled)->count(),
                'today' => $bookings()
                    ->whereDate('starts_at', today())
                    ->where('status', '!=', BookingStatus::Cancelled)
                    ->count(),
                'by_month' => $this->bookingsByMonth($bookings()),
            ],
            'reports' => [
                'total' => Report::query()->visibleTo($user)->count(),
                'pending' => $bookings()
                    ->where('status', BookingStatus::Completed)
                    ->where('report_status', ReportStatus::Pending)
                    ->count(),
            ],
            'clients' => [
                'total' => Client::query()->visibleTo($user)->count(),
                'new_this_month' => Client::query()->visibleTo($user)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count(),
            ],
        ];

        if (! $user->isConsultant()) {
            $paid = fn () => Payment::query()->where('status', PaymentRecordStatus::Paid);
            $thisMonth = (int) $paid()->where('paid_at', '>=', now()->startOfMonth())->sum('amount');
            $total = (int) $paid()->sum('amount');

            $data['consultants'] = [
                'total' => User::query()->where('type', UserType::Consultant)->count(),
                'active' => User::query()->where('type', UserType::Consultant)->active()->count(),
            ];
            $data['revenue'] = [
                'this_month' => $thisMonth,
                'this_month_formatted' => Money::format($thisMonth),
                'total' => $total,
                'total_formatted' => Money::format($total),
                'by_month' => $this->revenueByMonth(),
            ];
        }

        $data['upcoming_bookings'] = BookingResource::collection(
            Booking::query()
                ->visibleTo($user)
                ->where('status', BookingStatus::Pending)
                ->upcoming()
                ->with(['client', 'consultant.media', 'package', 'latestPayment', 'report.media'])
                ->limit(5)
                ->get()
        );

        return $data;
    }

    /**
     * @param  Builder<Booking>  $query
     * @return array<int, array{month: string, count: int}>
     */
    protected function bookingsByMonth($query): array
    {
        return $query
            ->selectRaw(QueryFilters::monthExpression('starts_at').' as month, COUNT(*) as count')
            ->where('starts_at', '>=', now()->subMonths(11)->startOfMonth())
            ->groupByRaw('month')
            ->orderByRaw('month')
            ->get()
            ->map(fn ($row) => ['month' => $row->month, 'count' => (int) $row->count])
            ->all();
    }

    /**
     * @return array<int, array{month: string, amount: int, amount_formatted: string}>
     */
    protected function revenueByMonth(): array
    {
        return Payment::query()
            ->where('status', PaymentRecordStatus::Paid)
            ->where('paid_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw(QueryFilters::monthExpression('paid_at').' as month, SUM(amount) as amount')
            ->groupByRaw('month')
            ->orderByRaw('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'amount' => (int) $row->amount,
                'amount_formatted' => Money::format((int) $row->amount),
            ])
            ->all();
    }
}
