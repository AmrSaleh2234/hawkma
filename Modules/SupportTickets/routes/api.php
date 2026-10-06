<?php

use Illuminate\Support\Facades\Route;
use Modules\SupportTickets\Http\Controllers\SupportTicketsController;

Route::prefix('v1')->group(function (): void {
    Route::prefix('client/support-tickets')->name('client.support-tickets.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function (): void {
        Route::get('/', [SupportTicketsController::class, 'index'])->name('index');
        Route::post('/', [SupportTicketsController::class, 'store'])->name('store');
        Route::get('{ticket}', [SupportTicketsController::class, 'show'])->name('show');
        Route::post('{ticket}/messages', [SupportTicketsController::class, 'message'])->name('messages.store');
        Route::get('{ticket}/messages/{message}/media/{collection}', [SupportTicketsController::class, 'media'])->name('messages.media');
    });
    Route::prefix('admin/support-tickets')->name('admin.support-tickets.')->middleware(['auth:admin', 'active.user', 'throttle:api'])->group(function (): void {
        Route::get('/', [SupportTicketsController::class, 'index'])->middleware('permission:view-support-tickets,admin')->name('index');
        Route::get('{ticket}', [SupportTicketsController::class, 'show'])->middleware('permission:view-support-tickets,admin')->name('show');
        Route::post('{ticket}/messages', [SupportTicketsController::class, 'message'])->middleware('permission:reply-support-tickets,admin')->name('messages.store');
        Route::get('{ticket}/messages/{message}/media/{collection}', [SupportTicketsController::class, 'media'])->middleware('permission:view-support-tickets,admin')->name('messages.media');
        Route::patch('{ticket}/status', [SupportTicketsController::class, 'updateStatus'])->middleware('permission:manage-support-tickets,admin')->name('status');
    });
});
