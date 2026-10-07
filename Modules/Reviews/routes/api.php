<?php

use Illuminate\Support\Facades\Route;
use Modules\Reviews\Http\Controllers\ReviewsController;

Route::prefix('v1')->group(function (): void {
    Route::get('public/reviews', [ReviewsController::class, 'index'])->middleware('throttle:public')->name('public.reviews.index');
    Route::prefix('client/reviews')->name('client.reviews.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function (): void {
        Route::post('/', [ReviewsController::class, 'store'])->name('store');
        Route::get('/', [ReviewsController::class, 'mine'])->name('mine');
    });
    Route::prefix('admin/reviews')->name('admin.reviews.')->middleware(['auth:admin', 'active.user', 'throttle:api', 'permission:view-reviews,admin'])->group(function (): void {
        Route::get('/', [ReviewsController::class, 'adminIndex'])->name('index');
        Route::get('{review}', [ReviewsController::class, 'show'])->name('show');
        Route::patch('{review}/approve', [ReviewsController::class, 'approve'])->middleware('permission:manage-reviews,admin')->name('approve');
        Route::patch('{review}/reject', [ReviewsController::class, 'reject'])->middleware('permission:manage-reviews,admin')->name('reject');
    });
});
