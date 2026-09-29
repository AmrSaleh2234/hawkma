<?php

namespace Modules\Users\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Users\Http\Requests\Admin\AssignRolesRequest;
use Modules\Users\Http\Requests\Admin\StoreUserRequest;
use Modules\Users\Http\Requests\Admin\UpdateUserRequest;
use Modules\Users\Http\Requests\Admin\UpdateUserStatusRequest;
use Modules\Users\Http\Requests\Admin\UploadAvatarRequest;
use Modules\Users\Http\Resources\UserResource;
use Modules\Users\Models\User;
use Modules\Users\Services\UserService;

class UserController extends ApiController
{
    public function __construct(protected UserService $users) {}

    /**
     * USR-01 GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['roles', 'media']);

        QueryFilters::apply($query, $request, ['name', 'email', 'phone'], ['name', 'created_at', 'last_login_at']);

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->value());
        }

        if ($request->filled('role')) {
            $query->role($request->string('role')->value());
        }

        if ($request->has('is_active') && $request->query('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $users = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(UserResource::collection($users));
    }

    /**
     * USR-02 POST /api/v1/admin/users
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->validated());

        if ($request->hasFile('avatar')) {
            $user->addMediaFromRequest('avatar')->toMediaCollection('avatar');
        }

        return $this->created(
            UserResource::make($user->fresh('roles')),
            __('core::messages.created'),
        );
    }

    /**
     * USR-03 GET /api/v1/admin/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        return $this->success(UserResource::make($user->load('roles')));
    }

    /**
     * USR-04 PUT /api/v1/admin/users/{user}
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->users->update($user, $request->validated());

        return $this->success(
            UserResource::make($user->fresh('roles')),
            __('core::messages.updated'),
        );
    }

    /**
     * USR-05 DELETE /api/v1/admin/users/{user}
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->users->delete($user, $request->user());

        return $this->noContent(__('core::messages.deleted'));
    }

    /**
     * USR-06 PUT /api/v1/admin/users/{user}/roles
     */
    public function syncRoles(AssignRolesRequest $request, User $user): JsonResponse
    {
        $user = $this->users->syncRoles($user, $request->validated('roles'));

        return $this->success(
            UserResource::make($user)->withPermissions(),
            __('core::messages.roles_assigned'),
        );
    }

    /**
     * USR-07 PATCH /api/v1/admin/users/{user}/status
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $user = $this->users->updateStatus($user, $request->boolean('is_active'), $request->user());

        return $this->success(
            UserResource::make($user->fresh('roles')),
            __('core::messages.updated'),
        );
    }

    /**
     * USR-08 POST /api/v1/admin/users/{user}/avatar
     */
    public function storeAvatar(UploadAvatarRequest $request, User $user): JsonResponse
    {
        $user->addMediaFromRequest('avatar')->toMediaCollection('avatar');

        return $this->success(
            UserResource::make($user->fresh('roles')),
            __('core::messages.avatar_updated'),
        );
    }
}
