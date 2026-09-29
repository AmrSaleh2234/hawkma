<?php

namespace Modules\Consultants\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Consultants\Http\Requests\Admin\UpdateJoinRequestStatusRequest;
use Modules\Consultants\Http\Resources\JoinRequestResource;
use Modules\Consultants\Models\JoinRequest;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;

class AdminJoinRequestController extends ApiController
{
    /**
     * CON-JOIN-01 GET /api/v1/admin/join-requests
     */
    public function index(Request $request): JsonResponse
    {
        $query = JoinRequest::query();

        QueryFilters::apply(
            $query,
            $request,
            ['name', 'email', 'phone', 'qualification', 'country_city'],
            ['name', 'created_at'],
        );

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        $requests = $query->latest()->paginate(QueryFilters::perPage($request));

        return $this->paginated(JoinRequestResource::collection($requests));
    }

    /**
     * CON-JOIN-02 GET /api/v1/admin/join-requests/{joinRequest}
     */
    public function show(JoinRequest $joinRequest): JsonResponse
    {
        return $this->success(JoinRequestResource::make($joinRequest));
    }

    /**
     * CON-JOIN-03 PATCH /api/v1/admin/join-requests/{joinRequest}
     *
     * Update status: pending | accepted | rejected.
     */
    public function update(UpdateJoinRequestStatusRequest $request, JoinRequest $joinRequest): JsonResponse
    {
        $joinRequest->update(['status' => $request->string('status')->value()]);

        return $this->success(
            JoinRequestResource::make($joinRequest),
            __('core::messages.updated'),
        );
    }

    /**
     * CON-JOIN-04 DELETE /api/v1/admin/join-requests/{joinRequest}
     */
    public function destroy(JoinRequest $joinRequest): JsonResponse
    {
        $joinRequest->delete();

        return $this->noContent(__('core::messages.deleted'));
    }
}
