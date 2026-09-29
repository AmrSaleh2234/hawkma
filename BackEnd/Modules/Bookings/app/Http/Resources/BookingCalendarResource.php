<?php

namespace Modules\Bookings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BKG-06: the minimal booking item for the calendar view.
 */
class BookingCalendarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'consultant' => [
                'id' => $this->consultant->id,
                'name' => $this->consultant->name,
            ],
            'client' => [
                'id' => $this->client->id,
                'company_name' => $this->client->company_name,
            ],
        ];
    }
}
