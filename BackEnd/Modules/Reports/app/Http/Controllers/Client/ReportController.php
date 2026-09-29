<?php

namespace Modules\Reports\Http\Controllers\Client;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Reports\Http\Resources\ReportResource;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends ApiController
{
    /**
     * CLI-RPT-01 GET /api/v1/client/reports
     *
     * Only the client's own reports. Query: search (title, consultant
     * name), date_from, date_to. download_url points to CLI-RPT-03.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $request->user('client')->reports()
            ->with(['booking', 'consultant', 'client', 'media'])
            ->when($request->query('date_from'), fn (Builder $q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($request->query('date_to'), fn (Builder $q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->latest();

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('consultant', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        $reports = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(ReportResource::collection($reports));
    }

    /**
     * CLI-RPT-02 GET /api/v1/client/reports/{report} — 404 if not his.
     */
    public function show(Request $request, string $report): JsonResponse
    {
        $report = $request->user('client')->reports()
            ->with(['booking', 'consultant', 'client'])
            ->findOrFail($report);

        return $this->success(ReportResource::make($report));
    }

    /**
     * CLI-RPT-03 GET /api/v1/client/reports/{report}/download
     *
     * Streams the file; the first download sets first_downloaded_at.
     */
    public function download(Request $request, string $report): BinaryFileResponse
    {
        $report = $request->user('client')->reports()->findOrFail($report);

        $media = $report->getFirstMedia('report_file');
        abort_if($media === null, 404);

        if ($report->first_downloaded_at === null) {
            $report->forceFill(['first_downloaded_at' => now()])->save();
        }

        return response()->download($media->getPath(), $media->file_name);
    }
}
