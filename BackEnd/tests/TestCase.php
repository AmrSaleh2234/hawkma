<?php

namespace Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\AccessControl\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\AccessControl\Models\Role;
use Modules\Clients\Models\Client;
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

        if ($withDefaultAvailability) {
            // Sunday–Thursday 09:00–17:00.
            foreach (range(0, 4) as $day) {
                $user->availabilities()->create([
                    'day_of_week' => $day,
                    'start_time' => '09:00',
                    'end_time' => '17:00',
                ]);
            }
        }

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

    protected function createClient(array $attrs = [], bool $withDefaultLocation = true): Client
    {
        $client = Client::factory()->create($attrs);

        if ($withDefaultLocation) {
            $client->locations()->create([
                'name' => 'Headquarters',
                'city' => 'Riyadh',
                'address' => 'King Fahd Rd',
                'latitude' => 24.7136,
                'longitude' => 46.6753,
                'is_default' => true,
            ]);
        }

        return $client;
    }

    protected function actingAsClient(?Client $client = null): Client
    {
        $client ??= $this->createClient();

        Sanctum::actingAs($client, ['*'], 'client');

        return $client;
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

    /**
     * N+1 guard for index endpoints (plan Phase 12): seed 3 records, then
     * 7 more, and assert the query count of the listing does not change.
     *
     * @param  callable(int $count): void  $seed  creates $count NEW records
     */
    protected function assertIndexQueryCountIsStable(string $url, callable $seed): void
    {
        $seed(3);

        // Warm-up: the first request fills the permissions cache etc.
        $this->getJson($url)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $forThree = count(DB::getQueryLog());

        $seed(7);

        DB::flushQueryLog();
        $this->getJson($url)->assertOk();
        $forTen = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $forThree,
            $forTen,
            "N+1 on {$url}: {$forThree} queries for 3 records, {$forTen} for 10.",
        );
    }
}
