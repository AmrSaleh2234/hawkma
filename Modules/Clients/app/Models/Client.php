<?php

namespace Modules\Clients\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Database\Factories\ClientFactory;
use Modules\Clients\Notifications\ClientResetPasswordNotification;
use Modules\Packages\Models\ClientSubscription;
use Modules\Payments\Models\PaymentMethod;
use Modules\Users\Models\User;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Client extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, InteractsWithMedia, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'company_name',
        'is_active',
        'email_verified_at',
        'password',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Match the database defaults so fresh models serialize correctly.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(200)
            ->nonQueued();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Data visibility scoping (plan §9.8): consultants only see clients who
     * have a booking with them; admins see everyone.
     *
     * The bookings table arrives in Phase 9 — until then a consultant has no
     * bookings, so the scope fails closed (returns nothing).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isConsultant()) {
            if (! class_exists(Booking::class)) {
                // Phase 9 activates this scope properly.
                return $query->whereRaw('1 = 0');
            }

            $query->whereHas('bookings', fn (Builder $b) => $b->where('consultant_id', $user->id));
        }

        return $query;
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->getFirstMediaUrl('avatar') ?: null);
    }

    protected function avatarThumbUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->getFirstMediaUrl('avatar', 'thumb') ?: null);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function locations(): HasMany
    {
        return $this->hasMany(ClientLocation::class);
    }

    public function defaultLocation(): HasOne
    {
        return $this->hasOne(ClientLocation::class)->where('is_default', true);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class);
    }

    public function activeSubscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class)->where('status', 'active');
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(ClientSubscription::class)->where('status', 'active')->latest();
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function defaultPaymentMethod(): HasOne
    {
        return $this->hasOne(PaymentMethod::class)->where('is_default', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    public function sendPasswordResetNotification($token): void
    {
        $url = rtrim((string) config('app.client_frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($this->email);

        $this->notify(new ClientResetPasswordNotification($url));
    }
}
