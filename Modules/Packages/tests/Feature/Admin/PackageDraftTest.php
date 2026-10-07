<?php

namespace Modules\Packages\Tests\Feature\Admin;

use Modules\Packages\Models\Package;
use Tests\TestCase;

class PackageDraftTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | PKG-07 GET /api/v1/admin/packages/trashed — Perm: view-packages
    |----------------------------------------------------------------------
    */

    public function test_pkg_07_lists_drafted_packages_with_counts(): void
    {
        $this->actingAsAdmin();
        $drafted = Package::factory()->iron()->create(['name_en' => 'Drafted Package']);
        Package::factory()->silver()->create();

        $this->deleteJson("/api/v1/admin/packages/{$drafted->id}")->assertOk();

        $response = $this->getJson('/api/v1/admin/packages/trashed');

        $this->assertPaginated($response);
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($drafted->id, $response->json('data.0.id'));
        $this->assertNotNull($response->json('data.0.deleted_at'));
        $this->assertSame(0, $response->json('data.0.subscriptions_count'));

        // Drafted packages stay out of the main list.
        $main = $this->getJson('/api/v1/admin/packages');
        $this->assertSame(1, $main->json('meta.total'));
    }

    public function test_pkg_07_requires_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/packages/trashed'), 401, 'UNAUTHENTICATED');
    }

    public function test_pkg_07_requires_view_packages_permission(): void
    {
        $this->actingAsAdmin($this->createStaffWithPermissions(['view-users']));

        $this->assertApiError($this->getJson('/api/v1/admin/packages/trashed'), 403, 'FORBIDDEN');
    }

    /*
    |----------------------------------------------------------------------
    | PKG-08 POST /api/v1/admin/packages/{id}/restore — Perm: delete-packages
    |----------------------------------------------------------------------
    */

    public function test_pkg_08_restores_a_drafted_package(): void
    {
        $package = Package::factory()->iron()->create();
        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/packages/{$package->id}")->assertOk();
        $this->assertSoftDeleted('packages', ['id' => $package->id]);

        $response = $this->postJson("/api/v1/admin/packages/{$package->id}/restore");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $package->id)
            ->assertJsonPath('data.deleted_at', null);

        $this->assertDatabaseHas('packages', ['id' => $package->id, 'deleted_at' => null]);
    }

    public function test_pkg_08_restoring_a_live_package_returns_not_drafted(): void
    {
        $package = Package::factory()->iron()->create();
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson("/api/v1/admin/packages/{$package->id}/restore"),
            422,
            'NOT_DRAFTED',
        );
    }

    public function test_pkg_08_unknown_package_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError($this->postJson('/api/v1/admin/packages/9999/restore'), 404, 'NOT_FOUND');
    }

    public function test_pkg_08_requires_delete_packages_permission(): void
    {
        $package = Package::factory()->iron()->create();
        $this->actingAsAdmin($this->createStaffWithPermissions(['view-packages']));

        $package->delete();

        $this->assertApiError(
            $this->postJson("/api/v1/admin/packages/{$package->id}/restore"),
            403,
            'FORBIDDEN',
        );
    }

    public function test_pkg_08_requires_authentication(): void
    {
        $this->assertApiError($this->postJson('/api/v1/admin/packages/1/restore'), 401, 'UNAUTHENTICATED');
    }
}
