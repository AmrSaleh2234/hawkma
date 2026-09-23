<?php

use Illuminate\Support\Facades\Route;
use Modules\Packages\Http\Controllers\Admin\PackageController;
use Modules\Packages\Http\Controllers\Client\SubscriptionController;
use Modules\Packages\Http\Controllers\Public\PublicPackageController;

Route::prefix('v1')->group(function () {
    // Public wizard endpoints (no auth).
    Route::prefix('public')->name('public.')->middleware('throttle:60,1')->group(function () {
        Route::get('packages', [PublicPackageController::class, 'index'])->name('packages.index');
        Route::get('packages/{slug}', [PublicPackageController::class, 'show'])->name('packages.show');
    });

    Route::prefix('client/subscriptions')->name('client.subscriptions.')->middleware(['auth:client', 'active.client'])->group(function () {
        Route::get('/', [SubscriptionController::class, 'index'])->name('index');
        Route::get('active', [SubscriptionController::class, 'active'])->name('active');
    });

    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active.user'])->group(function () {
        Route::get('packages', [PackageController::class, 'index'])
            ->middleware('permission:view-packages,admin')
            ->name('packages.index');
        Route::post('packages', [PackageController::class, 'store'])
            ->middleware('permission:create-packages,admin')
            ->name('packages.store');
        Route::get('packages/{package}', [PackageController::class, 'show'])
            ->middleware('permission:view-packages,admin')
            ->name('packages.show');
        Route::put('packages/{package}', [PackageController::class, 'update'])
            ->middleware('permission:update-packages,admin')
            ->name('packages.update');
        Route::delete('packages/{package}', [PackageController::class, 'destroy'])
            ->middleware('permission:delete-packages,admin')
            ->name('packages.destroy');
        Route::patch('packages/{package}/status', [PackageController::class, 'updateStatus'])
            ->middleware('permission:update-packages,admin')
            ->name('packages.status.update');
    });
});
