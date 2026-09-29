<?php

namespace Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    /**
     * gateway_token is never returned (plan §8.9 / §10.18).
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand' => $this->brand,
            'last_four' => $this->last_four,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'holder_name' => $this->holder_name,
            'is_default' => $this->is_default,
            'is_expired' => $this->isExpired(),
            'display' => $this->display,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
