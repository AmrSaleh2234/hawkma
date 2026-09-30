<?php

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Payments\Database\Factories\PaymentFactory;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Users\Models\User;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'booking_id',
        'client_id',
        'payment_method_id',
        'gateway',
        'gateway_payment_id',
        'amount',
        'currency',
        'status',
        'transaction_url',
        'failure_reason',
        'card_brand',
        'card_last_four',
        'gateway_response',
        'paid_at',
    ];

    /**
     * The raw gateway response is never returned by the API (§8.11).
     */
    protected $hidden = [
        'gateway_response',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentRecordStatus::class,
            'amount' => 'integer',
            'gateway_response' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    protected static function booted(): void
    {
        // Every payment gets a uuid (sent to Moyasar as `given_id`) even when
        // created through the factory or a seeder.
        static::creating(function (Payment $payment): void {
            $payment->uuid ??= (string) Str::uuid();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Data visibility (§9.8): consultants only see payments of their own
     * bookings; admins see everything.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isConsultant()) {
            $query->whereHas('booking', fn (Builder $b) => $b->where('consultant_id', $user->id));
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isPaid(): bool
    {
        return $this->status === PaymentRecordStatus::Paid;
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

    public function client(): BelongsTo
    {
        // withTrashed: a payment must still resolve its client after the
        // client is soft-deleted (admin payment list).
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
