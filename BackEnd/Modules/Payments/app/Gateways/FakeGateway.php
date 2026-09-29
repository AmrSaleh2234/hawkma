<?php

namespace Modules\Payments\Gateways;

use Illuminate\Support\Str;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\DTO\CardDetails;
use Modules\Payments\DTO\ChargeRequest;
use Modules\Payments\DTO\ChargeResult;

/**
 * The local-development and test driver (plan §9.6). Behaviour depends on
 * the token:
 *
 * - `tok_fake_success` or any `tok_fake_ok*` token → paid
 * - `tok_fake_3ds` → initiated with a fake 3DS URL; fetch() then returns paid
 * - `tok_fake_declined` → failed with "Card declined"
 */
class FakeGateway implements PaymentGateway
{
    public function charge(ChargeRequest $request): ChargeResult
    {
        if ($request->token === 'tok_fake_3ds') {
            $id = 'fake_3ds_'.Str::ulid();

            return ChargeResult::initiated(
                gatewayPaymentId: $id,
                transactionUrl: rtrim((string) config('app.url'), '/').'/fake-3ds/'.$id,
                raw: ['token' => $request->token, 'driver' => 'fake'],
            );
        }

        if ($request->token === 'tok_fake_declined') {
            return ChargeResult::failed(
                failureReason: 'Card declined',
                gatewayPaymentId: 'fake_pay_'.Str::ulid(),
                raw: ['token' => $request->token, 'driver' => 'fake'],
            );
        }

        if ($request->token === 'tok_fake_success' || str_starts_with($request->token, 'tok_fake_ok')) {
            return ChargeResult::paid(
                gatewayPaymentId: 'fake_pay_'.Str::ulid(),
                cardBrand: 'visa',
                cardLastFour: '4242',
                raw: ['token' => $request->token, 'driver' => 'fake'],
            );
        }

        return ChargeResult::failed(
            failureReason: 'Unknown token',
            raw: ['token' => $request->token, 'driver' => 'fake'],
        );
    }

    public function fetch(string $gatewayPaymentId): ChargeResult
    {
        // The fake 3DS flow always completes: once the buyer returns from the
        // fake 3DS page the payment is paid.
        return ChargeResult::paid(
            gatewayPaymentId: $gatewayPaymentId,
            cardBrand: 'visa',
            cardLastFour: '4242',
            raw: ['id' => $gatewayPaymentId, 'driver' => 'fake'],
        );
    }

    public function tokenDetails(string $token): CardDetails
    {
        return new CardDetails(
            brand: 'visa',
            lastFour: '4242',
            expMonth: 12,
            expYear: (int) now()->year + 2,
            holderName: 'Test Card',
        );
    }

    public function name(): string
    {
        return 'fake';
    }
}
