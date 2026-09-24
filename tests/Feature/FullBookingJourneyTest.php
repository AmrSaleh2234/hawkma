<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Notifications\BookingConfirmedNotification;
use Modules\Clients\Models\Client;
use Modules\Packages\Database\Seeders\PackagesSeeder;
use Modules\Reports\Notifications\ReportReadyNotification;
use Modules\Users\Models\User;
use Tests\TestCase;

/**
 * §12.4 — one long test that walks through the whole business, only
 * through HTTP calls.
 */
class FullBookingJourneyTest extends TestCase
{
    /**
     * The auth manager caches the resolved guard user across requests
     * inside one test; forget the guards so the bearer token actually
     * decides the identity of the next request.
     */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_the_full_booking_journey(): void
    {
        Notification::fake();
        $this->seed(PackagesSeeder::class);

        /*
         * 1. The admin logs in (real login, not actingAs).
         */
        $this->createAdmin(['email' => 'journey-admin@gcmc.sa']);

        $adminToken = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'journey-admin@gcmc.sa',
            'password' => 'Password@123',
        ])->assertOk()->json('data.token');

        $this->assertNotEmpty($adminToken);

        /*
         * 2. A supervisor role with view-bookings + view-reports, assigned
         *    to a new user.
         */
        $this->as($adminToken)->postJson('/api/v1/admin/roles', [
            'name' => 'supervisor',
            'permissions' => ['view-bookings', 'view-reports'],
        ])->assertCreated();

        $this->as($adminToken)->postJson('/api/v1/admin/users', [
            'name' => 'Supervisor User',
            'email' => 'supervisor@gcmc.sa',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'roles' => ['supervisor'],
        ])->assertCreated();

        /*
         * 3. The admin creates a consultant (photo + name + email).
         */
        $consultantId = $this->as($adminToken)->post('/api/v1/admin/consultants', [
            'name' => 'Journey Consultant',
            'email' => 'journey-consultant@gcmc.sa',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'photo' => UploadedFile::fake()->image('photo.jpg', 200, 200),
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $consultantId,
            'model_type' => (new User)->getMorphClass(),
        ]);

        // A second consultant for the 403 check in step 13.
        $otherConsultantId = $this->as($adminToken)->postJson('/api/v1/admin/consultants', [
            'name' => 'Other Consultant',
            'email' => 'other-consultant@gcmc.sa',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
        ])->assertCreated()->json('data.id');

        /*
         * 4. The admin sets the consultant's availability (Sunday 10:00–12:00).
         */
        $this->as($adminToken)->putJson("/api/v1/admin/consultants/{$consultantId}/availability", [
            'days' => [
                ['day_of_week' => 0, 'ranges' => [['start_time' => '10:00', 'end_time' => '12:00']]],
            ],
        ])->assertOk();

        /*
         * 5. Public: list the packages → choose iron.
         */
        $packages = $this->getJson('/api/v1/public/packages')->assertOk()->json('data');
        $iron = collect($packages)->firstWhere('slug', 'iron');
        $this->assertNotNull($iron);

        /*
         * 6. The client registers → gets a token.
         */
        $clientToken = $this->postJson('/api/v1/client/auth/register', [
            'name' => 'Journey Client',
            'email' => 'journey-client@example.com',
            'phone' => '0500000099',
            'company_name' => 'Journey Co',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
        ])->assertCreated()->json('data.token');

        $client = Client::query()->where('email', 'journey-client@example.com')->sole();

        /*
         * 7. Public: the new consultant is listed; slots for today (Sunday)
         *    are 10:00, 10:30, 11:00, 11:30.
         */
        $listed = $this->getJson('/api/v1/public/consultants')->assertOk()->json('data');
        $this->assertContains($consultantId, array_column($listed, 'id'));

        $date = now()->toDateString(); // 2026-09-20, a Sunday
        $slots = $this->getJson("/api/v1/public/consultants/{$consultantId}/slots?date={$date}")
            ->assertOk()->json('data.slots');
        $this->assertSame(['10:00', '10:30', '11:00', '11:30'], array_column($slots, 'time'));

        /*
         * 8. The client adds a location and a card.
         */
        $locationId = $this->as($clientToken)->postJson('/api/v1/client/locations', [
            'name' => 'HQ',
            'city' => 'Riyadh',
            'address' => 'King Fahd Rd',
            'is_default' => true,
        ])->assertCreated()->json('data.id');

        $paymentMethodId = $this->as($clientToken)->postJson('/api/v1/client/payment-methods', [
            'token' => 'tok_fake_success',
            'is_default' => true,
        ])->assertCreated()->json('data.id');

        /*
         * 9. The client quotes → requires_payment, amount 190000.
         */
        $quote = $this->as($clientToken)->postJson('/api/v1/client/bookings/quote', [
            'package_id' => $iron['id'],
            'consultant_id' => $consultantId,
            'date' => $date,
            'time' => '10:30',
        ])->assertOk()->json('data');

        $this->assertTrue($quote['requires_payment']);
        $this->assertSame(190000, $quote['amount']);
        $this->assertTrue($quote['slot_available']);

        /*
         * 10. The client books 10:30 with the saved card → pending, paid, a
         *     Meet URL is set (queue sync), the confirmation email contains
         *     the Meet URL.
         */
        $created = $this->as($clientToken)->postJson('/api/v1/client/bookings', [
            'package_id' => $iron['id'],
            'consultant_id' => $consultantId,
            'date' => $date,
            'time' => '10:30',
            'client_location_id' => $locationId,
            'payment_method_id' => $paymentMethodId,
        ])->assertCreated()->json('data');

        $this->assertSame('pending', $created['booking']['status']);
        $this->assertSame('paid', $created['booking']['payment_status']);
        $this->assertSame('paid', $created['payment']['status']);

        $meetUrl = $created['booking']['meeting']['url'] ?? null;
        $this->assertNotNull($meetUrl);

        Notification::assertSentTo(
            $client,
            BookingConfirmedNotification::class,
            fn ($notification) => $notification->toMail($client)->actionUrl === $meetUrl,
        );

        $bookingId = $created['booking']['id'];

        /*
         * 11. Public slots for that day → 10:00, 11:00, 11:30 (10:30 gone).
         */
        $slots = $this->getJson("/api/v1/public/consultants/{$consultantId}/slots?date={$date}")
            ->assertOk()->json('data.slots');
        $this->assertSame(['10:00', '11:00', '11:30'], array_column($slots, 'time'));

        /*
         * 12. The client quotes again → free (1 of 2 consultations used).
         */
        $quote = $this->as($clientToken)->postJson('/api/v1/client/bookings/quote', [
            'package_id' => $iron['id'],
            'consultant_id' => $consultantId,
            'date' => $date,
            'time' => '11:00',
        ])->assertOk()->json('data');

        $this->assertFalse($quote['requires_payment']);
        $this->assertSame(0, $quote['amount']);

        /*
         * 13. The consultant logs in → his bookings list shows 1; another
         *     consultant's profile → 403.
         */
        $consultantToken = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'journey-consultant@gcmc.sa',
            'password' => 'Password@123',
        ])->assertOk()->json('data.token');

        $this->assertSame(1, $this->as($consultantToken)
            ->getJson('/api/v1/admin/bookings')->assertOk()->json('meta.total'));

        $this->as($consultantToken)
            ->getJson("/api/v1/admin/consultants/{$otherConsultantId}")->assertForbidden();

        /*
         * 14. After the session, the consultant completes it → the booking
         *     appears in his pending reports.
         */
        $this->travel(3)->hours(); // 09:00 → 12:00, the 10:30–11:00 session is over

        $this->as($consultantToken)
            ->postJson("/api/v1/admin/bookings/{$bookingId}/complete")->assertOk();

        $pendingReports = $this->as($consultantToken)
            ->getJson("/api/v1/admin/consultants/{$consultantId}/pending-reports")->assertOk()->json('data');
        $this->assertSame([$bookingId], array_column($pendingReports, 'id'));

        /*
         * 15. The consultant uploads a PDF → it appears in /admin/reports
         *     with the consultant and client names; the client gets the
         *     ReportReadyNotification with the signed link.
         */
        $reportId = $this->as($consultantToken)->post("/api/v1/admin/bookings/{$bookingId}/report", [
            'title' => 'Journey report',
            'summary' => 'End-to-end',
            'file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.4 journey report'),
        ])->assertCreated()->json('data.id');

        $adminReport = collect($this->as($adminToken)
            ->getJson('/api/v1/admin/reports')->assertOk()->json('data'))
            ->firstWhere('id', $reportId);

        $this->assertSame('Journey Consultant', $adminReport['consultant']['name']);
        $this->assertSame('Journey Client', $adminReport['client']['name']);

        Notification::assertSentTo(
            $client,
            ReportReadyNotification::class,
            function ($notification) use ($client) {
                $mail = $notification->toMail($client);
                $body = implode(' ', array_merge($mail->introLines, $mail->outroLines));

                return str_contains($body, 'signed-download');
            },
        );

        /*
         * 16. The client lists the reports → 1; downloads it → attachment;
         *     the signed link works without a token.
         */
        $this->assertSame(1, $this->as($clientToken)
            ->getJson('/api/v1/client/reports')->assertOk()->json('meta.total'));

        $download = $this->as($clientToken)
            ->get("/api/v1/client/reports/{$reportId}/download");
        $download->assertOk();
        $download->assertHeader('content-disposition', 'attachment; filename=report.pdf');

        $signedUrl = URL::temporarySignedRoute('public.reports.signed-download', now()->addDays(7), ['report' => $reportId]);
        $this->get($signedUrl)->assertOk();

        /*
         * 17. The supervisor logs in → can list bookings, but cannot create
         *     users.
         */
        $supervisorToken = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'supervisor@gcmc.sa',
            'password' => 'Password@123',
        ])->assertOk()->json('data.token');

        $this->as($supervisorToken)->getJson('/api/v1/admin/bookings')->assertOk();

        $this->as($supervisorToken)->postJson('/api/v1/admin/users', [
            'name' => 'Nope',
            'email' => 'nope@gcmc.sa',
            'roles' => ['supervisor'],
        ])->assertForbidden();

        $this->assertSame(1, Booking::count());
    }
}
