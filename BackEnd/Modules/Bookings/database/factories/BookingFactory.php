<?php

namespace Modules\Bookings\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\MeetingStatus;
use Modules\Bookings\Enums\PaymentStatus;
use Modules\Bookings\Enums\RefundStatus;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Packages\Models\Package;
use Modules\Users\Models\User;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $startsAt = now()->addDays(3)->setTime(10, 0);

        return [
            'client_id' => Client::factory(),
            'consultant_id' => User::factory()->consultant(),
            'package_id' => Package::factory(),
            'client_subscription_id' => null,
            'client_location_id' => null,
            'location_snapshot' => null,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes((int) config('bookings.duration_minutes', 30)),
            'status' => BookingStatus::Pending,
            'report_status' => ReportStatus::None,
            'amount' => 0,
            'currency' => 'SAR',
            'payment_status' => PaymentStatus::NotRequired,
            'refund_status' => RefundStatus::None,
            'expires_at' => null,
            'meeting_provider' => null,
            'meeting_status' => MeetingStatus::None,
            'meeting_url' => null,
            'meeting_event_id' => null,
            'client_notes' => null,
        ];
    }

    public function pendingPayment(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::PendingPayment,
            'payment_status' => PaymentStatus::Unpaid,
            'expires_at' => now()->addMinutes((int) config('bookings.payment_hold_minutes', 15)),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Pending,
            'expires_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Completed,
            'starts_at' => now()->subDays(2)->setTime(10, 0),
            'ends_at' => now()->subDays(2)->setTime(10, 30),
            'completed_at' => now()->subDays(2)->setTime(10, 30),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by_type' => 'user',
            'cancellation_reason' => 'Cancelled',
            'expires_at' => null,
        ]);
    }

    /**
     * Completed and waiting for the consultant's report.
     */
    public function withReportPending(): static
    {
        return $this->completed()->state(fn () => [
            'report_status' => ReportStatus::Pending,
        ]);
    }

    public function past(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subDays(2)->setTime(10, 0),
            'ends_at' => now()->subDays(2)->setTime(10, 30),
        ]);
    }

    public function future(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->addDays(3)->setTime(10, 0),
            'ends_at' => now()->addDays(3)->setTime(10, 30),
        ]);
    }
}
