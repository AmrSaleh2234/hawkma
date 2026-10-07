<?php

namespace Modules\Consultants\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Consultants\Http\Requests\Admin\StoreConsultantRequest;
use Modules\Consultants\Http\Requests\Admin\UpdateConsultantRequest;
use Modules\Consultants\Http\Resources\ConsultantResource;
use Modules\Consultants\Services\ConsultantService;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\Money;
use Modules\Core\Support\QueryFilters;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Models\Payment;
use Modules\Users\Http\Requests\Admin\UpdateUserStatusRequest;
use Modules\Users\Models\User;

class ConsultantController extends ApiController
{
    public function __construct(protected ConsultantService $consultants) {}

    /**
     * CON-01 GET /api/v1/admin/consultants
     *
     * A consultant calling this (with the permission) gets only himself.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->consultants()->with(['roles', 'availabilities', 'media']);

        if ($request->user()->isConsultant()) {
            $query->whereKey($request->user()->id);
        }

        QueryFilters::apply(
            $query,
            $request,
            ['name', 'email', 'phone', 'specialization'],
            ['name', 'created_at'],
        );

        if ($request->has('is_active') && $request->query('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('specialization')) {
            $query->where('specialization', $request->string('specialization')->value());
        }

        // TODO Phase 9/10: load the stats counts (pending/completed bookings,
        // pending reports, reports, clients) once those tables exist.

        $consultants = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(ConsultantResource::collection($consultants));
    }

    /**
     * CON-01b GET /api/v1/admin/consultants/stats — Perm: view-consultants
     *
     * Aggregated counts for the consultants page charts and summary cards.
     */
    public function stats(Request $request): JsonResponse
    {
        $baseQuery = fn () => User::query()
            ->consultants()
            ->when(
                $request->user()->isConsultant(),
                fn (Builder $q) => $q->whereKey($request->user()->id),
            );

        $realBookings = fn (Builder $q) => $q->where('status', '!=', BookingStatus::PendingPayment);

        $bySpecialization = $baseQuery()
            ->whereNotNull('specialization')
            ->where('specialization', '!=', '')
            ->select('specialization', DB::raw('COUNT(*) as count'))
            ->groupBy('specialization')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'specialization' => $row->specialization,
                'count' => (int) $row->count,
            ])
            ->all();

        // Revenue: paid payments joined through bookings → consultant_id.
        $revenueByConsultant = Payment::query()
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->where('payments.status', PaymentRecordStatus::Paid)
            ->groupBy('bookings.consultant_id')
            ->selectRaw('bookings.consultant_id, SUM(payments.amount) as revenue')
            ->pluck('revenue', 'consultant_id');

        $topConsultants = $baseQuery()
            ->withCount(['consultantBookings as bookings_count' => $realBookings])
            ->withCount([
                'consultantBookings as completed_count' => fn (Builder $q) => $q
                    ->where('status', BookingStatus::Completed),
            ])
            ->withCount([
                'consultantBookings as pending_reports_count' => fn (Builder $q) => $q
                    ->where('status', BookingStatus::Completed)
                    ->where('report_status', ReportStatus::Pending),
            ])
            ->orderByDesc('bookings_count')
            ->limit(10)
            ->get()
            ->map(function (User $consultant) use ($revenueByConsultant) {
                $revenue = (int) ($revenueByConsultant[$consultant->id] ?? 0);

                return [
                    'consultant_id' => $consultant->id,
                    'consultant_name' => $consultant->name,
                    'bookings_count' => (int) $consultant->bookings_count,
                    'completed_count' => (int) $consultant->completed_count,
                    'pending_reports_count' => (int) $consultant->pending_reports_count,
                    'revenue' => $revenue,
                    'revenue_formatted' => Money::format($revenue),
                ];
            })
            ->all();

        return $this->success([
            'total' => $baseQuery()->count(),
            'active' => $baseQuery()->active()->count(),
            'by_specialization' => $bySpecialization,
            'top_consultants' => $topConsultants,
        ]);
    }

    /**
     * CON-19 GET /api/v1/admin/consultants/trashed — Perm: view-consultants
     *
     * Drafted (soft-deleted) consultants, most recently drafted first.
     * Query: search, specialization, per_page. A drafted consultant cannot
     * hold a token, so this list is effectively admins-only content.
     */
    public function trashed(Request $request): JsonResponse
    {
        $query = User::query()->consultants()->onlyTrashed()->with(['roles', 'media']);

        QueryFilters::apply(
            $query,
            $request,
            ['name', 'email', 'phone', 'specialization'],
            ['name', 'created_at', 'deleted_at'],
            '-deleted_at',
        );

        if ($request->filled('specialization')) {
            $query->where('specialization', $request->string('specialization')->value());
        }

        return $this->paginated(ConsultantResource::collection($query->paginate(QueryFilters::perPage($request))));
    }

    /**
     * CON-20 POST /api/v1/admin/consultants/{id}/restore — Perm: delete-consultants
     *
     * Restores a drafted consultant (roles and availability are kept; the
     * consultant logs in again — tokens were removed at draft time).
     */
    public function restore(string $id): JsonResponse
    {
        $consultant = User::query()->consultants()->withTrashed()->findOrFail($id);

        if (! $consultant->trashed()) {
            throw new BusinessException(ErrorCode::NotDrafted);
        }

        $consultant->restore();

        return $this->success(
            ConsultantResource::make($consultant->fresh('roles')),
            __('core::messages.restored'),
        );
    }

    /**
     * CON-02 POST /api/v1/admin/consultants
     *
     * The type and the consultant role are set automatically.
     */
    public function store(StoreConsultantRequest $request): JsonResponse
    {
        $consultant = $this->consultants->create($request->validated(), $request->file('photo'));

        return $this->created(
            ConsultantResource::make($consultant->load(['roles', 'availabilities'])),
            __('core::messages.created'),
        );
    }

    /**
     * CON-03 GET /api/v1/admin/consultants/{consultant}
     */
    public function show(User $consultant): JsonResponse
    {
        $this->authorize('view', $consultant);

        $consultant->load(['roles', 'availabilities']);

        // TODO Phase 9/10: load the stats counts.

        return $this->success(ConsultantResource::make($consultant)->withAvailability());
    }

    /**
     * CON-04 PUT /api/v1/admin/consultants/{consultant}
     */
    public function update(UpdateConsultantRequest $request, User $consultant): JsonResponse
    {
        $this->authorize('update', $consultant);

        $consultant = $this->consultants->update($consultant, $request->validated());

        return $this->success(
            ConsultantResource::make($consultant->fresh('roles')),
            __('core::messages.updated'),
        );
    }

    /**
     * CON-05 DELETE /api/v1/admin/consultants/{consultant}
     *
     * 409 CONSULTANT_HAS_FUTURE_BOOKINGS if there are future pending
     * bookings. Drafting keeps the consultant's data (soft delete) — he can
     * be restored with CON-20.
     */
    public function destroy(Request $request, User $consultant): JsonResponse
    {
        $this->authorize('delete', $consultant);

        $this->consultants->delete($consultant, $request->user());

        return $this->noContent(__('core::messages.deleted'));
    }

    /**
     * CON-06 PATCH /api/v1/admin/consultants/{consultant}/status
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $consultant): JsonResponse
    {
        $this->authorize('update', $consultant);

        $consultant = $this->consultants->setActive(
            $consultant,
            $request->boolean('is_active'),
            $request->user(),
        );

        return $this->success(
            ConsultantResource::make($consultant->fresh('roles')),
            __('core::messages.updated'),
        );
    }

    /**
     * CON-07 POST /api/v1/admin/consultants/{consultant}/photo
     */
    public function storePhoto(Request $request, User $consultant): JsonResponse
    {
        $this->authorize('update', $consultant);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $consultant = $this->consultants->updatePhoto($consultant, $request->file('photo'));

        return $this->success(
            ConsultantResource::make($consultant->fresh('roles')),
            __('core::messages.avatar_updated'),
        );
    }
}
