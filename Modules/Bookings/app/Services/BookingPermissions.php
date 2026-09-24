<?php

namespace Modules\Bookings\Services;

use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Users\Models\User;

/**
 * Calculates the `can` object of BookingResource for the current actor
 * (§8.10): admin side = permission + policy + state; client side = only
 * `cancel`.
 */
class BookingPermissions
{
    /**
     * @return array{complete: bool, cancel: bool, upload_report: bool}
     */
    public static function for(Booking $booking, User|Client|null $actor): array
    {
        if ($actor instanceof Client) {
            return [
                'complete' => false,
                'cancel' => self::clientCanCancel($booking),
                'upload_report' => false,
            ];
        }

        if ($actor instanceof User) {
            return [
                'complete' => $actor->hasPermissionTo('complete-bookings', 'admin')
                    && $actor->can('complete', $booking)
                    && $booking->status === BookingStatus::Pending
                    && now()->gte($booking->starts_at),
                'cancel' => $actor->hasPermissionTo('cancel-bookings', 'admin')
                    && $actor->can('cancel', $booking)
                    && in_array($booking->status, [BookingStatus::Pending, BookingStatus::PendingPayment], true),
                'upload_report' => $actor->hasPermissionTo('upload-reports', 'admin')
                    && $actor->can('uploadReport', $booking)
                    && $booking->status === BookingStatus::Completed,
            ];
        }

        return ['complete' => false, 'cancel' => false, 'upload_report' => false];
    }

    /**
     * A pending_payment booking can always be cancelled by the client; a
     * pending one only up to client_cancel_hours before it starts (CLI-BKG-05).
     */
    protected static function clientCanCancel(Booking $booking): bool
    {
        return match ($booking->status) {
            BookingStatus::PendingPayment => true,
            BookingStatus::Pending => now()
                ->addHours((int) config('bookings.client_cancel_hours', 24))
                ->lte($booking->starts_at),
            default => false,
        };
    }
}
