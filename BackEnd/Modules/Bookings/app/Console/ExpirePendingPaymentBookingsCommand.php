<?php

namespace Modules\Bookings\Console;

use Illuminate\Console\Command;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Services\BookingStateMachine;

/**
 * Cancel pending_payment bookings whose payment window has expired (plan
 * §9.1). Runs every minute via the module schedule.
 */
class ExpirePendingPaymentBookingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'bookings:expire-pending';

    /**
     * The console command description.
     */
    protected $description = 'Cancel pending_payment bookings whose expires_at has passed (reason: payment_timeout)';

    public function handle(BookingStateMachine $stateMachine): int
    {
        $count = 0;

        Booking::query()
            ->where('status', BookingStatus::PendingPayment)
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($bookings) use ($stateMachine, &$count): void {
                foreach ($bookings as $booking) {
                    $stateMachine->expire($booking);
                    $count++;
                }
            });

        $this->info("Expired {$count} booking(s).");

        return self::SUCCESS;
    }
}
