<?php

namespace Modules\AccessControl\Tests\Unit;

use Modules\AccessControl\Models\Role;
use Modules\AccessControl\Services\RoleService;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Tests\TestCase;

class RoleServiceTest extends TestCase
{
    protected RoleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RoleService::class);
    }

    public function test_the_admin_role_cannot_be_updated(): void
    {
        $admin = Role::findByName('admin', 'admin');

        try {
            $this->service->update($admin, ['permissions' => ['view-bookings']]);
            $this->fail('BusinessException was not thrown');
        } catch (BusinessException $e) {
            $this->assertSame(ErrorCode::RoleProtected, $e->errorCode);
            $this->assertSame(403, $e->status);
        }
    }

    public function test_the_admin_role_cannot_be_deleted(): void
    {
        $admin = Role::findByName('admin', 'admin');

        $this->expectException(BusinessException::class);

        $this->service->delete($admin);
    }

    public function test_the_consultant_role_cannot_be_renamed_but_permissions_can_change(): void
    {
        $consultant = Role::findByName('consultant', 'admin');

        try {
            $this->service->update($consultant, ['name' => 'advisor']);
            $this->fail('BusinessException was not thrown');
        } catch (BusinessException $e) {
            $this->assertSame(ErrorCode::RoleProtected, $e->errorCode);
        }

        $updated = $this->service->update($consultant, ['permissions' => ['view-dashboard']]);

        $this->assertSame('consultant', $updated->name);
        $this->assertEqualsCanonicalizing(['view-dashboard'], $updated->permissions->pluck('name')->all());
    }

    public function test_the_consultant_role_cannot_be_deleted(): void
    {
        $consultant = Role::findByName('consultant', 'admin');

        try {
            $this->service->delete($consultant);
            $this->fail('BusinessException was not thrown');
        } catch (BusinessException $e) {
            $this->assertSame(ErrorCode::RoleProtected, $e->errorCode);
        }
    }

    public function test_a_role_with_users_cannot_be_deleted(): void
    {
        $role = Role::create(['name' => 'supervisor', 'guard_name' => 'admin']);
        $this->createStaffWithPermissions([])->assignRole($role);

        try {
            $this->service->delete($role);
            $this->fail('BusinessException was not thrown');
        } catch (BusinessException $e) {
            $this->assertSame(ErrorCode::RoleHasUsers, $e->errorCode);
            $this->assertSame(409, $e->status);
        }
    }
}
