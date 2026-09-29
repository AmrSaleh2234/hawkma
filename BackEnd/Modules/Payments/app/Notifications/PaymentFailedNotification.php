<?php

namespace Modules\Payments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Bookings\Models\Booking;

/**
 * To the client when a payment fails: a retry link to the wizard (§9.11).
 */
class PaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('payments::notifications.payment_failed.subject', ['reference' => $this->booking->reference]))
            ->greeting(__('payments::notifications.payment_failed.greeting', ['name' => $notifiable->name]))
            ->line(__('payments::notifications.payment_failed.intro', ['reference' => $this->booking->reference]))
            ->action(
                __('payments::notifications.payment_failed.retry'),
                rtrim((string) config('app.client_frontend_url'), '/').'/bookings/new',
            );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_failed',
            'booking_id' => $this->booking->id,
            'reference' => $this->booking->reference,
        ];
    }
}
