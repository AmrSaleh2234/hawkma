<?php

namespace Modules\Packages\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Clients\Models\Client;
use Modules\Packages\Enums\SubscriptionStatus;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;

/**
 * @extends Factory<ClientSubscription>
 */
class ClientSubscriptionFactory extends Factory
{
    protected $model = ClientSubscription::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'package_id' => Package::factory(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'consultations_limit' => 2,
            'consultations_used' => 0,
            'price_paid' => 190000,
        ];
    }

    /**
     * Copy the purchase-time values off the given package.
     */
    public function forPackage(Package $package): static
    {
        return $this->state(fn () => [
            'package_id' => $package->id,
            'ends_at' => now()->addDays($package->billing_period_days),
            'consultations_limit' => $package->consultations_limit,
            'price_paid' => $package->price,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Expired,
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subDays(30),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Cancelled]);
    }

    public function unlimited(): static
    {
        return $this->state(fn () => ['consultations_limit' => null]);
    }

    public function usedUp(): static
    {
        return $this->state(fn (array $attributes) => [
            'consultations_limit' => $limit = (int) ($attributes['consultations_limit'] ?? 2),
            'consultations_used' => $limit,
        ]);
    }
}
