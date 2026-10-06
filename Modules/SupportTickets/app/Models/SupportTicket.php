<?php

namespace Modules\SupportTickets\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Clients\Models\Client;
use Modules\SupportTickets\Database\Factories\SupportTicketFactory;
use Modules\SupportTickets\Enums\TicketCategory;
use Modules\SupportTickets\Enums\TicketStatus;
use Modules\Users\Models\User;

class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'consultant_id', 'category', 'status', 'last_message_at'];

    protected function casts(): array
    {
        return ['category' => TicketCategory::class, 'status' => TicketStatus::class, 'last_message_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::created(function (SupportTicket $ticket): void {
            $ticket->forceFill(['reference' => sprintf('ST-%s-%06d', $ticket->created_at->year, $ticket->id)])->saveQuietly();
        });
    }

    protected static function newFactory(): SupportTicketFactory
    {
        return SupportTicketFactory::new();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isConsultant()) {
            $query->where('consultant_id', $user->id)->where('category', '!=', TicketCategory::ConsultantComplaint);
        }

        return $query;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id')->withTrashed();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    /**
     * The opening message holds the description the client submitted
     * (plus its optional image/voice attachments).
     */
    public function firstMessage(): HasOne
    {
        return $this->hasOne(SupportTicketMessage::class)->oldestOfMany();
    }
}
