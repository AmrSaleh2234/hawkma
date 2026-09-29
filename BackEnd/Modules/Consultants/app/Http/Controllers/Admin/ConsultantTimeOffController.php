<?php

namespace Modules\Consultants\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Consultants\Http\Requests\Admin\StoreTimeOffRequest;
use Modules\Consultants\Http\Resources\TimeOffResource;
use Modules\Consultants\Models\ConsultantTimeOff;
use Modules\Consultants\Services\AvailabilityService;
use Modules\Consultants\Services\TimeOffService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Users\Models\User;

class ConsultantTimeOffController extends ApiController
{
    public function __construct(
        protected TimeOffService $timeOffs,
        protected AvailabilityService $availability,
    ) {}

    /**
     * CON-10 GET /api/v1/admin/consultants/{consultant}/time-offs
     *
     * Query: `from`, `to` (dates; default: from today). Paginated.
     */
    public function index(Request $request, User $consultant): JsonResponse
    {
        $this->authorize('view', $consultant);

        $query = $consultant->timeOffs()->orderBy('date');

        $query->whereDate('date', '>=', $request->query('from') ?: now()->toDateString());

        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->query('to'));
        }

        $timeOffs = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(TimeOffResource::collection($timeOffs));
    }

    /**
     * CON-11 POST /api/v1/admin/consultants/{consultant}/time-offs
     *
     * 201 + `warnings.conflicting_bookings_count`; existing bookings are
     * never cancelled by a time off.
     */
    public function store(StoreTimeOffRequest $request, User $consultant): JsonResponse
    {
        $this->authorize('manageAvailability', $consultant);

        $timeOff = $this->timeOffs->create($consultant, $request->validated());

        return $this->created(
            array_merge(
                TimeOffResource::make($timeOff)->resolve($request),
                ['warnings' => ['conflicting_bookings_count' => $this->availability->countConflictingBookings($consultant)]],
            ),
            __('core::messages.time_off_created'),
        );
    }

    /**
     * CON-12 DELETE /api/v1/admin/consultants/{consultant}/time-offs/{timeOff}
     *
     * 404 if the time off belongs to another consultant (scopeBindings).
     */
    public function destroy(User $consultant, ConsultantTimeOff $timeOff): JsonResponse
    {
        $this->authorize('manageAvailability', $consultant);

        $this->timeOffs->delete($timeOff);

        return $this->noContent(__('core::messages.deleted'));
    }
}
