<?php

namespace Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Payments\Services\PaymentService;

class WebhookController extends ApiController
{
    /**
     * WHK-01 POST /api/v1/webhooks/payments/moyasar
     *
     * The body's secret_token must equal the configured webhook secret
     * (else 401). Idempotent; always answers 200 {success: true} (§9.6).
     */
    public function moyasar(Request $request, PaymentService $payments): JsonResponse
    {
        $secret = (string) config('payments.moyasar.webhook_secret');

        abort_unless(
            $secret !== ''
                && $request->filled('secret_token')
                && hash_equals($secret, (string) $request->input('secret_token')),
            401,
        );

        $payments->handleWebhook($request->all());

        return $this->success(null);
    }
}
