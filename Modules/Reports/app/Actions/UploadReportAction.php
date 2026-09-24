<?php

namespace Modules\Reports\Actions;

use Illuminate\Http\UploadedFile;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Services\BookingStateMachine;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Reports\Models\Report;
use Modules\Reports\Notifications\ReportReadyNotification;
use Modules\Users\Models\User;

/**
 * The report flow (plan §9.9): one report per booking; uploading again
 * replaces the file (single-file collection).
 */
class UploadReportAction
{
    public function __construct(protected BookingStateMachine $stateMachine) {}

    /**
     * @param  array<string, mixed>  $data  Validated UploadReportRequest data.
     * @return array{report: Report, created: bool}
     */
    public function execute(Booking $booking, User $by, array $data, UploadedFile $file): array
    {
        if ($booking->status !== BookingStatus::Completed) {
            throw new BusinessException(ErrorCode::ReportNotAllowed);
        }

        $report = Report::query()->updateOrCreate(
            ['booking_id' => $booking->id],
            [
                'consultant_id' => $booking->consultant_id,
                'client_id' => $booking->client_id,
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'uploaded_by' => $by->id,
            ],
        );

        $created = $report->wasRecentlyCreated;

        // singleFile() replaces the old file.
        $report->addMedia($file)->toMediaCollection('report_file');

        $this->stateMachine->markReportUploaded($booking);

        // On a replace the client is notified again only when asked (§9.9.4).
        $notify = (bool) ($data['notify_client'] ?? true);
        if ($created || $notify) {
            $report->client->notify(new ReportReadyNotification($report));
            $report->forceFill(['client_notified_at' => now()])->save();
        }

        return ['report' => $report->refresh(), 'created' => $created];
    }
}
