<?php

namespace Modules\Reports\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Bookings\Models\Booking;
use Modules\Reports\Models\Report;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            // A report belongs to a completed booking; the consultant/client
            // are derived from it (state closures receive the expanded
            // attributes, so the booking is created first).
            'booking_id' => Booking::factory()->completed(),
            'consultant_id' => fn (array $attributes) => Booking::query()->findOrFail($attributes['booking_id'])->consultant_id,
            'client_id' => fn (array $attributes) => Booking::query()->findOrFail($attributes['booking_id'])->client_id,
            'title' => fake()->sentence(4),
            'summary' => fake()->paragraph(),
            'uploaded_by' => fn (array $attributes) => Booking::query()->findOrFail($attributes['booking_id'])->consultant_id,
        ];
    }
}
