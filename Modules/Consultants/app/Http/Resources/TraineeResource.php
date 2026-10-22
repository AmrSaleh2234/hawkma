<?php

namespace Modules\Consultants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A consultant still in the training stage, with his scheduled sessions.
 * Expects `training_sessions` (+ `scheduler`) to be eager-loaded.
 */
class TraineeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $loaded = $this->resource->relationLoaded('trainingSessions');
        $sessions = $loaded ? $this->trainingSessions : collect();
        $next = $sessions->where('starts_at', '>=', now())->sortBy('starts_at')->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'title' => $this->title,
            'specialization' => $this->specialization,
            'avatar_url' => $this->avatar_url,
            'avatar_thumb_url' => $this->avatar_thumb_url,
            'is_active' => $this->is_active,
            'consultant_stage' => $this->consultant_stage,
            'sessions_count' => $sessions->count(),
            'next_session_at' => $next?->starts_at?->toIso8601String(),
            'sessions' => $this->whenLoaded(
                'trainingSessions',
                fn () => TrainingSessionResource::collection($sessions->sortBy('starts_at')->values()),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
