<?php

namespace Modules\Bookings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Users\Models\User;

/**
 * A consultant handoff request with the bits of the booking the list
 * needs (reference + slot) — full booking details live on the booking
 * endpoints.
 */
class BookingTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => __('bookings::labels.transfer_status.'.$this->status->value),
            'reason' => $this->reason,
            'decision_note' => $this->decision_note,
            'booking' => $this->booking === null ? null : [
                'id' => $this->booking->id,
                'reference' => $this->booking->reference,
                'status' => $this->booking->status->value,
                'date' => $this->booking->starts_at->format('Y-m-d'),
                'time' => $this->booking->starts_at->format('H:i'),
                'client' => $this->booking->client === null ? null : [
                    'id' => $this->booking->client->id,
                    'name' => $this->booking->client->name,
                    'company_name' => $this->booking->client->company_name,
                ],
            ],
            'from_consultant' => self::consultant($this->fromConsultant),
            'to_consultant' => self::consultant($this->toConsultant),
            'requested_by' => $this->requester === null ? null : [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ],
            'decided_by' => $this->decider === null ? null : [
                'id' => $this->decider->id,
                'name' => $this->decider->name,
            ],
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id:int,name:string,title:?string,specialization:?string}|null
     */
    private static function consultant(?User $consultant): ?array
    {
        if ($consultant === null) {
            return null;
        }

        return [
            'id' => $consultant->id,
            'name' => $consultant->name,
            'title' => $consultant->title,
            'specialization' => $consultant->specialization,
        ];
    }
}
