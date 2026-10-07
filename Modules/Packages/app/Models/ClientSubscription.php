<?php

namespace Modules\Packages\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clients\Models\Client;
use Modules\Packages\Database\Factories\ClientSubscriptionFactory;
use Modules\Packages\Enums\SubscriptionStatus;

class ClientSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'package_id',
        'package_snapshot',
        'status',
        'starts_at',
        'ends_at',
        'consultations_limit',
        'consultations_used',
        'price_paid',
    ];

    /**
     * Match the database defaults so fresh models serialize correctly.
     */
    protected $attributes = [
        'status' => SubscriptionStatus::Active->value,
        'consultations_used' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'package_snapshot' => 'array',
            'consultations_limit' => 'integer',
            'consultations_used' => 'integer',
            'price_paid' => 'integer',
        ];
    }

    protected static function newFactory(): ClientSubscriptionFactory
    {
        return ClientSubscriptionFactory::new();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers (plan §8.8)
    |--------------------------------------------------------------------------
    */

    /**
     * Active means: status active and now() between starts_at and ends_at.
     */
    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && $this->starts_at->lte(now())
            && $this->ends_at->gt(now());
    }

    /**
     * Remaining consultations; null = unlimited.
     */
    public function remaining(): ?int
    {
        if ($this->isUnlimited()) {
            return null;
        }

        return max(0, $this->consultations_limit - $this->consultations_used);
    }

    public function hasRemaining(): bool
    {
        return $this->remaining() === null || $this->remaining() > 0;
    }

    public function isUnlimited(): bool
    {
        return $this->consultations_limit === null;
    }

    /**
     * The package summary for resources/emails: the purchase-time snapshot
     * wins, so package updates or deletes never change what this
     * subscription shows.
     *
     * @return array<string, mixed>|null
     */
    public function packageDisplay(): ?array
    {
        return Package::displayFrom($this->package_snapshot, $this->package);
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

    public function package(): BelongsTo
    {
        // withTrashed: a subscription must still resolve its package after
        // the package is drafted (soft deleted).
        return $this->belongsTo(Package::class)->withTrashed();
    }
}
