<?php

use Illuminate\Support\Facades\Route;
use Modules\Certificates\Http\Controllers\CertificatesController;

Route::prefix('v1')->group(function (): void {
    Route::get('public/certificates', [CertificatesController::class, 'index'])->middleware('throttle:public')->name('public.certificates.index');

    Route::prefix('admin/certificates')->name('admin.certificates.')->middleware(['auth:admin', 'active.user', 'throttle:api', 'permission:view-certificates,admin'])->group(function (): void {
        Route::get('/', [CertificatesController::class, 'adminIndex'])->name('index');
        Route::post('/', [CertificatesController::class, 'store'])->middleware('permission:create-certificates,admin')->name('store');
        Route::get('{certificate}', [CertificatesController::class, 'show'])->name('show');
        Route::put('{certificate}', [CertificatesController::class, 'update'])->middleware('permission:update-certificates,admin')->name('update');
        // POST duplicate so multipart updates (image) work — PHP does not parse PUT bodies with files.
        Route::post('{certificate}', [CertificatesController::class, 'update'])->middleware('permission:update-certificates,admin')->name('update-multipart');
        Route::delete('{certificate}', [CertificatesController::class, 'destroy'])->middleware('permission:delete-certificates,admin')->name('destroy');
    });
});
