<?php

namespace Modules\Team\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $private = ! $request->routeIs('*.public.*');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'job_title' => $this->job_title,
            'bio' => $this->bio,
            'experience_years' => $this->experience_years,
            'avatar_url' => $this->avatar_url,
            'avatar_thumb_url' => $this->avatar_thumb_url,
            'sort_order' => $this->when($private, $this->sort_order),
            'is_active' => $this->when($private, $this->is_active),
            'created_at' => $this->when($private, $this->created_at?->toIso8601String()),
            'updated_at' => $this->when($private, $this->updated_at?->toIso8601String()),
        ];
    }
}
