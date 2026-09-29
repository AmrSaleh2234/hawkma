<?php

namespace Modules\Clients\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Modules\Clients\Models\Client;
use Modules\Clients\Notifications\ClientResetPasswordNotification;
use Modules\Clients\Notifications\ClientWelcomeNotification;
use Tests\TestCase;

class ClientAuthTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CLI-AUTH-01 POST /api/v1/client/auth/register
    |----------------------------------------------------------------------
    */

    public function test_cli_auth_01_register_success_returns_a_token(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/client/auth/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@company.sa',
            'phone' => '0501234567',
            'company_name' => 'ACME Trading',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'ahmed@company.sa')
            ->assertJsonPath('data.user.company_name', 'ACME Trading');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('clients', ['email' => 'ahmed@company.sa']);

        Notification::assertSentTo(
            Client::where('email', 'ahmed@company.sa')->first(),
            ClientWelcomeNotification::class,
        );
    }

    public function test_cli_auth_01_registered_client_can_call_me_with_the_token(): void
    {
        $token = $this->postJson('/api/v1/client/auth/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@company.sa',
            'phone' => '0501234567',
            'company_name' => 'ACME Trading',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/client/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ahmed@company.sa');
    }

    public function test_cli_auth_01_duplicate_email_returns_422(): void
    {
        $this->createClient(['email' => 'ahmed@company.sa']);

        $this->assertApiError(
            $this->postJson('/api/v1/client/auth/register', [
                'name' => 'Ahmed Ali',
                'email' => 'ahmed@company.sa',
                'phone' => '0501234567',
                'company_name' => 'ACME Trading',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_cli_auth_01_bad_phone_returns_422(): void
    {
        $this->assertApiError(
            $this->postJson('/api/v1/client/auth/register', [
                'name' => 'Ahmed Ali',
                'email' => 'ahmed@company.sa',
                'phone' => '1234567890',
                'company_name' => 'ACME Trading',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]),
            422,
            'VALIDATION_ERROR',
        )->assertJsonStructure(['errors' => ['phone']]);
    }

    public function test_cli_auth_01_password_confirmation_mismatch_returns_422(): void
    {
        $this->assertApiError(
            $this->postJson('/api/v1/client/auth/register', [
                'name' => 'Ahmed Ali',
                'email' => 'ahmed@company.sa',
                'phone' => '0501234567',
                'company_name' => 'ACME Trading',
                'password' => 'password123',
                'password_confirmation' => 'different123',
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_cli_auth_01_same_email_can_exist_in_users_and_clients(): void
    {
        $admin = $this->createAdmin(['email' => 'shared@gcmc.sa']);

        $this->postJson('/api/v1/client/auth/register', [
            'name' => 'Ahmed Ali',
            'email' => 'shared@gcmc.sa',
            'phone' => '0501234567',
            'company_name' => 'ACME Trading',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        // Both can log in on their own guard.
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'shared@gcmc.sa',
            'password' => 'Password@123',
        ])->assertOk();

        $this->postJson('/api/v1/client/auth/login', [
            'email' => 'shared@gcmc.sa',
            'password' => 'password123',
        ])->assertOk();
    }

    /*
    |----------------------------------------------------------------------
    | CLI-AUTH-02 POST /api/v1/client/auth/login
    |----------------------------------------------------------------------
    */

    public function test_cli_auth_02_success_returns_a_token_and_sets_last_login_at(): void
    {
        $client = $this->createClient();

        $response = $this->postJson('/api/v1/client/auth/login', [
            'email' => $client->email,
            'password' => 'Password@123',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $client->id);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotNull($client->fresh()->last_login_at);
    }

    public function test_cli_auth_02_wrong_password_returns_invalid_credentials(): void
    {
        $client = $this->createClient();

        $response = $this->postJson('/api/v1/client/auth/login', [
            'email' => $client->email,
            'password' => 'WrongPassword1',
        ]);

        $this->assertApiError($response, 422, 'INVALID_CREDENTIALS')
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_cli_auth_02_inactive_client_gets_account_disabled(): void
    {
        $client = $this->createClient(['is_active' => false]);

        $response = $this->postJson('/api/v1/client/auth/login', [
            'email' => $client->email,
            'password' => 'Password@123',
        ]);

        $this->assertApiError($response, 403, 'ACCOUNT_DISABLED');
    }

    public function test_cli_auth_02_admin_credentials_cannot_log_in_here(): void
    {
        $this->createAdmin(['email' => 'admin@gcmc.sa']);

        $response = $this->postJson('/api/v1/client/auth/login', [
            'email' => 'admin@gcmc.sa',
            'password' => 'Password@123',
        ]);

        $this->assertApiError($response, 422, 'INVALID_CREDENTIALS');
    }

    /*
    |----------------------------------------------------------------------
    | CLI-AUTH-03 POST /api/v1/client/auth/logout
    |----------------------------------------------------------------------
    */

    public function test_cli_auth_03_logout_deletes_the_current_token(): void
    {
        $client = $this->createClient();

        $token = $this->postJson('/api/v1/client/auth/login', [
            'email' => $client->email,
            'password' => 'Password@123',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/client/auth/me')
            ->assertStatus(401);
    }

    /*
    |----------------------------------------------------------------------
    | CLI-AUTH-04 GET /api/v1/client/auth/me
    |----------------------------------------------------------------------
    */

    public function test_cli_auth_04_returns_the_details_payload(): void
    {
        $client = $this->actingAsClient();

        $response = $this->getJson('/api/v1/client/auth/me');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.active_subscriptions', [])
            ->assertJsonPath('data.default_payment_method', null)
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'email', 'phone', 'company_name', 'avatar_url',
                    'is_active', 'last_login_at', 'created_at',
                    'active_subscriptions', 'default_location', 'default_payment_method',
                ],
            ]);

        $this->assertTrue($response->json('data.default_location.is_default'));
    }

    public function test_cli_auth_04_admin_token_gets_401(): void
    {
        $admin = $this->createAdmin();

        $token = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'Password@123',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/client/auth/me')
            ->assertStatus(401);
    }

    public function test_client_token_gets_401_on_admin_me(): void
    {
        $client = $this->createClient();

        $token = $this->postJson('/api/v1/client/auth/login', [
            'email' => $client->email,
            'password' => 'Password@123',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/auth/me')
            ->assertStatus(401);
    }

    /*
    |----------------------------------------------------------------------
    | CLI-AUTH-05/06 forgot + reset password (broker: clients)
    |----------------------------------------------------------------------
    */

    public function test_cli_auth_05_sends_reset_link_and_responds_the_same_for_unknown_emails(): void
    {
        Notification::fake();

        $client = $this->createClient();

        $known = $this->postJson('/api/v1/client/auth/forgot-password', ['email' => $client->email]);
        $unknown = $this->postJson('/api/v1/client/auth/forgot-password', ['email' => 'nobody@company.sa']);

        $this->assertApiSuccess($known);
        $this->assertSame($known->json(), $unknown->json());

        Notification::assertSentTo($client, ClientResetPasswordNotification::class);
    }

    public function test_cli_auth_06_valid_token_resets_the_password_and_deletes_old_tokens(): void
    {
        $client = $this->createClient();

        $oldToken = $client->createToken('client-dashboard')->plainTextToken;

        $token = Password::broker('clients')->createToken($client);

        $response = $this->postJson('/api/v1/client/auth/reset-password', [
            'token' => $token,
            'email' => $client->email,
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ]);

        $this->assertApiSuccess($response);

        $this->postJson('/api/v1/client/auth/login', [
            'email' => $client->email,
            'password' => 'NewPassword@123',
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$oldToken}")
            ->getJson('/api/v1/client/auth/me')
            ->assertStatus(401);
    }

    public function test_cli_auth_06_invalid_token_returns_422(): void
    {
        $client = $this->createClient();

        $this->assertApiError(
            $this->postJson('/api/v1/client/auth/reset-password', [
                'token' => 'invalid-token',
                'email' => $client->email,
                'password' => 'NewPassword@123',
                'password_confirmation' => 'NewPassword@123',
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }
}
