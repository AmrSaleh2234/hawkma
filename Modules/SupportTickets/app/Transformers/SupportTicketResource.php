<?php

namespace Modules\SupportTickets\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'reference' => $this->reference, 'category' => $this->category->value, 'status' => $this->status->value, 'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'name' => $this->client->name]), 'consultant' => $this->whenLoaded('consultant', fn () => $this->consultant ? ['id' => $this->consultant->id, 'name' => $this->consultant->name] : null), 'messages' => SupportTicketMessageResource::collection($this->whenLoaded('messages')), 'last_message_at' => $this->last_message_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
