<?php

namespace Modules\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Notifications\Http\Resources\NotificationResource;
use Modules\Notifications\Services\NotificationService;

/**
 * The notification centre API — shared by the admin guard (staff and
 * consultants) and the client guard. Both route groups point here because
 * notifications are personal and never cross users, so the same logic
 * serves both. No permission middleware: like the profile endpoints,
 * a user can only ever see his own notifications.
 */
class NotificationController extends ApiController
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * GET …/notifications?unread=1&type=new_booking&per_page=&page=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'unread' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $notifications = $this->notifications->listFor($request->user(), [
            'unread' => $request->boolean('unread'),
            'type' => $request->query('type'),
        ], QueryFilters::perPage($request));

        return $this->paginated(NotificationResource::collection($notifications));
    }

    /**
     * GET …/notifications/unread-count — lightweight polling endpoint
     * for the bell badge.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success([
            'unread_count' => $this->notifications->unreadCount($request->user()),
        ]);
    }

    /**
     * PATCH …/notifications/{notification}/read
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $notification = $this->notifications->markRead($request->user(), $notification);

        return $this->success(
            NotificationResource::make($notification),
            __('notifications::messages.marked_read'),
        );
    }

    /**
     * POST …/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $marked = $this->notifications->markAllRead($request->user());

        return $this->success(
            ['marked_count' => $marked],
            __('notifications::messages.all_marked_read'),
        );
    }

    /**
     * DELETE …/notifications/{notification}
     */
    public function destroy(Request $request, string $notification): JsonResponse
    {
        $this->notifications->delete($request->user(), $notification);

        return $this->noContent(__('notifications::messages.deleted'));
    }
}
