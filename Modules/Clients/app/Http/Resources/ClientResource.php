<?php

namespace Modules\Clients\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Clients\Models\Client;
use Modules\Packages\Http\Resources\SubscriptionResource;
use Modules\Packages\Models\ClientSubscription;
use Modules\Payments\Http\Resources\PaymentMethodResource;
use Modules\Payments\Models\PaymentMethod;

class ClientResource extends JsonResource
{
    protected bool $withDetails = false;

    /**
     * Include the client-dashboard details (subscriptions, default location,
     * default payment method) — used by CLI-AUTH-04 and CLI-PRF-01.
     */
    public function withDetails(bool $value = true): static
    {
        $this->withDetails = $value;

        return $this;
    }

    /**
     * The CLI-AUTH-04 / CLI-PRF-01 payload: the resource with the details
     * relations eager-loaded. The subscription and payment method relations
     * arrive in Phases 7 and 8; until then their keys render as empty/null
     * via the `whenLoaded` defaults.
     */
    public static function detailed(Client $client): static
    {
        $relations = ['defaultLocation'];

        if (class_exists(ClientSubscription::class)) {
            $relations[] = 'activeSubscriptions';
        }

        if (class_exists(PaymentMethod::class)) {
            $relations[] = 'defaultPaymentMethod';
        }

        $client->loadMissing($relations);

        return static::make($client)->withDetails();
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company_name' => $this->company_name,
            'avatar_url' => $this->avatar_url,
            'avatar_thumb_url' => $this->avatar_thumb_url,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'bookings_count' => $this->whenCounted('bookings'),
            'reports_count' => $this->whenCounted('reports'),
            // CON-14: present when the query used withMax('bookings as last_booking_at').
            'last_booking_at' => $this->when(
                array_key_exists('last_booking_at', $this->resource->getAttributes()),
                fn () => $this->last_booking_at === null ? null : Carbon::parse($this->last_booking_at)->toIso8601String(),
            ),
            'locations' => LocationResource::collection($this->whenLoaded('locations')),
            'active_subscription' => $this->whenLoaded(
                'activeSubscription',
                fn () => SubscriptionResource::make($this->activeSubscription),
            ),
            'active_subscriptions' => $this->when(
                $this->withDetails,
                fn () => $this->whenLoaded(
                    'activeSubscriptions',
                    fn () => SubscriptionResource::collection($this->activeSubscriptions),
                    [],
                ),
            ),
            // The explicit null defaults keep the keys present on /me payloads
            // even before Phases 7/8 introduce the relations.
            'default_location' => $this->when(
                $this->withDetails,
                fn () => $this->whenLoaded(
                    'defaultLocation',
                    fn () => LocationResource::make($this->defaultLocation),
                ),
            ),
            'default_payment_method' => $this->when(
                $this->withDetails,
                fn () => $this->whenLoaded(
                    'defaultPaymentMethod',
                    fn () => PaymentMethodResource::make($this->defaultPaymentMethod),
                    null,
                ),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
