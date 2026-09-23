<?php

namespace Modules\Consultants\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Consultants\Http\Requests\Admin\StoreConsultantRequest;
use Modules\Consultants\Http\Requests\Admin\UpdateConsultantRequest;
use Modules\Consultants\Http\Resources\ConsultantResource;
use Modules\Consultants\Services\ConsultantService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
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
        $query = User::query()->consultants()->with(['roles', 'availabilities']);

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
     * 409 CONSULTANT_HAS_FUTURE_BOOKINGS if there are future pending bookings.
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
