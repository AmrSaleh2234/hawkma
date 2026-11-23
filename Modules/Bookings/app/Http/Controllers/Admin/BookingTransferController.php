<?php

namespace Modules\Bookings\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\TransferStatus;
use Modules\Bookings\Http\Requests\Admin\RejectTransferRequest;
use Modules\Bookings\Http\Requests\Admin\StoreTransferRequest;
use Modules\Bookings\Http\Resources\BookingTransferResource;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Models\BookingTransferRequest;
use Modules\Bookings\Notifications\TransferRequestNotification;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Users\Models\User;

/**
 * Consultant handoff requests (booking → another consultant).
 *
 * Consultant flow: GET targets → POST / (pending). Admin flow: index
 * shows every request; approve moves the booking's consultant_id,
 * reject/cancel only update the request status.
 */
class BookingTransferController extends ApiController
{
    /**
     * GET /api/v1/admin/booking-transfers — Perm: view-booking-transfers.
     * Admins see everything, consultants only their own requests.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BookingTransferRequest::query()
            ->with(['booking.client', 'fromConsultant', 'toConsultant', 'requester', 'decider'])
            ->visibleTo($request->user('admin'));

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        $query->latest();

        return $this->paginated(
            BookingTransferResource::collection(
                $query->paginate(QueryFilters::perPage($request))
            )
        );
    }

    /**
     * GET /api/v1/admin/booking-transfers/targets — Perm: request-booking-transfers.
     * The pick-list for the modal: active consultants (id/name/title).
     */
    public function targets(Request $request): JsonResponse
    {
        $consultants = User::query()
            ->consultants()
            ->active()
            ->where('consultant_stage', 'active')
            ->where('id', '!=', $request->user('admin')->id)
            ->orderBy('name')
            ->get(['id', 'name', 'title', 'specialization'])
            ->map(fn (User $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'title' => $c->title,
                'specialization' => $c->specialization,
            ]);

        return $this->success($consultants);
    }

    /**
     * POST /api/v1/admin/booking-transfers — Perm: request-booking-transfers.
     * Only the consultant who owns the booking may request a transfer.
     */
    public function store(StoreTransferRequest $request): JsonResponse
    {
        $user = $request->user('admin');
        abort_unless($user->isConsultant(), 403);

        $data = $request->validated();
        $booking = Booking::query()->findOrFail($data['booking_id']);

        if ($booking->consultant_id !== $user->id) {
            throw new BusinessException(ErrorCode::Forbidden, status: 403);
        }

        if ($booking->status !== BookingStatus::Pending || $booking->starts_at->isPast()) {
            throw new BusinessException(ErrorCode::TransferNotAllowed);
        }

        $target = User::query()
            ->consultants()
            ->active()
            ->where('consultant_stage', 'active')
            ->findOrFail($data['to_consultant_id']);

        if ($target->id === $booking->consultant_id) {
            throw new BusinessException(ErrorCode::TransferNotAllowed);
        }

        if ($booking->transferRequests()->pending()->exists()) {
            throw new BusinessException(ErrorCode::TransferAlreadyPending);
        }

        $transfer = DB::transaction(function () use ($booking, $target, $user, $data): BookingTransferRequest {
            return $booking->transferRequests()->create([
                'from_consultant_id' => $user->id,
                'to_consultant_id' => $target->id,
                'requested_by' => $user->id,
                'reason' => $data['reason'],
                'status' => TransferStatus::Pending,
            ]);
        });

        // Notify every active staff admin about the new request.
        $transfer->loadMissing(['booking.client', 'fromConsultant', 'toConsultant']);
        User::staff()->active()->get()
            ->each(fn (User $admin) => $admin->notify(new TransferRequestNotification($transfer, 'requested')));

        return $this->created(
            BookingTransferResource::make($transfer),
            __('bookings::messages.transfer_requested'),
        );
    }

    /**
     * POST /api/v1/admin/booking-transfers/{transfer}/approve
     * Perm: manage-booking-transfers. Moves the booking to the new
     * consultant and notifies both consultants and the client.
     */
    public function approve(Request $request, string $transfer): JsonResponse
    {
        $transfer = BookingTransferRequest::query()
            ->with(['booking.client', 'fromConsultant', 'toConsultant'])
            ->findOrFail($transfer);

        if ($transfer->status !== TransferStatus::Pending) {
            throw new BusinessException(ErrorCode::TransferInvalidStatus);
        }

        $booking = $transfer->booking;
        if ($booking->status !== BookingStatus::Pending) {
            throw new BusinessException(ErrorCode::BookingInvalidStatus);
        }

        DB::transaction(function () use ($transfer, $booking, $request): void {
            $booking->forceFill(['consultant_id' => $transfer->to_consultant_id])->save();

            $transfer->forceFill([
                'status' => TransferStatus::Approved,
                'decided_by' => $request->user('admin')->id,
                'decided_at' => now(),
            ])->save();
        });

        $booking->client?->notify(new TransferRequestNotification($transfer, 'approved'));
        $transfer->toConsultant?->notify(new TransferRequestNotification($transfer, 'approved'));
        $transfer->fromConsultant?->notify(new TransferRequestNotification($transfer, 'approved'));

        return $this->success(
            BookingTransferResource::make($transfer->refresh()->loadMissing('decider')),
            __('bookings::messages.transfer_approved'),
        );
    }

    /**
     * POST /api/v1/admin/booking-transfers/{transfer}/reject
     * Perm: manage-booking-transfers.
     */
    public function reject(RejectTransferRequest $request, string $transfer): JsonResponse
    {
        $transfer = BookingTransferRequest::query()->findOrFail($transfer);

        if ($transfer->status !== TransferStatus::Pending) {
            throw new BusinessException(ErrorCode::TransferInvalidStatus);
        }

        $transfer->forceFill([
            'status' => TransferStatus::Rejected,
            'decision_note' => $request->input('note'),
            'decided_by' => $request->user('admin')->id,
            'decided_at' => now(),
        ])->save();

        $transfer->fromConsultant?->notify(new TransferRequestNotification($transfer, 'rejected'));

        return $this->success(
            BookingTransferResource::make($transfer->loadMissing(['booking.client', 'fromConsultant', 'toConsultant', 'decider'])),
            __('bookings::messages.transfer_rejected'),
        );
    }

    /**
     * POST /api/v1/admin/booking-transfers/{transfer}/cancel
     * Perm: request-booking-transfers. The requesting consultant
     * withdraws his own pending request.
     */
    public function cancel(Request $request, string $transfer): JsonResponse
    {
        $transfer = BookingTransferRequest::query()->findOrFail($transfer);
        $user = $request->user('admin');

        if ($transfer->requested_by !== $user->id) {
            throw new BusinessException(ErrorCode::Forbidden, status: 403);
        }

        if ($transfer->status !== TransferStatus::Pending) {
            throw new BusinessException(ErrorCode::TransferInvalidStatus);
        }

        $transfer->forceFill([
            'status' => TransferStatus::Cancelled,
            'decided_at' => now(),
        ])->save();

        return $this->success(
            BookingTransferResource::make($transfer->loadMissing(['booking.client', 'fromConsultant', 'toConsultant'])),
            __('bookings::messages.transfer_cancelled'),
        );
    }
}
