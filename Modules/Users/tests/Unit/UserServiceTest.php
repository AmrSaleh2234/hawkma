<?php

namespace Modules\Users\Tests\Unit;

use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Users\Services\UserService;
use Tests\TestCase;

class UserServiceTest extends TestCase
{
    protected UserService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(UserService::class);
    }

    public function test_a_user_cannot_delete_himself(): void
    {
        $admin = $this->createAdmin();

        try {
            $this->service->delete($admin, $admin);
            $this->fail('BusinessException was not thrown');
        } catch (BusinessException $e) {
            $this->assertSame(ErrorCode::CannotDeleteSelf, $e->errorCode);
        }
    }

    public function test_the_last_active_admin_cannot_be_deleted(): void
    {
        $onlyAdmin = $this->createAdmin();
        $actor = $this->createStaffWithPermissions(['delete-users']);

        try {
            $this->service->delete($onlyAdmin, $actor);
            $this->fail('BusinessException was not thrown');
        } catch (BusinessException $e) {
            $this->assertSame(ErrorCode::LastAdmin, $e->errorCode);
        }
    }

    public function test_the_last_active_admin_cannot_lose_the_admin_role(): void
    {
        $onlyAdmin = $this->createAdmin();

        try {
            $this->service->syncRoles($onlyAdmin, ['consultant']);
            $this->fail('BusinessException was not thrown');
        } catch (BusinessException $e) {
            $this->assertSame(ErrorCode::LastAdmin, $e->errorCode);
        }
    }

    public function test_the_last_active_admin_cannot_be_deactivated(): void
    {
        $onlyAdmin = $this->createAdmin();
        $actor = $this->createStaffWithPermissions(['update-users']);

        try {
            $this->service->updateStatus($onlyAdmin, false, $actor);
            $this->fail('BusinessException was not thrown');
        } catch (BusinessException $e) {
            $this->assertSame(ErrorCode::LastAdmin, $e->errorCode);
        }
    }

    public function test_an_admin_can_be_deleted_when_another_active_admin_exists(): void
    {
        $adminA = $this->createAdmin();
        $adminB = $this->createAdmin();

        $this->service->delete($adminA, $adminB);

        $this->assertSoftDeleted('users', ['id' => $adminA->id]);
    }

    public function test_the_consultant_role_is_kept_on_sync(): void
    {
        $consultant = $this->createConsultant();

        $updated = $this->service->syncRoles($consultant, []);

        $this->assertTrue($updated->hasRole('consultant'));
    }

    public function test_deactivating_a_user_deletes_his_tokens(): void
    {
        $target = $this->createAdmin();
        $target->createToken('admin-dashboard');

        $actor = $this->createAdmin();

        $this->service->updateStatus($target, false, $actor);

        $this->assertSame(0, $target->tokens()->count());
    }
}
