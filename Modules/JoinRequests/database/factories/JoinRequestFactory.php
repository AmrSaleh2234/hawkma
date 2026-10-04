<?php

namespace Modules\JoinRequests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\JoinRequests\Models\JoinRequest;

class JoinRequestFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = JoinRequest::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [];
    }
}
