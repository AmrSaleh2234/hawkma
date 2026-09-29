<?php

namespace Modules\Users\Tests\Feature\Admin;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | ADM-PRF-01 GET /api/v1/admin/profile
    |----------------------------------------------------------------------
    */

    public function test_adm_prf_01_returns_the_profile_with_permissions(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/profile');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.email', $admin->email)
            ->assertJsonStructure(['data' => ['permissions', 'roles', 'avatar_url', 'avatar_thumb_url']]);
    }

    public function test_adm_prf_01_requires_authentication(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/profile'), 401, 'UNAUTHENTICATED');
    }

    /*
    |----------------------------------------------------------------------
    | ADM-PRF-02 PUT /api/v1/admin/profile
    |----------------------------------------------------------------------
    */

    public function test_adm_prf_02_updates_name_email_and_phone(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->putJson('/api/v1/admin/profile', [
            'name' => 'New Name',
            'email' => 'new-email@gcmc.sa',
            'phone' => '0559999999',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new-email@gcmc.sa')
            ->assertJsonPath('data.phone', '0559999999');
    }

    public function test_adm_prf_02_consultant_can_update_title_specialization_and_bio(): void
    {
        $this->actingAsConsultant();

        $response = $this->putJson('/api/v1/admin/profile', [
            'name' => 'Consultant Name',
            'email' => 'consultant@gcmc.sa',
            'phone' => null,
            'title' => 'مستشار حوكمة',
            'specialization' => 'الحوكمة المؤسسية',
            'bio' => 'خبرة ١٥ سنة',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.title', 'مستشار حوكمة')
            ->assertJsonPath('data.specialization', 'الحوكمة المؤسسية')
            ->assertJsonPath('data.bio', 'خبرة ١٥ سنة');
    }

    public function test_adm_prf_02_admin_cannot_change_consultant_fields(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->putJson('/api/v1/admin/profile', [
            'name' => $admin->name,
            'email' => $admin->email,
            'title' => 'Should Not Stick',
        ]);

        $this->assertApiSuccess($response);
        $this->assertNull($admin->fresh()->title);
    }

    public function test_adm_prf_02_email_must_be_unique(): void
    {
        $other = $this->createAdmin();
        $admin = $this->actingAsAdmin();

        $this->assertApiError(
            $this->putJson('/api/v1/admin/profile', [
                'name' => 'Name',
                'email' => $other->email,
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_adm_prf_02_requires_authentication(): void
    {
        $this->assertApiError($this->putJson('/api/v1/admin/profile', []), 401, 'UNAUTHENTICATED');
    }

    /*
    |----------------------------------------------------------------------
    | ADM-PRF-03 / ADM-PRF-04 avatar
    |----------------------------------------------------------------------
    */

    public function test_adm_prf_03_uploads_an_avatar(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 400, 400),
        ]);

        $this->assertApiSuccess($response);

        $media = $admin->fresh()->getFirstMedia('avatar');

        $this->assertNotNull($media);
        $this->assertNotNull($response->json('data.avatar_url'));
    }

    public function test_adm_prf_03_replaces_the_old_avatar(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/profile/avatar', ['avatar' => UploadedFile::fake()->image('one.jpg')]);
        $this->postJson('/api/v1/admin/profile/avatar', ['avatar' => UploadedFile::fake()->image('two.jpg')]);

        $this->assertSame(1, $admin->fresh()->getMedia('avatar')->count());
    }

    public function test_adm_prf_03_rejects_a_pdf(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_adm_prf_04_deletes_the_avatar(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/profile/avatar', ['avatar' => UploadedFile::fake()->image('avatar.jpg')]);
        $this->assertSame(1, $admin->fresh()->getMedia('avatar')->count());

        $this->deleteJson('/api/v1/admin/profile/avatar')->assertOk();

        $this->assertSame(0, $admin->fresh()->getMedia('avatar')->count());
    }

    /*
    |----------------------------------------------------------------------
    | ADM-PRF-05 PUT /api/v1/admin/profile/password
    |----------------------------------------------------------------------
    */

    public function test_adm_prf_05_changes_the_password(): void
    {
        $admin = $this->actingAsAdmin();

        $this->assertApiSuccess($this->putJson('/api/v1/admin/profile/password', [
            'current_password' => 'Password@123',
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ]));

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'NewPassword@123',
        ])->assertOk();
    }

    public function test_adm_prf_05_wrong_current_password_returns_422(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->putJson('/api/v1/admin/profile/password', [
                'current_password' => 'WrongPassword1',
                'password' => 'NewPassword@123',
                'password_confirmation' => 'NewPassword@123',
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_adm_prf_05_deletes_other_tokens_and_keeps_the_current_one(): void
    {
        $admin = $this->createAdmin(['email' => 'admin@gcmc.sa']);

        $login = fn () => $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@gcmc.sa',
            'password' => 'Password@123',
        ])->json('data.token');

        $tokenOne = $login();
        $tokenTwo = $login();

        $this->withHeader('Authorization', "Bearer {$tokenOne}")
            ->putJson('/api/v1/admin/profile/password', [
                'current_password' => 'Password@123',
                'password' => 'NewPassword@123',
                'password_confirmation' => 'NewPassword@123',
            ])->assertOk();

        $this->app['auth']->forgetGuards();

        // Current token still works.
        $this->withHeader('Authorization', "Bearer {$tokenOne}")
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk();

        // The other token was deleted.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$tokenTwo}")
            ->getJson('/api/v1/admin/auth/me')
            ->assertStatus(401);
    }

    public function test_adm_prf_05_requires_authentication(): void
    {
        $this->assertApiError($this->putJson('/api/v1/admin/profile/password', []), 401, 'UNAUTHENTICATED');
    }
}
