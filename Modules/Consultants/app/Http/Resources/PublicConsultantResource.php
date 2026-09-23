<?php

namespace Modules\Consultants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wizard consultant resource — NO email/phone.
 */
class PublicConsultantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'specialization' => $this->specialization,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'avatar_thumb_url' => $this->avatar_thumb_url,
            'working_days' => $this->whenLoaded(
                'availabilities',
                fn () => $this->availabilities->pluck('day_of_week')->unique()->sort()->values(),
            ),
        ];
    }
}
