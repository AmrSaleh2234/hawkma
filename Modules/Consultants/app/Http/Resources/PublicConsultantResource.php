<?php

namespace Modules\Consultants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\Money;

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
            'consultation_price' => $this->consultation_price,
            'consultation_price_formatted' => $this->consultation_price === null
                ? null
                : Money::format($this->consultation_price, 'SAR'),
            'currency' => 'SAR',
            'working_days' => $this->whenLoaded(
                'availabilities',
                fn () => $this->availabilities->pluck('day_of_week')->unique()->sort()->values(),
            ),
        ];
    }
}
