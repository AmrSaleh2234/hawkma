<?php

namespace Modules\SupportTickets\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\SupportTickets\Database\Factories\SupportTicketMessageFactory;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class SupportTicketMessage extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = ['support_ticket_id', 'sender_type', 'sender_id', 'body', 'is_internal'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    protected static function newFactory(): SupportTicketMessageFactory
    {
        return SupportTicketMessageFactory::new();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile()->useDisk('local')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
        $this->addMediaCollection('voice')->singleFile()->useDisk('local')->acceptsMimeTypes(['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm']);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }
}
