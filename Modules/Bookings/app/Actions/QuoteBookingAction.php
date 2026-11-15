<?php

namespace Modules\Bookings\Actions;

use Carbon\CarbonImmutable;
use Modules\Clients\Models\Client;
use Modules\Consultants\Services\SlotService;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Packages\Services\SubscriptionService;
use Modules\Users\Models\User;

/**
 * CLI-BKG-01: price a booking before it is created (plan §9.4).
 *
 * - An active subscription for this package with remaining quota → free.
 * - Otherwise the client pays the package price.
 * - slot_available is only calculated when consultant + date + time are sent.
 */
class QuoteBookingAction
{
    public function __construct(
        protected SubscriptionService $subscriptions,
        protected SlotService $slots,
    ) {}

    /**
     * @return array{
     *     package: ?Package,
     *     requires_payment: bool,
     *     amount: int,
     *     currency: string,
     *     subscription: ?ClientSubscription,
     *     slot_available: ?bool
     * }
     */
    public function execute(
        Client $client,
        Package $package,
        ?User $consultant = null,
        ?string $date = null,
        ?string $time = null,
    ): array {
        $quote = $this->subscriptions->quote($client, $package);

        // Mirrors CreateBookingAction: the remaining quota of an active
        // subscription still applies after the package is updated or
        // drafted; only a new purchase requires an active package.
        if ($quote['requires_payment'] && (! $package->is_active || $package->trashed())) {
            throw new BusinessException(ErrorCode::PackageInactive);
        }

        $slotAvailable = null;
        if ($consultant && $date && $time) {
            $startsAt = CarbonImmutable::parse($date.' '.$time, config('app.timezone'));
            $slotAvailable = $this->slots->isSlotAvailable($consultant, $startsAt);
        }

        return [
            'package' => $package,
            'requires_payment' => $quote['requires_payment'],
            'amount' => $quote['amount'],
            'currency' => $package->currency,
            'subscription' => $quote['subscription'],
            'slot_available' => $slotAvailable,
        ];
    }

    /**
     * Direct booking quote: no package — the client always pays the
     * consultant's configured consultation_price.
     *
     * @return array{
     *     package: null,
     *     requires_payment: bool,
     *     amount: int,
     *     currency: string,
     *     subscription: null,
     *     slot_available: ?bool
     * }
     */
    public function executeDirect(
        Client $client,
        User $consultant,
        ?string $date = null,
        ?string $time = null,
    ): array {
        if ($consultant->consultation_price === null) {
            throw new BusinessException(ErrorCode::ConsultantPriceRequired);
        }

        $slotAvailable = null;
        if ($date && $time) {
            $startsAt = CarbonImmutable::parse($date.' '.$time, config('app.timezone'));
            $slotAvailable = $this->slots->isSlotAvailable($consultant, $startsAt);
        }

        return [
            'package' => null,
            'requires_payment' => true,
            'amount' => $consultant->consultation_price,
            'currency' => 'SAR',
            'subscription' => null,
            'slot_available' => $slotAvailable,
        ];
    }
}
