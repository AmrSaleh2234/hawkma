<?php

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\Controllers\NotificationController;

Route::prefix('v1')->group(function () {
    // Admin portal (staff + consultants): personal notifications only.
    Route::prefix('admin/notifications')->name('admin.notifications.')->middleware(['auth:admin', 'active.user', 'throttle:api'])->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::patch('{notification}/read', [NotificationController::class, 'markRead'])->name('read');
        Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::delete('{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    });

    // Client portal: the same endpoints for the client guard.
    Route::prefix('client/notifications')->name('client.notifications.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::patch('{notification}/read', [NotificationController::class, 'markRead'])->name('read');
        Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::delete('{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    });
});
