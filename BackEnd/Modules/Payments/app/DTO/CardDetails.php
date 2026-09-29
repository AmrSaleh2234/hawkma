<?php

namespace Modules\Payments\DTO;

final readonly class CardDetails
{
    public function __construct(
        public string $brand,
        public string $lastFour,
        public int $expMonth,
        public int $expYear,
        public ?string $holderName = null,
    ) {}
}
