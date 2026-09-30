<?php

namespace Tests\Feature;

use Modules\AccessControl\Models\Role;
use Modules\Bookings\Models\Booking;
use Modules\Consultants\Models\ConsultantTimeOff;
use Modules\Packages\Models\Package;
use Modules\Payments\Models\Payment;
use Modules\Reports\Models\Report;
use Tests\TestCase;

/**
 * Backend pagination contract: every list endpoint must return the
 * { success, message, data, meta, links } envelope and honour
 * page/per_page/search/sort. The frontend <Pagination> reads meta only.
 */
class PaginationContractTest extends TestCase
{
    public function test_admin_list_endpoints_return_the_paginated_envelope(): void
    {
        $this->actingAsAdmin();

        $consultant = $this->createConsultant();
        $client = $this->createClient();
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'consultant_id' => $consultant->id,
        ]);
        Report::factory()->create(['booking_id' => $booking->id]);
        Payment::factory()->paid()->create(['booking_id' => $booking->id]);
        Package::factory()->iron()->create();
        ConsultantTimeOff::factory()->create(['consultant_id' => $consultant->id]);
        $role = Role::firstOrFail();

        $endpoints = [
            '/api/v1/admin/users',
            '/api/v1/admin/roles',
            "/api/v1/admin/roles/{$role->id}/users",
            '/api/v1/admin/consultants',
            "/api/v1/admin/consultants/{$consultant->id}/clients",
            "/api/v1/admin/consultants/{$consultant->id}/bookings",
            "/api/v1/admin/consultants/{$consultant->id}/time-offs",
            '/api/v1/admin/clients',
            '/api/v1/admin/bookings',
            '/api/v1/admin/reports',
            '/api/v1/admin/payments',
            '/api/v1/admin/packages',
        ];

        foreach ($endpoints as $url) {
            $response = $this->getJson($url);
            $response->assertOk();
            $this->assertPaginated($response);
            $response->assertJsonStructure([
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
                'links' => ['first', 'last', 'prev', 'next'],
            ]);
        }
    }

    public function test_page_and_per_page_drive_the_meta_block(): void
    {
        $this->actingAsAdmin();
        Booking::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/admin/bookings?per_page=2&page=2');

        $response->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
        $this->assertCount(1, $response->json('data'));
        $this->assertNull($response->json('links.next'));
    }

    public function test_per_page_is_bounded_to_one_hundred(): void
    {
        $this->actingAsAdmin();

        // Validated endpoints reject out-of-range values.
        $this->getJson('/api/v1/admin/bookings?per_page=500')->assertUnprocessable();

        // Unvalidated endpoints clamp to the max instead of erroring.
        $response = $this->getJson('/api/v1/admin/users?per_page=500');
        $response->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_client_list_endpoints_return_the_paginated_envelope(): void
    {
        $client = $this->actingAsClient();
        $booking = Booking::factory()->create(['client_id' => $client->id]);
        Report::factory()->create(['booking_id' => $booking->id, 'client_id' => $client->id]);

        foreach (['/api/v1/client/bookings', '/api/v1/client/reports'] as $url) {
            $response = $this->getJson($url);
            $response->assertOk();
            $this->assertPaginated($response);
        }
    }

    public function test_public_consultants_is_paginated(): void
    {
        $this->createConsultant(); // active + default availability

        $response = $this->getJson('/api/v1/public/consultants');

        $response->assertOk();
        $this->assertPaginated($response);
        $this->assertSame(1, $response->json('meta.total'));
    }
}
