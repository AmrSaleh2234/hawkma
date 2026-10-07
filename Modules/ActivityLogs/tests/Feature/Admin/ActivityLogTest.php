<?php

namespace Modules\ActivityLogs\Tests\Feature\Admin;

use Laravel\Sanctum\PersonalAccessToken;
use Modules\ActivityLogs\Models\ActivityLog;
use Modules\Users\Models\User;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | Writing: model events land in the log automatically
    |----------------------------------------------------------------------
    */

    public function test_creating_a_record_through_the_api_writes_a_system_log(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Logged Admin',
            'email' => 'logged@gcmc.sa',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'roles' => ['admin'],
        ])->assertCreated();

        $user = User::query()->where('email', 'logged@gcmc.sa')->firstOrFail();

        $log = ActivityLog::query()
            ->where('event', 'created')
            ->where('subject_id', $user->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('system', $log->log_name);
        $this->assertSame('users', $log->module);
        $this->assertSame('User created', $log->description);
        $this->assertSame($user->getMorphClass(), $log->subject_type);
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame($admin->getMorphClass(), $log->causer_type);
        $this->assertNotNull($log->ip_address);
        $this->assertSame('POST', $log->properties['request']['method']);
        $this->assertSame('api/v1/admin/users', $log->properties['request']['url']);
        $this->assertSame('Logged Admin', $log->properties['attributes']['name']);
        // Secrets are never persisted.
        $this->assertArrayNotHasKey('password', $log->properties['attributes']);
    }

    public function test_updating_a_record_writes_a_diff_log(): void
    {
        $admin = $this->actingAsAdmin();
        $user = $this->createAdmin(['name' => 'Before Name', 'email' => 'before@gcmc.sa']);

        $this->putJson("/api/v1/admin/users/{$user->id}", [
            'name' => 'After Name',
        ])->assertOk();

        $log = ActivityLog::query()
            ->where('event', 'updated')
            ->where('subject_id', $user->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('users', $log->module);
        $this->assertSame('After Name', $log->properties['changes']['name']);
        $this->assertSame('Before Name', $log->properties['old']['name']);
        $this->assertSame($admin->id, $log->causer_id);
    }

    public function test_housekeeping_only_updates_are_not_logged(): void
    {
        $admin = $this->createAdmin(['email' => 'housekeeping@gcmc.sa']);

        // Login bumps last_login_at — that alone must not create an "updated" row.
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'housekeeping@gcmc.sa',
            'password' => 'Password@123',
        ])->assertOk();

        $this->assertSame(0, ActivityLog::query()
            ->where('event', 'updated')
            ->where('subject_id', $admin->id)
            ->count());
    }

    public function test_delete_and_restore_write_draft_entries(): void
    {
        $admin = $this->actingAsAdmin();
        $user = $this->createConsultant(['email' => 'draft-me@gcmc.sa']);

        $this->deleteJson("/api/v1/admin/users/{$user->id}")->assertOk();

        $deleted = ActivityLog::query()
            ->where('event', 'deleted')
            ->where('subject_id', $user->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertFalse($deleted->properties['force']);
        $this->assertSame($admin->id, $deleted->causer_id);

        $this->postJson("/api/v1/admin/users/{$user->id}/restore")->assertOk();

        $restored = ActivityLog::query()
            ->where('event', 'restored')
            ->where('subject_id', $user->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('users', $restored->module);
    }

    /*
    |----------------------------------------------------------------------
    | Writing: auth events land in the api log
    |----------------------------------------------------------------------
    */

    public function test_login_logout_and_failed_logins_are_logged_as_api_events(): void
    {
        $admin = $this->createAdmin(['email' => 'api-log@gcmc.sa']);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'api-log@gcmc.sa',
            'password' => 'WrongPassword1',
        ]);

        $failed = ActivityLog::query()
            ->where('event', 'login_failed')
            ->where('causer_id', $admin->id)
            ->firstOrFail();

        $this->assertSame('api', $failed->log_name);
        $this->assertSame('auth', $failed->module);
        $this->assertSame('api-log@gcmc.sa', $failed->properties['email']);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'api-log@gcmc.sa',
            'password' => 'Password@123',
        ])->assertOk();

        $login = ActivityLog::query()
            ->where('event', 'login')
            ->where('causer_id', $admin->id)
            ->firstOrFail();

        $this->assertSame('api', $login->log_name);
        $this->assertSame('admin', $login->properties['guard']);

        $token = PersonalAccessToken::query()->latest('id')->firstOrFail();

        $this->withHeader('Authorization', 'Bearer '.$token->id.'|fake')
            ->postJson('/api/v1/admin/auth/logout')
            ->assertUnauthorized();

        // Real logout via sanctum-issued token.
        $plainToken = $admin->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'event' => 'logout',
            'causer_id' => $admin->id,
            'log_name' => 'api',
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | LOG-01 GET /api/v1/admin/activity-logs — Perm: view-activity-logs
    |----------------------------------------------------------------------
    */

    public function test_log_01_lists_logs_paginated_newest_first(): void
    {
        $this->actingAsAdmin();
        $this->createClient();
        $this->createConsultant();

        $response = $this->getJson('/api/v1/admin/activity-logs');

        $this->assertPaginated($response);
        $this->assertGreaterThanOrEqual(2, $response->json('meta.total'));

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame($ids->sortDesc()->values()->all(), $ids->values()->all());
    }

    public function test_log_01_filters_by_module_event_and_log_name(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Filter Target',
            'email' => 'filter@gcmc.sa',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'roles' => ['admin'],
        ])->assertCreated();

        // The acting admin's own factory creation is also a users/created row,
        // but it ran before actingAs so only the API-created row has a causer.
        $byModule = $this->getJson('/api/v1/admin/activity-logs?module=users&event=created');
        $this->assertGreaterThanOrEqual(1, $byModule->json('meta.total'));
        $this->assertSame('User created', $byModule->json('data.0.description'));
        $this->assertSame($admin->id, $byModule->json('data.0.causer.id'));

        $byApi = $this->getJson('/api/v1/admin/activity-logs?log_name=api');
        $this->assertSame(0, $byApi->json('meta.total'));

        $byUser = $this->getJson("/api/v1/admin/activity-logs?user_id={$admin->id}");
        $this->assertSame(1, $byUser->json('meta.total'));
        $this->assertSame($admin->id, $byUser->json('data.0.causer.id'));
    }

    public function test_log_01_filters_by_date_and_time(): void
    {
        $this->actingAsAdmin();
        $this->createClient(['email' => 'dated@gcmc.sa']);

        // setUp seeds permissions BEFORE Carbon::setTestNow, so those rows carry
        // real-time stamps while everything below sits at the frozen
        // 2026-09-20 09:00 (Asia/Riyadh). Bound both sides to stay deterministic.
        $this->assertSame(0, $this->getJson('/api/v1/admin/activity-logs?date_from=2026-09-19&date_to=2026-09-19')->json('meta.total'));
        $this->assertGreaterThanOrEqual(1, $this->getJson('/api/v1/admin/activity-logs?date_from=2026-09-20&date_to=2026-09-20')->json('meta.total'));
        $this->assertGreaterThanOrEqual(1, $this->getJson('/api/v1/admin/activity-logs?date_from=2026-09-20 08:30:00&date_to=2026-09-20 09:30:00')->json('meta.total'));
        $this->assertSame(0, $this->getJson('/api/v1/admin/activity-logs?date_from=2026-09-20 10:00:00&date_to=2026-09-20 23:59:59')->json('meta.total'));
    }

    public function test_log_01_search_matches_description_and_causer_name(): void
    {
        $admin = $this->actingAsAdmin($this->createAdmin(['name' => 'Searchable Admin']));

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Needle Person',
            'email' => 'needle@gcmc.sa',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'roles' => ['admin'],
        ])->assertCreated();

        $byCauser = $this->getJson('/api/v1/admin/activity-logs?search=Searchable Admin');
        $this->assertGreaterThanOrEqual(1, $byCauser->json('meta.total'));

        $byDescription = $this->getJson('/api/v1/admin/activity-logs?search=User created');
        $this->assertGreaterThanOrEqual(1, $byDescription->json('meta.total'));
    }

    public function test_log_01_requires_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-dashboard']);
        $this->actingAsAdmin($staff);

        $this->assertApiError($this->getJson('/api/v1/admin/activity-logs'), 403, 'FORBIDDEN');
    }

    public function test_log_01_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/activity-logs')->assertUnauthorized();
    }

    /*
    |----------------------------------------------------------------------
    | LOG-02 GET /api/v1/admin/activity-logs/meta — Perm: view-activity-logs
    |----------------------------------------------------------------------
    */

    public function test_log_02_meta_returns_filter_values(): void
    {
        $this->actingAsAdmin();
        $this->createClient();

        $response = $this->getJson('/api/v1/admin/activity-logs/meta');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.log_names', ['system', 'api']);

        $this->assertContains('created', $response->json('data.events'));
        $this->assertContains('login_failed', $response->json('data.events'));
        $this->assertContains('clients', $response->json('data.modules'));
    }
}
