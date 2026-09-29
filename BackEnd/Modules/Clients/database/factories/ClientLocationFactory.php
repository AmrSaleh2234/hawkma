<?php

namespace Modules\Clients\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Clients\Models\Client;
use Modules\Clients\Models\ClientLocation;

/**
 * @extends Factory<ClientLocation>
 */
class ClientLocationFactory extends Factory
{
    protected $model = ClientLocation::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->randomElement(['Headquarters', 'Branch', 'Warehouse']).' '.fake()->city(),
            'city' => fake()->city(),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(16, 32),
            'longitude' => fake()->longitude(34, 55),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
