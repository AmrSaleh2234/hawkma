<?php

namespace Modules\Consultants\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Consultants\Http\Controllers\Concerns\BuildsSlotsPayload;
use Modules\Consultants\Http\Requests\Admin\ReplaceAvailabilityRequest;
use Modules\Consultants\Http\Requests\Admin\StoreTimeOffRequest;
use Modules\Consultants\Http\Requests\SlotsRequest;
use Modules\Consultants\Http\Resources\AvailabilityResource;
use Modules\Consultants\Http\Resources\TimeOffResource;
use Modules\Consultants\Services\AvailabilityService;
use Modules\Consultants\Services\TimeOffService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Users\Models\User;

/**
 * Consultant self-service (MY-01..06): the same behaviour as the CON-08..13
 * endpoints, resolved against the logged-in consultant. Only `type =
 * consultant` users may call these routes; anyone else gets a 403.
 */
class MyAvailabilityController extends ApiController
{
    use BuildsSlotsPayload;

    public function __construct(
        protected AvailabilityService $availability,
        protected TimeOffService $timeOffs,
    ) {}

    /**
     * MY-01 GET /api/v1/admin/my/availability
     */
    public function showAvailability(Request $request): JsonResponse
    {
        $consultant = $this->consultant($request);

        return $this->success(AvailabilityResource::make($consultant->load('availabilities')));
    }

    /**
     * MY-02 PUT /api/v1/admin/my/availability
     */
    public function replaceAvailability(ReplaceAvailabilityRequest $request): JsonResponse
    {
        $consultant = $this->consultant($request);

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
     * MY-03 GET /api/v1/admin/my/time-offs
     */
    public function timeOffs(Request $request): JsonResponse
    {
        $consultant = $this->consultant($request);

        $query = $consultant->timeOffs()->orderBy('date');

        $query->whereDate('date', '>=', $request->query('from') ?: now()->toDateString());

        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->query('to'));
        }

        return $this->paginated(TimeOffResource::collection($query->paginate(QueryFilters::perPage($request))));
    }

    /**
     * MY-04 POST /api/v1/admin/my/time-offs
     */
    public function storeTimeOff(StoreTimeOffRequest $request): JsonResponse
    {
        $consultant = $this->consultant($request);

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
     * MY-05 DELETE /api/v1/admin/my/time-offs/{timeOff}
     *
     * 404 if the time off is not his.
     */
    public function destroyTimeOff(Request $request, string $timeOff): JsonResponse
    {
        $consultant = $this->consultant($request);

        $this->timeOffs->delete($consultant->timeOffs()->findOrFail($timeOff));

        return $this->noContent(__('core::messages.deleted'));
    }

    /**
     * MY-06 GET /api/v1/admin/my/slots?date=
     */
    public function slots(SlotsRequest $request): JsonResponse
    {
        $consultant = $this->consultant($request);

        return $this->success($this->slotsPayload($consultant, $request->string('date')->value()));
    }

    /**
     * Only consultants may use the /my/* routes (admins get a 403).
     */
    protected function consultant(Request $request): User
    {
        $user = $request->user();

        abort_unless($user->isConsultant(), 403);

        return $user;
    }
}
