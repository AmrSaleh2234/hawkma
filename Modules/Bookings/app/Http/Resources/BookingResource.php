<?php

namespace Modules\Bookings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Services\BookingPermissions;
use Modules\Core\Support\Money;
use Modules\Payments\Http\Resources\PaymentResource;

/**
 * Plan §8.10. `location` comes from the snapshot (it still shows after the
 * location is deleted); `meeting.url` only has a value while the booking is
 * pending or completed; `can` is calculated for the current user/client.
 */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user('admin') ?? $request->user('client');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => __('bookings::labels.status.'.$this->status->value),
            'report_status' => $this->report_status->value,
            'payment_status' => $this->payment_status->value,
            'refund_status' => $this->refund_status->value,
            'date' => $this->starts_at->format('Y-m-d'),
            'time' => $this->starts_at->format('H:i'),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'duration_minutes' => $this->durationMinutes(),
            'amount' => $this->amount,
            'amount_formatted' => Money::format($this->amount, $this->currency),
            'currency' => $this->currency,
            'package' => $this->package === null ? null : [
                'id' => $this->package->id,
                'slug' => $this->package->slug,
                'name' => $this->package->localizedName(),
                'name_ar' => $this->package->name_ar,
                'name_en' => $this->package->name_en,
            ],
            'consultant' => $this->consultant === null ? null : [
                'id' => $this->consultant->id,
                'name' => $this->consultant->name,
                'title' => $this->consultant->title,
                'specialization' => $this->consultant->specialization,
                'avatar_thumb_url' => $this->consultant->avatar_thumb_url,
            ],
            'client' => $this->client === null ? null : [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'company_name' => $this->client->company_name,
                'email' => $this->client->email,
                'phone' => $this->client->phone,
            ],
            'location' => $this->location_snapshot === null ? null : [
                'id' => $this->client_location_id,
                'name' => $this->location_snapshot['name'] ?? null,
                'city' => $this->location_snapshot['city'] ?? null,
                'address' => $this->location_snapshot['address'] ?? null,
            ],
            'meeting' => [
                'provider' => $this->meeting_provider,
                'status' => $this->meeting_status->value,
                'url' => in_array($this->status, [BookingStatus::Pending, BookingStatus::Completed], true)
                    ? $this->meeting_url
                    : null,
            ],
            'payment' => $this->whenLoaded('latestPayment', fn () => $this->latestPayment
                ? PaymentResource::make($this->latestPayment)
                : null),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'report' => $this->whenLoaded('report', fn () => $this->report === null ? null : [
                'id' => $this->report->id,
                'title' => $this->report->title,
                'file_name' => $this->report->getFirstMedia('report_file')?->file_name,
                'uploaded_at' => $this->report->created_at?->toIso8601String(),
            ]),
            'client_notes' => $this->client_notes,
            'can' => BookingPermissions::for($this->resource, $actor),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
