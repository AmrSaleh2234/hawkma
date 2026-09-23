<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\MetaController;

Route::prefix('v1')->group(function () {
    Route::prefix('public')->name('public.')->middleware('throttle:60,1')->group(function () {
        Route::get('meta', MetaController::class)->name('meta');
    });
});
