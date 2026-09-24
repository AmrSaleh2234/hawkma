<?php

namespace Modules\Dashboard\Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Reports\Models\Report;
use Modules\Users\Enums\UserType;
use Modules\Users\Models\User;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    public function test_the_database_seeder_seeds_the_demo_data_outside_production(): void
    {
        $this->seed(DatabaseSeeder::class);

        // The six consultants with availability.
        $consultants = User::query()->where('type', UserType::Consultant)->get();
        $this->assertCount(6, $consultants);
        $this->assertTrue($consultants->every(fn (User $u) => $u->is_active && $u->availabilities()->count() === 5));
        $this->assertDatabaseHas('users', ['email' => 'ahmad.alotaibi@gcmc.sa', 'specialization' => 'الحوكمة المؤسسية']);

        // The demo client with the four locations and a saved card.
        $client = Client::query()->where('email', 'client@gcmc.sa')->sole();
        $this->assertSame('شركة تجريبية', $client->company_name);
        $this->assertSame('0500000001', $client->phone);
        $this->assertSame(4, $client->locations()->count());
        $this->assertSame(1, $client->locations()->where('is_default', true)->count());
        $this->assertSame(1, $client->paymentMethods()->count());
        $this->assertSame('4242', $client->defaultPaymentMethod->last_four);

        // An active subscription, two completed bookings (one with a
        // report and a paid payment, one awaiting its report), and one
        // upcoming pending booking.
        $this->assertSame(1, $client->subscriptions()->active()->count());

        $completed = $client->bookings()->where('status', BookingStatus::Completed)->get();
        $this->assertCount(2, $completed);

        $withReport = $completed->firstWhere('report_status', ReportStatus::Uploaded);
        $this->assertSame('paid', $withReport->payment_status->value);
        $this->assertTrue($withReport->payments()->where('status', 'paid')->exists());

        $awaiting = $completed->firstWhere('report_status', ReportStatus::Pending);
        $this->assertNotNull($awaiting);

        $report = Report::query()->where('booking_id', $withReport->id)->sole();
        $this->assertNotNull($report->getFirstMedia('report_file'));
        $this->assertNotNull($report->client_notified_at);

        $upcoming = $client->bookings()->where('status', BookingStatus::Pending)->sole();
        $this->assertTrue($upcoming->starts_at->isFuture());
    }

    public function test_the_demo_data_is_never_seeded_in_production(): void
    {
        $this->app['env'] = 'production';

        // Direct call: artisan db:seed would ask for confirmation in
        // production; the guard we are testing lives in run().
        (new DatabaseSeeder)->run();

        $this->assertSame(0, User::query()->where('type', UserType::Consultant)->count());
        $this->assertSame(0, Client::count());
        // The base seeders still ran.
        $this->assertDatabaseHas('users', ['email' => 'admin@gcmc.sa']);
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, User::query()->where('type', UserType::Consultant)->count());
        $this->assertSame(1, Client::query()->where('email', 'client@gcmc.sa')->count());
        $this->assertSame(3, Booking::count());
        $this->assertSame(1, Report::count());
    }
}
