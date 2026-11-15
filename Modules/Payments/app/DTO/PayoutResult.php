<?php

namespace Modules\Payments\DTO;

/**
 * The result of a transfer attempt returned by a PayoutGateway.
 */
class PayoutResult
{
    protected function __construct(
        public readonly bool $success,
        public readonly ?string $transferId = null,
        public readonly ?string $failureReason = null,
        public readonly array $raw = [],
    ) {}

    public static function succeeded(string $transferId, array $raw = []): static
    {
        return new static(true, transferId: $transferId, raw: $raw);
    }

    public static function failed(string $reason, array $raw = []): static
    {
        return new static(false, failureReason: $reason, raw: $raw);
    }
}
