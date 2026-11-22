<?php

namespace Modules\Certificates\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $private = ! $request->routeIs('*.public.*');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'issuer' => $this->issuer,
            'image_url' => $this->image_url,
            'image_thumb_url' => $this->image_thumb_url,
            'sort_order' => $this->when($private, $this->sort_order),
            'is_active' => $this->when($private, $this->is_active),
            'created_at' => $this->when($private, $this->created_at?->toIso8601String()),
            'updated_at' => $this->when($private, $this->updated_at?->toIso8601String()),
        ];
    }
}
