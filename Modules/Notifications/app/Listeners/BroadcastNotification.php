<?php

namespace Modules\Notifications\Listeners;

use Illuminate\Notifications\Events\NotificationSent;
use Modules\Clients\Models\Client;
use Modules\Notifications\Events\NotificationReceived;
use Modules\Users\Models\User;

/**
 * Pushes every stored notification to the notifiable's private WebSocket
 * channel. Listening on NotificationSent (like MirrorNotificationsToAdmins)
 * means zero changes to the ~14 notification classes — database stays the
 * source of truth, WebSocket is the live delivery layer on top.
 *
 * Guards:
 * - only the `database` channel run (the event fires once per channel);
 * - non-User/Client notifiables (e.g. on-demand) are skipped.
 */
class BroadcastNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        if (! $event->notifiable instanceof User && ! $event->notifiable instanceof Client) {
            return;
        }

        $channel = $event->notifiable instanceof User ? 'admin' : 'client';
        $data = $event->notification->toArray($event->notifiable);

        NotificationReceived::dispatch(
            $event->notifiable,
            [
                'id' => $event->notification->id,
                'type' => $data['type'] ?? null,
                'data' => $data,
                'read_at' => null,
                'created_at' => now()->toIso8601String(),
            ],
            "{$channel}.{$event->notifiable->getKey()}",
        );
    }
}
