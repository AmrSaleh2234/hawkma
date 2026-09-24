<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use Modules\Payments\Http\Controllers\Client\PaymentController;
use Modules\Payments\Http\Controllers\Client\PaymentMethodController;
use Modules\Payments\Http\Controllers\WebhookController;

Route::prefix('v1')->group(function () {
    Route::prefix('client/payment-methods')->name('client.payment-methods.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index'])->name('index');
        Route::post('/', [PaymentMethodController::class, 'store'])->name('store');
        Route::delete('{paymentMethod}', [PaymentMethodController::class, 'destroy'])->name('destroy');
        Route::patch('{paymentMethod}/default', [PaymentMethodController::class, 'setDefault'])->name('default');
    });

    Route::prefix('client/payments')->name('client.payments.')->middleware(['auth:client', 'active.client', 'throttle:api'])->group(function () {
        Route::get('/', [PaymentController::class, 'index'])->name('index');
        Route::post('{payment}/verify', [PaymentController::class, 'verify'])->name('verify');
    });

    // WHK-01: no auth — the shared secret in the body authenticates it.
    Route::post('webhooks/payments/moyasar', [WebhookController::class, 'moyasar'])
        ->middleware('throttle:public')
        ->name('webhooks.payments.moyasar');

    Route::prefix('admin/payments')->name('admin.payments.')->middleware(['auth:admin', 'active.user', 'throttle:api', 'permission:view-payments,admin'])->group(function () {
        Route::get('/', [AdminPaymentController::class, 'index'])->name('index');
        Route::get('{payment}', [AdminPaymentController::class, 'show'])->name('show');
    });
});
