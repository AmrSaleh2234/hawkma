<?php

namespace Modules\Consultants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'consultant_id' => $this->consultant_id,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'meeting_url' => $this->meeting_url,
            'notes' => $this->notes,
            'scheduled_by' => $this->whenLoaded(
                'scheduler',
                fn () => $this->scheduler ? ['id' => $this->scheduler->id, 'name' => $this->scheduler->name] : null,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
