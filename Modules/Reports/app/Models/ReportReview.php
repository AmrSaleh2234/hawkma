<?php

namespace Modules\Reports\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Reports\Database\Factories\ReportReviewFactory;
use Modules\Reports\Enums\ReportApprovalStatus;
use Modules\Users\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One immutable entry in the review history of a report (a submission, an
 * approval, a rejection or a return-for-revision). `media_id` points at the
 * file version as it was at that step.
 */
class ReportReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_id',
        'status',
        'note',
        'media_id',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReportApprovalStatus::class,
        ];
    }

    protected static function newFactory()
    {
        return ReportReviewFactory::new();
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
