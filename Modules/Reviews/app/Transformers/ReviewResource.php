<?php

namespace Modules\Reviews\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $private = ! $request->routeIs('public.*');

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => $this->when($private && $this->relationLoaded('client'), fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'company_name' => $this->client->company_name,
            ]),
            'name' => $this->name,
            'title' => $this->title,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'status' => $this->status->value,
            'status_label' => __('reviews::labels.status.'.$this->status->value),
            'rejection_reason' => $this->when($private, $this->rejection_reason),
            'reviewed_by' => $this->when($private, $this->reviewed_by),
            'reviewed_at' => $this->when($private, $this->reviewed_at?->toIso8601String()),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
