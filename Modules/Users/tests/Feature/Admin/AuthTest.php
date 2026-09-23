<?php

namespace Modules\Users\Tests\Feature\Admin;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Modules\AccessControl\Support\PermissionRegistry;
use Modules\Users\Notifications\ResetPasswordNotification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | ADM-AUTH-01 POST /api/v1/admin/auth/login
    |----------------------------------------------------------------------
    */

    public function test_adm_auth_01_success_returns_token_roles_and_permissions(): void
    {
        $admin = $this->createAdmin(['email' => 'admin@gcmc.sa']);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@gcmc.sa',
            'password' => 'Password@123',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.roles', ['admin']);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertCount(count(PermissionRegistry::names()), $response->json('data.user.permissions'));
    }

    public function test_adm_auth_01_sets_last_login_at(): void
    {
        $admin = $this->createAdmin();

        $this->assertNull($admin->last_login_at);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'Password@123',
        ])->assertOk();

        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_adm_auth_01_wrong_password_returns_invalid_credentials(): void
    {
        $admin = $this->createAdmin();

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'WrongPassword1',
        ]);

        $this->assertApiError($response, 422, 'INVALID_CREDENTIALS')
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_adm_auth_01_unknown_email_returns_invalid_credentials(): void
    {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'nobody@gcmc.sa',
            'password' => 'Password@123',
        ]);

        $this->assertApiError($response, 422, 'INVALID_CREDENTIALS');
    }

    public function test_adm_auth_01_inactive_user_gets_account_disabled(): void
    {
        $admin = $this->createAdmin(['is_active' => false]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'Password@123',
        ]);

        $this->assertApiError($response, 403, 'ACCOUNT_DISABLED');
    }

    public function test_adm_auth_01_validation_errors(): void
    {
        $this->assertApiError(
            $this->postJson('/api/v1/admin/auth/login', []),
            422,
            'VALIDATION_ERROR',
        );

        $this->assertApiError(
            $this->postJson('/api/v1/admin/auth/login', ['email' => 'not-an-email', 'password' => 'x']),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_adm_auth_01_throttled_after_10_attempts(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => 'admin@gcmc.sa',
                'password' => 'wrong',
            ])->assertStatus(422);
        }

        $this->assertApiError(
            $this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@gcmc.sa', 'password' => 'wrong']),
            429,
            'TOO_MANY_REQUESTS',
        );
    }

    /*
    |----------------------------------------------------------------------
    | ADM-AUTH-02 POST /api/v1/admin/auth/logout
    |----------------------------------------------------------------------
    */

    public function test_adm_auth_02_logout_deletes_the_current_token(): void
    {
        $admin = $this->createAdmin();

        $token = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'Password@123',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        // The token cannot be used any more.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/auth/me')
            ->assertStatus(401);
    }

    public function test_adm_auth_02_requires_authentication(): void
    {
        $this->assertApiError($this->postJson('/api/v1/admin/auth/logout'), 401, 'UNAUTHENTICATED');
    }

    /*
    |----------------------------------------------------------------------
    | ADM-AUTH-03 GET /api/v1/admin/auth/me
    |----------------------------------------------------------------------
    */

    public function test_adm_auth_03_admin_gets_all_permissions(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/auth/me');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.roles', ['admin']);

        $this->assertCount(34, $response->json('data.permissions'));
    }

    public function test_adm_auth_03_consultant_gets_exactly_the_consultant_permissions(): void
    {
        $this->actingAsConsultant();

        $response = $this->getJson('/api/v1/admin/auth/me');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.roles', ['consultant']);

        $this->assertEqualsCanonicalizing(
            PermissionRegistry::consultantDefaults(),
            $response->json('data.permissions'),
        );
    }

    public function test_adm_auth_03_requires_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/auth/me'), 401, 'UNAUTHENTICATED');
    }

    /*
    |----------------------------------------------------------------------
    | ADM-AUTH-04 POST /api/v1/admin/auth/forgot-password
    |----------------------------------------------------------------------
    */

    public function test_adm_auth_04_sends_reset_link_to_existing_user(): void
    {
        Notification::fake();

        $admin = $this->createAdmin();

        $response = $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $admin->email]);

        $this->assertApiSuccess($response);
        Notification::assertSentTo($admin, ResetPasswordNotification::class);
    }

    public function test_adm_auth_04_same_response_for_known_and_unknown_emails(): void
    {
        Notification::fake();

        $admin = $this->createAdmin();

        $known = $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $admin->email]);
        $unknown = $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'nobody@gcmc.sa']);

        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_adm_auth_04_nothing_is_sent_for_an_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'nobody@gcmc.sa'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    /*
    |----------------------------------------------------------------------
    | ADM-AUTH-05 POST /api/v1/admin/auth/reset-password
    |----------------------------------------------------------------------
    */

    public function test_adm_auth_05_valid_token_resets_the_password_and_deletes_old_tokens(): void
    {
        $admin = $this->createAdmin();

        // An existing API token that must be deleted by the reset.
        $oldToken = $admin->createToken('admin-dashboard')->plainTextToken;

        $token = Password::broker('users')->createToken($admin);

        $response = $this->postJson('/api/v1/admin/auth/reset-password', [
            'token' => $token,
            'email' => $admin->email,
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ]);

        $this->assertApiSuccess($response);

        // Can log in with the new password.
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'NewPassword@123',
        ])->assertOk();

        // The old token no longer works.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$oldToken}")
            ->getJson('/api/v1/admin/auth/me')
            ->assertStatus(401);
    }

    public function test_adm_auth_05_invalid_token_returns_422(): void
    {
        $admin = $this->createAdmin();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/auth/reset-password', [
                'token' => 'invalid-token',
                'email' => $admin->email,
                'password' => 'NewPassword@123',
                'password_confirmation' => 'NewPassword@123',
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_adm_auth_05_password_rules_are_enforced(): void
    {
        $admin = $this->createAdmin();
        $token = Password::broker('users')->createToken($admin);

        // Too weak: no mixed case / numbers.
        $this->assertApiError(
            $this->postJson('/api/v1/admin/auth/reset-password', [
                'token' => $token,
                'email' => $admin->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }
}
