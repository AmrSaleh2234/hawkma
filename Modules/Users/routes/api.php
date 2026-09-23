<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\Admin\AuthController;
use Modules\Users\Http\Controllers\Admin\ProfileController;
use Modules\Users\Http\Controllers\Admin\UserController;

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

    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active.user'])->group(function () {
        Route::get('users', [UserController::class, 'index'])
            ->middleware('permission:view-users,admin')
            ->name('users.index');
        Route::post('users', [UserController::class, 'store'])
            ->middleware('permission:create-users,admin')
            ->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])
            ->middleware('permission:view-users,admin')
            ->name('users.show');
        Route::put('users/{user}', [UserController::class, 'update'])
            ->middleware('permission:update-users,admin')
            ->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])
            ->middleware('permission:delete-users,admin')
            ->name('users.destroy');
        Route::put('users/{user}/roles', [UserController::class, 'syncRoles'])
            ->middleware('permission:assign-roles,admin')
            ->name('users.roles.update');
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])
            ->middleware('permission:update-users,admin')
            ->name('users.status.update');
        Route::post('users/{user}/avatar', [UserController::class, 'storeAvatar'])
            ->middleware('permission:update-users,admin')
            ->name('users.avatar.store');
    });
});
