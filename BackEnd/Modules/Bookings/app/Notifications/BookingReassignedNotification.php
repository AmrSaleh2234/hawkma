<?php

namespace Modules\Bookings\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Bookings\Models\Booking;

/**
 * Sent when an admin reassigns a booking to another consultant. The
 * audience tailors the text: 'client' | 'old_consultant' | 'new_consultant'.
 */
class BookingReassignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly string $audience,
        public readonly string $oldConsultantName,
        public readonly string $newConsultantName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking;

        return (new MailMessage)
            ->subject(__('bookings::notifications.booking_reassigned.subject', ['reference' => $booking->reference]))
            ->greeting(__('bookings::notifications.booking_reassigned.greeting', ['name' => $notifiable->name]))
            ->line(__('bookings::notifications.booking_reassigned.intro.'.$this->audience, [
                'reference' => $booking->reference,
                'company' => $booking->client->company_name,
                'old' => $this->oldConsultantName,
                'new' => $this->newConsultantName,
                'date' => $booking->starts_at->format('Y-m-d'),
                'time' => $booking->starts_at->format('H:i'),
            ]));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_reassigned',
            'booking_id' => $this->booking->id,
            'reference' => $this->booking->reference,
            'old_consultant' => $this->oldConsultantName,
            'new_consultant' => $this->newConsultantName,
        ];
    }
}
