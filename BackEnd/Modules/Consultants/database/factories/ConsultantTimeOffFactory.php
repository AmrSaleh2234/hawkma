<?php

namespace Modules\Consultants\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Consultants\Models\ConsultantTimeOff;
use Modules\Users\Models\User;

/**
 * @extends Factory<ConsultantTimeOff>
 */
class ConsultantTimeOffFactory extends Factory
{
    protected $model = ConsultantTimeOff::class;

    public function definition(): array
    {
        return [
            'consultant_id' => User::factory()->consultant(),
            'date' => fake()->dateTimeBetween('today', '+30 days')->format('Y-m-d'),
            'start_time' => null,
            'end_time' => null,
            'reason' => null,
        ];
    }

    /**
     * A partial time off (a time range inside the day).
     */
    public function ranged(string $start = '11:00', string $end = '12:00'): static
    {
        return $this->state(fn () => ['start_time' => $start, 'end_time' => $end]);
    }
}
