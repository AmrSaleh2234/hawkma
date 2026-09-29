<?php

namespace Modules\Payments\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Bookings\Http\Resources\BookingResource;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Payments\Http\Resources\PaymentResource;
use Modules\Payments\Services\PaymentService;

class PaymentController extends ApiController
{
    /**
     * CLI-PAY-01 GET /api/v1/client/payments
     *
     * The client's payment history.
     */
    public function index(Request $request): JsonResponse
    {
        $payments = $request->user('client')->payments()
            ->with('booking')
            ->latest()
            ->paginate(QueryFilters::perPage($request));

        return $this->paginated(PaymentResource::collection($payments));
    }

    /**
     * CLI-PAY-02 POST /api/v1/client/payments/{payment}/verify
     *
     * After the 3-D Secure redirect (§9.6). 404 if the payment is not the
     * client's. Idempotent.
     */
    public function verify(Request $request, string $payment, PaymentService $payments): JsonResponse
    {
        $payment = $request->user('client')->payments()->findOrFail($payment);

        $payment = $payments->verify($payment);

        $booking = $payment->booking->refresh()
            ->loadMissing(['package', 'consultant', 'client', 'latestPayment']);

        return $this->success([
            'booking' => BookingResource::make($booking),
            'payment' => PaymentResource::make($payment),
        ]);
    }
}
