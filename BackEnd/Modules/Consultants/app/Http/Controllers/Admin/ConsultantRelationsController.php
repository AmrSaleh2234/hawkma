<?php

namespace Modules\Consultants\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Bookings\Http\Requests\BookingIndexRequest;
use Modules\Bookings\Http\Resources\BookingResource;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Http\Resources\ClientResource;
use Modules\Clients\Models\Client;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Reports\Http\Resources\ReportResource;
use Modules\Reports\Models\Report;
use Modules\Users\Models\User;

/**
 * The consultant relation endpoints (CON-14..18). A consultant may only
 * ever see his own relations (ConsultantPolicy::view).
 */
class ConsultantRelationsController extends ApiController
{
    /**
     * CON-14 GET /api/v1/admin/consultants/{consultant}/clients
     * Perm: view-clients
     *
     * The distinct clients with at least one booking with this consultant
     * (any status except pending_payment), with bookings_count and
     * last_booking_at for this consultant only.
     */
    public function clients(Request $request, User $consultant): JsonResponse
    {
        $this->authorize('view', $consultant);

        $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $visibleBooking = fn (Builder $q) => $q
            ->where('consultant_id', $consultant->id)
            ->where('status', '!=', BookingStatus::PendingPayment);

        $query = Client::query()
            ->whereHas('bookings', $visibleBooking)
            ->withCount(['bookings as bookings_count' => $visibleBooking])
            ->withMax(['bookings as last_booking_at' => $visibleBooking], 'starts_at');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        $clients = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(ClientResource::collection($clients));
    }

    /**
     * CON-15 GET /api/v1/admin/consultants/{consultant}/bookings
     * Perm: view-bookings
     *
     * The "Pending bookings" tab = ?status=pending.
     */
    public function bookings(BookingIndexRequest $request, User $consultant): JsonResponse
    {
        $this->authorize('view', $consultant);

        $filters = $request->validated();
        unset($filters['consultant_id']); // fixed to this consultant

        $query = Booking::query()
            ->with(['client', 'consultant.media', 'package', 'latestPayment'])
            ->where('consultant_id', $consultant->id)
            ->filter($filters);

        $sort = (string) ($filters['sort'] ?? '-starts_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (! in_array($column, ['starts_at', 'created_at', 'amount'], true)) {
            $column = 'starts_at';
            $direction = 'desc';
        }
        $query->orderBy($column, $direction);

        $bookings = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(BookingResource::collection($bookings));
    }

    /**
     * CON-16 GET /api/v1/admin/consultants/{consultant}/pending-reports
     * Perm: view-reports
     *
     * The completed bookings whose report is still pending — the list the
     * consultant has to write reports for.
     */
    public function pendingReports(Request $request, User $consultant): JsonResponse
    {
        $this->authorize('view', $consultant);

        $bookings = Booking::query()
            ->with(['client', 'consultant.media', 'package', 'latestPayment'])
            ->where('consultant_id', $consultant->id)
            ->where('status', BookingStatus::Completed)
            ->where('report_status', ReportStatus::Pending)
            ->orderByDesc('starts_at')
            ->paginate(QueryFilters::perPage($request));

        return $this->paginated(BookingResource::collection($bookings));
    }

    /**
     * CON-17 GET /api/v1/admin/consultants/{consultant}/reports
     * Perm: view-reports
     *
     * This consultant's reports. Query: search (title, client), date_from,
     * date_to.
     */
    public function reports(Request $request, User $consultant): JsonResponse
    {
        $this->authorize('view', $consultant);

        $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Report::query()
            ->with(['booking', 'consultant', 'client'])
            ->where('consultant_id', $consultant->id)
            ->when($request->query('date_from'), fn (Builder $q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($request->query('date_to'), fn (Builder $q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->latest();

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('client', fn (Builder $c) => $c
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%"));
            });
        }

        $reports = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(ReportResource::collection($reports));
    }

    /**
     * CON-18 GET /api/v1/admin/consultants/{consultant}/stats
     * Perm: view-consultants
     */
    public function stats(User $consultant): JsonResponse
    {
        $this->authorize('view', $consultant);

        $base = fn () => Booking::query()->where('consultant_id', $consultant->id);

        $upcoming = $base()
            ->where('status', BookingStatus::Pending)
            ->upcoming()
            ->with(['client', 'consultant.media', 'package', 'latestPayment'])
            ->limit(5)
            ->get();

        $reports = Report::query()->where('consultant_id', $consultant->id)->count();

        return $this->success([
            'pending_bookings' => $base()->where('status', BookingStatus::Pending)->count(),
            'completed_bookings' => $base()->where('status', BookingStatus::Completed)->count(),
            'cancelled_bookings' => $base()->where('status', BookingStatus::Cancelled)->count(),
            'pending_reports' => $base()
                ->where('status', BookingStatus::Completed)
                ->where('report_status', ReportStatus::Pending)
                ->count(),
            'reports' => $reports,
            'clients' => Client::query()
                ->whereHas('bookings', fn (Builder $q) => $q
                    ->where('consultant_id', $consultant->id)
                    ->where('status', '!=', BookingStatus::PendingPayment))
                ->count(),
            'upcoming_bookings' => BookingResource::collection($upcoming),
        ]);
    }
}
