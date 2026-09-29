<?php

namespace Modules\Packages\Services;

use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Packages\Models\Package;

class PackageService
{
    public function create(array $data): Package
    {
        $data['features'] ??= [];

        return Package::create($data);
    }

    public function update(Package $package, array $data): Package
    {
        // Changing the price does NOT change existing subscriptions: the
        // price was copied onto client_subscriptions.price_paid at purchase.
        $package->update($data);

        return $package->refresh();
    }

    /**
     * Soft delete; 409 PACKAGE_HAS_SUBSCRIPTIONS when active subscriptions
     * exist (plan PKG-05).
     */
    public function delete(Package $package): void
    {
        if ($package->activeSubscriptions()->exists()) {
            throw new BusinessException(ErrorCode::PackageHasSubscriptions, status: 409);
        }

        $package->delete();
    }

    public function setActive(Package $package, bool $active): Package
    {
        $package->update(['is_active' => $active]);

        return $package->refresh();
    }
}
