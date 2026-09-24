<?php

namespace Modules\Reports\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Modules\Reports\Models\Report;

/**
 * To the client when a report is uploaded (§9.11): a dashboard link and a
 * 7-day signed download link (§9.9.5).
 */
class ReportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Report $report) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $signedUrl = URL::temporarySignedRoute(
            'public.reports.signed-download',
            now()->addDays(7),
            ['report' => $this->report->id],
        );

        return (new MailMessage)
            ->subject(__('reports::notifications.report_ready.subject', ['title' => $this->report->title]))
            ->greeting(__('reports::notifications.report_ready.greeting', ['name' => $notifiable->name]))
            ->line(__('reports::notifications.report_ready.intro', [
                'title' => $this->report->title,
                'consultant' => $this->report->consultant->name,
            ]))
            ->action(
                __('reports::notifications.report_ready.view'),
                rtrim((string) config('app.client_frontend_url'), '/').'/reports/'.$this->report->id,
            )
            ->line(__('reports::notifications.report_ready.direct_download'))
            ->line($signedUrl);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report_ready',
            'report_id' => $this->report->id,
            'title' => $this->report->title,
        ];
    }
}
