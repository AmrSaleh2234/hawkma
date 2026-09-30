<?php

namespace Modules\Payments\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\Money;
use Modules\Core\Support\QueryFilters;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Http\Resources\PaymentResource;
use Modules\Payments\Models\Payment;

class PaymentController extends ApiController
{
    /**
     * PAY-01 GET /api/v1/admin/payments — Perm: view-payments
     *
     * Scoped: a consultant only sees the payments of his own bookings.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(PaymentRecordStatus::class)],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:191'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Payment::query()
            ->with(['booking', 'client'])
            ->visibleTo($request->user('admin'))
            ->when($request->query('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->query('client_id'), fn (Builder $q, $id) => $q->where('client_id', $id))
            ->when($request->query('booking_id'), fn (Builder $q, $id) => $q->where('booking_id', $id))
            ->when($request->query('date_from'), fn (Builder $q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($request->query('date_to'), fn (Builder $q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'));

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('gateway_payment_id', 'like', "%{$search}%")
                    ->orWhereHas('booking', fn (Builder $b) => $b->where('reference', 'like', "%{$search}%"))
                    ->orWhereHas('client', fn (Builder $c) => $c->where('company_name', 'like', "%{$search}%"));
            });
        }

        $payments = $query->latest()->paginate(QueryFilters::perPage($request));

        return $this->paginated(PaymentResource::collection($payments));
    }

    /**
     * PAY-01b GET /api/v1/admin/payments/stats — Perm: view-payments
     *
     * Aggregated amounts for the payments page charts and summary cards.
     * Respects status, date_from, date_to filters.
     */
    public function stats(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(PaymentRecordStatus::class)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $baseQuery = fn () => Payment::query()
            ->visibleTo($request->user('admin'))
            ->when($request->query('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->query('date_from'), fn (Builder $q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($request->query('date_to'), fn (Builder $q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'));

        $totalAmount = (int) $baseQuery()->sum('amount');

        $byStatus = collect(PaymentRecordStatus::cases())
            ->map(function (PaymentRecordStatus $status) use ($baseQuery) {
                $rows = $baseQuery()
                    ->where('status', $status)
                    ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
                    ->first();

                return [
                    'status' => $status->value,
                    'count' => (int) $rows->count,
                    'amount' => (int) $rows->amount,
                    'amount_formatted' => Money::format((int) $rows->amount),
                ];
            })
            ->all();

        $byGateway = $baseQuery()
            ->select('gateway', DB::raw('COUNT(*) as count'))
            ->groupBy('gateway')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['gateway' => $row->gateway, 'count' => (int) $row->count])
            ->all();

        $byMonth = $baseQuery()
            ->selectRaw(QueryFilters::monthExpression('created_at').' as month, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->groupByRaw('month')
            ->orderByRaw('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'count' => (int) $row->count,
                'amount' => (int) $row->amount,
                'amount_formatted' => Money::format((int) $row->amount),
            ])
            ->all();

        return $this->success([
            'total_amount' => $totalAmount,
            'total_amount_formatted' => Money::format($totalAmount),
            'by_status' => $byStatus,
            'by_gateway' => $byGateway,
            'by_month' => $byMonth,
        ]);
    }

    /**
     * PAY-02 GET /api/v1/admin/payments/{payment} — Perm: view-payments
     *
     * Never returns gateway_response (the model hides it).
     */
    public function show(Request $request, string $payment): JsonResponse
    {
        $payment = Payment::query()
            ->with(['booking.consultant', 'client'])
            ->visibleTo($request->user('admin'))
            ->findOrFail($payment);

        $booking = $payment->booking;

        return $this->success([
            'payment' => PaymentResource::make($payment),
            'booking' => [
                'id' => $booking->id,
                'reference' => $booking->reference,
                'status' => $booking->status->value,
                'date' => $booking->starts_at->format('Y-m-d'),
                'time' => $booking->starts_at->format('H:i'),
                'consultant' => [
                    'id' => $booking->consultant->id,
                    'name' => $booking->consultant->name,
                ],
            ],
        ]);
    }
}
