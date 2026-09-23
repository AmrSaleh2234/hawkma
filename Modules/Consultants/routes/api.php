<?php

use Illuminate\Support\Facades\Route;
use Modules\Consultants\Http\Controllers\Admin\ConsultantAvailabilityController;
use Modules\Consultants\Http\Controllers\Admin\ConsultantController;
use Modules\Consultants\Http\Controllers\Admin\ConsultantTimeOffController;
use Modules\Consultants\Http\Controllers\Admin\MyAvailabilityController;
use Modules\Consultants\Http\Controllers\Public\PublicConsultantController;

Route::prefix('v1')->group(function () {
    // Public wizard endpoints (no auth).
    Route::prefix('public')->name('public.')->middleware('throttle:60,1')->group(function () {
        Route::get('consultants', [PublicConsultantController::class, 'index'])->name('consultants.index');
        Route::get('consultants/{consultant}', [PublicConsultantController::class, 'show'])->name('consultants.show');
        Route::get('consultants/{consultant}/available-dates', [PublicConsultantController::class, 'availableDates'])->name('consultants.available-dates');
        Route::get('consultants/{consultant}/slots', [PublicConsultantController::class, 'slots'])->name('consultants.slots');
    });

    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active.user'])->group(function () {
        Route::get('consultants', [ConsultantController::class, 'index'])
            ->middleware('permission:view-consultants,admin')
            ->name('consultants.index');
        Route::post('consultants', [ConsultantController::class, 'store'])
            ->middleware('permission:create-consultants,admin')
            ->name('consultants.store');
        Route::get('consultants/{consultant}', [ConsultantController::class, 'show'])
            ->middleware('permission:view-consultants,admin')
            ->name('consultants.show');
        Route::put('consultants/{consultant}', [ConsultantController::class, 'update'])
            ->middleware('permission:update-consultants,admin')
            ->name('consultants.update');
        Route::delete('consultants/{consultant}', [ConsultantController::class, 'destroy'])
            ->middleware('permission:delete-consultants,admin')
            ->name('consultants.destroy');
        Route::patch('consultants/{consultant}/status', [ConsultantController::class, 'updateStatus'])
            ->middleware('permission:update-consultants,admin')
            ->name('consultants.status.update');
        Route::post('consultants/{consultant}/photo', [ConsultantController::class, 'storePhoto'])
            ->middleware('permission:update-consultants,admin')
            ->name('consultants.photo.store');

        Route::get('consultants/{consultant}/availability', [ConsultantAvailabilityController::class, 'show'])
            ->middleware('permission:view-availability,admin')
            ->name('consultants.availability.show');
        Route::put('consultants/{consultant}/availability', [ConsultantAvailabilityController::class, 'replace'])
            ->middleware('permission:manage-availability,admin')
            ->name('consultants.availability.replace');
        Route::get('consultants/{consultant}/slots', [ConsultantAvailabilityController::class, 'slots'])
            ->middleware('permission:view-availability,admin')
            ->name('consultants.slots');

        Route::get('consultants/{consultant}/time-offs', [ConsultantTimeOffController::class, 'index'])
            ->middleware('permission:view-availability,admin')
            ->name('consultants.time-offs.index');
        Route::post('consultants/{consultant}/time-offs', [ConsultantTimeOffController::class, 'store'])
            ->middleware('permission:manage-availability,admin')
            ->name('consultants.time-offs.store');
        Route::delete('consultants/{consultant}/time-offs/{timeOff}', [ConsultantTimeOffController::class, 'destroy'])
            ->middleware('permission:manage-availability,admin')
            ->name('consultants.time-offs.destroy')
            ->scopeBindings();

        // TODO Phase 9/10: CON-14..18 (clients, bookings, pending-reports,
        // reports, stats) via Admin/ConsultantRelationsController.

        // Consultant self-service (only type=consultant, else 403).
        Route::prefix('my')->name('my.')->group(function () {
            Route::get('availability', [MyAvailabilityController::class, 'showAvailability'])
                ->middleware('permission:view-availability,admin')
                ->name('availability.show');
            Route::put('availability', [MyAvailabilityController::class, 'replaceAvailability'])
                ->middleware('permission:manage-availability,admin')
                ->name('availability.replace');
            Route::get('time-offs', [MyAvailabilityController::class, 'timeOffs'])
                ->middleware('permission:view-availability,admin')
                ->name('time-offs.index');
            Route::post('time-offs', [MyAvailabilityController::class, 'storeTimeOff'])
                ->middleware('permission:manage-availability,admin')
                ->name('time-offs.store');
            Route::delete('time-offs/{timeOff}', [MyAvailabilityController::class, 'destroyTimeOff'])
                ->middleware('permission:manage-availability,admin')
                ->name('time-offs.destroy');
            Route::get('slots', [MyAvailabilityController::class, 'slots'])
                ->middleware('permission:view-availability,admin')
                ->name('slots');
        });
    });
});
