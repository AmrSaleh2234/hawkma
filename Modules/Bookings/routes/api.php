<?php

use Illuminate\Support\Facades\Route;
use Modules\Bookings\Http\Controllers\Admin\BookingController as AdminBookingController;
use Modules\Bookings\Http\Controllers\Admin\BookingTransferController;
use Modules\Bookings\Http\Controllers\Client\BookingController;

Route::prefix('v1')->group(function () {
    Route::prefix('client/bookings')->name('client.bookings.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function () {
        Route::post('quote', [BookingController::class, 'quote'])->name('quote');
        Route::post('/', [BookingController::class, 'store'])->name('store');
        Route::get('/', [BookingController::class, 'index'])->name('index');
        Route::get('{booking}', [BookingController::class, 'show'])->name('show');
        Route::post('{booking}/cancel', [BookingController::class, 'cancel'])->name('cancel');
    });

    // Book a meeting inside an already-purchased package (CLI-BKG-06).
    Route::prefix('client/subscriptions')->name('client.subscriptions.bookings.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function () {
        Route::post('{subscription}/bookings', [BookingController::class, 'storeFromSubscription'])->name('store');
    });

    Route::prefix('admin/bookings')->name('admin.bookings.')->middleware(['auth:admin', 'active.user', 'throttle:api'])->group(function () {
        // Static segments before {booking} so they are not captured as an id.
        Route::get('calendar', [AdminBookingController::class, 'calendar'])
            ->middleware('permission:view-bookings,admin')
            ->name('calendar');

        Route::get('stats', [AdminBookingController::class, 'stats'])
            ->middleware('permission:view-bookings,admin')
            ->name('stats');

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

    // Consultant handoff requests (booking → another consultant).
    Route::prefix('admin/booking-transfers')->name('admin.booking-transfers.')->middleware(['auth:admin', 'active.user', 'throttle:api'])->group(function () {
        Route::get('targets', [BookingTransferController::class, 'targets'])
            ->middleware('permission:request-booking-transfers,admin')
            ->name('targets');
        Route::get('/', [BookingTransferController::class, 'index'])
            ->middleware('permission:view-booking-transfers,admin')
            ->name('index');
        Route::post('/', [BookingTransferController::class, 'store'])
            ->middleware('permission:request-booking-transfers,admin')
            ->name('store');
        Route::post('{transfer}/approve', [BookingTransferController::class, 'approve'])
            ->middleware('permission:manage-booking-transfers,admin')
            ->name('approve');
        Route::post('{transfer}/reject', [BookingTransferController::class, 'reject'])
            ->middleware('permission:manage-booking-transfers,admin')
            ->name('reject');
        Route::post('{transfer}/cancel', [BookingTransferController::class, 'cancel'])
            ->middleware('permission:request-booking-transfers,admin')
            ->name('cancel');
    });

    // TODO Phase 9: CLI-BKG-03..05 (client bookings).
});
