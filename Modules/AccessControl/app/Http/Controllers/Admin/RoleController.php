<?php

namespace Modules\AccessControl\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Http\Requests\Admin\StoreRoleRequest;
use Modules\AccessControl\Http\Requests\Admin\UpdateRoleRequest;
use Modules\AccessControl\Http\Resources\RoleResource;
use Modules\AccessControl\Models\Role;
use Modules\AccessControl\Services\RoleService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Users\Http\Resources\UserResource;
use Modules\Users\Models\User;

class RoleController extends ApiController
{
    public function __construct(protected RoleService $roles) {}

    /**
     * ACL-02 GET /api/v1/admin/roles
     */
    public function index(Request $request): JsonResponse
    {
        $query = Role::query()
            ->where('guard_name', 'admin')
            ->withCount(['permissions', 'users']);

        QueryFilters::apply($query, $request, ['name'], ['name', 'created_at']);

        $roles = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(RoleResource::collection($roles));
    }

    /**
     * ACL-03 POST /api/v1/admin/roles
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->validated());

        return $this->created(RoleResource::make($role), __('core::messages.created'));
    }

    /**
     * ACL-04 GET /api/v1/admin/roles/{role}
     */
    public function show(Role $role): JsonResponse
    {
        $role->load('permissions')->loadCount(['permissions', 'users']);

        return $this->success(RoleResource::make($role));
    }

    /**
     * ACL-05 PUT /api/v1/admin/roles/{role}
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roles->update($role, $request->validated());

        $role->loadCount(['permissions', 'users']);

        return $this->success(RoleResource::make($role), __('core::messages.updated'));
    }

    /**
     * ACL-06 DELETE /api/v1/admin/roles/{role}
     */
    public function destroy(Role $role): JsonResponse
    {
        $this->roles->delete($role);

        return $this->noContent(__('core::messages.deleted'));
    }

    /**
     * ACL-07 GET /api/v1/admin/roles/{role}/users
     */
    public function users(Request $request, Role $role): JsonResponse
    {
        $query = User::query()->role($role)->with('roles');

        QueryFilters::apply($query, $request, ['name', 'email'], ['name', 'created_at']);

        $users = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(UserResource::collection($users));
    }
}
