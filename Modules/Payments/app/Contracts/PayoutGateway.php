<?php

namespace Modules\Payments\Contracts;

use Modules\Payments\DTO\PayoutRequest;
use Modules\Payments\DTO\PayoutResult;

/**
 * Abstraction over a payout/transfer provider. Enabled via the
 * `payout.auto_transfer` setting — while it is off the service performs a
 * manual release and this contract is never touched, so a real provider
 * can be plugged in later without changing business logic.
 */
interface PayoutGateway
{
    /**
     * Send a transfer to the consultant's bank account (IBAN).
     */
    public function transfer(PayoutRequest $request): PayoutResult;

    /**
     * 'fake' | 'manual' | a real provider name.
     */
    public function name(): string;
}
