<?php

namespace Modules\Payments\Exceptions;

use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;
use RuntimeException;

/**
 * The gateway could not be reached or its answer could not be read (timeout,
 * 5xx, connection error). This is NOT a declined payment: the real state is
 * unknown, so the payment must stay `initiated` and the caller gets a 503
 * PAYMENT_PENDING_CONFIRMATION (which also makes Moyasar retry the webhook).
 * Only an explicit `status: failed` payload from the gateway may fail a
 * payment.
 */
class GatewayException extends RuntimeException
{
    /** The booking whose charge is unconfirmed, so the client can poll it. */
    public ?Booking $booking = null;

    public ?Payment $payment = null;

    public static function fetchFailed(string $gatewayPaymentId, string $reason): self
    {
        return new self("Could not fetch payment {$gatewayPaymentId} from the gateway: {$reason}");
    }

    public static function chargeFailed(string $reason): self
    {
        return new self("The gateway did not answer the charge request: {$reason}");
    }

    public function forBooking(Booking $booking, ?Payment $payment): self
    {
        $this->booking = $booking;
        $this->payment = $payment;

        return $this;
    }
}
