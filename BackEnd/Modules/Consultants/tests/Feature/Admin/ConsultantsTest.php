<?php

namespace Modules\Consultants\Tests\Feature\Admin;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Modules\Users\Enums\UserType;
use Modules\Users\Models\User;
use Modules\Users\Notifications\StaffAccountCreatedNotification;
use Tests\TestCase;

class ConsultantsTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CON-01 GET /api/v1/admin/consultants
    |----------------------------------------------------------------------
    */

    public function test_con_01_lists_consultants_with_working_days(): void
    {
        $this->actingAsAdmin();
        $this->createConsultant(['name' => 'Ahmad']);
        $this->createAdmin(['name' => 'Not A Consultant']);

        $response = $this->getJson('/api/v1/admin/consultants');

        $this->assertApiSuccess($response);
        $this->assertPaginated($response);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame([0, 1, 2, 3, 4], $response->json('data.0.working_days'));
        $this->assertSame(['consultant'], $response->json('data.0.roles'));
    }

    public function test_con_01_search(): void
    {
        $this->actingAsAdmin();
        $this->createConsultant(['name' => 'Ahmad Governance', 'specialization' => 'الحوكمة']);
        $this->createConsultant(['name' => 'Sara Strategy', 'specialization' => 'الاستراتيجية']);

        $this->assertCount(1, $this->getJson('/api/v1/admin/consultants?search=Ahmad')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/consultants?search='.urlencode('الاستراتيجية'))->json('data'));
    }

    public function test_con_01_consultant_sees_only_himself(): void
    {
        $consultant = $this->createConsultant();
        $consultant->givePermissionTo('view-consultants');
        $this->createConsultant(['name' => 'Another One']);

        $this->actingAsConsultant($consultant);

        $response = $this->getJson('/api/v1/admin/consultants');

        $this->assertApiSuccess($response);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($consultant->id, $response->json('data.0.id'));
    }

    public function test_con_01_consultant_without_the_permission_gets_403(): void
    {
        $this->actingAsConsultant();

        $this->assertApiError($this->getJson('/api/v1/admin/consultants'), 403, 'FORBIDDEN');
    }

    public function test_con_01_requires_the_view_consultants_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-users']);
        $this->actingAsAdmin($staff);

        $this->assertApiError($this->getJson('/api/v1/admin/consultants'), 403, 'FORBIDDEN');
    }

    /*
    |----------------------------------------------------------------------
    | CON-02 POST /api/v1/admin/consultants
    |----------------------------------------------------------------------
    */

    public function test_con_02_creates_a_consultant_with_only_name_and_email(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/consultants', [
            'name' => 'أ. أحمد العتيبي',
            'email' => 'ahmad@gcmc.sa',
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.name', 'أ. أحمد العتيبي')
            ->assertJsonPath('data.roles', ['consultant']);

        $consultant = User::where('email', 'ahmad@gcmc.sa')->firstOrFail();

        $this->assertSame(UserType::Consultant, $consultant->type);
        $this->assertTrue($consultant->hasRole('consultant'));
        $this->assertTrue($consultant->is_active);

        // No password was given → the set-password email is sent.
        Notification::assertSentTo($consultant, StaffAccountCreatedNotification::class);
    }

    public function test_con_02_with_a_photo(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/consultants', [
            'name' => 'Ahmad',
            'email' => 'ahmad@gcmc.sa',
            'photo' => UploadedFile::fake()->image('photo.jpg', 400, 400),
        ]);

        $this->assertApiSuccess($response, 201);

        $consultant = User::where('email', 'ahmad@gcmc.sa')->firstOrFail();
        $this->assertNotNull($consultant->getFirstMedia('avatar'));
    }

    public function test_con_02_validation(): void
    {
        $this->actingAsAdmin();
        $this->createConsultant(['email' => 'taken@gcmc.sa']);

        // Missing name.
        $this->assertApiError(
            $this->postJson('/api/v1/admin/consultants', ['email' => 'a@gcmc.sa']),
            422,
            'VALIDATION_ERROR',
        );

        // Missing email.
        $this->assertApiError(
            $this->postJson('/api/v1/admin/consultants', ['name' => 'Ahmad']),
            422,
            'VALIDATION_ERROR',
        );

        // Duplicate email.
        $this->assertApiError(
            $this->postJson('/api/v1/admin/consultants', ['name' => 'Ahmad', 'email' => 'taken@gcmc.sa']),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_con_02_with_availability_creates_the_rows(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/consultants', [
            'name' => 'Ahmad',
            'email' => 'ahmad@gcmc.sa',
            'availability' => [
                'days' => [
                    ['day_of_week' => 0, 'ranges' => [['start_time' => '10:00', 'end_time' => '12:00'], ['start_time' => '14:00', 'end_time' => '16:00']]],
                    ['day_of_week' => 2, 'ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                ],
            ],
        ]);

        $this->assertApiSuccess($response, 201);

        $consultant = User::where('email', 'ahmad@gcmc.sa')->firstOrFail();

        $this->assertSame(3, $consultant->availabilities()->count());
        $this->assertSame([0, 2], $consultant->availabilities()->pluck('day_of_week')->unique()->sort()->values()->all());
    }

    public function test_con_02_with_an_overlapping_availability_returns_422(): void
    {
        $this->actingAsAdmin();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/consultants', [
                'name' => 'Ahmad',
                'email' => 'ahmad@gcmc.sa',
                'availability' => [
                    'days' => [
                        ['day_of_week' => 0, 'ranges' => [['start_time' => '10:00', 'end_time' => '12:00'], ['start_time' => '11:00', 'end_time' => '13:00']]],
                    ],
                ],
            ]),
            422,
            'AVAILABILITY_OVERLAP',
        );
    }

    /*
    |----------------------------------------------------------------------
    | CON-03 GET /api/v1/admin/consultants/{consultant}
    |----------------------------------------------------------------------
    */

    public function test_con_03_admin_can_view_with_availability(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $response = $this->getJson("/api/v1/admin/consultants/{$consultant->id}");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $consultant->id)
            ->assertJsonStructure(['data' => ['availability' => ['days']]]);

        $this->assertCount(7, $response->json('data.availability.days'));
        $this->assertSame(0, $response->json('data.availability.days.0.day_of_week'));
        $this->assertSame('Sunday', $response->json('data.availability.days.0.day_name'));
        $this->assertSame('الأحد', $response->json('data.availability.days.0.day_name_ar'));
        $this->assertTrue($response->json('data.availability.days.0.is_working'));
        $this->assertFalse($response->json('data.availability.days.5.is_working'));
    }

    public function test_con_03_consultant_viewing_another_consultant_gets_403(): void
    {
        $a = $this->createConsultant();
        $a->givePermissionTo('view-consultants');
        $b = $this->createConsultant();

        $this->actingAsConsultant($a);

        // The permission middleware passes; the policy denies.
        $this->assertApiError(
            $this->getJson("/api/v1/admin/consultants/{$b->id}"),
            403,
            'FORBIDDEN',
        );
    }

    public function test_con_03_consultant_viewing_himself_gets_200(): void
    {
        $consultant = $this->createConsultant();
        $consultant->givePermissionTo('view-consultants');

        $this->actingAsConsultant($consultant);

        $this->getJson("/api/v1/admin/consultants/{$consultant->id}")->assertOk();
    }

    public function test_con_03_an_admin_user_id_is_a_404(): void
    {
        $this->actingAsAdmin();
        $admin = $this->createAdmin();

        $this->assertApiError(
            $this->getJson("/api/v1/admin/consultants/{$admin->id}"),
            404,
            'NOT_FOUND',
        );
    }

    /*
    |----------------------------------------------------------------------
    | CON-04 PUT /api/v1/admin/consultants/{consultant}
    |----------------------------------------------------------------------
    */

    public function test_con_04_updates_a_consultant(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $response = $this->putJson("/api/v1/admin/consultants/{$consultant->id}", [
            'title' => 'مستشار حوكمة',
            'specialization' => 'الحوكمة المؤسسية',
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.title', 'مستشار حوكمة')
            ->assertJsonPath('data.specialization', 'الحوكمة المؤسسية');

        $this->assertSame('مستشار حوكمة', $consultant->fresh()->title);
    }

    /*
    |----------------------------------------------------------------------
    | CON-05 DELETE /api/v1/admin/consultants/{consultant}
    |----------------------------------------------------------------------
    */

    public function test_con_05_soft_deletes_a_consultant(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $this->deleteJson("/api/v1/admin/consultants/{$consultant->id}")->assertOk();

        $this->assertSoftDeleted('users', ['id' => $consultant->id]);
    }

    /*
    |----------------------------------------------------------------------
    | CON-06 PATCH /api/v1/admin/consultants/{consultant}/status
    |----------------------------------------------------------------------
    */

    public function test_con_06_deactivated_consultant_disappears_from_the_public_list(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $this->assertCount(1, $this->getJson('/api/v1/public/consultants')->json('data'));

        $this->patchJson("/api/v1/admin/consultants/{$consultant->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertCount(0, $this->getJson('/api/v1/public/consultants')->json('data'));
    }

    /*
    |----------------------------------------------------------------------
    | CON-07 POST /api/v1/admin/consultants/{consultant}/photo
    |----------------------------------------------------------------------
    */

    public function test_con_07_updates_the_photo(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $response = $this->postJson("/api/v1/admin/consultants/{$consultant->id}/photo", [
            'photo' => UploadedFile::fake()->image('photo.png', 400, 400),
        ]);

        $this->assertApiSuccess($response);
        $this->assertNotNull($consultant->fresh()->getFirstMedia('avatar'));
    }
}
