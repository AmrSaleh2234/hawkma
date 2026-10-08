<?php

namespace Modules\Bookings\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\PaymentStatus;
use Modules\Bookings\Jobs\CreateMeetingJob;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Consultants\Services\SlotService;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Services\SubscriptionService;
use Modules\Users\Models\User;

/**
 * The "book the next meeting" flow: the package was already paid for with
 * the first booking (plan §9.4/§9.5), so every later meeting only consumes
 * one consultation from the active subscription — no payment fields, no
 * charge. The consultant row is locked for the whole transaction so two
 * concurrent requests can never take the same slot, and consume() re-checks
 * the quota under its own row lock so the last consultation can never be
 * taken twice.
 */
class CreateSubscriptionBookingAction
{
    public function __construct(
        protected SlotService $slots,
        protected SubscriptionService $subscriptions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated StoreSubscriptionBookingRequest data.
     */
    public function execute(Client $client, ClientSubscription $subscription, array $data): Booking
    {
        $consultant = User::query()->findOrFail($data['consultant_id']);
        if (! $consultant->isConsultant() || ! $consultant->is_active) {
            throw new BusinessException(ErrorCode::ConsultantInactive);
        }

        $location = $client->locations()->find($data['client_location_id']);
        if ($location === null) {
            throw new BusinessException(ErrorCode::LocationNotOwned);
        }

        // withTrashed: a drafted package must still serve the remaining quota.
        $package = $subscription->package;

        $booking = DB::transaction(function () use ($client, $subscription, $consultant, $package, $location, $data): Booking {
            $lockedConsultant = User::query()->whereKey($consultant->id)->lockForUpdate()->firstOrFail();

            $startsAt = CarbonImmutable::createFromFormat(
                'Y-m-d H:i',
                $data['date'].' '.$data['time'],
                'Asia/Riyadh',
            );

            if (! $this->slots->isSlotAvailable($lockedConsultant, $startsAt)) {
                throw new BusinessException(ErrorCode::SlotNotAvailable, status: 409);
            }

            // Throws SUBSCRIPTION_INACTIVE / SUBSCRIPTION_EXHAUSTED under a
            // row lock, so the quota cannot be consumed twice concurrently.
            $this->subscriptions->consume($subscription);

            // The subscription's purchase-time snapshot wins so all its
            // bookings display the same package data (§9.4).
            return Booking::query()->create([
                'client_id' => $client->id,
                'consultant_id' => $consultant->id,
                'package_id' => $package->id,
                'package_snapshot' => $subscription->package_snapshot ?? $package->snapshot(),
                'client_subscription_id' => $subscription->id,
                'client_location_id' => $location->id,
                'location_snapshot' => [
                    'name' => $location->name,
                    'city' => $location->city,
                    'address' => $location->address,
                ],
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes((int) config('bookings.duration_minutes', 30)),
                'status' => BookingStatus::Pending,
                'payment_status' => PaymentStatus::NotRequired,
                'amount' => 0,
                'currency' => $package->currency,
                'expires_at' => null,
                'client_notes' => $data['client_notes'] ?? null,
            ]);
        });

        CreateMeetingJob::dispatch($booking);

        return $booking;
    }
}
