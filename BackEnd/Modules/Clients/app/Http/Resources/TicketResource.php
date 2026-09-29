<?php

namespace Modules\Clients\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'type' => $this->type,
            'type_label' => __('clients::tickets.type.'.$this->type),
            'status' => $this->status,
            'status_label' => __('clients::tickets.status.'.$this->status),
            'messages_count' => $this->whenCounted('messages'),
            'client' => $this->when(
                $this->relationLoaded('client') && $this->client !== null,
                fn () => [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                    'company_name' => $this->client->company_name,
                    'email' => $this->client->email,
                ]
            ),
            'messages' => TicketMessageResource::collection($this->whenLoaded('messages')),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
