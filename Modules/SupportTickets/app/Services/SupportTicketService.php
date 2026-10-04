<?php

namespace Modules\SupportTickets\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Packages\Enums\SubscriptionStatus;
use Modules\SupportTickets\Enums\TicketCategory;
use Modules\SupportTickets\Enums\TicketStatus;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Models\SupportTicketMessage;
use Modules\SupportTickets\Notifications\SupportTicketNotification;
use Modules\Users\Models\User;

class SupportTicketService
{
    public function create(Client $client, array $data, ?UploadedFile $image, ?UploadedFile $voice): SupportTicket
    {
        $ticket = DB::transaction(function () use ($client, $data, $image, $voice): SupportTicket {
            $ticket = SupportTicket::query()->create(['client_id' => $client->id, 'consultant_id' => $this->activeConsultantId($client), 'category' => $data['category'], 'status' => TicketStatus::Open, 'last_message_at' => now()]);
            $this->addMessage($ticket, $client, $data['description'], $image, $voice, false, false);

            return $ticket->load(['client', 'consultant', 'messages.media', 'messages.sender']);
        });
        $this->notifyStaffAndConsultant($ticket, 'created');

        return $ticket;
    }

    public function addMessage(SupportTicket $ticket, Model $sender, ?string $body, ?UploadedFile $image, ?UploadedFile $voice, bool $isInternal, bool $notify = true): SupportTicketMessage
    {
        abort_if($ticket->status === TicketStatus::Closed, 422);
        if ($isInternal) {
            abort_unless($sender instanceof User && $sender->isAdmin(), 403);
        }
        $message = $ticket->messages()->create(['sender_type' => $sender->getMorphClass(), 'sender_id' => $sender->getKey(), 'body' => $body, 'is_internal' => $isInternal]);
        if ($image) {
            $message->addMedia($image)->toMediaCollection('image');
        }
        if ($voice) {
            $message->addMedia($voice)->toMediaCollection('voice');
        }
        $ticket->forceFill(['status' => $ticket->status === TicketStatus::Resolved ? TicketStatus::InProgress : $ticket->status, 'last_message_at' => now()])->save();
        if ($notify && ! $isInternal) {
            if ($sender instanceof Client) {
                $this->notifyStaffAndConsultant($ticket, 'message');
            } else {
                $ticket->client->notify(new SupportTicketNotification($ticket, 'message'));
            }
        }

        return $message->load(['media', 'sender']);
    }

    public function activeConsultantId(Client $client): ?int
    {
        $subscriptionIds = $client->subscriptions()->where('status', SubscriptionStatus::Active)->where('starts_at', '<=', now())->where('ends_at', '>', now())->pluck('id');

        return Booking::query()->where('client_id', $client->id)->whereIn('client_subscription_id', $subscriptionIds)->oldest()->value('consultant_id');
    }

    private function notifyStaffAndConsultant(SupportTicket $ticket, string $event): void
    {
        Notification::send(User::query()->staff()->active()->get(), new SupportTicketNotification($ticket, $event));
        if ($ticket->consultant && $ticket->category !== TicketCategory::ConsultantComplaint) {
            $ticket->consultant->notify(new SupportTicketNotification($ticket, $event));
        }
    }
}
