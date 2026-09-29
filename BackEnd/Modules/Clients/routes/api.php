<?php

use Illuminate\Support\Facades\Route;
use Modules\Clients\Http\Controllers\Admin\ClientController as AdminClientController;
use Modules\Clients\Http\Controllers\Admin\TicketController as AdminTicketController;
use Modules\Clients\Http\Controllers\Client\AuthController;
use Modules\Clients\Http\Controllers\Client\LocationController;
use Modules\Clients\Http\Controllers\Client\ProfileController;
use Modules\Clients\Http\Controllers\Client\ReviewController;
use Modules\Clients\Http\Controllers\Client\TicketController;
use Modules\Clients\Http\Controllers\Public\PublicReviewController;

Route::prefix('v1')->group(function () {
    Route::prefix('client/auth')->name('client.auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
            Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
        });

        Route::middleware(['auth:client', 'active.client', 'throttle:api'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
        });
    });

    Route::prefix('client/profile')->name('client.profile.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::post('avatar', [ProfileController::class, 'storeAvatar'])->name('avatar.store');
        Route::delete('avatar', [ProfileController::class, 'destroyAvatar'])->name('avatar.destroy');
        Route::put('password', [ProfileController::class, 'updatePassword'])->name('password.update');
    });

    Route::prefix('client/locations')->name('client.locations.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function () {
        Route::get('/', [LocationController::class, 'index'])->name('index');
        Route::post('/', [LocationController::class, 'store'])->name('store');
        Route::get('{location}', [LocationController::class, 'show'])->name('show');
        Route::put('{location}', [LocationController::class, 'update'])->name('update');
        Route::delete('{location}', [LocationController::class, 'destroy'])->name('destroy');
        Route::patch('{location}/default', [LocationController::class, 'setDefault'])->name('default');
    });

    // Landing-page testimonials (public) + client "my reviews" (auth).
    Route::prefix('public')->name('public.')->middleware('throttle:public')->group(function () {
        Route::get('reviews', [PublicReviewController::class, 'index'])->name('reviews.index');
    });

    Route::prefix('client/reviews')->name('client.reviews.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function () {
        Route::get('/', [ReviewController::class, 'index'])->name('index');
        Route::post('/', [ReviewController::class, 'store'])->name('store');
        Route::delete('{review}', [ReviewController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active.user', 'throttle:api'])->group(function () {
        Route::get('clients', [AdminClientController::class, 'index'])
            ->middleware('permission:view-clients,admin')
            ->name('clients.index');
        Route::get('clients/{client}', [AdminClientController::class, 'show'])
            ->middleware('permission:view-clients,admin')
            ->name('clients.show');
        Route::put('clients/{client}', [AdminClientController::class, 'update'])
            ->middleware('permission:update-clients,admin')
            ->name('clients.update');
        Route::patch('clients/{client}/status', [AdminClientController::class, 'updateStatus'])
            ->middleware('permission:update-clients,admin')
            ->name('clients.status.update');
        Route::delete('clients/{client}', [AdminClientController::class, 'destroy'])
            ->middleware('permission:delete-clients,admin')
            ->name('clients.destroy');

        Route::get('clients/{client}/subscriptions', [AdminClientController::class, 'subscriptions'])
            ->middleware('permission:view-clients,admin')
            ->name('clients.subscriptions');

        Route::get('clients/{client}/bookings', [AdminClientController::class, 'bookings'])
            ->middleware('permission:view-bookings,admin')
            ->name('clients.bookings');
        Route::get('clients/{client}/reports', [AdminClientController::class, 'reports'])
            ->middleware('permission:view-reports,admin')
            ->name('clients.reports');

        // Customer-service tickets
        Route::get('tickets', [AdminTicketController::class, 'index'])
            ->middleware('permission:view-tickets,admin')
            ->name('tickets.index');
        Route::get('tickets/{ticket}', [AdminTicketController::class, 'show'])
            ->middleware('permission:view-tickets,admin')
            ->name('tickets.show');
        Route::post('tickets/{ticket}/reply', [AdminTicketController::class, 'reply'])
            ->middleware('permission:update-tickets,admin')
            ->name('tickets.reply');
        Route::patch('tickets/{ticket}', [AdminTicketController::class, 'update'])
            ->middleware('permission:update-tickets,admin')
            ->name('tickets.update');
    });

    // Client support tickets (customer service).
    Route::prefix('client/tickets')->name('client.tickets.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function () {
        Route::get('/', [TicketController::class, 'index'])->name('index');
        Route::post('/', [TicketController::class, 'store'])->name('store');
        Route::get('{ticket}', [TicketController::class, 'show'])->name('show');
        Route::post('{ticket}/reply', [TicketController::class, 'reply'])->name('reply');
    });
});
