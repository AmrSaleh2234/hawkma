<?php

namespace Modules\Payments\DTO;

final readonly class ChargeRequest
{
    /**
     * @param  int  $amount  halalas
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public string $token,
        public string $description,
        public string $callbackUrl,
        public array $metadata = [],
    ) {}
}
