<?php

namespace Modules\Packages\Http\Controllers\Public;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Packages\Http\Resources\PackageResource;
use Modules\Packages\Models\Package;

class PublicPackageController extends ApiController
{
    /**
     * PUB-01 GET /api/v1/public/packages
     *
     * The active packages ordered by sort_order. Not paginated.
     */
    public function index(): JsonResponse
    {
        $packages = Package::active()->ordered()->get();

        return $this->success(PackageResource::collection($packages));
    }

    /**
     * PUB-02 GET /api/v1/public/packages/{slug}
     *
     * 404 if the slug is unknown or the package is inactive.
     */
    public function show(string $slug): JsonResponse
    {
        $package = Package::active()->where('slug', $slug)->firstOrFail();

        return $this->success(PackageResource::make($package));
    }
}
