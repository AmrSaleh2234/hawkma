<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use Modules\Dashboard\Http\Controllers\Client\DashboardController as ClientDashboardController;

Route::prefix('v1')->group(function () {
    Route::get('admin/dashboard/stats', [AdminDashboardController::class, 'stats'])
        ->middleware(['auth:admin', 'active.user', 'permission:view-dashboard,admin'])
        ->name('admin.dashboard.stats');

    Route::get('client/dashboard', [ClientDashboardController::class, 'show'])
        ->middleware(['auth:client', 'active.client'])
        ->name('client.dashboard.show');
});
