<?php

namespace Modules\Packages\Tests\Feature;

use Modules\Packages\Database\Seeders\PackagesSeeder;
use Modules\Packages\Models\Package;
use Tests\TestCase;

class PublicPackagesTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | PUB-01 GET /api/v1/public/packages
    |----------------------------------------------------------------------
    */

    public function test_pub_01_returns_only_active_packages_ordered_by_sort_order(): void
    {
        Package::factory()->create(['slug' => 'third', 'sort_order' => 3]);
        Package::factory()->create(['slug' => 'first', 'sort_order' => 1]);
        Package::factory()->inactive()->create(['slug' => 'hidden', 'sort_order' => 2]);
        Package::factory()->create(['slug' => 'second', 'sort_order' => 2]);

        $response = $this->getJson('/api/v1/public/packages');

        $this->assertApiSuccess($response);
        $this->assertEquals(
            ['first', 'second', 'third'],
            array_column($response->json('data'), 'slug'),
        );
    }

    public function test_pub_01_is_not_paginated_and_has_price_formatted_and_features(): void
    {
        Package::factory()->create(['price' => 190000]);

        $response = $this->getJson('/api/v1/public/packages');

        $this->assertApiSuccess($response);
        $this->assertIsArray($response->json('data'));
        $this->assertArrayNotHasKey('meta', $response->json());
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'slug', 'name', 'name_ar', 'name_en',
                    'description', 'description_ar', 'description_en',
                    'features', 'features_localized',
                    'price', 'price_formatted', 'currency',
                    'billing_period_days', 'consultations_limit', 'documents_limit',
                    'is_unlimited', 'is_featured', 'is_active', 'sort_order',
                ],
            ],
        ]);
        $this->assertSame('1,900.00 SAR', $response->json('data.0.price_formatted'));
    }

    public function test_pub_01_localizes_the_name_and_features(): void
    {
        Package::factory()->create([
            'name_ar' => 'الباقة الحديدية',
            'name_en' => 'Iron Package',
            'features' => [['ar' => 'استشارتان شهرياً', 'en' => 'Two consultations monthly']],
        ]);

        $ar = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/public/packages');
        $this->assertSame('الباقة الحديدية', $ar->json('data.0.name'));
        $this->assertSame(['استشارتان شهرياً'], $ar->json('data.0.features_localized'));

        $en = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/public/packages');
        $this->assertSame('Iron Package', $en->json('data.0.name'));
        $this->assertSame(['Two consultations monthly'], $en->json('data.0.features_localized'));
    }

    public function test_pub_01_returns_the_seeded_packages(): void
    {
        $this->seed(PackagesSeeder::class);

        $response = $this->getJson('/api/v1/public/packages');

        $this->assertApiSuccess($response);
        $this->assertEquals(['iron', 'silver', 'gold'], array_column($response->json('data'), 'slug'));
        $this->assertSame(190000, $response->json('data.0.price'));
        $this->assertSame(450000, $response->json('data.1.price'));
        $this->assertSame(980000, $response->json('data.2.price'));
        $this->assertTrue($response->json('data.2.is_featured'));
        $this->assertTrue($response->json('data.2.is_unlimited'));
    }

    /*
    |----------------------------------------------------------------------
    | PUB-02 GET /api/v1/public/packages/{slug}
    |----------------------------------------------------------------------
    */

    public function test_pub_02_shows_an_active_package_by_slug(): void
    {
        Package::factory()->iron()->create();

        $response = $this->getJson('/api/v1/public/packages/iron');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.slug', 'iron')
            ->assertJsonPath('data.price', 190000)
            ->assertJsonPath('data.consultations_limit', 2);
    }

    public function test_pub_02_returns_404_for_an_unknown_slug(): void
    {
        $this->assertApiError($this->getJson('/api/v1/public/packages/nope'), 404, 'NOT_FOUND');
    }

    public function test_pub_02_returns_404_for_an_inactive_package(): void
    {
        Package::factory()->inactive()->create(['slug' => 'iron']);

        $this->assertApiError($this->getJson('/api/v1/public/packages/iron'), 404, 'NOT_FOUND');
    }
}
