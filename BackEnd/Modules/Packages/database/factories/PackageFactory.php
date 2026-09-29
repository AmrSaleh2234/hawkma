<?php

namespace Modules\Packages\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Packages\Models\Package;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->lexify('package-????????'),
            'name_ar' => 'باقة '.fake()->word(),
            'name_en' => fake()->words(2, true).' Package',
            'description_ar' => fake('ar_SA')->sentence(),
            'description_en' => fake()->sentence(),
            'features' => [
                ['ar' => 'ميزة أولى', 'en' => 'First feature'],
                ['ar' => 'ميزة ثانية', 'en' => 'Second feature'],
            ],
            'price' => fake()->numberBetween(1000, 1000000),
            'currency' => 'SAR',
            'billing_period_days' => 30,
            'consultations_limit' => fake()->numberBetween(1, 10),
            'documents_limit' => fake()->numberBetween(1, 10),
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function iron(): static
    {
        return $this->state(fn () => [
            'slug' => 'iron',
            'name_en' => 'Iron Package',
            'name_ar' => 'الباقة الحديدية',
            'price' => 190000,
            'consultations_limit' => 2,
            'documents_limit' => 2,
            'is_featured' => false,
            'sort_order' => 1,
        ]);
    }

    public function silver(): static
    {
        return $this->state(fn () => [
            'slug' => 'silver',
            'name_en' => 'Silver Package',
            'name_ar' => 'الباقة الفضية',
            'price' => 450000,
            'consultations_limit' => 5,
            'documents_limit' => 8,
            'is_featured' => false,
            'sort_order' => 2,
        ]);
    }

    public function gold(): static
    {
        return $this->state(fn () => [
            'slug' => 'gold',
            'name_en' => 'Gold Package',
            'name_ar' => 'الباقة الذهبية',
            'price' => 980000,
            'consultations_limit' => null,
            'documents_limit' => null,
            'is_featured' => true,
            'sort_order' => 3,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unlimited(): static
    {
        return $this->state(fn () => [
            'consultations_limit' => null,
            'documents_limit' => null,
        ]);
    }
}
