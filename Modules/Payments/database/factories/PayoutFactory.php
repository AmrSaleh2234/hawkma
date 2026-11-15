<?php

namespace Modules\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Enums\PayoutStatus;
use Modules\Payments\Models\Payout;
use Modules\Users\Models\User;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    public function definition(): array
    {
        $amount = 50000;
        $feeAmount = (int) round($amount * 0.15);

        return [
            'booking_id' => Booking::factory(),
            'consultant_id' => User::factory(),
            'amount' => $amount,
            'fee_percent' => 15,
            'fee_amount' => $feeAmount,
            'net_amount' => $amount - $feeAmount,
            'status' => PayoutStatus::Pending,
        ];
    }

    public function released(): static
    {
        return $this->state(fn () => [
            'status' => PayoutStatus::Released,
            'released_at' => now(),
            'driver' => 'manual',
        ]);
    }
}
