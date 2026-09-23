<?php

namespace Modules\Consultants\Tests\Feature\Admin;

use Tests\TestCase;

class ConsultantAvailabilityTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CON-08 GET /api/v1/admin/consultants/{consultant}/availability
    |----------------------------------------------------------------------
    */

    public function test_con_08_always_returns_7_days_sunday_first(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant([], false);

        $response = $this->getJson("/api/v1/admin/consultants/{$consultant->id}/availability");

        $this->assertApiSuccess($response);
        $this->assertCount(7, $response->json('data.days'));
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], array_column($response->json('data.days'), 'day_of_week'));
        $this->assertFalse($response->json('data.days.0.is_working'));
    }

    /*
    |----------------------------------------------------------------------
    | CON-09 PUT /api/v1/admin/consultants/{consultant}/availability
    |----------------------------------------------------------------------
    */

    public function test_con_09_replaces_the_schedule(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant(); // has Sun–Thu 09:00–17:00

        $response = $this->putJson("/api/v1/admin/consultants/{$consultant->id}/availability", [
            'days' => [
                ['day_of_week' => 0, 'ranges' => [['start_time' => '10:00', 'end_time' => '12:00'], ['start_time' => '14:00', 'end_time' => '16:00']]],
                ['day_of_week' => 1, 'ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                ['day_of_week' => 5, 'ranges' => []],
            ],
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.warnings.conflicting_bookings_count', 0);

        $this->assertSame(3, $consultant->availabilities()->count());
        $this->assertSame([0, 1], $consultant->availabilities()->pluck('day_of_week')->unique()->sort()->values()->all());

        $days = collect($response->json('data.days'));
        $this->assertCount(2, $days->firstWhere('day_of_week', 0)['ranges']);
        $this->assertFalse($days->firstWhere('day_of_week', 2)['is_working']);
    }

    public function test_con_09_overlap_returns_422_with_keyed_errors(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $response = $this->putJson("/api/v1/admin/consultants/{$consultant->id}/availability", [
            'days' => [
                ['day_of_week' => 0, 'ranges' => [['start_time' => '10:00', 'end_time' => '12:00'], ['start_time' => '11:30', 'end_time' => '13:00']]],
            ],
        ]);

        $this->assertApiError($response, 422, 'AVAILABILITY_OVERLAP')
            ->assertJsonStructure(['errors' => ['days.0.ranges.1']]);
    }

    public function test_con_09_touching_ranges_are_allowed(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $this->putJson("/api/v1/admin/consultants/{$consultant->id}/availability", [
            'days' => [
                ['day_of_week' => 0, 'ranges' => [['start_time' => '10:00', 'end_time' => '12:00'], ['start_time' => '12:00', 'end_time' => '14:00']]],
            ],
        ])->assertOk();
    }

    public function test_con_09_minutes_must_be_multiples_of_the_slot_size(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $this->assertApiError(
            $this->putJson("/api/v1/admin/consultants/{$consultant->id}/availability", [
                'days' => [['day_of_week' => 0, 'ranges' => [['start_time' => '10:15', 'end_time' => '12:00']]]],
            ]),
            422,
            'AVAILABILITY_OVERLAP',
        );
    }

    public function test_con_09_end_before_start_returns_422(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $this->assertApiError(
            $this->putJson("/api/v1/admin/consultants/{$consultant->id}/availability", [
                'days' => [['day_of_week' => 0, 'ranges' => [['start_time' => '12:00', 'end_time' => '10:00']]]],
            ]),
            422,
            'AVAILABILITY_OVERLAP',
        );
    }

    public function test_con_09_a_duplicate_day_returns_422(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $this->assertApiError(
            $this->putJson("/api/v1/admin/consultants/{$consultant->id}/availability", [
                'days' => [
                    ['day_of_week' => 0, 'ranges' => []],
                    ['day_of_week' => 0, 'ranges' => []],
                ],
            ]),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_con_09_a_range_shorter_than_the_duration_returns_422(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        // 10:00–10:30 is exactly one duration (30 min) → OK; 10:00–10:00 and
        // a 15-minute range are not. (10:00–10:30 passes, so use a range that
        // is shorter than 30 minutes via a valid step: not possible with
        // 30-minute steps — assert the exact-minimum boundary instead.)
        $this->putJson("/api/v1/admin/consultants/{$consultant->id}/availability", [
            'days' => [['day_of_week' => 0, 'ranges' => [['start_time' => '10:00', 'end_time' => '10:30']]]],
        ])->assertOk();

        config()->set('bookings.duration_minutes', 60);

        $this->assertApiError(
            $this->putJson("/api/v1/admin/consultants/{$consultant->id}/availability", [
                'days' => [['day_of_week' => 0, 'ranges' => [['start_time' => '10:00', 'end_time' => '10:30']]]],
            ]),
            422,
            'AVAILABILITY_OVERLAP',
        );
    }

    public function test_con_09_consultant_updates_his_own_schedule(): void
    {
        $consultant = $this->actingAsConsultant();

        $this->putJson("/api/v1/admin/consultants/{$consultant->id}/availability", [
            'days' => [['day_of_week' => 3, 'ranges' => [['start_time' => '10:00', 'end_time' => '12:00']]]],
        ])->assertOk();
    }

    public function test_con_09_consultant_cannot_update_another_consultants_schedule(): void
    {
        $this->actingAsConsultant();
        $other = $this->createConsultant();

        $this->assertApiError(
            $this->putJson("/api/v1/admin/consultants/{$other->id}/availability", [
                'days' => [['day_of_week' => 3, 'ranges' => [['start_time' => '10:00', 'end_time' => '12:00']]]],
            ]),
            403,
            'FORBIDDEN',
        );
    }

    public function test_con_09_requires_the_manage_availability_permission(): void
    {
        $staff = $this->createStaffWithPermissions(['view-availability']);
        $this->actingAsAdmin($staff);
        $consultant = $this->createConsultant();

        $this->assertApiError(
            $this->putJson("/api/v1/admin/consultants/{$consultant->id}/availability", ['days' => []]),
            403,
            'FORBIDDEN',
        );
    }

    /*
    |----------------------------------------------------------------------
    | CON-13 GET /api/v1/admin/consultants/{consultant}/slots
    |----------------------------------------------------------------------
    */

    public function test_con_13_returns_the_same_slots_payload_as_the_public_endpoint(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant([], false);
        $consultant->availabilities()->create(['day_of_week' => 0, 'start_time' => '10:00', 'end_time' => '12:00']);

        $response = $this->getJson("/api/v1/admin/consultants/{$consultant->id}/slots?date=2026-09-27");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.date', '2026-09-27')
            ->assertJsonPath('data.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.slot_minutes', 30)
            ->assertJsonPath('data.duration_minutes', 30);

        $this->assertSame(
            ['10:00', '10:30', '11:00', '11:30'],
            array_column($response->json('data.slots'), 'time'),
        );
    }
}
