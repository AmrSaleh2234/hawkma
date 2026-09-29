<?php

namespace Modules\Packages\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\Money;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'package' => $this->whenLoaded('package', fn () => [
                'id' => $this->package->id,
                'slug' => $this->package->slug,
                'name' => $this->package->localizedName(),
                'name_ar' => $this->package->name_ar,
                'name_en' => $this->package->name_en,
            ]),
            'status' => $this->status->value,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'consultations_limit' => $this->consultations_limit,
            'consultations_used' => $this->consultations_used,
            'consultations_remaining' => $this->remaining(),
            'is_unlimited' => $this->isUnlimited(),
            'price_paid' => $this->price_paid,
            'price_paid_formatted' => Money::format($this->price_paid),
        ];
    }
}
