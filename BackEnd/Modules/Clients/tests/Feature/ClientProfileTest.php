<?php

namespace Modules\Clients\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClientProfileTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CLI-PRF-01 GET /api/v1/client/profile
    |----------------------------------------------------------------------
    */

    public function test_cli_prf_01_returns_the_profile_with_details(): void
    {
        $client = $this->actingAsClient();

        $response = $this->getJson('/api/v1/client/profile');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.email', $client->email)
            ->assertJsonPath('data.active_subscriptions', [])
            ->assertJsonPath('data.default_payment_method', null);

        $this->assertTrue($response->json('data.default_location.is_default'));
    }

    public function test_cli_prf_01_requires_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/client/profile'), 401, 'UNAUTHENTICATED');
    }

    /*
    |----------------------------------------------------------------------
    | CLI-PRF-02 PUT /api/v1/client/profile
    |----------------------------------------------------------------------
    */

    public function test_cli_prf_02_updates_the_profile(): void
    {
        $client = $this->actingAsClient();

        $response = $this->putJson('/api/v1/client/profile', [
            'name' => 'New Name',
            'email' => 'new@company.sa',
            'phone' => '0509876543',
            'company_name' => 'New Company',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new@company.sa')
            ->assertJsonPath('data.phone', '0509876543')
            ->assertJsonPath('data.company_name', 'New Company');

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'email' => 'new@company.sa']);
    }

    public function test_cli_prf_02_email_must_be_unique_ignoring_self(): void
    {
        $client = $this->actingAsClient();
        $other = $this->createClient();

        // Own email is fine.
        $this->putJson('/api/v1/client/profile', [
            'name' => $client->name,
            'email' => $client->email,
            'phone' => $client->phone,
            'company_name' => $client->company_name,
        ])->assertOk();

        // Another client's email is not.
        $this->assertApiError(
            $this->putJson('/api/v1/client/profile', [
                'name' => $client->name,
                'email' => $other->email,
                'phone' => $client->phone,
                'company_name' => $client->company_name,
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    /*
    |----------------------------------------------------------------------
    | CLI-PRF-03/04 avatar
    |----------------------------------------------------------------------
    */

    public function test_cli_prf_03_uploads_an_avatar(): void
    {
        $client = $this->actingAsClient();

        $response = $this->postJson('/api/v1/client/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 400, 400),
        ]);

        $this->assertApiSuccess($response);

        $this->assertNotNull($client->fresh()->getFirstMedia('avatar'));
    }

    public function test_cli_prf_04_deletes_the_avatar(): void
    {
        $client = $this->actingAsClient();
        $client->addMedia(UploadedFile::fake()->image('avatar.jpg'))->toMediaCollection('avatar');

        $this->deleteJson('/api/v1/client/profile/avatar');

        $this->assertNull($client->fresh()->getFirstMedia('avatar'));
    }

    /*
    |----------------------------------------------------------------------
    | CLI-PRF-05 PUT /api/v1/client/profile/password
    |----------------------------------------------------------------------
    */

    public function test_cli_prf_05_changes_the_password_and_deletes_other_tokens(): void
    {
        $client = $this->createClient();

        $token1 = $client->createToken('client-dashboard')->plainTextToken;
        $token2 = $client->createToken('client-dashboard')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token1}")
            ->putJson('/api/v1/client/profile/password', [
                'current_password' => 'Password@123',
                'password' => 'NewPassword@123',
                'password_confirmation' => 'NewPassword@123',
            ])->assertOk();

        $this->app['auth']->forgetGuards();

        // The current token still works.
        $this->withHeader('Authorization', "Bearer {$token1}")
            ->getJson('/api/v1/client/auth/me')
            ->assertOk();

        // The other token is dead (forget the guard cached by the request above).
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/v1/client/auth/me')
            ->assertStatus(401);

        // The new password works.
        $this->postJson('/api/v1/client/auth/login', [
            'email' => $client->email,
            'password' => 'NewPassword@123',
        ])->assertOk();
    }

    public function test_cli_prf_05_wrong_current_password_returns_422(): void
    {
        $this->actingAsClient();

        $this->assertApiError(
            $this->putJson('/api/v1/client/profile/password', [
                'current_password' => 'WrongPassword1',
                'password' => 'NewPassword@123',
                'password_confirmation' => 'NewPassword@123',
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_cli_prf_05_admin_current_password_rule_does_not_leak_into_client_guard(): void
    {
        // The current_password rule must check the client guard, not admin.
        $client = $this->actingAsClient();

        $this->putJson('/api/v1/client/profile/password', [
            'current_password' => 'Password@123',
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ])->assertOk();

        $this->assertTrue(password_verify('NewPassword@123', $client->fresh()->password));
    }
}
