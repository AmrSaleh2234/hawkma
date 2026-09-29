<?php

namespace Modules\Consultants\Tests\Feature\Admin;

use Tests\TestCase;

class ConsultantTimeOffTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CON-10 GET /api/v1/admin/consultants/{consultant}/time-offs
    |----------------------------------------------------------------------
    */

    public function test_con_10_lists_time_offs_from_today_by_default(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $consultant->timeOffs()->create(['date' => '2026-09-19']); // past
        $future = $consultant->timeOffs()->create(['date' => '2026-09-25', 'reason' => 'Hajj']);

        $response = $this->getJson("/api/v1/admin/consultants/{$consultant->id}/time-offs");

        $this->assertApiSuccess($response);
        $this->assertPaginated($response);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($future->id, $response->json('data.0.id'));
        $this->assertTrue($response->json('data.0.is_full_day'));
    }

    public function test_con_10_filters_by_from_and_to(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $consultant->timeOffs()->create(['date' => '2026-09-25']);
        $consultant->timeOffs()->create(['date' => '2026-10-10']);

        $this->assertCount(2, $this->getJson("/api/v1/admin/consultants/{$consultant->id}/time-offs?from=2026-09-01&to=2026-12-31")->json('data'));
        $this->assertCount(1, $this->getJson("/api/v1/admin/consultants/{$consultant->id}/time-offs?from=2026-09-01&to=2026-09-30")->json('data'));
    }

    /*
    |----------------------------------------------------------------------
    | CON-11 POST /api/v1/admin/consultants/{consultant}/time-offs
    |----------------------------------------------------------------------
    */

    public function test_con_11_creates_a_whole_day_time_off(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $response = $this->postJson("/api/v1/admin/consultants/{$consultant->id}/time-offs", [
            'date' => '2026-09-25',
            'reason' => 'National day',
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.date', '2026-09-25')
            ->assertJsonPath('data.is_full_day', true)
            ->assertJsonPath('data.start_time', null)
            ->assertJsonPath('data.warnings.conflicting_bookings_count', 0);
    }

    public function test_con_11_creates_a_ranged_time_off(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $response = $this->postJson("/api/v1/admin/consultants/{$consultant->id}/time-offs", [
            'date' => '2026-09-25',
            'start_time' => '11:00',
            'end_time' => '12:00',
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.is_full_day', false)
            ->assertJsonPath('data.start_time', '11:00')
            ->assertJsonPath('data.end_time', '12:00');
    }

    public function test_con_11_validation(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        // Past date.
        $this->assertApiError(
            $this->postJson("/api/v1/admin/consultants/{$consultant->id}/time-offs", ['date' => '2026-09-19']),
            422,
            'VALIDATION_ERROR',
        );

        // end_time without start_time.
        $this->assertApiError(
            $this->postJson("/api/v1/admin/consultants/{$consultant->id}/time-offs", ['date' => '2026-09-25', 'end_time' => '12:00']),
            422,
            'VALIDATION_ERROR',
        );

        // end before start.
        $this->assertApiError(
            $this->postJson("/api/v1/admin/consultants/{$consultant->id}/time-offs", ['date' => '2026-09-25', 'start_time' => '12:00', 'end_time' => '11:00']),
            422,
            'VALIDATION_ERROR',
        );
    }

    /*
    |----------------------------------------------------------------------
    | CON-12 DELETE /api/v1/admin/consultants/{consultant}/time-offs/{timeOff}
    |----------------------------------------------------------------------
    */

    public function test_con_12_deletes_a_time_off(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();
        $timeOff = $consultant->timeOffs()->create(['date' => '2026-09-25']);

        $this->deleteJson("/api/v1/admin/consultants/{$consultant->id}/time-offs/{$timeOff->id}")->assertOk();

        $this->assertDatabaseMissing('consultant_time_offs', ['id' => $timeOff->id]);
    }

    public function test_con_12_a_time_off_of_another_consultant_is_a_404(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();
        $other = $this->createConsultant();
        $othersTimeOff = $other->timeOffs()->create(['date' => '2026-09-25']);

        $this->assertApiError(
            $this->deleteJson("/api/v1/admin/consultants/{$consultant->id}/time-offs/{$othersTimeOff->id}"),
            404,
            'NOT_FOUND',
        );
    }
}
