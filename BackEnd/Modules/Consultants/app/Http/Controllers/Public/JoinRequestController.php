<?php

namespace Modules\Consultants\Http\Controllers\Public;

use Illuminate\Http\JsonResponse;
use Modules\Consultants\Http\Requests\StoreJoinRequestRequest;
use Modules\Consultants\Http\Resources\JoinRequestResource;
use Modules\Consultants\Models\JoinRequest;
use Modules\Core\Http\Controllers\ApiController;

class JoinRequestController extends ApiController
{
    /**
     * PUB-JOIN-01 POST /api/v1/public/join-requests
     *
     * Public consultant application form ("join us" landing page).
     */
    public function store(StoreJoinRequestRequest $request): JsonResponse
    {
        $joinRequest = JoinRequest::create($request->validated());

        return $this->created(
            JoinRequestResource::make($joinRequest),
            __('consultants::join_request.received'),
        );
    }
}
