<?php

namespace Modules\Bookings\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Bookings\Actions\CreateBookingAction;
use Modules\Bookings\Actions\QuoteBookingAction;
use Modules\Bookings\Http\Requests\Client\CancelBookingRequest;
use Modules\Bookings\Http\Requests\Client\QuoteBookingRequest;
use Modules\Bookings\Http\Requests\Client\StoreBookingRequest;
use Modules\Bookings\Http\Resources\BookingResource;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Services\BookingStateMachine;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\Money;
use Modules\Core\Support\QueryFilters;
use Modules\Packages\Http\Resources\PackageResource;
use Modules\Packages\Http\Resources\SubscriptionResource;
use Modules\Packages\Models\Package;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Users\Models\User;

class BookingController extends ApiController
{
    /**
     * CLI-BKG-01 POST /api/v1/client/bookings/quote
     *
     * Prices a booking before it is created: subscription-covered or paid.
     */
    public function quote(QuoteBookingRequest $request, QuoteBookingAction $action): JsonResponse
    {
        $package = Package::query()->findOrFail($request->integer('package_id'));
        $consultant = $request->filled('consultant_id')
            ? User::query()->findOrFail($request->integer('consultant_id'))
            : null;

        $result = $action->execute(
            $request->user('client'),
            $package,
            $consultant,
            $request->input('date'),
            $request->input('time'),
        );

        return $this->success([
            'package' => PackageResource::make($result['package']),
            'requires_payment' => $result['requires_payment'],
            'amount' => $result['amount'],
            'amount_formatted' => Money::format($result['amount'], $result['currency']),
            'currency' => $result['currency'],
            'subscription' => $result['subscription']
                ? SubscriptionResource::make($result['subscription'])
                : null,
            'slot_available' => $result['slot_available'],
        ]);
    }

    /**
     * CLI-BKG-02 POST /api/v1/client/bookings
     *
     * The booking creation flow (§9.5). `payment` is null when the booking
     * is covered by a subscription; when the payment is paid,
     * requires_action is false and transaction_url is null.
     */
    public function store(StoreBookingRequest $request, CreateBookingAction $action): JsonResponse
    {
        $result = $action->execute($request->user('client'), $request->validated());

        $booking = $result['booking']->loadMissing(['package', 'consultant', 'client', 'latestPayment']);
        $payment = $result['payment'];

        return $this->created([
            'booking' => BookingResource::make($booking),
            'payment' => $payment === null ? null : [
                'id' => $payment->id,
                'status' => $payment->status->value,
                'requires_action' => $payment->status === PaymentRecordStatus::Initiated,
                'transaction_url' => $payment->status === PaymentRecordStatus::Initiated
                    ? $payment->transaction_url
                    : null,
            ],
        ], __('bookings::messages.booking_created'));
    }

    /**
     * CLI-BKG-03 GET /api/v1/client/bookings
     *
     * Query: status, date_from, date_to, upcoming, sort (default
     * -starts_at). Only the client's own bookings.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'upcoming' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:starts_at,-starts_at,created_at,-created_at'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $request->user('client')->bookings()
            ->with(['package', 'consultant.media', 'latestPayment'])
            ->filter($request->only(['status', 'date_from', 'date_to', 'upcoming']));

        $sort = (string) $request->query('sort', '-starts_at');
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');

        $bookings = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(BookingResource::collection($bookings));
    }

    /**
     * CLI-BKG-04 GET /api/v1/client/bookings/{booking}
     *
     * 404 if the booking is not the client's. Includes the meeting link and
     * the report (if uploaded).
     */
    public function show(Request $request, string $booking): JsonResponse
    {
        $booking = $request->user('client')->bookings()
            ->with(['package', 'consultant.media', 'latestPayment'])
            ->findOrFail($booking);

        return $this->success(BookingResource::make($booking));
    }

    /**
     * CLI-BKG-05 POST /api/v1/client/bookings/{booking}/cancel
     *
     * Only pending, and only up to client_cancel_hours before the start (a
     * pending_payment booking can always be cancelled). 404 if not his.
     */
    public function cancel(CancelBookingRequest $request, string $booking, BookingStateMachine $stateMachine): JsonResponse
    {
        $booking = $request->user('client')->bookings()->findOrFail($booking);

        $booking = $stateMachine->cancel(
            $booking,
            $request->user('client'),
            $request->validated('reason'),
        );

        return $this->success(
            BookingResource::make($booking->loadMissing(['package', 'consultant.media', 'latestPayment'])),
            __('bookings::messages.booking_cancelled'),
        );
    }
}
