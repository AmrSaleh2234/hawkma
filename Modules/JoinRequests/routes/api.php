<?php

use Illuminate\Support\Facades\Route;
use Modules\JoinRequests\Http\Controllers\JoinRequestsController;

Route::prefix('v1')->group(function (): void {
    Route::post('public/join-requests', [JoinRequestsController::class, 'store'])->middleware('throttle:public')->name('public.join-requests.store');
    Route::prefix('admin/join-requests')->name('admin.join-requests.')->middleware(['auth:admin', 'active.user', 'throttle:api', 'permission:view-join-requests,admin'])->group(function (): void {
        Route::get('/', [JoinRequestsController::class, 'index'])->name('index');
        Route::get('{joinRequest}', [JoinRequestsController::class, 'show'])->name('show');
        Route::get('{joinRequest}/cv', [JoinRequestsController::class, 'downloadCv'])->name('cv');
        Route::patch('{joinRequest}/approve', [JoinRequestsController::class, 'approve'])->middleware('permission:manage-join-requests,admin')->name('approve');
        Route::patch('{joinRequest}/reject', [JoinRequestsController::class, 'reject'])->middleware('permission:manage-join-requests,admin')->name('reject');
    });
});
