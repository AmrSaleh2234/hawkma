<?php

namespace Modules\Packages\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\Money;

class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->localizedName(),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description' => $this->localizedDescription(),
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'features' => $this->features ?? [],
            'features_localized' => $this->localizedFeatures(),
            'price' => $this->price,
            'price_formatted' => Money::format($this->price, $this->currency),
            'currency' => $this->currency,
            'billing_period_days' => $this->billing_period_days,
            'consultations_limit' => $this->consultations_limit,
            'documents_limit' => $this->documents_limit,
            'is_unlimited' => $this->isUnlimited(),
            'is_featured' => $this->is_featured,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'subscriptions_count' => $this->whenCounted('subscriptions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
