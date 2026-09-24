<?php

namespace Modules\Bookings\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\PaymentStatus;
use Modules\Bookings\Jobs\CreateMeetingJob;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Services\BookingStateMachine;
use Modules\Clients\Models\Client;
use Modules\Consultants\Services\SlotService;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Packages\Models\Package;
use Modules\Packages\Services\SubscriptionService;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentMethod;
use Modules\Payments\Services\PaymentService;
use Modules\Users\Models\User;

/**
 * The booking creation flow (plan §9.5). The consultant row is locked for
 * the whole transaction so two concurrent requests can never take the same
 * slot.
 */
class CreateBookingAction
{
    public function __construct(
        protected SlotService $slots,
        protected SubscriptionService $subscriptions,
        protected PaymentService $payments,
        protected BookingStateMachine $stateMachine,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated StoreBookingRequest data.
     * @return array{booking: Booking, payment: ?Payment}
     */
    public function execute(Client $client, array $data): array
    {
        $package = Package::query()->findOrFail($data['package_id']);
        if (! $package->is_active) {
            throw new BusinessException(ErrorCode::PackageInactive);
        }

        $consultant = User::query()->findOrFail($data['consultant_id']);
        if (! $consultant->isConsultant() || ! $consultant->is_active) {
            throw new BusinessException(ErrorCode::ConsultantInactive);
        }

        $location = $client->locations()->find($data['client_location_id']);
        if ($location === null) {
            throw new BusinessException(ErrorCode::LocationNotOwned);
        }

        $method = null;
        if (! empty($data['payment_method_id'])) {
            $method = PaymentMethod::query()->find($data['payment_method_id']);
            if ($method === null || $method->client_id !== $client->id) {
                throw new BusinessException(ErrorCode::PaymentMethodNotOwned);
            }
        }

        $cardToken = $data['card_token'] ?? null;
        $saveCard = (bool) ($data['save_card'] ?? false);

        [$booking, $quote] = DB::transaction(function () use ($client, $consultant, $package, $location, $method, $cardToken, $data): array {
            // (a) Locking the consultant row serializes concurrent bookings.
            $lockedConsultant = User::query()->whereKey($consultant->id)->lockForUpdate()->firstOrFail();

            // (b)
            $startsAt = CarbonImmutable::createFromFormat(
                'Y-m-d H:i',
                $data['date'].' '.$data['time'],
                'Asia/Riyadh',
            );

            // (c)
            if (! $this->slots->isSlotAvailable($lockedConsultant, $startsAt)) {
                throw new BusinessException(ErrorCode::SlotNotAvailable, status: 409);
            }

            // (d)
            $quote = $this->subscriptions->quote($client, $package);

            // (e)
            if ($quote['requires_payment'] && $method === null && $cardToken === null) {
                throw new BusinessException(ErrorCode::PaymentMethodRequired);
            }

            // (f)
            $booking = Booking::query()->create([
                'client_id' => $client->id,
                'consultant_id' => $consultant->id,
                'package_id' => $package->id,
                'client_subscription_id' => $quote['subscription']?->id,
                'client_location_id' => $location->id,
                'location_snapshot' => [
                    'name' => $location->name,
                    'city' => $location->city,
                    'address' => $location->address,
                ],
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes((int) config('bookings.duration_minutes', 30)),
                'status' => $quote['requires_payment'] ? BookingStatus::PendingPayment : BookingStatus::Pending,
                'payment_status' => $quote['requires_payment'] ? PaymentStatus::Unpaid : PaymentStatus::NotRequired,
                'amount' => $quote['amount'],
                'currency' => $package->currency,
                'expires_at' => $quote['requires_payment']
                    ? now()->addMinutes((int) config('bookings.payment_hold_minutes', 15))
                    : null,
                'client_notes' => $data['client_notes'] ?? null,
            ]);

            // (g) A subscription-covered booking consumes one consultation now.
            if (! $quote['requires_payment']) {
                $this->subscriptions->consume($quote['subscription']);
            }

            return [$booking, $quote];
        });

        // After commit (§9.5 step 2).
        if (! $quote['requires_payment']) {
            CreateMeetingJob::dispatch($booking);

            return ['booking' => $booking, 'payment' => null];
        }

        $payment = $this->payments->chargeBooking($booking, $method, $cardToken, $saveCard);

        if ($payment->isPaid()) {
            $booking = $this->stateMachine->markPaid($booking, $payment);
        } elseif ($payment->status === PaymentRecordStatus::Failed) {
            $this->stateMachine->markPaymentFailed(
                $booking,
                $payment,
                $payment->failure_reason ?? 'Payment failed',
            );

            throw new BusinessException(ErrorCode::PaymentFailed, status: 402);
        }

        return ['booking' => $booking->refresh(), 'payment' => $payment];
    }
}
