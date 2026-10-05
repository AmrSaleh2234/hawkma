<?php

namespace Modules\Notifications\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Notifications\Notification;
use Modules\Notifications\Listeners\BroadcastNotification;

/**
 * Real-time twin of a stored database notification.
 *
 * Dispatched by {@see BroadcastNotification}
 * right after the database row is written, carrying the exact payload shape
 * of NotificationResource ({id, type, data, read_at, created_at}) so the
 * frontend can insert it into the bell without an extra request.
 *
 * `notification.id` matches the `notifications` table primary key because
 * the NotificationSender assigns the uuid before any channel runs.
 */
class NotificationReceived implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $notification
     */
    public function __construct(
        public readonly Model $notifiable,
        public readonly array $notification,
        protected readonly string $channel,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    /**
     * Dot-style name so Echo clients can listen via `.notification.received`
     * on the private channel.
     */
    public function broadcastAs(): string
    {
        return 'notification.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'notification' => $this->notification,
        ];
    }
}
