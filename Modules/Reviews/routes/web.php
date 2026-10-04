<?php

use Illuminate\Support\Facades\Route;
use Modules\Reviews\Http\Controllers\ReviewsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('reviews', ReviewsController::class)->names('reviews');
});
