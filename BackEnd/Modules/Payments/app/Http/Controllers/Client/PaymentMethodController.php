<?php

namespace Modules\Payments\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Clients\Models\Client;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Payments\Http\Requests\Client\StorePaymentMethodRequest;
use Modules\Payments\Http\Resources\PaymentMethodResource;
use Modules\Payments\Models\PaymentMethod;
use Modules\Payments\Services\PaymentMethodService;

class PaymentMethodController extends ApiController
{
    public function __construct(protected PaymentMethodService $paymentMethods) {}

    /**
     * CLI-PM-01 GET /api/v1/client/payment-methods
     *
     * Not paginated; the default first.
     */
    public function index(Request $request): JsonResponse
    {
        $methods = $request->user('client')->paymentMethods()
            ->orderByDesc('is_default')
            ->latest('id')
            ->get();

        return $this->success(PaymentMethodResource::collection($methods));
    }

    /**
     * CLI-PM-02 POST /api/v1/client/payment-methods
     *
     * The token is made by the frontend with the gateway JS. A duplicate
     * token returns the existing one.
     */
    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $method = $this->paymentMethods->storeFromToken(
            $request->user('client'),
            $request->validated('token'),
            $request->boolean('is_default'),
        );

        return $this->created(PaymentMethodResource::make($method), __('core::messages.payment_method_added'));
    }

    /**
     * CLI-PM-03 DELETE /api/v1/client/payment-methods/{paymentMethod}
     *
     * Soft delete; 404 if it is not the client's.
     */
    public function destroy(Request $request, string $paymentMethod): JsonResponse
    {
        $this->paymentMethods->delete($this->findOwned($request, $paymentMethod));

        return $this->noContent(__('core::messages.payment_method_deleted'));
    }

    /**
     * CLI-PM-04 PATCH /api/v1/client/payment-methods/{paymentMethod}/default
     */
    public function setDefault(Request $request, string $paymentMethod): JsonResponse
    {
        $method = $this->paymentMethods->setDefault($this->findOwned($request, $paymentMethod));

        return $this->success(PaymentMethodResource::make($method), __('core::messages.default_payment_method_set'));
    }

    /**
     * Scope the lookup to the authenticated client: another client's card is
     * a 404 (not 403), so ids do not leak.
     */
    protected function findOwned(Request $request, string $id): PaymentMethod
    {
        /** @var Client $client */
        $client = $request->user('client');

        return $client->paymentMethods()->findOrFail($id);
    }
}
