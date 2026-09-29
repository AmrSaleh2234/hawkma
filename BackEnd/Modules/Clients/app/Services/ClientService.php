<?php

namespace Modules\Clients\Services;

use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;

class ClientService
{
    /**
     * ADM-CL-03: update the client's basic data.
     */
    public function update(Client $client, array $data): Client
    {
        $client->fill($data);
        $client->save();

        return $client;
    }

    /**
     * ADM-CL-04: activate or deactivate. Deactivating deletes the tokens.
     */
    public function setActive(Client $client, bool $isActive): Client
    {
        $client->is_active = $isActive;
        $client->save();

        if (! $isActive) {
            $client->tokens()->delete();
        }

        return $client;
    }

    /**
     * ADM-CL-05: soft delete; blocked while the client has future pending
     * bookings.
     */
    public function delete(Client $client): void
    {
        $this->assertClientHasNoFutureBookings($client);

        $client->tokens()->delete();
        $client->delete();
    }

    protected function assertClientHasNoFutureBookings(Client $client): void
    {
        // The Booking model is introduced in Phase 9; this check activates then.
        if (! class_exists(Booking::class)) {
            return;
        }

        $hasFuturePendingBookings = $client->bookings()
            ->where('status', 'pending')
            ->where('starts_at', '>', now())
            ->exists();

        if ($hasFuturePendingBookings) {
            throw new BusinessException(ErrorCode::ClientHasFutureBookings, status: 409);
        }
    }
}
