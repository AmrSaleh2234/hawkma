<?php

use Illuminate\Support\Facades\Route;
use Modules\SupportTickets\Http\Controllers\SupportTicketsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('supporttickets', SupportTicketsController::class)->names('supporttickets');
});
