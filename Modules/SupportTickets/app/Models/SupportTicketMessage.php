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
        // MIME aliases included deliberately: validation checks extensions but
        // the collection sniffs content, and real files surface as aliases
        // (audio/x-wav, audio/x-m4a, video/webm/mp4 shared containers).
        $this->addMediaCollection('image')->singleFile()->useDisk('local')->acceptsMimeTypes(['image/jpeg', 'image/pjpeg', 'image/png', 'image/x-png', 'image/webp']);
        $this->addMediaCollection('voice')->singleFile()->useDisk('local')->acceptsMimeTypes([
            'audio/mpeg', 'audio/mpeg3', 'audio/mp3', 'audio/x-mp3',
            'audio/mp4', 'video/mp4', 'audio/x-m4a', 'audio/aac', 'audio/mp4a-latm', 'audio/x-mp4a',
            'audio/ogg', 'audio/vorbis', 'audio/oga',
            'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/x-wave', 'audio/vnd.wave', 'audio/x-pn-wav',
            'audio/webm', 'video/webm',
        ]);
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
