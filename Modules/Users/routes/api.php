<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\Admin\AuthController;
use Modules\Users\Http\Controllers\Admin\ProfileController;

Route::prefix('v1')->group(function () {
    Route::prefix('admin/auth')->name('admin.auth.')->group(function () {
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
            Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
        });

        Route::middleware(['auth:admin', 'active.user'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
        });
    });

    Route::prefix('admin/profile')->name('admin.profile.')->middleware(['auth:admin', 'active.user'])->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::post('avatar', [ProfileController::class, 'storeAvatar'])->name('avatar.store');
        Route::delete('avatar', [ProfileController::class, 'destroyAvatar'])->name('avatar.destroy');
        Route::put('password', [ProfileController::class, 'updatePassword'])->name('password.update');
    });
});
