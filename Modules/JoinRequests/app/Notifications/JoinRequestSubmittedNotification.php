<?php

namespace Modules\JoinRequests\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\JoinRequests\Models\JoinRequest;

class JoinRequestSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly JoinRequest $joinRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'join_request_submitted', 'join_request_id' => $this->joinRequest->id, 'name' => $this->joinRequest->name, 'specialization' => $this->joinRequest->specialization];
    }
}
