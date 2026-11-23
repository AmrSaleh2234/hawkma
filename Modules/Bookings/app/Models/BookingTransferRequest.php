<?php

namespace Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Bookings\Enums\TransferStatus;
use Modules\Users\Models\User;

/**
 * A consultant's request to hand a booking over to another consultant.
 * `requested_by` == `from_consultant_id` today; kept separate in case an
 * admin ever initiates transfers on behalf of a consultant.
 */
class BookingTransferRequest extends Model
{
    protected $fillable = [
        'booking_id',
        'from_consultant_id',
        'to_consultant_id',
        'requested_by',
        'reason',
        'status',
        'decision_note',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TransferStatus::Pending);
    }

    /**
     * Data visibility: admins see every request; consultants only theirs.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isConsultant()) {
            $query->where('from_consultant_id', $user->id);
        }

        return $query;
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function fromConsultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_consultant_id')->withTrashed();
    }

    public function toConsultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_consultant_id')->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
