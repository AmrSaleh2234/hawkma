<?php

namespace Modules\Clients\Policies;

use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Users\Models\User;

class ClientPolicy
{
    /**
     * Admins see everything; a consultant must have a booking with this
     * client (plan §9.8).
     */
    public function view(User $user, Client $client): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // The Booking model is introduced in Phase 9; until then a consultant
        // cannot have any booking, so this fails closed.
        if (! class_exists(Booking::class)) {
            return false;
        }

        return $client->bookings()->where('consultant_id', $user->id)->exists();
    }
}
