<?php

namespace Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\AccessControl\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\AccessControl\Models\Role;
use Modules\Users\Models\User;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        Storage::fake('public');
        Storage::fake('local');
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-20 09:00:00', 'Asia/Riyadh')); // a Sunday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function createAdmin(array $attrs = []): User
    {
        $user = User::factory()->admin()->create($attrs);
        $user->assignRole(Role::ADMIN);

        return $user;
    }

    protected function createConsultant(array $attrs = [], bool $withDefaultAvailability = true): User
    {
        $user = User::factory()->consultant()->create($attrs);
        $user->assignRole(Role::CONSULTANT);

        // The default Sun-Thu 09:00-17:00 availability is wired up in Phase 6,
        // when the Consultants module introduces ConsultantAvailability.

        return $user;
    }

    protected function createStaffWithPermissions(array $permissions): User
    {
        $user = User::factory()->admin()->create();

        $role = Role::create([
            'name' => 'staff-'.fake()->unique()->lexify('????????'),
            'guard_name' => 'admin',
        ]);
        $role->syncPermissions($permissions);

        $user->assignRole($role);

        return $user;
    }

    protected function actingAsAdmin(?User $user = null): User
    {
        $user ??= $this->createAdmin();

        Sanctum::actingAs($user, ['*'], 'admin');

        return $user;
    }

    protected function actingAsConsultant(?User $user = null): User
    {
        $user ??= $this->createConsultant();

        Sanctum::actingAs($user, ['*'], 'admin');

        return $user;
    }

    protected function assertApiSuccess(TestResponse $r, int $status = 200): TestResponse
    {
        return $r->assertStatus($status)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data']);
    }

    protected function assertApiError(TestResponse $r, int $status, string $code): TestResponse
    {
        return $r->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', $code);
    }

    protected function assertPaginated(TestResponse $r): TestResponse
    {
        return $r->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            'links',
        ]);
    }
}
