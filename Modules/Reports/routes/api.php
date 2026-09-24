<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\Admin\ReportController as AdminReportController;
use Modules\Reports\Http\Controllers\Client\ReportController as ClientReportController;
use Modules\Reports\Http\Controllers\Public\SignedDownloadController;

Route::prefix('v1')->group(function () {
    // PUB-07: no auth — the signed URL authenticates it.
    Route::get('public/reports/{report}/signed-download', SignedDownloadController::class)
        ->middleware('signed')
        ->name('public.reports.signed-download');

    Route::prefix('client/reports')->name('client.reports.')->middleware(['auth:client', 'active.client'])->group(function () {
        Route::get('/', [ClientReportController::class, 'index'])->name('index');
        Route::get('{report}', [ClientReportController::class, 'show'])->name('show');
        Route::get('{report}/download', [ClientReportController::class, 'download'])->name('download');
    });

    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active.user'])->group(function () {
        // RPT-03 lives under /admin/bookings (the report belongs to a booking).
        Route::post('bookings/{booking}/report', [AdminReportController::class, 'upload'])
            ->middleware('permission:upload-reports,admin')
            ->name('reports.upload');

        Route::get('reports', [AdminReportController::class, 'index'])
            ->middleware('permission:view-reports,admin')
            ->name('reports.index');
        Route::get('reports/{report}', [AdminReportController::class, 'show'])
            ->middleware('permission:view-reports,admin')
            ->name('reports.show');
        Route::get('reports/{report}/download', [AdminReportController::class, 'download'])
            ->middleware('permission:download-reports,admin')
            ->name('reports.download');
        Route::delete('reports/{report}', [AdminReportController::class, 'destroy'])
            ->middleware('permission:delete-reports,admin')
            ->name('reports.destroy');
        Route::post('reports/{report}/notify-client', [AdminReportController::class, 'notifyClient'])
            ->middleware('permission:upload-reports,admin')
            ->name('reports.notify-client');
    });
});
