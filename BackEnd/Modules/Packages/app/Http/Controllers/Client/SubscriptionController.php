<?php

namespace Modules\Packages\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Packages\Enums\SubscriptionStatus;
use Modules\Packages\Http\Resources\SubscriptionResource;

class SubscriptionController extends ApiController
{
    /**
     * CLI-SUB-01 GET /api/v1/client/subscriptions
     *
     * Query: status. Paginated SubscriptionResource list.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
        ]);

        $query = $request->user()->subscriptions()->with('package')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $subscriptions = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(SubscriptionResource::collection($subscriptions));
    }

    /**
     * CLI-SUB-02 GET /api/v1/client/subscriptions/active
     *
     * Not paginated: the active subscriptions with the remaining counts.
     */
    public function active(Request $request): JsonResponse
    {
        $subscriptions = $request->user()
            ->activeSubscriptions()
            ->with('package')
            ->latest()
            ->get();

        return $this->success(SubscriptionResource::collection($subscriptions));
    }
}
