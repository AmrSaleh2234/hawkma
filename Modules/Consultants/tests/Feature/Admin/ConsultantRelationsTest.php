<?php

namespace Modules\Consultants\Tests\Feature\Admin;

use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Tests\TestCase;

class ConsultantRelationsTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CON-14 GET /api/v1/admin/consultants/{consultant}/clients
    |----------------------------------------------------------------------
    */

    public function test_con_14_returns_the_consultants_clients_with_counts(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $clientA = $this->createClient(['company_name' => 'Alpha Co']);
        $clientB = $this->createClient(['company_name' => 'Beta Co']);
        $clientC = $this->createClient(['company_name' => 'Gamma Co']);

        // Two real bookings with A, one with B.
        Booking::factory()->completed()->create(['consultant_id' => $consultant->id, 'client_id' => $clientA->id]);
        Booking::factory()->pending()->future()->create(['consultant_id' => $consultant->id, 'client_id' => $clientA->id]);
        Booking::factory()->cancelled()->create(['consultant_id' => $consultant->id, 'client_id' => $clientB->id]);
        // A pending_payment booking does not count.
        Booking::factory()->pendingPayment()->create(['consultant_id' => $consultant->id, 'client_id' => $clientC->id]);
        // A booking with another consultant does not count.
        Booking::factory()->completed()->create(['client_id' => $clientC->id]);

        $response = $this->getJson("/api/v1/admin/consultants/{$consultant->id}/clients");

        $this->assertPaginated($response);
        $this->assertSame(2, $response->json('meta.total'));

        $a = collect($response->json('data'))->firstWhere('id', $clientA->id);
        $this->assertSame(2, $a['bookings_count']);
        $this->assertNotNull($a['last_booking_at']);

        $b = collect($response->json('data'))->firstWhere('id', $clientB->id);
        $this->assertSame(1, $b['bookings_count']);
    }

    public function test_con_14_searches_by_company(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $clientA = $this->createClient(['company_name' => 'Alpha Co']);
        $clientB = $this->createClient(['company_name' => 'Beta Co']);
        Booking::factory()->create(['consultant_id' => $consultant->id, 'client_id' => $clientA->id]);
        Booking::factory()->create(['consultant_id' => $consultant->id, 'client_id' => $clientB->id]);

        $response = $this->getJson("/api/v1/admin/consultants/{$consultant->id}/clients?search=Alpha");

        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_con_14_a_consultant_cannot_see_another_consultants_clients(): void
    {
        $consultant = $this->createConsultant();
        $other = $this->createConsultant();
        $this->actingAsConsultant($consultant);

        $this->getJson("/api/v1/admin/consultants/{$other->id}/clients")->assertForbidden();
        $this->getJson("/api/v1/admin/consultants/{$consultant->id}/clients")->assertOk();
    }

    /*
    |----------------------------------------------------------------------
    | CON-15 GET /api/v1/admin/consultants/{consultant}/bookings
    |----------------------------------------------------------------------
    */

    public function test_con_15_lists_the_consultants_bookings_with_filters(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        Booking::factory()->pending()->future()->create(['consultant_id' => $consultant->id]);
        Booking::factory()->completed()->create(['consultant_id' => $consultant->id]);
        Booking::factory()->create(); // another consultant's

        $response = $this->getJson("/api/v1/admin/consultants/{$consultant->id}/bookings");
        $this->assertSame(2, $response->json('meta.total'));

        // The "Pending bookings" tab.
        $pending = $this->getJson("/api/v1/admin/consultants/{$consultant->id}/bookings?status=pending");
        $this->assertSame(1, $pending->json('meta.total'));
        $this->assertSame('pending', $pending->json('data.0.status'));

        // consultant_id cannot widen the fixed scope.
        $other = $this->createConsultant();
        $widened = $this->getJson("/api/v1/admin/consultants/{$consultant->id}/bookings?consultant_id={$other->id}");
        $this->assertSame(2, $widened->json('meta.total'));
    }

    /*
    |----------------------------------------------------------------------
    | CON-18 GET /api/v1/admin/consultants/{consultant}/stats
    |----------------------------------------------------------------------
    */

    public function test_con_18_returns_the_consultant_stats(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();
        $client = $this->createClient();

        Booking::factory()->pending()->future()->create(['consultant_id' => $consultant->id, 'client_id' => $client->id]);
        Booking::factory()->withReportPending()->create(['consultant_id' => $consultant->id, 'client_id' => $client->id]);
        Booking::factory()->completed()->create([
            'consultant_id' => $consultant->id,
            'client_id' => $client->id,
            'report_status' => 'uploaded',
        ]);
        Booking::factory()->cancelled()->create(['consultant_id' => $consultant->id, 'client_id' => $client->id]);
        Booking::factory()->pending()->future()->create(); // another consultant's

        $response = $this->getJson("/api/v1/admin/consultants/{$consultant->id}/stats");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.pending_bookings', 1)
            ->assertJsonPath('data.completed_bookings', 2)
            ->assertJsonPath('data.cancelled_bookings', 1)
            ->assertJsonPath('data.pending_reports', 1)
            ->assertJsonPath('data.reports', 0)
            ->assertJsonPath('data.clients', 1);

        $this->assertCount(1, $response->json('data.upcoming_bookings'));
        $this->assertSame('pending', $response->json('data.upcoming_bookings.0.status'));
    }

    /*
    |----------------------------------------------------------------------
    | ADM-CL-06 GET /api/v1/admin/clients/{client}/bookings
    |----------------------------------------------------------------------
    */

    public function test_adm_cl_06_an_admin_sees_the_clients_bookings(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        Booking::factory()->count(2)->create(['client_id' => $client->id]);
        Booking::factory()->create(); // another client's

        $response = $this->getJson("/api/v1/admin/clients/{$client->id}/bookings");

        $this->assertPaginated($response);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_adm_cl_06_a_consultant_sees_only_his_bookings_with_this_client(): void
    {
        $consultant = $this->createConsultant();
        $this->actingAsConsultant($consultant);
        $client = $this->createClient();

        Booking::factory()->create(['client_id' => $client->id, 'consultant_id' => $consultant->id]);
        Booking::factory()->create(['client_id' => $client->id]); // another consultant

        $response = $this->getJson("/api/v1/admin/clients/{$client->id}/bookings");

        $this->assertSame(1, $response->json('meta.total'));
    }
}
