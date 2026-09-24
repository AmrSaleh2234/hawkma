<?php

namespace Modules\Payments\Services;

use Illuminate\Support\Facades\Log;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\RefundStatus;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Services\BookingStateMachine;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\DTO\ChargeRequest;
use Modules\Payments\DTO\ChargeResult;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentMethod;

/**
 * The payment flow (plan §9.6): charge a booking, verify after the 3-D
 * Secure redirect, and handle the Moyasar webhook.
 */
class PaymentService
{
    public function __construct(
        protected PaymentGateway $gateway,
        protected PaymentMethodService $paymentMethods,
        protected BookingStateMachine $stateMachine,
    ) {}

    /**
     * Charge a booking with a saved card or a one-time token (§9.6).
     *
     * 1. A given method must belong to the booking's client.
     * 2. save_card stores the token first, then charges with that method.
     * 3. A payments row is created with status initiated.
     * 4. The gateway is called; the result is stored on the payment.
     * 5. paid → paid_at = now.
     */
    public function chargeBooking(
        Booking $booking,
        ?PaymentMethod $method = null,
        ?string $cardToken = null,
        bool $saveCard = false,
    ): Payment {
        if ($method !== null && $method->client_id !== $booking->client_id) {
            throw new BusinessException(ErrorCode::PaymentMethodNotOwned);
        }

        if ($cardToken !== null && $saveCard) {
            $method = $this->paymentMethods->storeFromToken($booking->client, $cardToken);
        }

        $token = $method?->gateway_token ?? $cardToken;

        $payment = $booking->payments()->create([
            'client_id' => $booking->client_id,
            'payment_method_id' => $method?->id,
            'gateway' => $this->gateway->name(),
            'amount' => $booking->amount,
            'currency' => $booking->currency,
            'status' => PaymentRecordStatus::Initiated,
        ]);

        $result = $this->gateway->charge(new ChargeRequest(
            amount: $booking->amount,
            currency: $booking->currency,
            token: (string) $token,
            description: "Booking {$booking->reference}",
            callbackUrl: (string) config('payments.callback_url'),
            metadata: [
                'booking_id' => $booking->id,
                'reference' => $booking->reference,
            ],
        ));

        $this->storeResult($payment, $result);

        return $payment->refresh();
    }

    /**
     * Verify a payment after the 3-D Secure redirect (§9.6). Idempotent: an
     * already paid/failed payment is returned as it is.
     */
    public function verify(Payment $payment): Payment
    {
        if (in_array($payment->status, [PaymentRecordStatus::Paid, PaymentRecordStatus::Failed], true)) {
            return $payment;
        }

        $result = $this->gateway->fetch((string) $payment->gateway_payment_id);
        $this->storeResult($payment, $result);

        return $this->applyResult($payment->refresh());
    }

    /**
     * The Moyasar webhook (§9.6): find the payment by the gateway id and run
     * the same update logic as verify. Unknown ids are ignored.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): ?Payment
    {
        $gatewayPaymentId = $payload['data']['id'] ?? $payload['id'] ?? null;

        if (! is_string($gatewayPaymentId) || $gatewayPaymentId === '') {
            return null;
        }

        $payment = Payment::query()
            ->where('gateway_payment_id', $gatewayPaymentId)
            ->first();

        if ($payment === null) {
            return null;
        }

        return $this->verify($payment);
    }

    /**
     * Apply a gateway result to the booking.
     *
     * Race rule (§9.6): a payment that becomes paid after the booking was
     * cancelled/expired sets refund_status = requested and logs a warning —
     * the booking is NOT revived (the slot may be taken).
     */
    public function applyResult(Payment $payment): Payment
    {
        $booking = $payment->booking;

        if ($payment->isPaid()) {
            if ($booking->status === BookingStatus::PendingPayment) {
                $this->stateMachine->markPaid($booking, $payment);
            } elseif ($booking->status === BookingStatus::Cancelled) {
                if ($booking->refund_status !== RefundStatus::Requested) {
                    $booking->forceFill(['refund_status' => RefundStatus::Requested])->save();
                }

                Log::warning("Payment #{$payment->id} was paid after booking #{$booking->id} was cancelled; refund requested.");
            }
        } elseif ($payment->status === PaymentRecordStatus::Failed
            && $booking->status === BookingStatus::PendingPayment) {
            $this->stateMachine->markPaymentFailed(
                $booking,
                $payment,
                $payment->failure_reason ?? 'Payment failed',
            );
        }

        return $payment;
    }

    /**
     * Store a gateway result on the payment row (§9.6 step 4-5). A later
     * non-paid result (e.g. refunded) keeps the original paid_at.
     */
    protected function storeResult(Payment $payment, ChargeResult $result): void
    {
        $payment->forceFill([
            'status' => PaymentRecordStatus::from($result->status),
            'gateway_payment_id' => $result->gatewayPaymentId ?? $payment->gateway_payment_id,
            'transaction_url' => $result->transactionUrl,
            'failure_reason' => $result->failureReason,
            'card_brand' => $result->cardBrand ?? $payment->card_brand,
            'card_last_four' => $result->cardLastFour ?? $payment->card_last_four,
            'gateway_response' => $result->raw,
            'paid_at' => $result->isPaid() ? ($payment->paid_at ?? now()) : $payment->paid_at,
        ])->save();
    }
}
