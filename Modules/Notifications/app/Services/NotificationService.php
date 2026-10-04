<?php

namespace Modules\Notifications\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Reads the `notifications` table (the database channel) for a given
 * notifiable — admin user or client. Notifications are strictly personal:
 * every method is scoped through the `$notifiable->notifications()` morph
 * relation so no one can read, mark or delete someone else's notification.
 */
class NotificationService
{
    /**
     * Paginated list, newest first.
     *
     * Supported filters:
     * - `unread=1`  → only unread notifications
     * - `type=...`  → the semantic type stored inside data->type
     *                 (e.g. new_booking, report_ready, payment_failed)
     * - `per_page`  → QueryFilters::perPage (max 100)
     *
     * @param  array<string, mixed>  $filters
     */
    public function listFor(Model $notifiable, array $filters, int $perPage): LengthAwarePaginator
    {
        return $notifiable->notifications()
            ->when($filters['unread'] ?? false, fn ($q) => $q->whereNull('read_at'))
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('data->type', $type))
            ->latest()
            ->paginate($perPage);
    }

    public function unreadCount(Model $notifiable): int
    {
        return $notifiable->unreadNotifications()->count();
    }

    /**
     * 404 when the notification does not belong to the notifiable.
     */
    public function findOwned(Model $notifiable, string $id): DatabaseNotification
    {
        return $notifiable->notifications()->findOrFail($id);
    }

    public function markRead(Model $notifiable, string $id): DatabaseNotification
    {
        $notification = $this->findOwned($notifiable, $id);
        $notification->markAsRead();

        return $notification;
    }

    /**
     * @return int how many notifications were marked
     */
    public function markAllRead(Model $notifiable): int
    {
        return $notifiable->unreadNotifications()->update(['read_at' => now()]);
    }

    public function delete(Model $notifiable, string $id): void
    {
        $this->findOwned($notifiable, $id)->delete();
    }
}
