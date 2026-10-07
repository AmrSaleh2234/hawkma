<?php

namespace Modules\Reviews\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Reviews\Http\Requests\RejectReviewRequest;
use Modules\Reviews\Http\Requests\StoreReviewRequest;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Services\ReviewService;
use Modules\Reviews\Transformers\ReviewResource;

class ReviewsController extends ApiController
{
    public function __construct(private ReviewService $service) {}

    // PUB: approved testimonials for the landing page.
    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $reviews = Review::query()->approved()->latest('published_at')->latest('id')->paginate(QueryFilters::perPage($request));

        return $this->paginated(ReviewResource::collection($reviews));
    }

    public function store(StoreReviewRequest $request): JsonResponse
    {
        $review = $this->service->submit($request->user('client'), $request->validated());

        return $this->created(ReviewResource::make($review), __('reviews::messages.submitted'));
    }

    public function mine(Request $request): JsonResponse
    {
        $reviews = Review::query()->ownedBy($request->user('client'))->latest()->paginate(QueryFilters::perPage($request));

        return $this->paginated(ReviewResource::collection($reviews));
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'search' => ['nullable', 'string', 'max:191'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = Review::query()->with(['client:id,name,company_name'])->when($request->query('status'), fn (Builder $q, string $status) => $q->where('status', $status));
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('comment', 'like', "%{$search}%"));
        }

        return $this->paginated(ReviewResource::collection($query->latest()->paginate(QueryFilters::perPage($request))));
    }

    public function show(string $review): JsonResponse
    {
        return $this->success(ReviewResource::make(Review::query()->with(['client:id,name,company_name'])->findOrFail($review)));
    }

    public function approve(Request $request, string $review): JsonResponse
    {
        $review = Review::query()->findOrFail($review);

        return $this->success(ReviewResource::make($this->service->approve($review, $request->user('admin'))), __('reviews::messages.approved'));
    }

    public function reject(RejectReviewRequest $request, string $review): JsonResponse
    {
        $review = Review::query()->findOrFail($review);

        return $this->success(ReviewResource::make($this->service->reject($review, $request->user('admin'), $request->string('reason')->value())), __('reviews::messages.rejected'));
    }
}
