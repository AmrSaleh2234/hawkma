<?php

namespace Modules\Reports\Http\Controllers\Public;

use Modules\Core\Http\Controllers\ApiController;
use Modules\Reports\Models\Report;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SignedDownloadController extends ApiController
{
    /**
     * PUB-07 GET /api/v1/public/reports/{report}/signed-download
     *
     * Middleware `signed` rejects invalid/expired signatures with 403.
     * Streams the file and sets first_downloaded_at if it is empty.
     */
    public function __invoke(string $report): BinaryFileResponse
    {
        $report = Report::query()->findOrFail($report);

        $media = $report->getFirstMedia('report_file');
        abort_if($media === null, 404);

        if ($report->first_downloaded_at === null) {
            $report->forceFill(['first_downloaded_at' => now()])->save();
        }

        return response()->download($media->getPath(), $media->file_name);
    }
}
