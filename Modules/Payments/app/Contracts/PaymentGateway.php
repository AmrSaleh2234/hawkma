<?php

namespace Modules\Payments\Contracts;

use Modules\Payments\DTO\CardDetails;
use Modules\Payments\DTO\ChargeRequest;
use Modules\Payments\DTO\ChargeResult;

interface PaymentGateway
{
    /**
     * Charge an amount using a token (saved card token or a one-time token
     * from the frontend).
     */
    public function charge(ChargeRequest $request): ChargeResult;

    /**
     * Fetch the latest status of a payment from the gateway (used by verify
     * + webhook).
     */
    public function fetch(string $gatewayPaymentId): ChargeResult;

    /**
     * Read card details for a token so we can save it as a payment method.
     */
    public function tokenDetails(string $token): CardDetails;

    /**
     * 'fake' | 'moyasar'
     */
    public function name(): string;
}
