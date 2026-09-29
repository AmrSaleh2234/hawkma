<?php

namespace Modules\Clients\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Clients\Models\SupportTicket;

/**
 * To staff (users with view-tickets) when a client opens a new support ticket.
 */
class NewTicketNotification extends Notification implements ShouldQueue
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
            ->subject(__('clients::notifications.new_ticket.subject', ['subject' => $this->ticket->subject]))
            ->greeting(__('clients::notifications.new_ticket.greeting', ['name' => $notifiable->name]))
            ->line(__('clients::notifications.new_ticket.intro', [
                'client' => $this->ticket->client->name,
                'type' => __('clients::tickets.type.'.$this->ticket->type),
                'subject' => $this->ticket->subject,
            ]))
            ->action(
                __('clients::notifications.new_ticket.action'),
                rtrim((string) config('app.admin_frontend_url'), '/').'/admin/tickets'
            );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_ticket',
            'ticket_id' => $this->ticket->id,
            'subject' => $this->ticket->subject,
            'client_name' => $this->ticket->client->name,
            'ticket_type' => $this->ticket->type,
        ];
    }
}
