<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\MetaController;
use Modules\Core\Http\Controllers\NotificationController;

Route::prefix('v1')->group(function () {
    Route::prefix('public')->name('public.')->middleware('throttle:public')->group(function () {
        Route::get('meta', MetaController::class)->name('meta');
    });

    Route::prefix('client/notifications')->name('client.notifications.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('{id}/read', [NotificationController::class, 'markRead'])->name('read');
    });

    Route::prefix('admin/notifications')->name('admin.notifications.')->middleware(['auth:admin', 'active.user', 'throttle:api'])->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('{id}/read', [NotificationController::class, 'markRead'])->name('read');
    });
});