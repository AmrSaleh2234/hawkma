<?php

namespace Modules\Reports\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Services\BookingStateMachine;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Reports\Enums\ReportApprovalStatus;
use Modules\Reports\Models\Report;
use Modules\Users\Models\User;

/**
 * The report flow: one report per booking; every upload adds a new file
 * version (kept for the review history) and puts the report back into
 * `pending_review`. The client is only notified once an admin approves the
 * report — never on upload.
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

        return DB::transaction(function () use ($booking, $by, $data, $file): array {
            $report = Report::query()->updateOrCreate(
                ['booking_id' => $booking->id],
                [
                    'consultant_id' => $booking->consultant_id,
                    'client_id' => $booking->client_id,
                    'title' => $data['title'],
                    'summary' => $data['summary'] ?? null,
                    'uploaded_by' => $by->id,
                    'approval_status' => ReportApprovalStatus::PendingReview,
                    'review_note' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ],
            );

            $created = $report->wasRecentlyCreated;

            // Every submission is a new version: the file is appended to the
            // collection and recorded in the review history.
            $media = $report->addMedia($file)->toMediaCollection('report_file');
            $report->forceFill(['current_media_id' => $media->id])->save();

            $report->reviews()->create([
                'status' => ReportApprovalStatus::PendingReview,
                'note' => $data['note'] ?? null,
                'media_id' => $media->id,
                'actor_id' => $by->id,
            ]);

            $this->stateMachine->markReportUploaded($booking);

            return ['report' => $report->refresh(), 'created' => $created];
        });
    }
}
