<?php

namespace Modules\Bookings\Policies;

use Modules\Bookings\Models\Booking;
use Modules\Users\Models\User;

/**
 * Data visibility (§9.8): an admin may act on any booking; a consultant
 * only on his own. A consultant opening another consultant's record gets a
 * 403.
 */
class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $user->isAdmin() || $booking->consultant_id === $user->id;
    }

    public function complete(User $user, Booking $booking): bool
    {
        return $user->isAdmin() || $booking->consultant_id === $user->id;
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $user->isAdmin() || $booking->consultant_id === $user->id;
    }

    public function uploadReport(User $user, Booking $booking): bool
    {
        return $user->isAdmin() || $booking->consultant_id === $user->id;
    }

    public function manageMeeting(User $user, Booking $booking): bool
    {
        return $user->isAdmin() || $booking->consultant_id === $user->id;
    }
}
