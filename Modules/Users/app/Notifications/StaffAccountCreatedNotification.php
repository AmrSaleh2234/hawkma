<?php

namespace Modules\Users\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffAccountCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $email,
        public readonly string $setPasswordUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('users::notifications.staff_account_created.subject'))
            ->line(__('users::notifications.staff_account_created.intro'))
            ->line($this->email)
            ->action(__('users::notifications.staff_account_created.action'), $this->setPasswordUrl);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'staff_account_created',
            'email' => $this->email,
        ];
    }
}
