<?php

namespace Modules\Notifications\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Modules\Notifications\Notifications\AdminActivityNotification;
use Modules\Users\Models\User;

/**
 * The admin activity feed. Whenever a notification is stored through the
 * `database` channel, a copy goes to every active staff user (type=admin)
 * holding the related `view-*` permission — so admins see everything
 * without changing how the existing modules send their notifications.
 *
 * Guards:
 * - only the `database` channel run (the event fires once per channel);
 * - never mirrors an AdminActivityNotification (no recursion);
 * - `reset_password` is private and never mirrored;
 * - types outside the map produce no copy.
 */
class MirrorNotificationsToAdmins
{
    /**
     * Semantic type → permission whose holders receive the mirror.
     *
     * @var array<string, string>
     */
    protected const AUDIENCE = [
        'new_booking' => 'view-bookings',
        'booking_confirmed' => 'view-bookings',
        'booking_cancelled' => 'view-bookings',
        'payment_failed' => 'view-payments',
        'report_ready' => 'view-reports',
        'client_welcome' => 'view-clients',
        'staff_account_created' => 'view-users',
    ];

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        if ($event->notification instanceof AdminActivityNotification) {
            return;
        }

        if (! $event->notifiable instanceof Model) {
            return;
        }

        $data = $event->notification->toArray($event->notifiable);
        $type = $data['type'] ?? null;

        $permission = is_string($type) ? (self::AUDIENCE[$type] ?? null) : null;

        if ($permission === null) {
            return;
        }

        $data['recipient'] = [
            'type' => $event->notifiable instanceof User ? 'user' : 'client',
            'id' => $event->notifiable->getKey(),
            'name' => $event->notifiable->getAttribute('company_name')
                ?? $event->notifiable->getAttribute('name'),
            'email' => $event->notifiable->getAttribute('email'),
        ];

        User::query()
            ->staff()
            ->active()
            ->permission($permission)
            ->each(fn (User $admin) => $admin->notify(new AdminActivityNotification($data)));
    }
}
