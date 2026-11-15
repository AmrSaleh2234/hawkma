<?php

namespace Modules\Payments\DTO;

/**
 * The data a PayoutGateway needs to send money to a consultant.
 */
class PayoutRequest
{
    public function __construct(
        public readonly int $payoutId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $iban,
        public readonly ?string $bankName,
        public readonly ?string $bankAccountName,
        public readonly string $description,
    ) {}
}
