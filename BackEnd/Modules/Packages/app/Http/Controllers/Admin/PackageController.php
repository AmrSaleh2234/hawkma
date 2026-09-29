<?php

namespace Modules\Packages\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Packages\Http\Requests\Admin\StorePackageRequest;
use Modules\Packages\Http\Requests\Admin\UpdatePackageRequest;
use Modules\Packages\Http\Requests\Admin\UpdatePackageStatusRequest;
use Modules\Packages\Http\Resources\PackageResource;
use Modules\Packages\Models\Package;
use Modules\Packages\Services\PackageService;

class PackageController extends ApiController
{
    public function __construct(protected PackageService $packages) {}

    /**
     * PKG-01 GET /api/v1/admin/packages
     *
     * Includes inactive packages. Query: is_active, search. With
     * subscriptions_count.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Package::query()->withCount('subscriptions');

        QueryFilters::apply(
            $query,
            $request,
            ['name_ar', 'name_en', 'slug'],
            ['name_ar', 'name_en', 'price', 'sort_order', 'created_at'],
            'sort_order',
        );

        if ($request->has('is_active') && $request->query('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $packages = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(PackageResource::collection($packages));
    }

    /**
     * PKG-02 POST /api/v1/admin/packages
     */
    public function store(StorePackageRequest $request): JsonResponse
    {
        $package = $this->packages->create($request->validated());

        return $this->created(PackageResource::make($package), __('core::messages.created'));
    }

    /**
     * PKG-03 GET /api/v1/admin/packages/{package}
     */
    public function show(Package $package): JsonResponse
    {
        $package->loadCount('subscriptions');

        return $this->success(PackageResource::make($package));
    }

    /**
     * PKG-04 PUT /api/v1/admin/packages/{package}
     *
     * Changing the price does NOT change existing subscriptions.
     */
    public function update(UpdatePackageRequest $request, Package $package): JsonResponse
    {
        $package = $this->packages->update($package, $request->validated());

        return $this->success(PackageResource::make($package), __('core::messages.updated'));
    }

    /**
     * PKG-05 DELETE /api/v1/admin/packages/{package}
     *
     * 409 PACKAGE_HAS_SUBSCRIPTIONS if there are active subscriptions;
     * otherwise soft delete.
     */
    public function destroy(Package $package): JsonResponse
    {
        $this->packages->delete($package);

        return $this->noContent(__('core::messages.deleted'));
    }

    /**
     * PKG-06 PATCH /api/v1/admin/packages/{package}/status
     */
    public function updateStatus(UpdatePackageStatusRequest $request, Package $package): JsonResponse
    {
        $package = $this->packages->setActive($package, $request->boolean('is_active'));

        return $this->success(PackageResource::make($package), __('core::messages.updated'));
    }
}
