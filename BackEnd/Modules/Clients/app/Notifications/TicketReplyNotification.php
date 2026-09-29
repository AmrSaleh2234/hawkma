<?php

namespace Modules\Clients\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Clients\Models\SupportTicket;

/**
 * To the ticket's client when a staff member replies on their ticket.
 */
class TicketReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly string $replierName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('clients::notifications.ticket_reply.subject', ['subject' => $this->ticket->subject]))
            ->greeting(__('clients::notifications.ticket_reply.greeting', ['name' => $notifiable->name]))
            ->line(__('clients::notifications.ticket_reply.intro', [
                'replier' => $this->replierName,
                'subject' => $this->ticket->subject,
            ]))
            ->action(
                __('clients::notifications.ticket_reply.action'),
                rtrim((string) config('app.client_frontend_url'), '/').'/tickets'
            );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket_reply',
            'ticket_id' => $this->ticket->id,
            'subject' => $this->ticket->subject,
            'replier_name' => $this->replierName,
        ];
    }
}
