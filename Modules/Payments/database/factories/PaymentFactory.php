<?php

namespace Modules\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Models\Payment;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'client_id' => fn (array $attributes) => Booking::find($attributes['booking_id'])->client_id,
            'payment_method_id' => null,
            'gateway' => 'fake',
            'gateway_payment_id' => 'fake_pay_'.fake()->lexify('????????????'),
            'amount' => 190000,
            'currency' => 'SAR',
            'status' => PaymentRecordStatus::Paid,
            'transaction_url' => null,
            'failure_reason' => null,
            'card_brand' => 'visa',
            'card_last_four' => '4242',
            'gateway_response' => null,
            'paid_at' => now(),
        ];
    }

    public function initiated(): static
    {
        return $this->state(fn () => [
            'status' => PaymentRecordStatus::Initiated,
            'transaction_url' => config('app.url').'/fake-3ds/fake_3ds_'.fake()->lexify('????????'),
            'paid_at' => null,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => PaymentRecordStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentRecordStatus::Failed,
            'failure_reason' => 'Card declined',
            'paid_at' => null,
        ]);
    }
}
