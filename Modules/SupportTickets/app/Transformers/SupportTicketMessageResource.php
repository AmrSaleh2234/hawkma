<?php

namespace Modules\SupportTickets\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Clients\Models\Client;
use Modules\Users\Models\User;

class SupportTicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'image' => $this->mediaPayload($request, 'image'),
            'voice' => $this->mediaPayload($request, 'voice'),
            'is_internal' => $this->is_internal,
            'sender_type' => $this->senderAlias(),
            'sender' => $this->when($this->relationLoaded('sender'), fn () => $this->sender ? [
                'id' => $this->sender_id,
                'name' => $this->sender->name,
                'type' => $this->senderAlias(),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function senderAlias(): string
    {
        return match ($this->sender_type) {
            (new Client)->getMorphClass() => 'client',
            (new User)->getMorphClass() => 'user',
            default => 'system',
        };
    }

    /**
     * @return array{name: string, url: string, mime_type: string}|null
     */
    private function mediaPayload(Request $request, string $collection): ?array
    {
        $media = $this->getFirstMedia($collection);
        if ($media === null) {
            return null;
        }

        $portal = $request->user('client') !== null ? 'client' : 'admin';

        return [
            'name' => $media->file_name,
            'url' => url("/api/v1/{$portal}/support-tickets/{$this->support_ticket_id}/messages/{$this->id}/media/{$collection}"),
            'mime_type' => $media->mime_type,
        ];
    }
}
