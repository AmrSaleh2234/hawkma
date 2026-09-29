<?php

namespace Modules\Clients\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Clients\Http\Requests\Client\StoreReviewRequest;
use Modules\Clients\Http\Resources\ReviewResource;
use Modules\Clients\Models\Client;
use Modules\Clients\Models\ClientReview;
use Modules\Core\Http\Controllers\ApiController;

class ReviewController extends ApiController
{
    /**
     * GET /api/v1/client/reviews
     *
     * Not paginated; newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $reviews = $request->user('client')->reviews()
            ->latest('id')
            ->get();

        return $this->success(ReviewResource::collection($reviews));
    }

    /**
     * POST /api/v1/client/reviews
     */
    public function store(StoreReviewRequest $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user('client');

        $review = $client->reviews()->create([
            ...$request->validated(),
            'is_approved' => true,
        ]);

        return $this->created(ReviewResource::make($review), __('core::messages.created'));
    }

    /**
     * DELETE /api/v1/client/reviews/{review}
     *
     * Scoped to the authenticated client: another client's review is a 404
     * (not 403), so ids do not leak.
     */
    public function destroy(Request $request, string $review): JsonResponse
    {
        /** @var ClientReview $review */
        $review = $request->user('client')->reviews()->findOrFail($review);
        $review->delete();

        return $this->noContent(__('core::messages.deleted'));
    }
}
