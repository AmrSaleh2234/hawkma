<?php

namespace Modules\Payments\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\Money;
use Modules\Core\Support\QueryFilters;
use Modules\Core\Support\Settings;
use Modules\Payments\Enums\PayoutStatus;
use Modules\Payments\Http\Resources\PayoutResource;
use Modules\Payments\Models\Payout;
use Modules\Payments\Services\PayoutService;

class PayoutController extends ApiController
{
    /**
     * PO-01 GET /api/v1/admin/payouts — Perm: view-payouts
     *
     * Scoped: a consultant only sees his own payouts.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(PayoutStatus::class)],
            'consultant_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:191'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Payout::query()
            ->with(['booking', 'consultant', 'releaser'])
            ->visibleTo($request->user('admin'))
            ->when($request->query('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->query('consultant_id'), fn (Builder $q, $id) => $q->where('consultant_id', $id))
            ->when($request->query('date_from'), fn (Builder $q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($request->query('date_to'), fn (Builder $q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'));

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('transfer_reference', 'like', "%{$search}%")
                    ->orWhereHas('booking', fn (Builder $b) => $b->where('reference', 'like', "%{$search}%"))
                    ->orWhereHas('consultant', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $payouts = $query->latest()->paginate(QueryFilters::perPage($request));

        return $this->paginated(PayoutResource::collection($payouts));
    }

    /**
     * PO-01b GET /api/v1/admin/payouts/stats — Perm: view-payouts
     *
     * Summary cards for the payouts page: totals by status plus what the
     * platform earned in fees.
     */
    public function stats(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $baseQuery = fn () => Payout::query()
            ->visibleTo($request->user('admin'))
            ->when($request->query('date_from'), fn (Builder $q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($request->query('date_to'), fn (Builder $q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'));

        $byStatus = collect(PayoutStatus::cases())
            ->map(function (PayoutStatus $status) use ($baseQuery) {
                $row = $baseQuery()
                    ->where('status', $status)
                    ->selectRaw('COUNT(*) as count, COALESCE(SUM(net_amount), 0) as net, COALESCE(SUM(fee_amount), 0) as fee')
                    ->first();

                return [
                    'status' => $status->value,
                    'count' => (int) $row->count,
                    'net_amount' => (int) $row->net,
                    'net_amount_formatted' => Money::format((int) $row->net),
                    'fee_amount' => (int) $row->fee,
                    'fee_amount_formatted' => Money::format((int) $row->fee),
                ];
            })
            ->all();

        return $this->success([
            'by_status' => $byStatus,
            'auto_transfer' => Settings::getBool(Settings::PAYOUT_AUTO_TRANSFER, false),
        ]);
    }

    /**
     * PO-02 GET /api/v1/admin/payouts/{payout} — Perm: view-payouts
     */
    public function show(Request $request, string $payout): JsonResponse
    {
        $payout = Payout::query()
            ->with(['booking.client', 'consultant', 'releaser'])
            ->visibleTo($request->user('admin'))
            ->findOrFail($payout);

        return $this->success(PayoutResource::make($payout));
    }

    /**
     * PO-03 POST /api/v1/admin/payouts/{payout}/release — Perm: release-payouts
     *
     * Manual mode (default): the admin has already transferred the money
     * outside the system; `transfer_reference` records the bank reference.
     * When the auto-transfer setting is on, the payout gateway performs the
     * transfer instead.
     */
    public function release(Request $request, string $payout, PayoutService $service): JsonResponse
    {
        $request->validate([
            'transfer_reference' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $payout = Payout::query()
            ->visibleTo($request->user('admin'))
            ->findOrFail($payout);

        $payout = $service->release(
            $payout,
            $request->user('admin'),
            $request->input('transfer_reference'),
            $request->input('note'),
        );

        return $this->success(
            PayoutResource::make($payout->load(['booking', 'consultant', 'releaser'])),
            __('core::messages.payout_released'),
        );
    }
}
