<?php

namespace Modules\Notifications\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A database-only copy of a domain notification, written to admin users so
 * staff see all relevant activity in their notification centre.
 *
 * The payload is the original notification's `toArray()` enriched with
 * `recipient` (who got the original notification). `data.type` keeps the
 * original semantic type (e.g. `new_booking`) so the admin frontend can
 * reuse the same type → icon → details-page mapping as everyone else.
 */
class AdminActivityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->data;
    }
}
