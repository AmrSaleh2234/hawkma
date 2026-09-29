<?php

namespace Modules\Dashboard\Services;

use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Http\Resources\BookingResource;
use Modules\Clients\Models\Client;
use Modules\Packages\Http\Resources\SubscriptionResource;

class ClientDashboardService
{
    /**
     * CLI-DSH-01 (§10.23). `unread` = reports never downloaded by the client.
     *
     * @return array<string, mixed>
     */
    public function dashboardFor(Client $client): array
    {
        $next = $client->bookings()
            ->where('status', BookingStatus::Pending)
            ->upcoming()
            ->with(['client', 'consultant.media', 'package', 'latestPayment', 'report.media'])
            ->first();

        return [
            'next_booking' => $next ? BookingResource::make($next) : null,
            'bookings' => [
                'upcoming' => $client->bookings()
                    ->where('status', BookingStatus::Pending)
                    ->where('starts_at', '>=', now())
                    ->count(),
                'completed' => $client->bookings()->where('status', BookingStatus::Completed)->count(),
                'cancelled' => $client->bookings()->where('status', BookingStatus::Cancelled)->count(),
            ],
            'reports' => [
                'total' => $client->reports()->count(),
                'unread' => $client->reports()->whereNull('first_downloaded_at')->count(),
            ],
            'active_subscriptions' => SubscriptionResource::collection(
                $client->subscriptions()->active()->with('package')->latest()->get()
            ),
        ];
    }
}
