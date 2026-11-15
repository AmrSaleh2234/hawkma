<?php

namespace Modules\Payments\Gateways;

use Illuminate\Support\Str;
use Modules\Payments\Contracts\PayoutGateway;
use Modules\Payments\DTO\PayoutRequest;
use Modules\Payments\DTO\PayoutResult;

/**
 * The local-development and test payout driver. It simulates a successful
 * bank transfer and is only used when `payout.auto_transfer` is enabled
 * and `payments.payout_driver` is 'fake'.
 */
class FakePayoutGateway implements PayoutGateway
{
    public function transfer(PayoutRequest $request): PayoutResult
    {
        if ($request->iban === null || $request->iban === '') {
            return PayoutResult::failed('Missing consultant IBAN', [
                'driver' => 'fake',
            ]);
        }

        return PayoutResult::succeeded('fake_payout_'.Str::ulid(), [
            'driver' => 'fake',
            'iban' => $request->iban,
            'amount' => $request->amount,
        ]);
    }

    public function name(): string
    {
        return 'fake';
    }
}
