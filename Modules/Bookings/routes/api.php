<?php

use Illuminate\Support\Facades\Route;
use Modules\Bookings\Http\Controllers\Admin\BookingController as AdminBookingController;
use Modules\Bookings\Http\Controllers\Client\BookingController;

Route::prefix('v1')->group(function () {
    Route::prefix('client/bookings')->name('client.bookings.')->middleware(['auth:client', 'active.client'])->group(function () {
        Route::post('quote', [BookingController::class, 'quote'])->name('quote');
        Route::post('/', [BookingController::class, 'store'])->name('store');
        Route::get('/', [BookingController::class, 'index'])->name('index');
        Route::get('{booking}', [BookingController::class, 'show'])->name('show');
        Route::post('{booking}/cancel', [BookingController::class, 'cancel'])->name('cancel');
    });

    Route::prefix('admin/bookings')->name('admin.bookings.')->middleware(['auth:admin', 'active.user'])->group(function () {
        // Static segments before {booking} so they are not captured as an id.
        Route::get('calendar', [AdminBookingController::class, 'calendar'])
            ->middleware('permission:view-bookings,admin')
            ->name('calendar');

        Route::get('/', [AdminBookingController::class, 'index'])
            ->middleware('permission:view-bookings,admin')
            ->name('index');
        Route::get('{booking}', [AdminBookingController::class, 'show'])
            ->middleware('permission:view-bookings,admin')
            ->name('show');
        Route::post('{booking}/complete', [AdminBookingController::class, 'complete'])
            ->middleware('permission:complete-bookings,admin')
            ->name('complete');
        Route::post('{booking}/cancel', [AdminBookingController::class, 'cancel'])
            ->middleware('permission:cancel-bookings,admin')
            ->name('cancel');
        Route::post('{booking}/meeting', [AdminBookingController::class, 'regenerateMeeting'])
            ->middleware('permission:manage-meetings,admin')
            ->name('meeting.regenerate');
        Route::post('{booking}/mark-refunded', [AdminBookingController::class, 'markRefunded'])
            ->middleware('permission:refund-payments,admin')
            ->name('mark-refunded');
    });

    // TODO Phase 9: CLI-BKG-03..05 (client bookings).
});
