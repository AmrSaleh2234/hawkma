<?php

namespace Modules\Dashboard\Tests\Feature\Admin;

use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;
use Modules\Reports\Models\Report;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | DSH-01 GET /api/v1/admin/dashboard/stats
    |----------------------------------------------------------------------
    */

    public function test_dsh_01_returns_the_correct_numbers_for_an_admin(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();
        $this->createConsultant(['is_active' => false]);

        // Exactly two clients: one old, one new this month.
        $clientA = $this->createClient(['created_at' => now()->subMonths(2)]);
        $clientB = $this->createClient();

        // Bookings (all with clientA): 2 pending (one today), 2 completed
        // (one awaiting a report), 1 cancelled, 1 pending_payment hold that
        // is excluded from every number.
        Booking::factory()->pending()->create([
            'consultant_id' => $consultant->id,
            'client_id' => $clientA->id,
            'starts_at' => now()->setTime(14, 0),
            'ends_at' => now()->setTime(14, 30),
        ]);
        Booking::factory()->pending()->future()->create([
            'consultant_id' => $consultant->id,
            'client_id' => $clientA->id,
        ]);
        $awaitingReport = Booking::factory()->withReportPending()->create([
            'consultant_id' => $consultant->id,
            'client_id' => $clientA->id,
        ]);
        $completedWithReport = Booking::factory()->completed()->create([
            'consultant_id' => $consultant->id,
            'client_id' => $clientA->id,
            'report_status' => 'uploaded',
        ]);
        $cancelled = Booking::factory()->cancelled()->past()->create([
            'consultant_id' => $consultant->id,
            'client_id' => $clientA->id,
        ]);
        $hold = Booking::factory()->pendingPayment()->create([
            'consultant_id' => $consultant->id,
            'client_id' => $clientA->id,
        ]);

        Report::factory()->create(['booking_id' => $completedWithReport->id]);

        // Revenue: a paid payment this month, one last month, one failed.
        Payment::factory()->paid()->create([
            'booking_id' => $completedWithReport->id,
            'amount' => 190000,
            'paid_at' => now(),
        ]);
        Payment::factory()->paid()->create([
            'booking_id' => $awaitingReport->id,
            'amount' => 450000,
            'paid_at' => now()->subMonth(),
        ]);
        Payment::factory()->failed()->create([
            'booking_id' => $hold->id,
            'amount' => 980000,
        ]);

        $response = $this->getJson('/api/v1/admin/dashboard/stats');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.bookings.total', 5)
            ->assertJsonPath('data.bookings.pending', 2)
            ->assertJsonPath('data.bookings.completed', 2)
            ->assertJsonPath('data.bookings.cancelled', 1)
            ->assertJsonPath('data.bookings.today', 1)
            ->assertJsonPath('data.reports.total', 1)
            ->assertJsonPath('data.reports.pending', 1)
            ->assertJsonPath('data.clients.total', 2)
            ->assertJsonPath('data.clients.new_this_month', 1)
            ->assertJsonPath('data.consultants.total', 2)
            ->assertJsonPath('data.consultants.active', 1)
            ->assertJsonPath('data.revenue.this_month', 190000)
            ->assertJsonPath('data.revenue.this_month_formatted', '1,900.00 SAR')
            ->assertJsonPath('data.revenue.total', 640000)
            ->assertJsonPath('data.revenue.total_formatted', '6,400.00 SAR');

        // Upcoming bookings: today's 14:00 session first, then the future
        // one — both pending, as resources.
        $upcoming = $response->json('data.upcoming_bookings');
        $this->assertCount(2, $upcoming);
        $this->assertSame('pending', $upcoming[0]['status']);
        $this->assertSame(now()->toDateString(), substr($upcoming[0]['starts_at'], 0, 10));
        $this->assertArrayHasKey('reference', $upcoming[0]);
    }

    public function test_dsh_01_upcoming_bookings_are_capped_at_five(): void
    {
        $this->actingAsAdmin();
        Booking::factory()->count(7)->pending()->future()->create();

        $response = $this->getJson('/api/v1/admin/dashboard/stats');

        $this->assertCount(5, $response->json('data.upcoming_bookings'));
    }

    public function test_dsh_01_a_consultant_gets_only_his_numbers_and_no_revenue(): void
    {
        $consultant = $this->createConsultant();
        $this->actingAsConsultant($consultant);
        $client = $this->createClient();

        Booking::factory()->pending()->future()->create([
            'consultant_id' => $consultant->id,
            'client_id' => $client->id,
        ]);
        $completed = Booking::factory()->completed()->create([
            'consultant_id' => $consultant->id,
            'client_id' => $client->id,
            'report_status' => 'uploaded',
        ]);
        Booking::factory()->pending()->future()->create(); // another consultant's

        Report::factory()->create(['booking_id' => $completed->id]);
        Report::factory()->create(); // another consultant's
        Payment::factory()->paid()->create(['booking_id' => $completed->id, 'amount' => 190000]);

        $response = $this->getJson('/api/v1/admin/dashboard/stats');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.bookings.total', 2)
            ->assertJsonPath('data.bookings.pending', 1)
            ->assertJsonPath('data.bookings.completed', 1)
            ->assertJsonPath('data.reports.total', 1)
            ->assertJsonPath('data.clients.total', 1);

        $this->assertArrayNotHasKey('consultants', $response->json('data'));
        $this->assertArrayNotHasKey('revenue', $response->json('data'));

        // His upcoming list only contains his own booking.
        $this->assertCount(1, $response->json('data.upcoming_bookings'));
    }

    public function test_dsh_01_requires_the_view_dashboard_permission(): void
    {
        $this->actingAsAdmin(); // has all permissions
        $this->getJson('/api/v1/admin/dashboard/stats')->assertOk();

        $this->app['auth']->forgetGuards();
        $staff = $this->createStaffWithPermissions([]);

        $this->actingAsAdmin($staff);
        $this->getJson('/api/v1/admin/dashboard/stats')->assertForbidden();
    }
}
