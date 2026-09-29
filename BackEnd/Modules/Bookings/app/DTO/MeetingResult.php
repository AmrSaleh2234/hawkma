<?php

namespace Modules\Bookings\DTO;

final readonly class MeetingResult
{
    public function __construct(
        public ?string $eventId,
        public ?string $joinUrl,
    ) {}
}
