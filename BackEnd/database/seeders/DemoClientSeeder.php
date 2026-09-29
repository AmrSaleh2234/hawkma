<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\MeetingStatus;
use Modules\Bookings\Enums\PaymentStatus;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\PaymentMethodService;
use Modules\Reports\Models\Report;
use Modules\Users\Enums\UserType;
use Modules\Users\Models\User;

class DemoClientSeeder extends Seeder
{
    /**
     * The demo client (plan Phase 11): client@gcmc.sa with the four
     * locations from the screenshot, a saved fake card, an active
     * subscription, one completed booking with a report, and one upcoming
     * pending booking — so every dashboard page has data.
     */
    public function run(): void
    {
        $client = Client::firstOrCreate(
            ['email' => 'client@gcmc.sa'],
            [
                'name' => 'عميل تجريبي',
                'phone' => '0500000001',
                'company_name' => 'شركة تجريبية',
                'password' => 'Password@123',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->seedLocations($client);

        app(PaymentMethodService::class)->storeFromToken($client, 'tok_fake_success', true);

        $consultant = User::query()
            ->where('type', UserType::Consultant)
            ->where('email', 'ahmad.alotaibi@gcmc.sa')
            ->first() ?? User::query()->where('type', UserType::Consultant)->first();

        $package = Package::query()->where('slug', 'gold')->first() ?? Package::query()->first();

        if ($consultant === null || $package === null) {
            return; // DemoConsultantsSeeder / PackagesSeeder must run first.
        }

        $subscription = $this->seedSubscription($client, $package);

        $this->seedCompletedBookingWithReport($client, $consultant, $package, $subscription);
        $this->seedCompletedBookingAwaitingReport($client, $package);
        $this->seedUpcomingBooking($client, $consultant, $package);
    }

    protected function seedLocations(Client $client): void
    {
        $locations = [
            ['name' => 'الفرع الرئيسي — الرياض', 'city' => 'الرياض', 'address' => 'طريق العروبة، حي العليا، برج المملكة، الدور ١٨', 'is_default' => true],
            ['name' => 'فرع جدة', 'city' => 'جدة', 'address' => 'طريق الملك عبدالله، حي الصحافة، مركز الإجادة', 'is_default' => false],
            ['name' => 'فرع الدمام', 'city' => 'الدمام', 'address' => 'طريق الملك فهد، حي الفيصلية، برج الأعمال', 'is_default' => false],
            ['name' => 'فرع مكة المكرمة', 'city' => 'مكة المكرمة', 'address' => 'حي العزيزية، شارع إبراهيم الخليل', 'is_default' => false],
        ];

        foreach ($locations as $location) {
            $client->locations()->firstOrCreate(
                ['name' => $location['name']],
                $location + ['latitude' => 24.7136, 'longitude' => 46.6753],
            );
        }
    }

    protected function seedSubscription(Client $client, Package $package): ClientSubscription
    {
        $subscription = $client->subscriptions()->firstOrCreate(
            ['package_id' => $package->id, 'status' => 'active'],
            [
                'starts_at' => now()->startOfMonth(),
                'ends_at' => now()->startOfMonth()->addMonth(),
                'consultations_limit' => $package->consultations_limit,
                'consultations_used' => 1,
                'price_paid' => $package->price,
            ],
        );

        return $subscription;
    }

    protected function seedCompletedBookingWithReport(
        Client $client,
        User $consultant,
        Package $package,
        ClientSubscription $subscription,
    ): void {
        $location = $client->defaultLocation;
        $startsAt = now()->subDays(3)->setTime(10, 0);

        $booking = Booking::firstOrCreate(
            ['client_id' => $client->id, 'consultant_id' => $consultant->id, 'starts_at' => $startsAt],
            [
                'package_id' => $package->id,
                'client_subscription_id' => $subscription->id,
                'client_location_id' => $location?->id,
                'location_snapshot' => $location ? [
                    'name' => $location->name,
                    'city' => $location->city,
                    'address' => $location->address,
                ] : null,
                'ends_at' => $startsAt->copy()->addMinutes((int) config('bookings.duration_minutes', 30)),
                'status' => BookingStatus::Completed,
                'report_status' => ReportStatus::Uploaded,
                'amount' => $package->price,
                'payment_status' => PaymentStatus::Paid,
                'meeting_provider' => config('bookings.meeting_driver', 'fake'),
                'meeting_status' => MeetingStatus::Created,
                'meeting_url' => 'https://meet.google.com/fak-dem-o123',
                'completed_at' => $startsAt->copy()->addMinutes(30),
            ],
        );

        Payment::firstOrCreate(
            ['booking_id' => $booking->id, 'gateway_payment_id' => 'fake_pay_demo_completed'],
            [
                'client_id' => $client->id,
                'payment_method_id' => $client->defaultPaymentMethod?->id,
                'gateway' => 'fake',
                'amount' => $booking->amount,
                'currency' => 'SAR',
                'status' => PaymentRecordStatus::Paid,
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'paid_at' => now()->startOfMonth()->addDay(),
            ],
        );

        $report = Report::firstOrCreate(
            ['booking_id' => $booking->id],
            [
                'consultant_id' => $consultant->id,
                'client_id' => $client->id,
                'title' => 'تقرير الحوكمة المؤسسية',
                'summary' => 'تقرير تجريبي يغطي تقييم ممارسات الحوكمة المؤسسية والتوصيات.',
                'uploaded_by' => User::query()->where('type', UserType::Admin)->value('id'),
                'client_notified_at' => now(),
            ],
        );

        if ($report->getFirstMedia('report_file') === null) {
            $report->addMediaFromString("%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF")
                ->usingFileName('governance-report.pdf')
                ->toMediaCollection('report_file');
        }
    }

    /**
     * A second completed booking — with another consultant and still
     * awaiting its report, so the "pending reports" pages have data and the
     * Postman run can upload a fresh report (RPT-03 → 201).
     */
    protected function seedCompletedBookingAwaitingReport(Client $client, Package $package): void
    {
        $consultant = User::query()
            ->where('type', UserType::Consultant)
            ->where('email', 'sara.aldosari@gcmc.sa')
            ->first() ?? User::query()->where('type', UserType::Consultant)->first();

        if ($consultant === null) {
            return;
        }

        $location = $client->defaultLocation;
        $startsAt = now()->subDays(2)->setTime(12, 0);

        Booking::firstOrCreate(
            ['client_id' => $client->id, 'consultant_id' => $consultant->id, 'starts_at' => $startsAt],
            [
                'package_id' => $package->id,
                'client_location_id' => $location?->id,
                'location_snapshot' => $location ? [
                    'name' => $location->name,
                    'city' => $location->city,
                    'address' => $location->address,
                ] : null,
                'ends_at' => $startsAt->copy()->addMinutes((int) config('bookings.duration_minutes', 30)),
                'status' => BookingStatus::Completed,
                'report_status' => ReportStatus::Pending,
                'amount' => 0,
                'payment_status' => PaymentStatus::NotRequired,
                'meeting_provider' => config('bookings.meeting_driver', 'fake'),
                'meeting_status' => MeetingStatus::Created,
                'meeting_url' => 'https://meet.google.com/fak-dem-o456',
                'completed_at' => $startsAt->copy()->addMinutes(30),
            ],
        );
    }

    protected function seedUpcomingBooking(Client $client, User $consultant, Package $package): void
    {
        $location = $client->defaultLocation;
        // Sunday–Thursday availability: the next Sunday always works.
        $startsAt = Carbon::now()->next(Carbon::SUNDAY)->setTime(10, 0);

        Booking::firstOrCreate(
            ['client_id' => $client->id, 'consultant_id' => $consultant->id, 'starts_at' => $startsAt],
            [
                'package_id' => $package->id,
                'client_location_id' => $location?->id,
                'location_snapshot' => $location ? [
                    'name' => $location->name,
                    'city' => $location->city,
                    'address' => $location->address,
                ] : null,
                'ends_at' => $startsAt->copy()->addMinutes((int) config('bookings.duration_minutes', 30)),
                'status' => BookingStatus::Pending,
                'amount' => 0,
                'payment_status' => PaymentStatus::NotRequired,
                'meeting_provider' => config('bookings.meeting_driver', 'fake'),
                'meeting_status' => MeetingStatus::Pending,
                'client_notes' => 'حجز تجريبي قادم',
            ],
        );
    }
}
