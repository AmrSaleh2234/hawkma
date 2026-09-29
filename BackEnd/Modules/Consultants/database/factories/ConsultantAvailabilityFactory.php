<?php

namespace Modules\Consultants\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Consultants\Models\ConsultantAvailability;
use Modules\Users\Models\User;

/**
 * @extends Factory<ConsultantAvailability>
 */
class ConsultantAvailabilityFactory extends Factory
{
    protected $model = ConsultantAvailability::class;

    public function definition(): array
    {
        return [
            'consultant_id' => User::factory()->consultant(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ];
    }
}
