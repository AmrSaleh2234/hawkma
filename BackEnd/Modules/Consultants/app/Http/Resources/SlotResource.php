<?php

namespace Modules\Consultants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps one slot array as produced by SlotService::getSlots()
 * (`['time' => '10:30', 'starts_at' => ISO, 'ends_at' => ISO]`).
 */
class SlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'time' => $this->resource['time'],
            'starts_at' => $this->resource['starts_at'],
            'ends_at' => $this->resource['ends_at'],
        ];
    }
}
