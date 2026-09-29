<?php

namespace Modules\Clients\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Clients\Models\SupportTicket;

/**
 * To staff (users with view-tickets) when a client adds a reply to a ticket.
 */
class TicketClientReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('clients::notifications.ticket_client_reply.subject', ['subject' => $this->ticket->subject]))
            ->greeting(__('clients::notifications.ticket_client_reply.greeting', ['name' => $notifiable->name]))
            ->line(__('clients::notifications.ticket_client_reply.intro', [
                'client' => $this->ticket->client->name,
                'subject' => $this->ticket->subject,
            ]))
            ->action(
                __('clients::notifications.ticket_client_reply.action'),
                rtrim((string) config('app.admin_frontend_url'), '/').'/admin/tickets'
            );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket_client_reply',
            'ticket_id' => $this->ticket->id,
            'subject' => $this->ticket->subject,
            'client_name' => $this->ticket->client->name,
        ];
    }
}
