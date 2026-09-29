<?php

namespace Modules\Users\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Users\Enums\UserType;
use Modules\Users\Models\User;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'type' => UserType::Admin,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '05'.fake()->numerify('########'),
            'title' => null,
            'specialization' => null,
            'bio' => null,
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => 'Password@123',
            'last_login_at' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['type' => UserType::Admin]);
    }

    public function consultant(): static
    {
        return $this->state(fn () => [
            'type' => UserType::Consultant,
            'title' => fake()->jobTitle(),
            'specialization' => fake()->words(2, true),
            'bio' => fake()->paragraph(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
