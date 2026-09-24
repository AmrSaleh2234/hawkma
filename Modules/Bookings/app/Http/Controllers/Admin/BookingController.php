<?php

namespace Modules\Bookings\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Bookings\Contracts\MeetingProvider;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\MeetingStatus;
use Modules\Bookings\Enums\PaymentStatus;
use Modules\Bookings\Enums\RefundStatus;
use Modules\Bookings\Http\Requests\Admin\CalendarRequest;
use Modules\Bookings\Http\Requests\Admin\CancelBookingRequest;
use Modules\Bookings\Http\Requests\Admin\CompleteBookingRequest;
use Modules\Bookings\Http\Requests\Admin\RegenerateMeetingRequest;
use Modules\Bookings\Http\Requests\BookingIndexRequest;
use Modules\Bookings\Http\Resources\BookingCalendarResource;
use Modules\Bookings\Http\Resources\BookingResource;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Notifications\BookingConfirmedNotification;
use Modules\Bookings\Services\BookingStateMachine;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Reports\Models\Report;
use Throwable;

class BookingController extends ApiController
{
    /**
     * BKG-01 GET /api/v1/admin/bookings — Perm: view-bookings
     *
     * Scoped: a consultant only sees his own bookings (consultant_id is
     * ignored for him).
     */
    public function index(BookingIndexRequest $request): JsonResponse
    {
        $user = $request->user('admin');

        $filters = $request->validated();
        if ($user->isConsultant()) {
            unset($filters['consultant_id']);
        }

        $query = Booking::query()
            ->with($this->listEagerLoads())
            ->visibleTo($user)
            ->filter($filters);

        // Sort: starts_at, created_at, amount; default -starts_at.
        $sort = (string) ($filters['sort'] ?? '-starts_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (! in_array($column, ['starts_at', 'created_at', 'amount'], true)) {
            $column = 'starts_at';
            $direction = 'desc';
        }
        $query->orderBy($column, $direction);

        $bookings = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(BookingResource::collection($bookings));
    }

    /**
     * BKG-02 GET /api/v1/admin/bookings/{booking} — Perm: view-bookings + policy
     */
    public function show(string $booking): JsonResponse
    {
        $booking = Booking::query()
            ->with(array_merge($this->listEagerLoads(), ['payments']))
            ->findOrFail($booking);

        $this->authorize('view', $booking);

        return $this->success(BookingResource::make($booking));
    }

    /**
     * BKG-03 POST /api/v1/admin/bookings/{booking}/complete
     * Perm: complete-bookings + policy
     */
    public function complete(CompleteBookingRequest $request, string $booking, BookingStateMachine $stateMachine): JsonResponse
    {
        $booking = Booking::query()->findOrFail($booking);
        $this->authorize('complete', $booking);

        $booking = $stateMachine->complete($booking, $request->user('admin'));

        if ($request->filled('notes')) {
            $booking->forceFill(['completion_notes' => $request->input('notes')])->save();
        }

        return $this->success(
            BookingResource::make($booking->loadMissing($this->listEagerLoads())),
            __('bookings::messages.booking_completed'),
        );
    }

    /**
     * BKG-04 POST /api/v1/admin/bookings/{booking}/cancel
     * Perm: cancel-bookings + policy
     */
    public function cancel(CancelBookingRequest $request, string $booking, BookingStateMachine $stateMachine): JsonResponse
    {
        $booking = Booking::query()->findOrFail($booking);
        $this->authorize('cancel', $booking);

        $booking = $stateMachine->cancel(
            $booking,
            $request->user('admin'),
            $request->validated('reason'),
        );

        return $this->success(
            BookingResource::make($booking->loadMissing($this->listEagerLoads())),
            __('bookings::messages.booking_cancelled'),
        );
    }

    /**
     * BKG-05 POST /api/v1/admin/bookings/{booking}/meeting — Perm: manage-meetings
     *
     * (Re)creates the meeting event synchronously so the admin sees the
     * result. Only for pending bookings.
     */
    public function regenerateMeeting(RegenerateMeetingRequest $request, string $booking, MeetingProvider $provider): JsonResponse
    {
        $booking = Booking::query()->findOrFail($booking);
        $this->authorize('manageMeeting', $booking);

        if ($booking->status !== BookingStatus::Pending) {
            throw new BusinessException(ErrorCode::BookingInvalidStatus);
        }

        if ($booking->meeting_event_id !== null) {
            try {
                $provider->cancel($booking);
            } catch (Throwable $e) {
                Log::warning("Cancelling the old meeting of booking #{$booking->id} failed: {$e->getMessage()}");
            }
        }

        try {
            $result = $provider->create($booking);
        } catch (Throwable $e) {
            $booking->forceFill(['meeting_status' => MeetingStatus::Failed])->save();
            report($e);

            throw new BusinessException(ErrorCode::MeetingCreationFailed, status: 502);
        }

        $booking->forceFill([
            'meeting_provider' => $provider->name(),
            'meeting_status' => MeetingStatus::Created,
            'meeting_url' => $result->joinUrl,
            'meeting_event_id' => $result->eventId,
        ])->save();

        if ($request->boolean('notify_client', true)) {
            $booking->client->notify(new BookingConfirmedNotification($booking, withLink: true));
        }

        return $this->success(
            BookingResource::make($booking->refresh()->loadMissing($this->listEagerLoads())),
            __('bookings::messages.meeting_regenerated'),
        );
    }

    /**
     * BKG-06 GET /api/v1/admin/bookings/calendar?from=...&to=...
     * Perm: view-bookings — not paginated, max 62 days.
     */
    public function calendar(CalendarRequest $request): JsonResponse
    {
        $user = $request->user('admin');

        $from = CarbonImmutable::parse($request->query('from'))->startOfDay();
        $to = CarbonImmutable::parse($request->query('to'))->endOfDay();

        $query = Booking::query()
            ->with(['client:id,company_name', 'consultant:id,name'])
            ->visibleTo($user)
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at');

        if ($user->isAdmin() && $request->filled('consultant_id')) {
            $query->where('consultant_id', $request->integer('consultant_id'));
        }

        return $this->success(BookingCalendarResource::collection($query->get()));
    }

    /**
     * BKG-07 POST /api/v1/admin/bookings/{booking}/mark-refunded
     * Perm: refund-payments — only when refund_status = requested.
     */
    public function markRefunded(Request $request, string $booking): JsonResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $booking = Booking::query()->findOrFail($booking);

        if ($booking->refund_status !== RefundStatus::Requested) {
            throw new BusinessException(ErrorCode::BookingInvalidStatus);
        }

        DB::transaction(function () use ($booking): void {
            $booking->forceFill([
                'refund_status' => RefundStatus::Refunded,
                'payment_status' => PaymentStatus::Refunded,
            ])->save();

            $booking->payments()
                ->where('status', PaymentRecordStatus::Paid)
                ->latest('id')
                ->first()
                ?->forceFill(['status' => PaymentRecordStatus::Refunded])
                ->save();
        });

        return $this->success(
            BookingResource::make($booking->refresh()->loadMissing($this->listEagerLoads())),
            __('bookings::messages.refund_marked'),
        );
    }

    /**
     * The shared eager loads (no N+1). The report relation is only eager
     * loaded once the Reports module exists (Phase 10).
     *
     * @return array<int, string>
     */
    protected function listEagerLoads(): array
    {
        $with = ['client', 'consultant.media', 'package', 'latestPayment'];

        // TODO Phase 10: drop the guard once Report always exists.
        if (class_exists(Report::class)) {
            $with[] = 'report.media';
        }

        return $with;
    }
}
