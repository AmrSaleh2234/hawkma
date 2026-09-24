<?php

namespace Modules\Packages\Services;

use Illuminate\Support\Facades\DB;
use Modules\Clients\Models\Client;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Packages\Enums\SubscriptionStatus;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Payments\Models\Payment;

class SubscriptionService
{
    /**
     * Quote a package for a client (plan §9.4).
     *
     * If the client already has an active subscription for this package with
     * remaining consultations, no payment is required; otherwise the client
     * pays the package price.
     *
     * @return array{requires_payment: bool, amount: int, subscription: ?ClientSubscription, remaining_before?: ?int}
     */
    public function quote(Client $client, Package $package): array
    {
        $active = $client->subscriptions()
            ->where('package_id', $package->id)
            ->where('status', SubscriptionStatus::Active)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->latest()
            ->first();

        if ($active && $active->hasRemaining()) {
            return [
                'requires_payment' => false,
                'amount' => 0,
                'subscription' => $active,
                'remaining_before' => $active->remaining(),
            ];
        }

        return [
            'requires_payment' => true,
            'amount' => $package->price,
            'subscription' => null,
        ];
    }

    /**
     * Create the subscription once a payment succeeds (plan §9.4). The state
     * machine then increments consultations_used to 1 for the booking that
     * paid.
     */
    public function activateFromPayment(Client $client, Package $package, Payment $payment): ClientSubscription
    {
        return $client->subscriptions()->create([
            'package_id' => $package->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at' => now()->addDays($package->billing_period_days),
            'consultations_limit' => $package->consultations_limit,
            'consultations_used' => 0,
            'price_paid' => $payment->amount,
        ]);
    }

    /**
     * consultations_used++ with a row lock; throws when nothing is left
     * (plan §9.4). The quote flow checks hasRemaining() upstream, so this is
     * a last-resort guard against concurrent consumption — it surfaces as a
     * 422 SUBSCRIPTION_EXHAUSTED business error, never a raw 500.
     */
    public function consume(ClientSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $locked = ClientSubscription::query()
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            // Re-checked under the lock: the subscription may have been
            // cancelled (refund) or expired after the booking was quoted.
            if (! $locked->isActive()) {
                throw new BusinessException(ErrorCode::SubscriptionInactive);
            }

            if (! $locked->hasRemaining()) {
                throw new BusinessException(ErrorCode::SubscriptionExhausted);
            }

            $locked->increment('consultations_used');
        });

        $subscription->refresh();
    }

    /**
     * Cancel a subscription whose payment was refunded: the client got the
     * money back, so the package cannot stay active. Already finished
     * (expired/cancelled) subscriptions are left untouched. Takes the same
     * row lock as consume() so the two cannot interleave.
     */
    public function cancel(ClientSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $locked = ClientSubscription::query()
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            if ($locked->status === SubscriptionStatus::Active) {
                $locked->forceFill(['status' => SubscriptionStatus::Cancelled])->save();
            }
        });

        $subscription->refresh();
    }

    /**
     * consultations_used-- (never below 0) — used when a booking is
     * cancelled and the consultation returns to the quota.
     */
    public function release(ClientSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $locked = ClientSubscription::query()
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            if ($locked->consultations_used > 0) {
                $locked->decrement('consultations_used');
            }
        });

        $subscription->refresh();
    }

    /**
     * Expire active subscriptions whose ends_at has passed. Runs daily via
     * the subscriptions:expire command.
     *
     * @return int the number of subscriptions expired
     */
    public function expireExpired(): int
    {
        return ClientSubscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('ends_at', '<=', now())
            ->update(['status' => SubscriptionStatus::Expired]);
    }
}
