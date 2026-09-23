<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\Client\PaymentMethodController;

Route::prefix('v1')->group(function () {
    Route::prefix('client/payment-methods')->name('client.payment-methods.')->middleware(['auth:client', 'active.client'])->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index'])->name('index');
        Route::post('/', [PaymentMethodController::class, 'store'])->name('store');
        Route::delete('{paymentMethod}', [PaymentMethodController::class, 'destroy'])->name('destroy');
        Route::patch('{paymentMethod}/default', [PaymentMethodController::class, 'setDefault'])->name('default');
    });

    // TODO Phase 9: CLI-PAY-01..02 (client payments), WHK-01 (Moyasar webhook),
    // PAY-01..02 (admin payments).
});
