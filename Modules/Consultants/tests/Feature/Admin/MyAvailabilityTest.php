<?php

namespace Modules\Consultants\Tests\Feature\Admin;

use Tests\TestCase;

class MyAvailabilityTest extends TestCase
{
    public function test_my_01_returns_the_consultants_own_availability(): void
    {
        $this->actingAsConsultant();

        $response = $this->getJson('/api/v1/admin/my/availability');

        $this->assertApiSuccess($response);
        $this->assertCount(7, $response->json('data.days'));
        $this->assertTrue($response->json('data.days.0.is_working'));
        $this->assertFalse($response->json('data.days.5.is_working'));
    }

    public function test_my_02_replaces_the_own_schedule(): void
    {
        $consultant = $this->actingAsConsultant();

        $response = $this->putJson('/api/v1/admin/my/availability', [
            'days' => [['day_of_week' => 6, 'ranges' => [['start_time' => '10:00', 'end_time' => '12:00']]]],
        ]);

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.warnings.conflicting_bookings_count', 0);

        $this->assertSame([6], $consultant->availabilities()->pluck('day_of_week')->all());
    }

    public function test_my_03_and_my_04_time_offs(): void
    {
        $consultant = $this->actingAsConsultant();

        $create = $this->postJson('/api/v1/admin/my/time-offs', [
            'date' => '2026-09-25',
            'start_time' => '11:00',
            'end_time' => '12:00',
            'reason' => 'Doctor',
        ]);

        $this->assertApiSuccess($create, 201)
            ->assertJsonPath('data.warnings.conflicting_bookings_count', 0);

        $list = $this->getJson('/api/v1/admin/my/time-offs');

        $this->assertApiSuccess($list);
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('11:00', $list->json('data.0.start_time'));

        $this->assertSame(1, $consultant->timeOffs()->count());
    }

    public function test_my_05_deletes_only_his_own_time_off(): void
    {
        $consultant = $this->actingAsConsultant();
        $other = $this->createConsultant();

        $own = $consultant->timeOffs()->create(['date' => '2026-09-25']);
        $others = $other->timeOffs()->create(['date' => '2026-09-26']);

        $this->deleteJson("/api/v1/admin/my/time-offs/{$own->id}")->assertOk();
        $this->assertDatabaseMissing('consultant_time_offs', ['id' => $own->id]);

        $this->assertApiError(
            $this->deleteJson("/api/v1/admin/my/time-offs/{$others->id}"),
            404,
            'NOT_FOUND',
        );
    }

    public function test_my_06_returns_his_own_slots(): void
    {
        $consultant = $this->actingAsConsultant($this->createConsultant([], false));
        $consultant->availabilities()->create(['day_of_week' => 0, 'start_time' => '10:00', 'end_time' => '12:00']);

        $response = $this->getJson('/api/v1/admin/my/slots?date=2026-09-27');

        $this->assertApiSuccess($response);
        $this->assertSame(['10:00', '10:30', '11:00', '11:30'], array_column($response->json('data.slots'), 'time'));
    }

    public function test_my_routes_are_forbidden_for_admin_users(): void
    {
        $this->actingAsAdmin();

        $validDays = ['days' => [['day_of_week' => 0, 'ranges' => [['start_time' => '10:00', 'end_time' => '12:00']]]]];

        $this->assertApiError($this->getJson('/api/v1/admin/my/availability'), 403, 'FORBIDDEN');
        $this->assertApiError($this->putJson('/api/v1/admin/my/availability', $validDays), 403, 'FORBIDDEN');
        $this->assertApiError($this->getJson('/api/v1/admin/my/time-offs'), 403, 'FORBIDDEN');
        $this->assertApiError($this->postJson('/api/v1/admin/my/time-offs', ['date' => '2026-09-25']), 403, 'FORBIDDEN');
        $this->assertApiError($this->deleteJson('/api/v1/admin/my/time-offs/1'), 403, 'FORBIDDEN');
        $this->assertApiError($this->getJson('/api/v1/admin/my/slots?date=2026-09-27'), 403, 'FORBIDDEN');
    }
}
