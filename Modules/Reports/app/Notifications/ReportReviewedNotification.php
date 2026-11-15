<?php

namespace Modules\Reports\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Reports\Enums\ReportApprovalStatus;
use Modules\Reports\Models\Report;

/**
 * To the consultant when an admin approves, rejects or returns his report.
 */
class ReportReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Report $report,
        public readonly ReportApprovalStatus $decision,
        public readonly ?string $note = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = 'reports::notifications.report_reviewed.'.$this->decision->value;

        $mail = (new MailMessage)
            ->subject(__('reports::notifications.report_reviewed.subject', ['title' => $this->report->title]))
            ->greeting(__('reports::notifications.report_reviewed.greeting', ['name' => $notifiable->name]))
            ->line(__("{$key}.line", ['title' => $this->report->title]));

        if ($this->note !== null && $this->note !== '') {
            $mail->line(__('reports::notifications.report_reviewed.note', ['note' => $this->note]));
        }

        return $mail->action(
            __('reports::notifications.report_reviewed.view'),
            rtrim((string) config('app.admin_frontend_url'), '/').'/reports/'.$this->report->id,
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report_reviewed',
            'report_id' => $this->report->id,
            'title' => $this->report->title,
            'decision' => $this->decision->value,
            'note' => $this->note,
        ];
    }
}
