<?php

namespace Modules\Core\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Http\Requests\Admin\UpdateSettingsRequest;
use Modules\Core\Support\Settings;

class SettingsController extends ApiController
{
    /**
     * SET-01 GET /api/v1/admin/settings — Perm: view-settings
     */
    public function show(): JsonResponse
    {
        return $this->success(Settings::editable());
    }

    /**
     * SET-02 PUT /api/v1/admin/settings — Perm: update-settings
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists(Settings::PLATFORM_FEE_PERCENT, $data)) {
            Settings::set(Settings::PLATFORM_FEE_PERCENT, $data[Settings::PLATFORM_FEE_PERCENT]);
        }

        if (array_key_exists(Settings::PAYOUT_AUTO_TRANSFER, $data)) {
            Settings::set(Settings::PAYOUT_AUTO_TRANSFER, $data[Settings::PAYOUT_AUTO_TRANSFER]);
        }

        return $this->success(Settings::editable(), __('core::messages.settings_updated'));
    }
}
