<?php

namespace Modules\Users\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'reset_password',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('users::notifications.reset_password.subject'))
            ->line(__('users::notifications.reset_password.intro'))
            ->action(__('users::notifications.reset_password.action'), $this->url)
            ->line(__('users::notifications.reset_password.expire', ['count' => config('auth.passwords.users.expire')]))
            ->line(__('users::notifications.reset_password.outro'));
    }
}
