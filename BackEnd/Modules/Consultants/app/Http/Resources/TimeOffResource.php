<?php

namespace Modules\Consultants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Consultants\Models\ConsultantAvailability;

class TimeOffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'start_time' => $this->start_time === null ? null : ConsultantAvailability::toHi($this->start_time),
            'end_time' => $this->end_time === null ? null : ConsultantAvailability::toHi($this->end_time),
            'is_full_day' => $this->is_full_day,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
