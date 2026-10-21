<?php

use Illuminate\Support\Facades\Route;
use Modules\Team\Http\Controllers\TeamMembersController;

Route::prefix('v1')->group(function (): void {
    Route::get('public/team-members', [TeamMembersController::class, 'index'])->middleware('throttle:public')->name('public.team-members.index');

    Route::prefix('admin/team-members')->name('admin.team-members.')->middleware(['auth:admin', 'active.user', 'throttle:api', 'permission:view-team,admin'])->group(function (): void {
        Route::get('/', [TeamMembersController::class, 'adminIndex'])->name('index');
        Route::post('/', [TeamMembersController::class, 'store'])->middleware('permission:create-team,admin')->name('store');
        Route::get('{teamMember}', [TeamMembersController::class, 'show'])->name('show');
        Route::put('{teamMember}', [TeamMembersController::class, 'update'])->middleware('permission:update-team,admin')->name('update');
        // POST duplicate so multipart updates (avatar) work — PHP does not parse PUT bodies with files.
        Route::post('{teamMember}', [TeamMembersController::class, 'update'])->middleware('permission:update-team,admin')->name('update-multipart');
        Route::delete('{teamMember}', [TeamMembersController::class, 'destroy'])->middleware('permission:delete-team,admin')->name('destroy');
    });
});
