<?php

namespace Modules\SupportTickets\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\SupportTickets\Models\SupportTicket;

class SupportTicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SupportTicket $ticket, public readonly string $event) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'support_ticket_'.$this->event, 'ticket_id' => $this->ticket->id, 'reference' => $this->ticket->reference, 'category' => $this->ticket->category->value];
    }
}
