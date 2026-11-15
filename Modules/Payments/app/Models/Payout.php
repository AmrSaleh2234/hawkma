<?php

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Database\Factories\PayoutFactory;
use Modules\Payments\Enums\PayoutStatus;
use Modules\Users\Models\User;

/**
 * One payout per completed booking: the consultant's price minus the
 * platform fee, payable to the consultant's bank account.
 */
class Payout extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'consultant_id',
        'amount',
        'fee_percent',
        'fee_amount',
        'net_amount',
        'status',
        'driver',
        'transfer_reference',
        'release_note',
        'released_by',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'net_amount' => 'integer',
            'fee_percent' => 'decimal:2',
            'released_at' => 'datetime',
        ];
    }

    protected static function newFactory()
    {
        return PayoutFactory::new();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Consultants only see their own payouts; admins see everything.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isConsultant()) {
            $query->where('consultant_id', $user->id);
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->status === PayoutStatus::Pending;
    }

    public function isReleased(): bool
    {
        return $this->status === PayoutStatus::Released;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
