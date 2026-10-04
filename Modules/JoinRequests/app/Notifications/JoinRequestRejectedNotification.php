<?php

namespace Modules\JoinRequests\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\JoinRequests\Models\JoinRequest;

class JoinRequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly JoinRequest $joinRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('joinrequests::notifications.rejected.subject'))->greeting(__('joinrequests::notifications.rejected.greeting', ['name' => $this->joinRequest->name]))->line(__('joinrequests::notifications.rejected.intro'))->line(__('joinrequests::notifications.rejected.reason', ['reason' => $this->joinRequest->rejection_reason]));
    }
}
