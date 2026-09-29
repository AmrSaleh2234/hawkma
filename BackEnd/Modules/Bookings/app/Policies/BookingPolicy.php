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

    /**
     * A consultant may raise a "can't attend" request only on his own booking.
     */
    public function requestReassign(User $user, Booking $booking): bool
    {
        return $booking->consultant_id === $user->id;
    }

    /**
     * Only an admin may reassign a booking to another consultant (or dismiss
     * a pending "can't attend" request).
     */
    public function reassign(User $user, Booking $booking): bool
    {
        return $user->isAdmin();
    }
}
