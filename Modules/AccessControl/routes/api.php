<?php

use Illuminate\Support\Facades\Route;
use Modules\AccessControl\Http\Controllers\Admin\PermissionController;
use Modules\AccessControl\Http\Controllers\Admin\RoleController;

Route::prefix('v1')->group(function () {
    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active.user'])->group(function () {
        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware('permission:view-roles|view-permissions,admin')
            ->name('permissions.index');

        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('permission:view-roles,admin')
            ->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])
            ->middleware('permission:create-roles,admin')
            ->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])
            ->middleware('permission:view-roles,admin')
            ->name('roles.show');
        Route::put('roles/{role}', [RoleController::class, 'update'])
            ->middleware('permission:update-roles,admin')
            ->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])
            ->middleware('permission:delete-roles,admin')
            ->name('roles.destroy');
        Route::get('roles/{role}/users', [RoleController::class, 'users'])
            ->middleware('permission:view-roles,admin')
            ->name('roles.users');
    });
});
