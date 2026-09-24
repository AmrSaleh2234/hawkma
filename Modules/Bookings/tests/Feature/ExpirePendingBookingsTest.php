<?php

namespace Modules\Bookings\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Modules\Bookings\Models\Booking;
use Tests\TestCase;

class ExpirePendingBookingsTest extends TestCase
{
    public function test_bookings_expire_pending_cancels_stale_payment_windows(): void
    {
        Notification::fake();

        $stale = Booking::factory()->pendingPayment()->create([
            'expires_at' => now()->subMinute(),
        ]);
        $fresh = Booking::factory()->pendingPayment()->create([
            'expires_at' => now()->addMinutes(10),
        ]);
        $pending = Booking::factory()->pending()->future()->create();

        $this->artisan('bookings:expire-pending')->assertSuccessful();

        $stale->refresh();
        $this->assertSame('cancelled', $stale->status->value);
        $this->assertSame('payment_timeout', $stale->cancellation_reason);
        $this->assertSame('system', $stale->cancelled_by_type);

        // A future expiry and a pending booking are untouched.
        $this->assertSame('pending_payment', $fresh->refresh()->status->value);
        $this->assertSame('pending', $pending->refresh()->status->value);

        // Payment timeouts do not notify (§9.11).
        Notification::assertNothingSent();
    }
}
