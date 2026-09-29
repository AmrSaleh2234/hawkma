<?php

namespace Modules\Bookings\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Bookings\Models\Booking;

/**
 * To the consultant when a booking is confirmed: client company, date, time,
 * Meet link (§9.11).
 */
class NewBookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking;

        $mail = (new MailMessage)
            ->subject(__('bookings::notifications.new_booking.subject', ['reference' => $booking->reference]))
            ->greeting(__('bookings::notifications.new_booking.greeting', ['name' => $notifiable->name]))
            ->line(__('bookings::notifications.new_booking.intro', [
                'company' => $booking->client->company_name,
                'date' => $booking->starts_at->format('Y-m-d'),
                'time' => $booking->starts_at->format('H:i'),
            ]));

        if ($booking->meeting_url) {
            $mail->action(__('bookings::notifications.new_booking.join'), $booking->meeting_url);
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_booking',
            'booking_id' => $this->booking->id,
            'reference' => $this->booking->reference,
            'company_name' => $this->booking->client->company_name,
        ];
    }
}
