<?php

namespace Modules\Consultants\Tests\Feature;

use Tests\TestCase;

class PublicConsultantsTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | PUB-03 GET /api/v1/public/consultants
    |----------------------------------------------------------------------
    */

    public function test_pub_03_lists_only_active_consultants_with_availability(): void
    {
        $this->createConsultant(['name' => 'Visible']);
        $this->createConsultant(['name' => 'Inactive', 'is_active' => false]);
        $this->createConsultant(['name' => 'No Schedule'], false);
        $this->createAdmin(['name' => 'Admin User']);

        $response = $this->getJson('/api/v1/public/consultants');

        $this->assertApiSuccess($response);
        $this->assertPaginated($response);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Visible', $response->json('data.0.name'));
    }

    public function test_pub_03_never_exposes_email_or_phone(): void
    {
        $this->createConsultant();

        $response = $this->getJson('/api/v1/public/consultants');

        $this->assertArrayNotHasKey('email', $response->json('data.0'));
        $this->assertArrayNotHasKey('phone', $response->json('data.0'));
        $this->assertSame([0, 1, 2, 3, 4], $response->json('data.0.working_days'));
    }

    public function test_pub_03_search_by_name_and_specialization(): void
    {
        $this->createConsultant(['name' => 'Ahmad', 'specialization' => 'الحوكمة']);
        $this->createConsultant(['name' => 'Sara', 'specialization' => 'الامتثال']);

        $this->assertCount(1, $this->getJson('/api/v1/public/consultants?search=Ahmad')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/public/consultants?search='.urlencode('الامتثال'))->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/public/consultants?specialization='.urlencode('الحوكمة'))->json('data'));
    }

    /*
    |----------------------------------------------------------------------
    | PUB-04 GET /api/v1/public/consultants/{consultant}
    |----------------------------------------------------------------------
    */

    public function test_pub_04_shows_an_active_consultant(): void
    {
        $consultant = $this->createConsultant();

        $this->getJson("/api/v1/public/consultants/{$consultant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $consultant->id);
    }

    public function test_pub_04_inactive_consultant_is_a_404(): void
    {
        $consultant = $this->createConsultant(['is_active' => false]);

        $this->assertApiError(
            $this->getJson("/api/v1/public/consultants/{$consultant->id}"),
            404,
            'NOT_FOUND',
        );
    }

    public function test_pub_04_an_admin_user_id_is_a_404(): void
    {
        $admin = $this->createAdmin();

        $this->assertApiError(
            $this->getJson("/api/v1/public/consultants/{$admin->id}"),
            404,
            'NOT_FOUND',
        );
    }

    /*
    |----------------------------------------------------------------------
    | PUB-05 available-dates
    |----------------------------------------------------------------------
    */

    public function test_pub_05_returns_only_dates_with_slots(): void
    {
        $consultant = $this->createConsultant([], false);
        $consultant->availabilities()->create(['day_of_week' => 0, 'start_time' => '10:00', 'end_time' => '12:00']);

        $response = $this->getJson("/api/v1/public/consultants/{$consultant->id}/available-dates?month=2026-09");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.month', '2026-09')
            ->assertJsonPath('data.dates', ['2026-09-20', '2026-09-27']);
    }

    public function test_pub_05_excludes_past_months(): void
    {
        $consultant = $this->createConsultant();

        $response = $this->getJson("/api/v1/public/consultants/{$consultant->id}/available-dates?month=2026-08");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.dates', []);
    }

    public function test_pub_05_a_bad_month_is_a_422(): void
    {
        $consultant = $this->createConsultant();

        $this->assertApiError(
            $this->getJson("/api/v1/public/consultants/{$consultant->id}/available-dates?month=09-2026"),
            422,
            'VALIDATION_ERROR',
        );

        $this->assertApiError(
            $this->getJson("/api/v1/public/consultants/{$consultant->id}/available-dates"),
            422,
            'VALIDATION_ERROR',
        );
    }

    /*
    |----------------------------------------------------------------------
    | PUB-06 slots
    |----------------------------------------------------------------------
    */

    public function test_pub_06_the_10_to_12_example_returns_4_slots(): void
    {
        $consultant = $this->createConsultant([], false);
        $consultant->availabilities()->create(['day_of_week' => 0, 'start_time' => '10:00', 'end_time' => '12:00']);

        $response = $this->getJson("/api/v1/public/consultants/{$consultant->id}/slots?date=2026-09-27");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.date', '2026-09-27')
            ->assertJsonPath('data.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.slot_minutes', 30)
            ->assertJsonPath('data.duration_minutes', 30);

        $this->assertSame(['10:00', '10:30', '11:00', '11:30'], array_column($response->json('data.slots'), 'time'));
        $this->assertSame('2026-09-27T10:00:00+03:00', $response->json('data.slots.0.starts_at'));
        $this->assertSame('2026-09-27T10:30:00+03:00', $response->json('data.slots.0.ends_at'));
    }

    public function test_pub_06_without_a_date_is_a_422(): void
    {
        $consultant = $this->createConsultant();

        $this->assertApiError(
            $this->getJson("/api/v1/public/consultants/{$consultant->id}/slots"),
            422,
            'VALIDATION_ERROR',
        );
    }

    public function test_pub_06_an_unknown_consultant_is_a_404(): void
    {
        $this->assertApiError(
            $this->getJson('/api/v1/public/consultants/9999/slots?date=2026-09-27'),
            404,
            'NOT_FOUND',
        );
    }

    public function test_pub_06_an_inactive_consultant_has_no_slots(): void
    {
        $consultant = $this->createConsultant(['is_active' => false]);

        $this->assertApiError(
            $this->getJson("/api/v1/public/consultants/{$consultant->id}/slots?date=2026-09-27"),
            404,
            'NOT_FOUND',
        );
    }
}
