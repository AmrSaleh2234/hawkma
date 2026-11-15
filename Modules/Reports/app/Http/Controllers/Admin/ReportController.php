<?php

namespace Modules\Reports\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Bookings\Models\Booking;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Reports\Actions\ReviewReportAction;
use Modules\Reports\Actions\UploadReportAction;
use Modules\Reports\Http\Requests\Admin\ReportIndexRequest;
use Modules\Reports\Http\Requests\Admin\ReviewReportRequest;
use Modules\Reports\Http\Requests\Admin\UploadReportRequest;
use Modules\Reports\Http\Resources\ReportResource;
use Modules\Reports\Http\Resources\ReportReviewResource;
use Modules\Reports\Models\Report;
use Modules\Reports\Models\ReportReview;
use Modules\Reports\Notifications\ReportReadyNotification;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends ApiController
{
    /**
     * RPT-01 GET /api/v1/admin/reports — Perm: view-reports
     *
     * The "Reports" page: each row shows the consultant who made it, the
     * client it is for, and a download link. Scoped for consultants.
     */
    public function index(ReportIndexRequest $request): JsonResponse
    {
        $user = $request->user('admin');

        $filters = $request->validated();
        if ($user->isConsultant()) {
            unset($filters['consultant_id']); // ignored for consultants
        }

        $query = Report::query()
            ->with(['booking', 'consultant', 'client', 'media'])
            ->visibleTo($user)
            ->when($filters['consultant_id'] ?? null, fn (Builder $q, $id) => $q->where('consultant_id', $id))
            ->when($filters['client_id'] ?? null, fn (Builder $q, $id) => $q->where('client_id', $id))
            ->when($filters['approval_status'] ?? null, fn (Builder $q, $status) => $q->where('approval_status', $status))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'));

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('booking', fn (Builder $b) => $b->where('reference', 'like', "%{$search}%"))
                    ->orWhereHas('client', fn (Builder $c) => $c->where('company_name', 'like', "%{$search}%"))
                    ->orWhereHas('consultant', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $sort = (string) ($filters['sort'] ?? '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (! in_array($column, ['created_at', 'title'], true)) {
            $column = 'created_at';
            $direction = 'desc';
        }
        $query->orderBy($column, $direction);

        $reports = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(ReportResource::collection($reports));
    }

    /**
     * RPT-01b GET /api/v1/admin/reports/stats — Perm: view-reports
     *
     * Aggregated counts for the reports page charts and summary cards.
     * `pending` = completed bookings still missing a report; `uploaded` =
     * actual Report records, so `total` = pending + uploaded.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user('admin');

        $pending = Booking::query()
            ->visibleTo($user)
            ->where('status', BookingStatus::Completed)
            ->where('report_status', ReportStatus::Pending)
            ->count();

        $uploaded = Report::query()->visibleTo($user)->count();

        $byMonth = Report::query()
            ->visibleTo($user)
            ->selectRaw(QueryFilters::monthExpression('created_at').' as month, COUNT(*) as count')
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->groupByRaw('month')
            ->orderByRaw('month')
            ->get()
            ->map(fn ($row) => ['month' => $row->month, 'count' => (int) $row->count])
            ->all();

        $byConsultant = Report::query()
            ->visibleTo($user)
            ->select('consultant_id', DB::raw('COUNT(*) as count'))
            ->whereNotNull('consultant_id')
            ->with('consultant:id,name')
            ->groupBy('consultant_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'consultant_id' => $row->consultant_id,
                'consultant_name' => $row->consultant?->name,
                'count' => (int) $row->count,
            ])
            ->all();

        return $this->success([
            'total' => $pending + $uploaded,
            'pending' => $pending,
            'uploaded' => $uploaded,
            'by_month' => $byMonth,
            'by_consultant' => $byConsultant,
        ]);
    }

    /**
     * RPT-01c GET /api/v1/admin/reports/inbox — Perm: approve-reports
     *
     * The review inbox: reports waiting for an admin decision, oldest
     * submission first.
     */
    public function inbox(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $reports = Report::query()
            ->with(['booking', 'consultant', 'client', 'media'])
            ->awaitingReview()
            ->oldest()
            ->paginate(QueryFilters::perPage($request));

        return $this->paginated(ReportResource::collection($reports));
    }

    /**
     * RPT-02 GET /api/v1/admin/reports/{report} — Perm: view-reports + policy
     */
    public function show(string $report): JsonResponse
    {
        $report = Report::query()->with(['booking', 'consultant', 'client', 'reviewer'])->findOrFail($report);

        $this->authorize('view', $report);

        return $this->success(ReportResource::make($report));
    }

    /**
     * RPT-02b GET /api/v1/admin/reports/{report}/history
     * Perm: view-reports + policy
     *
     * The full review trail: every submission and decision, each pointing
     * at the file version of that step.
     */
    public function history(string $report): JsonResponse
    {
        $report = Report::query()->findOrFail($report);
        $this->authorize('viewHistory', $report);

        $reviews = $report->reviews()->with(['actor', 'media'])->get();

        return $this->success(ReportReviewResource::collection($reviews));
    }

    /**
     * RPT-02c GET /api/v1/admin/report-reviews/{review}/download
     * Perm: download-reports + policy
     *
     * Streams the file version attached to one history entry.
     */
    public function downloadReviewFile(string $review): BinaryFileResponse
    {
        $review = ReportReview::query()->with('report')->findOrFail($review);
        $this->authorize('download', $review->report);

        $media = $review->media;
        abort_if($media === null, 404);

        return response()->download($media->getPath(), $media->file_name);
    }

    /**
     * RPT-03 POST /api/v1/admin/bookings/{booking}/report (multipart)
     * Perm: upload-reports + BookingPolicy::uploadReport
     *
     * Creates the report or submits a new version: the report goes to
     * pending_review and the client is only notified on approval.
     * 201 on create, 200 on resubmission.
     */
    public function upload(UploadReportRequest $request, string $booking, UploadReportAction $action): JsonResponse
    {
        $booking = Booking::query()->findOrFail($booking);
        $this->authorize('uploadReport', $booking);

        $result = $action->execute(
            $booking,
            $request->user('admin'),
            $request->validated(),
            $request->file('file'),
        );

        $report = $result['report']->loadMissing(['booking', 'consultant', 'client']);

        return $result['created']
            ? $this->created(ReportResource::make($report), __('reports::messages.report_uploaded'))
            : $this->success(ReportResource::make($report), __('reports::messages.report_uploaded'));
    }

    /**
     * RPT-04 GET /api/v1/admin/reports/{report}/download
     * Perm: download-reports + policy
     */
    public function download(string $report): BinaryFileResponse
    {
        $report = Report::query()->findOrFail($report);
        $this->authorize('download', $report);

        $media = $report->currentMedia();
        abort_if($media === null, 404);

        return response()->download($media->getPath(), $media->file_name);
    }

    /**
     * RPT-04a POST /api/v1/admin/reports/{report}/approve
     * Perm: approve-reports + policy (admin only)
     *
     * Approves the report: the client sees it and gets the ready email.
     */
    public function approve(ReviewReportRequest $request, string $report, ReviewReportAction $action): JsonResponse
    {
        $report = Report::query()->findOrFail($report);
        $this->authorize('review', $report);

        $report = $action->approve($report, $request->user('admin'), $request->input('note'));

        return $this->success(
            ReportResource::make($report->loadMissing(['booking', 'consultant', 'client', 'reviewer'])),
            __('core::messages.report_approved'),
        );
    }

    /**
     * RPT-04b POST /api/v1/admin/reports/{report}/reject
     * Perm: approve-reports + policy (admin only)
     */
    public function reject(ReviewReportRequest $request, string $report, ReviewReportAction $action): JsonResponse
    {
        $report = Report::query()->findOrFail($report);
        $this->authorize('review', $report);

        $report = $action->reject($report, $request->user('admin'), $request->input('note'));

        return $this->success(
            ReportResource::make($report->loadMissing(['booking', 'consultant', 'client', 'reviewer'])),
            __('core::messages.report_rejected'),
        );
    }

    /**
     * RPT-04c POST /api/v1/admin/reports/{report}/return
     * Perm: approve-reports + policy (admin only)
     *
     * Returns the report to the consultant for revision (the note tells him
     * what to fix); the booking asks for a report again.
     */
    public function returnForRevision(ReviewReportRequest $request, string $report, ReviewReportAction $action): JsonResponse
    {
        $report = Report::query()->findOrFail($report);
        $this->authorize('review', $report);

        $report = $action->returnForRevision($report, $request->user('admin'), $request->input('note'));

        return $this->success(
            ReportResource::make($report->loadMissing(['booking', 'consultant', 'client', 'reviewer'])),
            __('core::messages.report_returned'),
        );
    }

    /**
     * RPT-05 DELETE /api/v1/admin/reports/{report} — Perm: delete-reports
     *
     * Deletes the report and its media; the booking report_status goes back
     * to pending.
     */
    public function destroy(string $report): JsonResponse
    {
        $report = Report::query()->findOrFail($report);
        $this->authorize('delete', $report);

        DB::transaction(function () use ($report): void {
            $booking = $report->booking;

            $report->clearMediaCollection('report_file');
            $report->delete();

            if ($booking !== null && $booking->report_status === ReportStatus::Uploaded) {
                $booking->forceFill(['report_status' => ReportStatus::Pending])->save();
            }
        });

        return $this->noContent(__('reports::messages.report_deleted'));
    }

    /**
     * RPT-06 POST /api/v1/admin/reports/{report}/notify-client
     * Perm: upload-reports + policy
     */
    public function notifyClient(string $report): JsonResponse
    {
        $report = Report::query()->findOrFail($report);
        $this->authorize('notifyClient', $report);

        if (! $report->isApproved()) {
            throw new BusinessException(ErrorCode::ReportReviewNotAllowed);
        }

        $report->client->notify(new ReportReadyNotification($report));
        $report->forceFill(['client_notified_at' => now()])->save();

        return $this->success(
            ReportResource::make($report->refresh()->loadMissing(['booking', 'consultant', 'client'])),
            __('reports::messages.client_notified'),
        );
    }
}
