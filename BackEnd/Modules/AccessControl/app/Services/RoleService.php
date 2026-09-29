<?php

namespace Modules\AccessControl\Services;

use Modules\AccessControl\Models\Role;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;

class RoleService
{
    /**
     * Create a role with the given permissions (ACL-03).
     *
     * @param  array{name: string, permissions: array<int, string>}  $data
     */
    public function create(array $data): Role
    {
        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => 'admin',
        ]);

        $role->syncPermissions($data['permissions']);

        return $role->load('permissions');
    }

    /**
     * Update a role (ACL-05).
     *
     * The admin role cannot be changed at all. The consultant role cannot be
     * renamed, but its permissions can be edited.
     *
     * @param  array{name?: string, permissions?: array<int, string>}  $data
     */
    public function update(Role $role, array $data): Role
    {
        if ($role->isAdmin()) {
            throw new BusinessException(ErrorCode::RoleProtected, status: 403);
        }

        if (array_key_exists('name', $data) && $data['name'] !== $role->name) {
            if ($role->isProtected()) {
                throw new BusinessException(ErrorCode::RoleProtected, status: 403);
            }

            $role->name = $data['name'];
            $role->save();
        }

        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($data['permissions']);
        }

        return $role->load('permissions');
    }

    /**
     * Delete a role (ACL-06).
     */
    public function delete(Role $role): void
    {
        if ($role->isProtected()) {
            throw new BusinessException(ErrorCode::RoleProtected, status: 403);
        }

        if ($role->users()->exists()) {
            throw new BusinessException(ErrorCode::RoleHasUsers, status: 409);
        }

        $role->delete();
    }
}
