<?php

namespace Modules\Payments\DTO;

use Modules\Payments\Enums\PaymentRecordStatus;

final readonly class ChargeResult
{
    /**
     * @param  string  $status  initiated|paid|failed|refunded (PaymentRecordStatus value)
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $status,
        public ?string $gatewayPaymentId = null,
        public ?string $transactionUrl = null,
        public ?string $failureReason = null,
        public ?string $cardBrand = null,
        public ?string $cardLastFour = null,
        public array $raw = [],
    ) {}

    public static function initiated(?string $gatewayPaymentId = null, ?string $transactionUrl = null, array $raw = []): self
    {
        return new self(
            status: PaymentRecordStatus::Initiated->value,
            gatewayPaymentId: $gatewayPaymentId,
            transactionUrl: $transactionUrl,
            raw: $raw,
        );
    }

    public static function paid(?string $gatewayPaymentId = null, ?string $cardBrand = null, ?string $cardLastFour = null, array $raw = []): self
    {
        return new self(
            status: PaymentRecordStatus::Paid->value,
            gatewayPaymentId: $gatewayPaymentId,
            cardBrand: $cardBrand,
            cardLastFour: $cardLastFour,
            raw: $raw,
        );
    }

    public static function failed(?string $failureReason = null, ?string $gatewayPaymentId = null, array $raw = []): self
    {
        return new self(
            status: PaymentRecordStatus::Failed->value,
            gatewayPaymentId: $gatewayPaymentId,
            failureReason: $failureReason,
            raw: $raw,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentRecordStatus::Paid->value;
    }

    public function isInitiated(): bool
    {
        return $this->status === PaymentRecordStatus::Initiated->value;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentRecordStatus::Failed->value;
    }
}
