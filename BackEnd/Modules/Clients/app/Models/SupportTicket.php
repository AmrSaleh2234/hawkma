<?php

namespace Modules\Clients\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Client support ticket (customer service thread).
 * status: open | in_progress | closed
 */
class SupportTicket extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_CLOSED,
    ];

    public const TYPE_GENERAL = 'general';

    public const TYPE_CONSULTANT_COMPLAINT = 'consultant_complaint';

    public const TYPE_BOOKING_ISSUE = 'booking_issue';

    public const TYPE_PAYMENT_ISSUE = 'payment_issue';

    public const TYPE_TECHNICAL = 'technical';

    public const TYPE_SUGGESTION = 'suggestion';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_GENERAL,
        self::TYPE_CONSULTANT_COMPLAINT,
        self::TYPE_BOOKING_ISSUE,
        self::TYPE_PAYMENT_ISSUE,
        self::TYPE_TECHNICAL,
        self::TYPE_SUGGESTION,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'client_id',
        'subject',
        'type',
        'status',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id');
    }
}
