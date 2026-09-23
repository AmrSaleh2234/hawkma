<?php

use Illuminate\Support\Facades\Route;
use Modules\Clients\Http\Controllers\Admin\ClientController as AdminClientController;
use Modules\Clients\Http\Controllers\Client\AuthController;
use Modules\Clients\Http\Controllers\Client\LocationController;
use Modules\Clients\Http\Controllers\Client\ProfileController;

Route::prefix('v1')->group(function () {
    Route::prefix('client/auth')->name('client.auth.')->group(function () {
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
            Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
        });

        Route::middleware(['auth:client', 'active.client'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
        });
    });

    Route::prefix('client/profile')->name('client.profile.')->middleware(['auth:client', 'active.client'])->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::post('avatar', [ProfileController::class, 'storeAvatar'])->name('avatar.store');
        Route::delete('avatar', [ProfileController::class, 'destroyAvatar'])->name('avatar.destroy');
        Route::put('password', [ProfileController::class, 'updatePassword'])->name('password.update');
    });

    Route::prefix('client/locations')->name('client.locations.')->middleware(['auth:client', 'active.client'])->group(function () {
        Route::get('/', [LocationController::class, 'index'])->name('index');
        Route::post('/', [LocationController::class, 'store'])->name('store');
        Route::get('{location}', [LocationController::class, 'show'])->name('show');
        Route::put('{location}', [LocationController::class, 'update'])->name('update');
        Route::delete('{location}', [LocationController::class, 'destroy'])->name('destroy');
        Route::patch('{location}/default', [LocationController::class, 'setDefault'])->name('default');
    });

    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active.user'])->group(function () {
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

        // TODO Phase 9: ADM-CL-06 GET clients/{client}/bookings (view-bookings)
        // TODO Phase 10: ADM-CL-07 GET clients/{client}/reports (view-reports)
    });
});
