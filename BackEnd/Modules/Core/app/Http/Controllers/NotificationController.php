<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * In-app notifications inbox for both guards (client + admin/consultant).
 * Notifications are stored via Laravel's `database` channel; this controller
 * only exposes read/mark-read endpoints — nothing is created here.
 */
class NotificationController extends ApiController
{
    /**
     * GET /api/v1/{client|admin}/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->notifiable($request);

        $paginator = $user->notifications()
            ->latest()
            ->paginate(min(max($request->integer('per_page', 15), 1), 50));

        $paginator->getCollection()->transform(
            fn (DatabaseNotification $notification) => $this->serialize($notification),
        );

        return $this->paginated($paginator);
    }

    /**
     * GET /api/v1/{client|admin}/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success([
            'unread_count' => $this->notifiable($request)->unreadNotifications()->count(),
        ]);
    }

    /**
     * POST /api/v1/{client|admin}/notifications/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->notifiable($request)
            ->notifications()
            ->where('id', $id)
            ->firstOrFail();

        $notification->markAsRead();

        return $this->success($this->serialize($notification->fresh()), __('core::messages.notification_read'));
    }

    /**
     * POST /api/v1/{client|admin}/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $this->notifiable($request)->unreadNotifications->markAsRead();

        return $this->noContent(__('core::messages.notifications_all_read'));
    }

    protected function notifiable(Request $request): mixed
    {
        return $request->user('client') ?? $request->user('admin') ?? $request->user();
    }

    protected function serialize(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->data['type'] ?? class_basename($notification->type),
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
