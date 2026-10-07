<?php

use Illuminate\Support\Facades\Route;
use Modules\JoinRequests\Http\Controllers\JoinRequestsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('joinrequests', JoinRequestsController::class)->names('joinrequests');
});
