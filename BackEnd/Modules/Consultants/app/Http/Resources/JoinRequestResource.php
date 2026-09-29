<?php

namespace Modules\Consultants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Consultants\Models\JoinRequest as JoinRequestModel;

class JoinRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'qualification' => $this->qualification,
            'experience' => $this->experience,
            'service_fields' => $this->service_fields,
            'licenses' => $this->licenses,
            'phone' => $this->phone,
            'email' => $this->email,
            'country_city' => $this->country_city,
            'social_accounts' => $this->social_accounts,
            'linkedin' => $this->linkedin,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function statusLabel(): string
    {
        return match ($this->status) {
            JoinRequestModel::STATUS_ACCEPTED => __('consultants::join_request.status_accepted'),
            JoinRequestModel::STATUS_REJECTED => __('consultants::join_request.status_rejected'),
            default => __('consultants::join_request.status_pending'),
        };
    }
}
