<?php

namespace Modules\Core\Tests\Feature\Admin;

use Modules\Core\Support\Settings;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | SET-01 GET /api/v1/admin/settings
    |----------------------------------------------------------------------
    */

    public function test_set_01_returns_the_editable_settings(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/settings');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.'.Settings::PLATFORM_FEE_PERCENT, 15)
            ->assertJsonPath('data.'.Settings::PAYOUT_AUTO_TRANSFER, false);
    }

    /*
    |----------------------------------------------------------------------
    | SET-02 PUT /api/v1/admin/settings
    |----------------------------------------------------------------------
    */

    public function test_set_02_updates_the_platform_fee_and_toggle(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/v1/admin/settings', [
            Settings::PLATFORM_FEE_PERCENT => 20,
            Settings::PAYOUT_AUTO_TRANSFER => true,
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.'.Settings::PLATFORM_FEE_PERCENT, 20)
            ->assertJsonPath('data.'.Settings::PAYOUT_AUTO_TRANSFER, true);

        $this->assertDatabaseHas('settings', [
            'key' => Settings::PLATFORM_FEE_PERCENT,
            'value' => '20',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => Settings::PAYOUT_AUTO_TRANSFER,
            'value' => 'true',
        ]);
    }

    public function test_set_02_validates_the_fee_range(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/v1/admin/settings', [
            Settings::PLATFORM_FEE_PERCENT => 120,
        ])->assertUnprocessable();
    }

    public function test_settings_require_permissions(): void
    {
        $staff = $this->createStaffWithPermissions(['view-bookings']);
        $this->actingAsAdmin($staff);

        $this->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->putJson('/api/v1/admin/settings', [])->assertForbidden();
    }
}
