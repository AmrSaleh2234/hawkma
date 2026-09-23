<?php

namespace Modules\AccessControl\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Modules\AccessControl\Http\Resources\PermissionResource;
use Modules\AccessControl\Models\Permission;
use Modules\AccessControl\Support\PermissionRegistry;
use Modules\Core\Http\Controllers\ApiController;

class PermissionController extends ApiController
{
    /**
     * ACL-01 GET /api/v1/admin/permissions
     *
     * Not paginated; grouped by the permission group.
     */
    public function index(): JsonResponse
    {
        $data = collect(PermissionRegistry::all())
            ->map(function (array $names, string $group) {
                $permissions = Permission::query()
                    ->where('guard_name', 'admin')
                    ->whereIn('name', $names)
                    ->get()
                    ->sortBy(fn (Permission $p) => array_search($p->name, $names, true))
                    ->values();

                return [
                    'group' => $group,
                    'label' => __("accesscontrol::groups.{$group}"),
                    'permissions' => PermissionResource::collection($permissions),
                ];
            })
            ->values();

        return $this->success($data);
    }
}
