<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Admin\SettingsController;
use Modules\Core\Http\Controllers\MetaController;

Route::prefix('v1')->group(function () {
    Route::prefix('public')->name('public.')->middleware('throttle:public')->group(function () {
        Route::get('meta', MetaController::class)->name('meta');
    });

    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active.user', 'throttle:api'])->group(function () {
        Route::get('settings', [SettingsController::class, 'show'])
            ->middleware('permission:view-settings,admin')
            ->name('settings.show');
        Route::put('settings', [SettingsController::class, 'update'])
            ->middleware('permission:update-settings,admin')
            ->name('settings.update');
    });
});
