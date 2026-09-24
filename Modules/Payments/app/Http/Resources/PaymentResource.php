<?php

namespace Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\Money;
use Modules\Payments\Enums\PaymentRecordStatus;

/**
 * Plan §8.11. transaction_url only has a value while status=initiated; the
 * raw gateway response is never returned.
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'booking_reference' => $this->booking?->reference,
            'amount' => $this->amount,
            'amount_formatted' => Money::format($this->amount, $this->currency),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'gateway' => $this->gateway,
            'card_brand' => $this->card_brand,
            'card_last_four' => $this->card_last_four,
            'failure_reason' => $this->failure_reason,
            'transaction_url' => $this->status === PaymentRecordStatus::Initiated
                ? $this->transaction_url
                : null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'client' => $this->when(
                $request->user('admin') !== null && $this->relationLoaded('client'),
                fn () => [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                    'company_name' => $this->client->company_name,
                ],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
