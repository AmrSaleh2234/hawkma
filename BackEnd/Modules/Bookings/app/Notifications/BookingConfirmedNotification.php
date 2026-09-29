<?php

namespace Modules\Bookings\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Bookings\Models\Booking;

/**
 * To the client when the booking becomes pending and the meeting is created
 * (or failed — then without a link). Content: reference, consultant, date,
 * time (Riyadh), package, location, Google Meet link, amount paid (§9.11).
 */
class BookingConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly bool $withLink = true,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking;

        $mail = (new MailMessage)
            ->subject(__('bookings::notifications.booking_confirmed.subject', ['reference' => $booking->reference]))
            ->greeting(__('bookings::notifications.booking_confirmed.greeting', ['name' => $notifiable->name]))
            ->line(__('bookings::notifications.booking_confirmed.intro', [
                'consultant' => $booking->consultant->name,
                'date' => $booking->starts_at->format('Y-m-d'),
                'time' => $booking->starts_at->format('H:i'),
                'package' => $booking->package->localizedName(),
            ]));

        if ($booking->location_snapshot) {
            $mail->line(__('bookings::notifications.booking_confirmed.location', [
                'location' => $booking->location_snapshot['name'] ?? '',
            ]));
        }

        if ($this->withLink && $booking->meeting_url) {
            $mail->action(
                __('bookings::notifications.booking_confirmed.join'),
                $booking->meeting_url,
            );
        } else {
            $mail->line(__('bookings::notifications.booking_confirmed.link_later'));
        }

        return $mail->line(__('bookings::notifications.booking_confirmed.amount', [
            'amount' => number_format($booking->amount / 100, 2).' '.$booking->currency,
        ]));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_confirmed',
            'booking_id' => $this->booking->id,
            'reference' => $this->booking->reference,
            'meeting_url' => $this->withLink ? $this->booking->meeting_url : null,
        ];
    }
}
