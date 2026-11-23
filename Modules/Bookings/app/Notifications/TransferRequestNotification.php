<?php

namespace Modules\Bookings\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Bookings\Models\BookingTransferRequest;

/**
 * Transfer-request lifecycle emails/db rows. $event is one of
 * 'requested' (to admins), 'approved' (to client + both consultants) or
 * 'rejected' (to the requesting consultant).
 */
class TransferRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly BookingTransferRequest $transfer,
        public readonly string $event,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = $this->transfer;
        $booking = $t->booking;

        $mail = (new MailMessage)
            ->subject(__('bookings::notifications.transfer_'.$this->event.'.subject', [
                'reference' => $booking->reference,
            ]))
            ->greeting(__('bookings::notifications.transfer_'.$this->event.'.greeting', [
                'name' => $notifiable->name,
            ]))
            ->line(__('bookings::notifications.transfer_'.$this->event.'.intro', [
                'reference' => $booking->reference,
                'from' => $t->fromConsultant->name,
                'to' => $t->toConsultant->name,
                'company' => $booking->client?->company_name ?? $booking->client?->name,
                'date' => $booking->starts_at->format('Y-m-d'),
                'time' => $booking->starts_at->format('H:i'),
                'reason' => $t->reason,
                'note' => $t->decision_note,
            ]));

        if ($this->event === 'approved' && $booking->meeting_url) {
            $mail->action(__('bookings::notifications.new_booking.join'), $booking->meeting_url);
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'transfer_'.$this->event,
            'transfer_id' => $this->transfer->id,
            'booking_id' => $this->transfer->booking_id,
            'reference' => $this->transfer->booking->reference,
        ];
    }
}
