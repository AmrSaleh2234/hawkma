<?php

namespace Modules\Reviews\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Reviews\Enums\ReviewStatus;
use Modules\Reviews\Models\Review;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        return ['name' => $this->faker->name(), 'rating' => $this->faker->numberBetween(4, 5), 'comment' => $this->faker->sentence(10), 'status' => ReviewStatus::Pending];
    }
}
