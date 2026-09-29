<?php

namespace Modules\Clients\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Clients\Models\Client;

class TicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isClient = $this->sender_type === Client::class;

        return [
            'id' => $this->id,
            'body' => $this->body,
            'from_client' => $isClient,
            'sender_name' => $this->sender?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
