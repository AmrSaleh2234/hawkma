<?php

namespace Modules\Reports\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Reports\Database\Factories\ReportFactory;
use Modules\Users\Models\User;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Report extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'booking_id',
        'consultant_id',
        'client_id',
        'title',
        'summary',
        'uploaded_by',
        'client_notified_at',
        'first_downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'client_notified_at' => 'datetime',
            'first_downloaded_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ReportFactory
    {
        return ReportFactory::new();
    }

    /**
     * The single report file (§8.12): private disk, pdf/doc/docx, max 20 MB
     * (the request validates the size; the collection enforces the types).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('report_file')
            ->singleFile()
            ->useDisk('local')
            ->acceptsMimeTypes([
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Data visibility (§9.8): consultants only see their own reports;
     * admins see everything.
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
