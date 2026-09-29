<?php

namespace Modules\Clients\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Clients\Http\Requests\Client\StoreLocationRequest;
use Modules\Clients\Http\Requests\Client\UpdateLocationRequest;
use Modules\Clients\Http\Resources\LocationResource;
use Modules\Clients\Models\Client;
use Modules\Clients\Models\ClientLocation;
use Modules\Clients\Services\LocationService;
use Modules\Core\Http\Controllers\ApiController;

class LocationController extends ApiController
{
    public function __construct(protected LocationService $locations) {}

    /**
     * CLI-LOC-01 GET /api/v1/client/locations
     *
     * Not paginated; the default location first.
     */
    public function index(Request $request): JsonResponse
    {
        $locations = $request->user('client')->locations()
            ->orderByDesc('is_default')
            ->latest('id')
            ->get();

        return $this->success(LocationResource::collection($locations));
    }

    /**
     * CLI-LOC-02 POST /api/v1/client/locations
     */
    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = $this->locations->create($request->user('client'), $request->validated());

        return $this->created(LocationResource::make($location), __('core::messages.created'));
    }

    /**
     * CLI-LOC-03 GET /api/v1/client/locations/{location}
     */
    public function show(Request $request, string $location): JsonResponse
    {
        return $this->success(LocationResource::make($this->findOwned($request, $location)));
    }

    /**
     * CLI-LOC-04 PUT /api/v1/client/locations/{location}
     */
    public function update(UpdateLocationRequest $request, string $location): JsonResponse
    {
        $location = $this->locations->update(
            $this->findOwned($request, $location),
            $request->validated(),
        );

        return $this->success(LocationResource::make($location), __('core::messages.updated'));
    }

    /**
     * CLI-LOC-05 DELETE /api/v1/client/locations/{location}
     */
    public function destroy(Request $request, string $location): JsonResponse
    {
        $this->locations->delete($this->findOwned($request, $location));

        return $this->noContent(__('core::messages.deleted'));
    }

    /**
     * CLI-LOC-06 PATCH /api/v1/client/locations/{location}/default
     */
    public function setDefault(Request $request, string $location): JsonResponse
    {
        $location = $this->locations->setDefault($this->findOwned($request, $location));

        return $this->success(LocationResource::make($location), __('core::messages.updated'));
    }

    /**
     * Scope the lookup to the authenticated client: another client's location
     * is a 404 (not 403), so ids do not leak.
     */
    protected function findOwned(Request $request, string $id): ClientLocation
    {
        /** @var Client $client */
        $client = $request->user('client');

        return $client->locations()->findOrFail($id);
    }
}
