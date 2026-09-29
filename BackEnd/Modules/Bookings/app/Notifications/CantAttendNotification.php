<?php

namespace Modules\Bookings\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Bookings\Models\Booking;

/**
 * To the admin staff when a consultant raises a "can't attend" request:
 * booking reference, consultant, date, time and the reason.
 */
class CantAttendNotification extends Notification implements ShouldQueue
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

        return (new MailMessage)
            ->subject(__('bookings::notifications.cant_attend.subject', ['reference' => $booking->reference]))
            ->greeting(__('bookings::notifications.cant_attend.greeting', ['name' => $notifiable->name]))
            ->line(__('bookings::notifications.cant_attend.intro', [
                'consultant' => $booking->consultant->name,
                'reference' => $booking->reference,
                'date' => $booking->starts_at->format('Y-m-d'),
                'time' => $booking->starts_at->format('H:i'),
            ]))
            ->line(__('bookings::notifications.cant_attend.reason', [
                'reason' => $booking->reassign_reason ?? '-',
            ]));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'cant_attend',
            'booking_id' => $this->booking->id,
            'reference' => $this->booking->reference,
            'consultant' => $this->booking->consultant->name,
            'reason' => $this->booking->reassign_reason,
        ];
    }
}
