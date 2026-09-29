<?php

namespace Modules\Consultants\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Modules\Consultants\Http\Controllers\Concerns\BuildsSlotsPayload;
use Modules\Consultants\Http\Requests\Admin\ReplaceAvailabilityRequest;
use Modules\Consultants\Http\Requests\SlotsRequest;
use Modules\Consultants\Http\Resources\AvailabilityResource;
use Modules\Consultants\Services\AvailabilityService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Users\Models\User;

class ConsultantAvailabilityController extends ApiController
{
    use BuildsSlotsPayload;

    public function __construct(protected AvailabilityService $availability) {}

    /**
     * CON-08 GET /api/v1/admin/consultants/{consultant}/availability
     */
    public function show(User $consultant): JsonResponse
    {
        $this->authorize('view', $consultant);

        return $this->success(AvailabilityResource::make($consultant->load('availabilities')));
    }

    /**
     * CON-09 PUT /api/v1/admin/consultants/{consultant}/availability
     *
     * Replaces the whole week in one transaction. The response includes
     * `warnings.conflicting_bookings_count`; existing bookings are never
     * cancelled by an availability change.
     */
    public function replace(ReplaceAvailabilityRequest $request, User $consultant): JsonResponse
    {
        $this->authorize('manageAvailability', $consultant);

        $this->availability->replaceWeek($consultant, $request->validated('days'));

        return $this->success(
            array_merge(
                AvailabilityResource::make($consultant->load('availabilities'))->resolve($request),
                ['warnings' => ['conflicting_bookings_count' => $this->availability->countConflictingBookings($consultant)]],
            ),
            __('core::messages.availability_updated'),
        );
    }

    /**
     * CON-13 GET /api/v1/admin/consultants/{consultant}/slots?date=
     *
     * The same output as PUB-06 (a preview for the admin).
     */
    public function slots(SlotsRequest $request, User $consultant): JsonResponse
    {
        $this->authorize('view', $consultant);

        return $this->success($this->slotsPayload($consultant, $request->string('date')->value()));
    }
}
