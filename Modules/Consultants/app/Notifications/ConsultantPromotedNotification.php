<?php

namespace Modules\Consultants\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConsultantPromotedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $email,
        public readonly string $password,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('consultants::training.promoted_email_subject'))
            ->greeting(__('consultants::training.promoted_email_greeting', ['name' => $notifiable->name]))
            ->line(__('consultants::training.promoted_email_intro'))
            ->line(__('consultants::training.promoted_email_email') . ': ' . $this->email)
            ->line(__('consultants::training.promoted_email_password') . ': ' . $this->password)
            ->action(__('consultants::training.promoted_email_login'), config('app.admin_frontend_url') . '/login')
            ->line(__('consultants::training.promoted_email_outro'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'consultant_promoted',
            'email' => $this->email,
        ];
    }
}
