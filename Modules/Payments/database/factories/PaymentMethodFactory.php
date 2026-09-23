<?php

namespace Modules\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Clients\Models\Client;
use Modules\Payments\Models\PaymentMethod;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'gateway' => 'fake',
            'gateway_token' => fake()->unique()->lexify('tok_fake_ok_????????'),
            'brand' => 'visa',
            'last_four' => '4242',
            'exp_month' => 12,
            'exp_year' => (int) now()->year + 2,
            'holder_name' => fake()->name(),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'exp_month' => 1,
            'exp_year' => (int) now()->year - 1,
        ]);
    }
}
