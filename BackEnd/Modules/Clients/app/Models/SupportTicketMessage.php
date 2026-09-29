<?php

namespace Modules\Clients\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SupportTicketMessage extends Model
{
    protected $fillable = [
        'ticket_id',
        'sender_type',
        'sender_id',
        'body',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    /**
     * The author: a Client (sender_type = Modules\Clients\Models\Client)
     * or a staff User (sender_type = Modules\Users\Models\User).
     */
    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    public function isFromClient(): bool
    {
        return $this->sender_type === Client::class;
    }
}
