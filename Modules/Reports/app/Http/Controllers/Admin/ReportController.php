<?php

namespace Modules\Reports\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Bookings\Models\Booking;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Reports\Actions\UploadReportAction;
use Modules\Reports\Http\Requests\Admin\ReportIndexRequest;
use Modules\Reports\Http\Requests\Admin\UploadReportRequest;
use Modules\Reports\Http\Resources\ReportResource;
use Modules\Reports\Models\Report;
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
     * RPT-02 GET /api/v1/admin/reports/{report} — Perm: view-reports + policy
     */
    public function show(string $report): JsonResponse
    {
        $report = Report::query()->with(['booking', 'consultant', 'client'])->findOrFail($report);

        $this->authorize('view', $report);

        return $this->success(ReportResource::make($report));
    }

    /**
     * RPT-03 POST /api/v1/admin/bookings/{booking}/report (multipart)
     * Perm: upload-reports + BookingPolicy::uploadReport
     *
     * Creates the report or replaces it (§9.9). 201 on create, 200 on replace.
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

        $media = $report->getFirstMedia('report_file');
        abort_if($media === null, 404);

        return response()->download($media->getPath(), $media->file_name);
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

        $report->client->notify(new ReportReadyNotification($report));
        $report->forceFill(['client_notified_at' => now()])->save();

        return $this->success(
            ReportResource::make($report->refresh()->loadMissing(['booking', 'consultant', 'client'])),
            __('reports::messages.client_notified'),
        );
    }
}
