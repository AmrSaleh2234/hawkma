<?php

namespace Modules\Clients\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('clients::notifications.welcome.subject'))
            ->greeting(__('clients::notifications.welcome.greeting', ['name' => $notifiable->name]))
            ->line(__('clients::notifications.welcome.intro'))
            ->action(
                __('clients::notifications.welcome.action'),
                rtrim((string) config('app.client_frontend_url'), '/')
            );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'client_welcome',
        ];
    }
}
