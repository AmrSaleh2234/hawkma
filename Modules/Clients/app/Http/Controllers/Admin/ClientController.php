<?php

namespace Modules\Clients\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Http\Requests\Admin\UpdateClientRequest;
use Modules\Clients\Http\Requests\Admin\UpdateClientStatusRequest;
use Modules\Clients\Http\Resources\ClientResource;
use Modules\Clients\Models\Client;
use Modules\Clients\Services\ClientService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Packages\Http\Resources\SubscriptionResource;
use Modules\Packages\Models\ClientSubscription;
use Modules\Reports\Models\Report;

class ClientController extends ApiController
{
    public function __construct(protected ClientService $clients) {}

    /**
     * ADM-CL-01 GET /api/v1/admin/clients
     *
     * Search: name, email, phone, company_name. Filters: is_active,
     * consultant_id (admin only), has_active_subscription. With
     * bookings_count, reports_count, active_subscription.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Client::query()->visibleTo($request->user());

        // Counts and eager loads that depend on later phases activate
        // automatically once those models exist.
        $withCount = [];
        if (class_exists(Booking::class)) {
            $withCount[] = 'bookings';
        }
        if (class_exists(Report::class)) {
            $withCount[] = 'reports';
        }
        if ($withCount !== []) {
            $query->withCount($withCount);
        }
        if (class_exists(ClientSubscription::class)) {
            $query->with('activeSubscription');
        }

        QueryFilters::apply(
            $query,
            $request,
            ['name', 'email', 'phone', 'company_name'],
            ['name', 'email', 'created_at', 'last_login_at'],
            '-created_at',
        );

        if ($request->has('is_active') && $request->query('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Phase 9: filter by the consultant the client has bookings with.
        if ($request->filled('consultant_id') && $request->user()->isAdmin() && class_exists(Booking::class)) {
            $query->whereHas('bookings', fn ($b) => $b->where('consultant_id', $request->integer('consultant_id')));
        }

        // Phase 7: filter by active subscription.
        if ($request->has('has_active_subscription') && $request->query('has_active_subscription') !== null) {
            $want = $request->boolean('has_active_subscription');

            if (! class_exists(ClientSubscription::class)) {
                // No subscriptions can exist yet: "yes" matches nothing.
                if ($want) {
                    $query->whereRaw('1 = 0');
                }
            } else {
                $query->{$want ? 'whereHas' : 'whereDoesntHave'}('activeSubscriptions');
            }
        }

        $clients = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(ClientResource::collection($clients));
    }

    /**
     * ADM-CL-02 GET /api/v1/admin/clients/{client}
     *
     * + locations, active_subscription, counts. Policy: a consultant must
     * have a booking with this client, else 403.
     */
    public function show(Request $request, Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $with = ['locations'];
        $withCount = [];

        if (class_exists(ClientSubscription::class)) {
            $with[] = 'activeSubscription';
        }
        if (class_exists(Booking::class)) {
            $withCount[] = 'bookings';
        }
        if (class_exists(Report::class)) {
            $withCount[] = 'reports';
        }

        $client->load($with);
        if ($withCount !== []) {
            $client->loadCount($withCount);
        }

        return $this->success(ClientResource::make($client));
    }

    /**
     * ADM-CL-03 PUT /api/v1/admin/clients/{client}
     */
    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $client = $this->clients->update(
            $client,
            $request->safe()->only(['name', 'email', 'phone', 'company_name']),
        );

        return $this->success(ClientResource::make($client), __('core::messages.updated'));
    }

    /**
     * ADM-CL-04 PATCH /api/v1/admin/clients/{client}/status
     *
     * Deactivating deletes the client's tokens.
     */
    public function updateStatus(UpdateClientStatusRequest $request, Client $client): JsonResponse
    {
        $client = $this->clients->setActive($client, $request->boolean('is_active'));

        return $this->success(ClientResource::make($client), __('core::messages.updated'));
    }

    /**
     * ADM-CL-05 DELETE /api/v1/admin/clients/{client}
     *
     * Soft delete; 409 CLIENT_HAS_FUTURE_BOOKINGS if the client has future
     * pending bookings.
     */
    public function destroy(Client $client): JsonResponse
    {
        $this->clients->delete($client);

        return $this->noContent(__('core::messages.deleted'));
    }

    /**
     * ADM-CL-08 GET /api/v1/admin/clients/{client}/subscriptions
     *
     * SubscriptionResource list. Same visibility policy as ADM-CL-02: a
     * consultant must have a booking with this client, else 403.
     */
    public function subscriptions(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $subscriptions = $client->subscriptions()
            ->with('package')
            ->latest()
            ->paginate(QueryFilters::perPage(request()));

        return $this->paginated(SubscriptionResource::collection($subscriptions));
    }
}
