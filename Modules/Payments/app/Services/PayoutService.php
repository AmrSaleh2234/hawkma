<?php

namespace Modules\Payments\Services;

use Illuminate\Support\Facades\DB;
use Modules\Bookings\Models\Booking;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Core\Support\Settings;
use Modules\Payments\Contracts\PayoutGateway;
use Modules\Payments\DTO\PayoutRequest;
use Modules\Payments\Enums\PayoutStatus;
use Modules\Payments\Models\Payout;
use Modules\Users\Models\User;

/**
 * Payout lifecycle (one per completed booking):
 *
 * - created as `pending` when the booking completes — the consultant's
 *   `consultation_price` minus the platform fee. If the consultant has not
 *   set a price, no payout is created (admin can see that on his profile).
 * - `release()` marks it paid. When the `payout.auto_transfer` setting is
 *   off (the default) the admin transfers the money outside the system and
 *   records a reference; when it is on, the configured PayoutGateway
 *   performs the transfer.
 */
class PayoutService
{
    public function __construct(protected PayoutGateway $gateway) {}

    /**
     * The effective fee percentage for a consultant: his own override when
     * set, otherwise the global setting.
     */
    public function feePercentFor(User $consultant): float
    {
        if ($consultant->platform_fee_percent !== null) {
            return (float) $consultant->platform_fee_percent;
        }

        return Settings::getFloat(
            Settings::PLATFORM_FEE_PERCENT,
            (float) Settings::DEFAULT_PLATFORM_FEE_PERCENT,
        );
    }

    /**
     * Create the pending payout for a completed booking. Idempotent: a
     * second call for the same booking is a no-op (the booking_id column is
     * unique). Returns null when the consultant has no price.
     */
    public function createForBooking(Booking $booking): ?Payout
    {
        $existing = Payout::query()->where('booking_id', $booking->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $consultant = $booking->consultant;
        if ($consultant === null || $consultant->consultation_price === null) {
            return null;
        }

        $amount = (int) $consultant->consultation_price;
        $feePercent = $this->feePercentFor($consultant);
        $feeAmount = (int) round($amount * $feePercent / 100);

        return Payout::query()->create([
            'booking_id' => $booking->id,
            'consultant_id' => $consultant->id,
            'amount' => $amount,
            'fee_percent' => $feePercent,
            'fee_amount' => $feeAmount,
            'net_amount' => max(0, $amount - $feeAmount),
            'status' => PayoutStatus::Pending,
        ]);
    }

    /**
     * Release a pending payout. Manual mode (default): the admin provides
     * the bank transfer reference and the payout is marked released.
     * Auto mode: the configured gateway performs the transfer first.
     */
    public function release(Payout $payout, User $by, ?string $reference = null, ?string $note = null): Payout
    {
        if ($payout->status === PayoutStatus::Released) {
            throw new BusinessException(ErrorCode::PayoutAlreadyReleased);
        }

        if ($payout->status !== PayoutStatus::Pending && $payout->status !== PayoutStatus::Failed) {
            throw new BusinessException(ErrorCode::PayoutNotReleasable);
        }

        if (! Settings::getBool(Settings::PAYOUT_AUTO_TRANSFER, false)) {
            return $this->markReleased($payout, $by, $reference, $note, 'manual');
        }

        $consultant = $payout->consultant;

        $result = $this->gateway->transfer(new PayoutRequest(
            payoutId: $payout->id,
            amount: $payout->net_amount,
            currency: (string) config('payments.currency', 'SAR'),
            iban: $consultant?->iban,
            bankName: $consultant?->bank_name,
            bankAccountName: $consultant?->bank_account_name,
            description: "Payout for booking #{$payout->booking_id}",
        ));

        if (! $result->success) {
            $payout->forceFill([
                'status' => PayoutStatus::Failed,
                'release_note' => $result->failureReason,
            ])->save();

            throw new BusinessException(ErrorCode::PayoutNotReleasable, $result->failureReason);
        }

        return $this->markReleased($payout, $by, $result->transferId ?? $reference, $note, $this->gateway->name());
    }

    protected function markReleased(Payout $payout, User $by, ?string $reference, ?string $note, string $driver): Payout
    {
        return DB::transaction(function () use ($payout, $by, $reference, $note, $driver): Payout {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($locked->status === PayoutStatus::Released) {
                throw new BusinessException(ErrorCode::PayoutAlreadyReleased);
            }

            $locked->forceFill([
                'status' => PayoutStatus::Released,
                'driver' => $driver,
                'transfer_reference' => $reference,
                'release_note' => $note,
                'released_by' => $by->id,
                'released_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
