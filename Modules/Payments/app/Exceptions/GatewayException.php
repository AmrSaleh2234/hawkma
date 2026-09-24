<?php

namespace Modules\Payments\Exceptions;

use RuntimeException;

/**
 * The gateway could not be reached or its answer could not be read (timeout,
 * 5xx, connection error). This is NOT a declined payment: the real state is
 * unknown, so the payment must stay `initiated` and the caller gets a 500
 * (which also makes Moyasar retry the webhook). Only an explicit
 * `status: failed` payload from the gateway may fail a payment.
 */
class GatewayException extends RuntimeException
{
    public static function fetchFailed(string $gatewayPaymentId, string $reason): self
    {
        return new self("Could not fetch payment {$gatewayPaymentId} from the gateway: {$reason}");
    }

    public static function chargeFailed(string $reason): self
    {
        return new self("The gateway did not answer the charge request: {$reason}");
    }
}
