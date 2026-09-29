<?php

namespace Modules\Bookings\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Bookings\Models\Booking;

/**
 * To the client and the consultant when a booking is cancelled (not by
 * payment timeout): reference, reason (§9.11).
 */
class BookingCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $audience  'client' | 'consultant' — tailors the text.
     */
    public function __construct(
        public readonly Booking $booking,
        public readonly string $audience = 'client',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking;

        return (new MailMessage)
            ->subject(__('bookings::notifications.booking_cancelled.subject', ['reference' => $booking->reference]))
            ->greeting(__('bookings::notifications.booking_cancelled.greeting', ['name' => $notifiable->name]))
            ->line(__('bookings::notifications.booking_cancelled.intro.'.$this->audience, [
                'reference' => $booking->reference,
                'company' => $booking->client->company_name,
                'date' => $booking->starts_at->format('Y-m-d'),
                'time' => $booking->starts_at->format('H:i'),
            ]))
            ->line(__('bookings::notifications.booking_cancelled.reason', [
                'reason' => $booking->cancellation_reason ?? '-',
            ]));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_cancelled',
            'booking_id' => $this->booking->id,
            'reference' => $this->booking->reference,
            'reason' => $this->booking->cancellation_reason,
        ];
    }
}
