<?php

namespace Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\Money;

class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = (string) config('payments.currency', 'SAR');

        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'booking_reference' => $this->booking?->reference,
            'consultant' => $this->when(
                $this->relationLoaded('consultant'),
                fn () => $this->consultant === null ? null : [
                    'id' => $this->consultant->id,
                    'name' => $this->consultant->name,
                    'iban' => $this->consultant->iban,
                    'bank_name' => $this->consultant->bank_name,
                    'bank_account_name' => $this->consultant->bank_account_name,
                ],
            ),
            'amount' => $this->amount,
            'amount_formatted' => Money::format($this->amount, $currency),
            'fee_percent' => (float) $this->fee_percent,
            'fee_amount' => $this->fee_amount,
            'fee_amount_formatted' => Money::format($this->fee_amount, $currency),
            'net_amount' => $this->net_amount,
            'net_amount_formatted' => Money::format($this->net_amount, $currency),
            'currency' => $currency,
            'status' => $this->status->value,
            'driver' => $this->driver,
            'transfer_reference' => $this->transfer_reference,
            'release_note' => $this->release_note,
            'released_by' => $this->when(
                $this->relationLoaded('releaser'),
                fn () => $this->releaser === null ? null : [
                    'id' => $this->releaser->id,
                    'name' => $this->releaser->name,
                ],
            ),
            'released_at' => $this->released_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
