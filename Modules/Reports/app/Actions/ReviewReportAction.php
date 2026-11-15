<?php

namespace Modules\Reports\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Reports\Enums\ReportApprovalStatus;
use Modules\Reports\Models\Report;
use Modules\Reports\Notifications\ReportReadyNotification;
use Modules\Reports\Notifications\ReportReviewedNotification;
use Modules\Users\Models\User;

/**
 * The admin decisions on a submitted report. Every decision writes an
 * immutable ReportReview history entry (with the file version that was
 * reviewed) and notifies the consultant.
 *
 * - approve: the client sees + is emailed the report.
 * - reject:  the report is dead; the booking goes back to report pending.
 * - return:  the consultant revises and re-uploads; also back to pending.
 */
class ReviewReportAction
{
    public function approve(Report $report, User $by, ?string $note = null): Report
    {
        return $this->decide($report, $by, ReportApprovalStatus::Approved, $note);
    }

    public function reject(Report $report, User $by, ?string $note = null): Report
    {
        return $this->decide($report, $by, ReportApprovalStatus::Rejected, $note);
    }

    public function returnForRevision(Report $report, User $by, ?string $note = null): Report
    {
        return $this->decide($report, $by, ReportApprovalStatus::Returned, $note);
    }

    protected function decide(Report $report, User $by, ReportApprovalStatus $decision, ?string $note): Report
    {
        $report = DB::transaction(function () use ($report, $by, $decision, $note): Report {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);

            if (! $locked->isPendingReview()) {
                throw new BusinessException(ErrorCode::ReportReviewNotAllowed);
            }

            $locked->forceFill([
                'approval_status' => $decision,
                'review_note' => $note,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
            ])->save();

            $locked->reviews()->create([
                'status' => $decision,
                'note' => $note,
                'media_id' => $locked->current_media_id,
                'actor_id' => $by->id,
            ]);

            // On reject/return the booking asks for a report again.
            if ($decision !== ReportApprovalStatus::Approved && $locked->booking !== null) {
                $locked->booking->forceFill(['report_status' => ReportStatus::Pending])->save();
            }

            return $locked->refresh();
        });

        $report->consultant->notify(new ReportReviewedNotification($report, $decision, $note));

        // Approval is the only moment the client learns about the report.
        if ($decision === ReportApprovalStatus::Approved) {
            $report->client->notify(new ReportReadyNotification($report));
            $report->forceFill(['client_notified_at' => now()])->save();
        }

        return $report;
    }
}
