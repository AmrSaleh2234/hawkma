<?php

namespace Modules\Reports\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Reports\Database\Factories\ReportFactory;
use Modules\Reports\Enums\ReportApprovalStatus;
use Modules\Users\Models\User;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
        'approval_status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        'current_media_id',
        'client_notified_at',
        'first_downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'approval_status' => ReportApprovalStatus::class,
            'reviewed_at' => 'datetime',
            'client_notified_at' => 'datetime',
            'first_downloaded_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ReportFactory
    {
        return ReportFactory::new();
    }

    /**
     * The report files (§8.12): private disk, pdf/doc/docx, max 20 MB (the
     * request validates the size; the collection enforces the types). Every
     * submission adds a new version — `current_media_id` points at the one
     * the client downloads; the rest are kept for the review history.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('report_file')
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

    /**
     * Reports whose review is done and the client may see them.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', ReportApprovalStatus::Approved->value);
    }

    /**
     * The admin inbox: reports waiting for a decision.
     */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('approval_status', ReportApprovalStatus::PendingReview->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isApproved(): bool
    {
        return $this->approval_status === ReportApprovalStatus::Approved;
    }

    public function isPendingReview(): bool
    {
        return $this->approval_status === ReportApprovalStatus::PendingReview;
    }

    /**
     * The media the client should download — the file of the approved (or
     * latest submitted) version.
     */
    public function currentMedia(): ?Media
    {
        if ($this->current_media_id !== null) {
            return $this->getMedia('report_file')->firstWhere('id', $this->current_media_id);
        }

        return $this->getMedia('report_file')->sortByDesc('id')->first();
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
        // withTrashed: a report must still resolve its people after they
        // are soft-deleted (admin/client report lists).
        return $this->belongsTo(User::class, 'consultant_id')->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ReportReview::class)->latest('id');
    }
}
