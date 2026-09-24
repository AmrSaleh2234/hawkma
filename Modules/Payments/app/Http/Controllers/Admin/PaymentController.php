<?php

namespace Modules\Payments\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\ApiController;
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
