<?php

namespace Modules\Payments\Gateways;

use Modules\Payments\Contracts\PayoutGateway;
use Modules\Payments\DTO\PayoutRequest;
use Modules\Payments\DTO\PayoutResult;
use RuntimeException;

/**
 * The default payout driver: it performs no external call. Manual mode is
 * handled inside PayoutService — the gateway is only reached when
 * `payout.auto_transfer` is enabled, so this class exists to make the
 * configuration explicit and fails loudly if it is ever invoked.
 */
class ManualPayoutGateway implements PayoutGateway
{
    public function transfer(PayoutRequest $request): PayoutResult
    {
        throw new RuntimeException('Automatic payouts are not configured. Choose a payout driver or disable auto transfer.');
    }

    public function name(): string
    {
        return 'manual';
    }
}
