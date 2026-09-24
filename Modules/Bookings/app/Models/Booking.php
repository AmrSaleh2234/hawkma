<?php

namespace Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Bookings\Database\Factories\BookingFactory;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\MeetingStatus;
use Modules\Bookings\Enums\PaymentStatus;
use Modules\Bookings\Enums\RefundStatus;
use Modules\Bookings\Enums\ReportStatus;
use Modules\Bookings\Services\BookingQueryService;
use Modules\Clients\Models\Client;
use Modules\Clients\Models\ClientLocation;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Payments\Models\Payment;
use Modules\Reports\Models\Report;
use Modules\Users\Models\User;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference',
        'client_id',
        'consultant_id',
        'package_id',
        'client_subscription_id',
        'client_location_id',
        'location_snapshot',
        'starts_at',
        'ends_at',
        'status',
        'report_status',
        'amount',
        'currency',
        'payment_status',
        'refund_status',
        'expires_at',
        'meeting_provider',
        'meeting_status',
        'meeting_url',
        'meeting_event_id',
        'client_notes',
        'completed_at',
        'completion_notes',
        'completed_by',
        'cancelled_at',
        'cancelled_by_type',
        'cancelled_by_id',
        'cancellation_reason',
    ];

    /**
     * Match the database defaults so fresh models serialize correctly.
     */
    protected $attributes = [
        'report_status' => ReportStatus::None->value,
        'currency' => 'SAR',
        'refund_status' => RefundStatus::None->value,
        'meeting_status' => MeetingStatus::None->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'report_status' => ReportStatus::class,
            'payment_status' => PaymentStatus::class,
            'refund_status' => RefundStatus::class,
            'meeting_status' => MeetingStatus::class,
            'location_snapshot' => 'array',
            'amount' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BookingFactory
    {
        return BookingFactory::new();
    }

    /**
     * reference = BK-{Y}-{id padded to 6}, set once the id exists (§8.10).
     */
    protected static function booted(): void
    {
        static::created(function (Booking $booking): void {
            $booking->forceFill([
                'reference' => sprintf('BK-%s-%06d', $booking->created_at->year, $booking->id),
            ])->saveQuietly();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes (§8.10)
    |--------------------------------------------------------------------------
    */

    /**
     * Bookings that block a slot: pending, or pending_payment with an
     * unexpired payment window.
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where('status', BookingStatus::Pending)
            ->orWhere(function (Builder $q) {
                $q->where('status', BookingStatus::PendingPayment)
                    ->where('expires_at', '>', now());
            });
    }

    public function scopeForConsultant(Builder $query, int $consultantId): Builder
    {
        return $query->where('consultant_id', $consultantId);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Data visibility (§9.8): consultants only see their own bookings;
     * admins see everything.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isConsultant()) {
            $query->where('consultant_id', $user->id);
        }

        return $query;
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now())->orderBy('starts_at');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return BookingQueryService::filter($query, $filters);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ClientSubscription::class, 'client_subscription_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ClientLocation::class, 'client_location_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }
}
