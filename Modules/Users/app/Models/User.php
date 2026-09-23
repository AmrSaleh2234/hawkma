<?php

namespace Modules\Users\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Bookings\Models\Booking;
use Modules\Consultants\Models\ConsultantAvailability;
use Modules\Consultants\Models\ConsultantTimeOff;
use Modules\Reports\Models\Report;
use Modules\Users\Database\Factories\UserFactory;
use Modules\Users\Enums\UserType;
use Modules\Users\Notifications\ResetPasswordNotification;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, HasRoles, InteractsWithMedia, Notifiable, SoftDeletes;

    protected string $guard_name = 'admin';

    protected $fillable = [
        'type',
        'name',
        'email',
        'phone',
        'title',
        'specialization',
        'bio',
        'is_active',
        'email_verified_at',
        'password',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
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

    public function isAdmin(): bool
    {
        return $this->type === UserType::Admin;
    }

    public function isConsultant(): bool
    {
        return $this->type === UserType::Consultant;
    }

    public function scopeConsultants(Builder $query): Builder
    {
        return $query->where('type', UserType::Consultant->value);
    }

    public function scopeStaff(Builder $query): Builder
    {
        return $query->where('type', UserType::Admin->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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
    | Relations (as consultant)
    |--------------------------------------------------------------------------
    */

    public function availabilities(): HasMany
    {
        return $this->hasMany(ConsultantAvailability::class, 'consultant_id');
    }

    public function timeOffs(): HasMany
    {
        return $this->hasMany(ConsultantTimeOff::class, 'consultant_id');
    }

    public function consultantBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'consultant_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'consultant_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    public function sendPasswordResetNotification($token): void
    {
        $url = rtrim((string) config('app.admin_frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($this->email);

        $this->notify(new ResetPasswordNotification($url));
    }
}
