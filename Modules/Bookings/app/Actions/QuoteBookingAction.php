<?php

namespace Modules\Bookings\Actions;

use Carbon\CarbonImmutable;
use Modules\Clients\Models\Client;
use Modules\Consultants\Services\SlotService;
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
     *     package: Package,
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
}
