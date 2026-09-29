<?php

namespace Modules\Clients\Http\Controllers\Public;

use Illuminate\Http\JsonResponse;
use Modules\Clients\Http\Resources\ReviewResource;
use Modules\Clients\Models\ClientReview;
use Modules\Core\Http\Controllers\ApiController;

class PublicReviewController extends ApiController
{
    /**
     * GET /api/v1/public/reviews
     *
     * Approved client reviews shown on the landing page, newest first.
     */
    public function index(): JsonResponse
    {
        $reviews = ClientReview::approved()
            ->latest('id')
            ->limit(24)
            ->get();

        return $this->success(ReviewResource::collection($reviews));
    }
}
