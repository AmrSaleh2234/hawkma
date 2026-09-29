<?php

namespace Modules\Packages\Tests\Feature\Admin;

use Modules\Packages\Enums\SubscriptionStatus;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Tests\TestCase;

class AdminPackagesTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | PKG-01 GET /api/v1/admin/packages
    |----------------------------------------------------------------------
    */

    public function test_pkg_01_lists_active_and_inactive_packages_with_subscriptions_count(): void
    {
        $this->actingAsAdmin();
        $active = Package::factory()->create(['is_active' => true, 'sort_order' => 1]);
        Package::factory()->inactive()->create(['sort_order' => 2]);
        ClientSubscription::factory()->count(2)->create(['package_id' => $active->id]);

        $response = $this->getJson('/api/v1/admin/packages');

        $this->assertApiSuccess($response);
        $this->assertPaginated($response);
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('data.0.subscriptions_count'));
    }

    public function test_pkg_01_filters_by_is_active_and_searches(): void
    {
        $this->actingAsAdmin();
        Package::factory()->create(['name_en' => 'Iron Package', 'is_active' => true]);
        Package::factory()->inactive()->create(['name_en' => 'Gold Package']);

        $inactive = $this->getJson('/api/v1/admin/packages?is_active=0');
        $this->assertCount(1, $inactive->json('data'));
        $this->assertSame('Gold Package', $inactive->json('data.0.name_en'));

        $search = $this->getJson('/api/v1/admin/packages?search=Iron');
        $this->assertCount(1, $search->json('data'));
    }

    public function test_pkg_01_requires_the_view_packages_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-clients']);
        $this->actingAsAdmin($staff);

        $this->assertApiError($this->getJson('/api/v1/admin/packages'), 403, 'FORBIDDEN');
    }

    /*
    |----------------------------------------------------------------------
    | PKG-02 POST /api/v1/admin/packages
    |----------------------------------------------------------------------
    */

    public function test_pkg_02_creates_a_package(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/packages', [
            'slug' => 'diamond',
            'name_ar' => 'الباقة الماسية',
            'name_en' => 'Diamond Package',
            'description_ar' => 'وصف',
            'description_en' => 'Description',
            'features' => [
                ['ar' => 'ميزة', 'en' => 'Feature'],
            ],
            'price' => 1500000,
            'billing_period_days' => 90,
            'consultations_limit' => 10,
            'documents_limit' => 20,
            'is_featured' => true,
            'sort_order' => 4,
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.slug', 'diamond')
            ->assertJsonPath('data.price', 1500000)
            ->assertJsonPath('data.billing_period_days', 90)
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.currency', 'SAR');

        $this->assertDatabaseHas('packages', ['slug' => 'diamond', 'price' => 1500000]);
    }

    public function test_pkg_02_applies_the_defaults(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/packages', [
            'slug' => 'basic',
            'name_ar' => 'باقة',
            'name_en' => 'Basic',
            'price' => 1000,
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.billing_period_days', 30)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_featured', false)
            ->assertJsonPath('data.features', []);
    }

    public function test_pkg_02_validates_the_payload(): void
    {
        $this->actingAsAdmin();
        Package::factory()->create(['slug' => 'taken']);

        $response = $this->postJson('/api/v1/admin/packages', [
            'slug' => 'taken',
            'price' => -5,
            'features' => [['en' => 'missing the Arabic']],
        ]);

        $this->assertApiError($response, 422, 'VALIDATION_ERROR')
            ->assertJsonStructure([
                'errors' => ['slug', 'name_ar', 'name_en', 'price', 'features.0.ar'],
            ]);
    }

    public function test_pkg_02_requires_the_create_packages_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-packages']);
        $this->actingAsAdmin($staff);

        $this->assertApiError(
            $this->postJson('/api/v1/admin/packages', [
                'slug' => 'x', 'name_ar' => 'س', 'name_en' => 'X', 'price' => 1,
            ]),
            403,
            'FORBIDDEN',
        );
    }

    /*
    |----------------------------------------------------------------------
    | PKG-03 GET /api/v1/admin/packages/{package}
    |----------------------------------------------------------------------
    */

    public function test_pkg_03_shows_a_package(): void
    {
        $this->actingAsAdmin();
        $package = Package::factory()->iron()->create();

        $response = $this->getJson("/api/v1/admin/packages/{$package->id}");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.slug', 'iron')
            ->assertJsonPath('data.subscriptions_count', 0);
    }

    /*
    |----------------------------------------------------------------------
    | PKG-04 PUT /api/v1/admin/packages/{package}
    |----------------------------------------------------------------------
    */

    public function test_pkg_04_updates_a_package(): void
    {
        $this->actingAsAdmin();
        $package = Package::factory()->create(['name_en' => 'Old Name', 'price' => 1000]);

        $response = $this->putJson("/api/v1/admin/packages/{$package->id}", [
            'name_en' => 'New Name',
            'price' => 2000,
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.name_en', 'New Name')
            ->assertJsonPath('data.price', 2000);
    }

    public function test_pkg_04_changing_the_price_does_not_change_existing_subscriptions(): void
    {
        $this->actingAsAdmin();
        $package = Package::factory()->iron()->create();
        $subscription = ClientSubscription::factory()->forPackage($package)->create();

        $this->putJson("/api/v1/admin/packages/{$package->id}", ['price' => 250000])
            ->assertOk();

        $this->assertSame(190000, $subscription->refresh()->price_paid);
        $this->assertSame(250000, $package->refresh()->price);
    }

    public function test_pkg_04_validates_a_taken_slug(): void
    {
        $this->actingAsAdmin();
        Package::factory()->create(['slug' => 'taken']);
        $package = Package::factory()->create(['slug' => 'free']);

        $response = $this->putJson("/api/v1/admin/packages/{$package->id}", ['slug' => 'taken']);

        $this->assertApiError($response, 422, 'VALIDATION_ERROR');
    }

    /*
    |----------------------------------------------------------------------
    | PKG-05 DELETE /api/v1/admin/packages/{package}
    |----------------------------------------------------------------------
    */

    public function test_pkg_05_soft_deletes_a_package_without_active_subscriptions(): void
    {
        $this->actingAsAdmin();
        $package = Package::factory()->create();
        ClientSubscription::factory()->expired()->create(['package_id' => $package->id]);

        $this->deleteJson("/api/v1/admin/packages/{$package->id}")->assertOk();

        $this->assertSoftDeleted('packages', ['id' => $package->id]);
    }

    public function test_pkg_05_returns_409_when_active_subscriptions_exist(): void
    {
        $this->actingAsAdmin();
        $package = Package::factory()->create();
        ClientSubscription::factory()->create([
            'package_id' => $package->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->assertApiError(
            $this->deleteJson("/api/v1/admin/packages/{$package->id}"),
            409,
            'PACKAGE_HAS_SUBSCRIPTIONS',
        );

        $this->assertDatabaseHas('packages', ['id' => $package->id, 'deleted_at' => null]);
    }

    public function test_pkg_05_requires_the_delete_packages_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-packages', 'update-packages']);
        $this->actingAsAdmin($staff);
        $package = Package::factory()->create();

        $this->assertApiError(
            $this->deleteJson("/api/v1/admin/packages/{$package->id}"),
            403,
            'FORBIDDEN',
        );
    }

    /*
    |----------------------------------------------------------------------
    | PKG-06 PATCH /api/v1/admin/packages/{package}/status
    |----------------------------------------------------------------------
    */

    public function test_pkg_06_toggles_the_status(): void
    {
        $this->actingAsAdmin();
        $package = Package::factory()->create(['is_active' => true]);

        $this->patchJson("/api/v1/admin/packages/{$package->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/v1/admin/packages/{$package->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_pkg_06_requires_is_active(): void
    {
        $this->actingAsAdmin();
        $package = Package::factory()->create();

        $this->assertApiError(
            $this->patchJson("/api/v1/admin/packages/{$package->id}/status", []),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_pkg_routes_require_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/packages'), 401, 'UNAUTHENTICATED');
    }
}
