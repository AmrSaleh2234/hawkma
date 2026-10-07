<?php

use Illuminate\Support\Facades\Route;
use Modules\ActivityLogs\Http\Controllers\Admin\ActivityLogController;

Route::prefix('v1')->group(function () {
    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active.user', 'throttle:api'])->group(function () {
        Route::get('activity-logs/meta', [ActivityLogController::class, 'meta'])
            ->middleware('permission:view-activity-logs,admin')
            ->name('activity-logs.meta');
        Route::get('activity-logs', [ActivityLogController::class, 'index'])
            ->middleware('permission:view-activity-logs,admin')
            ->name('activity-logs.index');
    });
});
