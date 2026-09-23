<?php

namespace Modules\Consultants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-side consultant resource (wraps a User with type=consultant).
 */
class ConsultantResource extends JsonResource
{
    protected bool $withAvailability = false;

    /**
     * Include the full weekly schedule (CON-03).
     */
    public function withAvailability(bool $value = true): static
    {
        $this->withAvailability = $value;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'title' => $this->title,
            'specialization' => $this->specialization,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'avatar_thumb_url' => $this->avatar_thumb_url,
            'is_active' => $this->is_active,
            'roles' => $this->getRoleNames()->values(),
            // Only when the counts were loaded (they need the bookings and
            // reports tables — wired in Phases 9/10).
            'stats' => $this->when(
                isset($this->pending_bookings_count),
                fn () => [
                    'pending_bookings' => $this->pending_bookings_count,
                    'completed_bookings' => $this->completed_bookings_count,
                    'pending_reports' => $this->pending_reports_count,
                    'reports' => $this->reports_count,
                    'clients' => $this->clients_count,
                ],
            ),
            'working_days' => $this->whenLoaded(
                'availabilities',
                fn () => $this->availabilities->pluck('day_of_week')->unique()->sort()->values(),
            ),
            'availability' => $this->when(
                $this->withAvailability,
                fn () => AvailabilityResource::make($this->resource),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
